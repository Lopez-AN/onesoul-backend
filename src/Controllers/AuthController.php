<?php

namespace App\Controllers;

use Exception;
use Throwable;
use App\Exceptions\DatabaseException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Auth;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Notification;
use App\Services\TwilioService;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Stripe\Stripe;
use DateTime;
use App\Helpers\EmailHelper;
use Predis\Client as RedisClient;
use App\Utils\ParameterValidator;

require_once ROOT . '/src/Utils/validateToken.php';
require_once ROOT . '/src/Utils/validateReCaptcha.php';

class AuthController{

  protected $user;
  protected $auth;
  protected $notification;
  protected $subscription;
  protected $redis;
  protected $twilio;

  public function __construct(Auth $auth, User $user, Notification $notification,
    Subscription $subscription, RedisClient $redisClient, TwilioService $twilio
  ){
    $this->auth = $auth;
    $this->user = $user;
    $this->notification = $notification;
    $this->subscription = $subscription;
    $this->redis = $redisClient;
    $this->twilio = $twilio;
  }

  /**
   * Autentica un usuario con email/username y contraseña
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: login exitoso
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: credenciales inválidas
   * @statusCode 403: usuario bloqueado o deshabilitado
   * @statusCode 500: error del servidor
   **/
  public function login(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();

    $pValidation = ParameterValidator::validate($response, 'auth','login', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    # Validar credenciales básicas
    if(empty($params['UserName']) && empty($params['Email'])){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Either username or email must be provided."
        ]
      ]);
    }

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Busco por mail o username
      $user = !empty($params['Email']) ?
        $this->user->getUserByEmail($params['Email'], false) : $this->user->getUserByUserName($params['UserName'], false);
      if(empty($user)){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "Invalid credentials"
          ]
        ]);
      }

      # Verificar si el usuario esta bloqueado
      if (!is_null($user['LockedUntil']) && strtotime($user['LockedUntil']) > time()) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporaly locked until " . $user['LockedUntil']
          ]
        ]);
      }

      # Verificar si la cuenta está desactivada
      if (!is_null($user['DeactivationDate'])) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_DISABLED",
            "desc" => "The specified user is disabled"
          ]
        ]);
      }

      # valido credenciales
      $userAuth = $this->auth->login($params['UserName'], $params['Email']);
      if(empty($userAuth)){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "Invalid credentials"
          ]
        ]);
      }
      if (!password_verify($params['Password'], $userAuth['PasswordHash'])) {
        # Logueo fallido actualizar contador de erroneos y tiempo bloqueo si corresponde
        $failedAttempts = $userAuth['FailedLoginAttempts'] + 1;
        $lockTime = $this->auth->calculateLockTime($failedAttempts);

        $this->auth->updateFailedLogin($userAuth['UserID'], $failedAttempts, $lockTime);

        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "The password is invalid"
          ]
        ]);
      }
      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $params['MfaID'], $params['MfaCode'], $clientIp);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Autentica un usuario con Google OAuth
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: login exitoso
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: token inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function loginGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $token = $data['Token'] ?? null;
    $mfaID = $data['MfaID'] ?? null;
    $mfaCode = $data['MfaCode'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if($token=== null || $recaptchaToken === null){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Validar el token de Google
      $oAuthResponse = validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
      if($oAuthResponse === false || !$oAuthResponse -> sub){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SSO_INVALID_TOKEN",
            "desc" => "Invalid Google token"
          ]
        ]);
      }
      $oAuthID = $oAuthResponse -> sub;

      # Traigo el resto de los datos del usuario
      $user = $this->user->getUserByOAuthID($oAuthID, 'google');
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified Google account was found"
          ],
          "data" => [
            "FirstName" => $oAuthResponse->given_name ?? null,
            "LastName"  => $oAuthResponse->family_name ?? null,
            "Email"     => $oAuthResponse->email ?? null,
            "Picture"   => $oAuthResponse->picture ?? null
          ]
        ]);
      }

      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Autentica un usuario con Facebook OAuth
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: login exitoso
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: token inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function loginFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $oAuthID = $data['UserID'] ?? null;
    $jwtToken = $data['JwtToken'] ?? null; # null = web, not null = native
    $token = $data['Token'] ?? null;
    $mfaID = $data['MfaID'] ?? null;
    $mfaCode = $data['MfaCode'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if($oAuthID === null || $token === null || $recaptchaToken === null) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # NATIVE
      if($jwtToken){
        # 1. Validar JWT contra las claves públicas de Facebook
        $oAuthResponse = $this->_validateFacebookJWT($jwtToken);
        if ($oAuthResponse === false) {
          return $response->withStatus(403)->withJson([
            "error" => [
              "code" => "SSO_INVALID_TOKEN",
              "desc" => "Invalid Facebook JWT token"
            ]
          ]);
        }

        # Me traigo el ID de facebook
        $oAuthID = $oAuthResponse->sub ?? null;
      }else{ # WEB
        $oAuthResponse = validateToken("https://graph.facebook.com/$oAuthID?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
        if($oAuthResponse === false){
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "SSO_INVALID_TOKEN",
              "desc" => "Invalid Facebook token"
            ]
          ]);
        }
      }

      # Me traigo los datos del SSO
      $email = $oAuthResponse -> email ?? null;
      $firstName = $oAuthResponse -> first_name ?? null;
      $lastName = $oAuthResponse -> last_name ?? null;
      $picture = $oAuthResponse -> picture -> data -> url ?? null;

      # Traigo el resto de los datos del usuario
      $user = $this->user->getUserByOAuthID($oAuthID, "facebook");
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified Facebook account was found"
          ],
          "data" => [
            "FirstName" => $oAuthResponse->first_name ?? null,
            "LastName"  => $oAuthResponse->last_name ?? null,
            "Email"     => $oAuthResponse->email ?? null,
            "Picture"   => $oAuthResponse->picture->data->url ?? null
          ]
        ]);
      }

      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Autentica un usuario con Apple OAuth
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: login exitoso
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: token inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function loginApple(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $code = $data['Code'] ?? null;
    $idToken = $data['IdToken'] ?? null;
    $rawNonce = $data['RawNonce'] ?? null;
    $mfaID = $data['MfaID'] ?? null;
    $mfaCode = $data['MfaCode'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(($idToken === null && $code === null) || $rawNonce === null || $recaptchaToken === null) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try{
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Si no vino id_token, hacer exchange con code
      if (empty($idToken) && !empty($code)) {
        $idToken = $this->_appleExchangeCodeForIdToken($code);
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SSO_INVALID_CODE",
            "desc" => "Invalid Apple authorization code"
          ]
        ]);
      }

      /* Se valida contra el AUC (nuestro client ID) y el nonce recibido del front
        vs el hasheado recibido en el token */
      $expectedAud = $GLOBALS['config']['apple']['client_id'];
      $oAuthResponse = $this->_validateAppleToken($idToken, $expectedAud, $rawNonce);
      if ($oAuthResponse === false) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SSO_INVALID_TOKEN",
            "desc" => "Invalid Apple token"
          ]
        ]);
      }
      $oAuthID = $oAuthResponse -> sub;
      $user = $this->user->getUserByOAuthID($oAuthID, "apple");
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified Apple account was found"
          ],
          "data" => [
            "Email" => $oAuthResponse->email ?? null
          ]
        ]);
      }
      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Callback de Apple OAuth para redirecciones web
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: HTML con script para enviar datos al opener
   **/
  public function appleLoginCallback(Request $request, Response $response, array $args) {
    $parsed   = $request->getParsedBody() ?? [];
    $code     = $parsed['code']     ?? null;
    $id_token = $parsed['id_token'] ?? null;
    $state    = isset($parsed['state']) ? @json_decode($parsed['state']) : null;
    $error    = $parsed['error']    ?? null;

    if (!$state) {
      $html = '<!doctype html><html><body><script>(function(){window.close()})();</script></body></html>';
    } else {
      $html = '<!doctype html><html><body><script>(function(){var p=' .
      json_encode([
        'provider' => 'apple',
        'code'     => $code,
        'id_token' => $id_token,
        'uuid'     => $state->uuid,
        'error'    => $error,
      ]) .
      ';try{window.opener&&window.opener.postMessage(p,"https://' . $state->origin . '")}catch(e){}window.close()})();</script></body></html>';
    }

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
  }

  /**
   * Lógica genérica de login para todos los tipos de autenticación
   * @param  Response $response: objeto de response HTTP
   * @param  Request $request: objeto de request HTTP
   * @param  array $user: datos del usuario
   * @param  string $mfaID: ID de MFA si está disponible
   * @param  string $mfaCode: código MFA si está disponible
   * @param  string $clientIp: IP del cliente
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: login exitoso
   * @statusCode 400: MFA requerido sin código
   * @statusCode 401: MFA ID inválido
   * @statusCode 403: usuario bloqueado o deshabilitado
   * @statusCode 500: error del servidor
   **/
  private function _loginGeneric(Response $response, Request $request, $user, $mfaID, $mfaCode, $clientIp) {
    # Verificar si el usuario esta bloqueado
    if (!is_null($user['LockedUntil']) && strtotime($user['LockedUntil']) > time()) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "USER_LOCKED",
          "desc" => "Account is temporaly locked until " . $user['LockedUntil']
        ]
      ]);
    }
    # Verificar si el usuario esta deshabilitado
    if (!is_null($user['DeactivationDate'])) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "USER_DISABLED",
          "desc" => "Account is disabled"
        ]
      ]);
    }

    # USUARIO TIENE MFA ACTIVAOD
    $newMfaId = null;
    if ($user['TwoFactorAuth'] && !is_null($user['MfaSecret'])) {
      # USUARIO PROPORCIONO UN ID DE NAVEGADOR
      if (!empty($mfaID)) {
        # Valido el ID de navegador
        if (!$this->auth->validateMfaId($user['UserID'], $mfaID)) {
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "INVALID_MFA_ID",
              "desc" => "MFA ID is not valid"
            ]
          ]);
        }
      # USUARIO PROPORCIONO CODIGO MFA
      } elseif (!empty($mfaCode)) {
        $validation = $this->_mfaCheck($response, $user['UserID'], $mfaCode, $user['MfaSecret'], $user['FailedLoginAttempts'], $user['LockedUntil']);
        if(!$validation->valid){
          return $validation->response;
        }
      } else {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MFA_REQUIRED",
            "desc" => "MFA validation is required"
          ]
        ]);
      }

      if (empty($mfaID)) {
        $newMfaId = uniqid();
      }
    }

    # Login exitoso: resetear intentos fallidos y desbloquear cuenta
    $this->auth->updateFailedLogin($user['UserID'], 0, null);

    # Generar JWT
    $jwt = $this -> _JWTgen($user);

    # Guardar datos del navegador si es necesario
    if ($newMfaId !== null) {
      $this->auth->storeBrowserData($user['UserID'], $request, $newMfaId, $clientIp);
    }

    $userPlan = $this->subscription->getSubscriptionByUser($user['UserID']);
    $userSettings = $this->user->getSettings($user['UserID']) ?:
      throw new DatabaseException("Failed to retrieve the user settings");

    return $response->withStatus(200)->withJson([
      'Token' => $jwt,
      'MfaID' => $newMfaId,
      'UserData' => $user,
      'UserPlan' => $userPlan,
      'UserSettings' => $userSettings,
    ]);
  }

  /**
   * Registra un nuevo usuario con email y contraseña
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: registro exitoso
   * @statusCode 400: parámetros inválidos o contraseña débil
   * @statusCode 409: email o username duplicado
   * @statusCode 422: email no validado
   * @statusCode 500: error del servidor
   **/
  public function register(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $email = $data['Email'] ?? null;
    $userName = $data['UserName'] ?? null;
    $password = $data['Password'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? false;
    $acceptedTerms = $data['AcceptedTerms'] ?? null;
    $acceptedPrivacy = $data['AcceptedPrivacyPolicy'] ?? null;
    $tycVersion = $data['TyCVersion'] ?? null;
    $privacyVersion = $data['PrivacyPolicyVersion'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $userAgent = $request->getHeader('User-Agent')[0] ?? '';

    if(empty($email) || empty($userName) || empty($password) || empty($recaptchaToken) || $receiveNewsletters === null){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $otpJson = $this->redis->get("otp:{$email}");
      # Decodificar JSON
      $otpData = @json_decode($otpJson);
      if (!$otpData || !$otpData -> validated) {
        return $response->withStatus(422)->withJson([
          "error" => [
            "code" => "EMAIL_NOT_VALIDATED",
            "desc" => "The email address is not validated."
          ]
        ]);
      }

      # Validación de fortaleza de contraseña
      if(!$this->_passwordComplexity($password)) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "WEAK_PASSWORD",
            "desc" => "Password doesn't meet complexity requirements"
          ]
        ]);
      }

      # Validar que AcceptedTerms y AcceptedPrivacyPolicy esten aceptados
      if ($acceptedTerms !== 1 || $acceptedPrivacy !== 1) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "CONSENT_REQUIRED",
            "desc" => "AcceptedTerms and AcceptedPrivacyPolicy must both be accepted."
          ]
        ]);
      }

      # Validacion de referidos
      $referrerUserID = null;
      if (!empty($referralCode)) {
        $result = $this->user->getUserByRefCode($referralCode);
        if (!$result) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_REFERRAL_CODE",
              "desc" => "The provided referral code is not valid"
            ]
          ]);
        }
        $referrerUserID = $result['UserID'];
      }

      # Verifico que usuario e email no existan
      if($this->user->getUserByEmail($email)){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DUPLICATED_EMAIL",
            "desc" => "A user with the specified email address already exists"
          ]
        ]);
      }
      if($this->user->getUserByUserName($userName)){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DUPLICATED_USERNAME",
            "desc" => "A user with the specified username already exists"
          ]
        ]);
      }

      # Busco si las versiones de tyc y privacy son correctas
      $result = $this->auth->checkLegalDocuments($tycVersion, $privacyVersion);
      if ((int)$result['total'] < 2) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_LEGAL_DOCUMENT_VERSION",
            "desc" => "One or both legal document versions are invalid."
          ]
        ]);
      }

      # El password se guarda hasheado (obvio!)
      $passwordHash = password_hash($password,PASSWORD_BCRYPT);

      # Registro al usuario
      $user = $this->auth->register($this->user, $email, $userName, $passwordHash, $tycVersion,
        $privacyVersion, $receiveNewsletters, $clientIp, $userAgent);

      $jwt = $this -> _JWTgen($user);
      $this->redis->del("otp:{$email}");

      if($referrerUserID){
        $this-> _handleReferralReward($referrerUserID, $user['UserID']);
      }

      $userSettings = $this->user->getSettings($user['UserID']) ?:
        throw new DatabaseException("Failed to retrieve the user settings");

      return $response->withStatus(200)->withJson([
        'Token' => $jwt,
        'UserData' => $user,
        'UserPlan' => null,
        'UserSettings' => $userSettings
      ]);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Registra un nuevo usuario con Google OAuth
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: registro exitoso
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: token inválido
   * @statusCode 409: usuario o email duplicado
   * @statusCode 500: error del servidor
   **/
  public function registerGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $altEmail = $data['AltEmail'] ?? null; # Opcional cuando el SSO no comparte el correo
    $token = $data['Token'] ?? null;
    $userName = $data['UserName'] ?? null;

    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? null;
    $acceptedTerms = $data['AcceptedTerms'] ?? null;
    $acceptedPrivacy = $data['AcceptedPrivacyPolicy'] ?? null;
    $tycVersion = $data['TyCVersion'] ?? null;
    $privacyVersion = $data['PrivacyPolicyVersion'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $userAgent = $request->getHeader('User-Agent')[0] ?? '';

    if($token === null || $userName === null || $recaptchaToken === null ||
      $receiveNewsletters === null || $acceptedTerms === null || $acceptedPrivacy === null ||
      $tycVersion === null || $privacyVersion === null
    ){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Validar el token de Google
      $oAuthResponse = validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
      if($oAuthResponse === false || !$oAuthResponse -> sub){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SSO_INVALID_TOKEN",
            "desc" => "Invalid Google token"
          ]
        ]);
      }
      $oAuthID = $oAuthResponse -> sub;

      # Me traigo los datos del SSO
      $email = $oAuthResponse -> email ?? null;
      $firstName = $oAuthResponse -> given_name ?? null;
      $lastName = $oAuthResponse -> family_name ?? null;
      $picture = $oAuthResponse -> picture ?? null;

      return $this->_registerGenericSSO(
        $response, $oAuthID, 'google', $email, $altEmail, $userName,
        $acceptedTerms, $acceptedPrivacy, $tycVersion, $privacyVersion,
        $receiveNewsletters, $referralCode, $clientIp, $userAgent,
        $firstName, $lastName, $picture
      );
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Registra un nuevo usuario con Facebook OAuth
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: registro exitoso
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: token inválido
   * @statusCode 409: usuario o email duplicado
   * @statusCode 500: error del servidor
   **/
  public function registerFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $altEmail = $data['AltEmail'] ?? null; # Opcional cuando el SSO no comparte el correo
    $token = $data['Token'] ?? null;
    $oAuthID = $data['UserID'] ?? null;
    $jwtToken = $data['JwtToken'] ?? null; # null = web, not null = native
    $userName = $data['UserName'] ?? null;

    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? null;
    $acceptedTerms = $data['AcceptedTerms'] ?? null;
    $acceptedPrivacy = $data['AcceptedPrivacyPolicy'] ?? null;
    $tycVersion = $data['TyCVersion'] ?? null;
    $privacyVersion = $data['PrivacyPolicyVersion'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $userAgent = $request->getHeader('User-Agent')[0] ?? '';

    if(($jwtToken === null && ($token === null || $oAuthID === null)) || $userName === null || $recaptchaToken === null ||
      $receiveNewsletters === null || $acceptedTerms === null || $acceptedPrivacy === null ||
      $tycVersion === null || $privacyVersion === null
    ){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try{
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # NATIVE
      if($jwtToken){
        # 1. Validar JWT contra las claves públicas de Facebook
        $decoded = $this->_validateFacebookJWT($jwtToken);
        if ($decoded === false) {
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "SSO_INVALID_TOKEN",
              "desc" => "Invalid Facebook JWT token"
            ]
          ]);
        }

        # Me traigo el ID de facebook
        $oAuthID = $decoded->sub ?? null;
      }else{ # WEB
        $oAuthResponse = validateToken("https://graph.facebook.com/$oAuthID?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
        if($oAuthResponse === false){
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "SSO_INVALID_TOKEN",
              "desc" => "Invalid Facebook token"
            ]
          ]);
        }
      }

      # Me traigo los datos del SSO
      $email = $oAuthResponse -> email ?? null;
      $firstName = $oAuthResponse -> first_name ?? null;
      $lastName = $oAuthResponse -> last_name ?? null;
      $picture = $oAuthResponse -> picture -> data -> url ?? null;

      return $this->_registerGenericSSO(
        $response, $oAuthID, 'facebook', $email, $altEmail, $userName,
        $acceptedTerms, $acceptedPrivacy, $tycVersion, $privacyVersion,
        $receiveNewsletters, $referralCode, $clientIp, $userAgent,
        $firstName, $lastName, $picture
      );
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Registra un nuevo usuario con Apple OAuth
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: registro exitoso
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: token inválido
   * @statusCode 409: usuario o email duplicado
   * @statusCode 500: error del servidor
   **/
  public function registerApple(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $altEmail = $data['AltEmail'] ?? null; # Opcional cuando el SSO no comparte el correo
    $code = $data['Code'] ?? null;
    $idToken = $data['IdToken'] ?? null;
    $rawNonce = $data['RawNonce'] ?? null;
    $userName = $data['UserName'] ?? null;

    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? false;
    $acceptedTerms = $data['AcceptedTerms'] ?? null;
    $acceptedPrivacy = $data['AcceptedPrivacyPolicy'] ?? null;
    $tycVersion = $data['TyCVersion'] ?? null;
    $privacyVersion = $data['PrivacyPolicyVersion'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $userAgent = $request->getHeader('User-Agent')[0] ?? '';

    if(($idToken === null && $code === null) || $rawNonce === null || $userName === null ||
      $recaptchaToken === null || $receiveNewsletters === null || $acceptedTerms === null ||
      $acceptedPrivacy === null || $tycVersion === null || $privacyVersion === null
    ){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try{
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Si no vino id_token, hacer exchange con code
      if (empty($idToken) && !empty($code)) {
        $idToken = $this->_appleExchangeCodeForIdToken($code);
        if (!$idToken) {
          $response->withStatus(401)->withJson([
            "error" => [
              "code" => "SSO_INVALID_CODE",
              "desc" => "Invalid Apple authorization code"
            ]
          ]);
        }
      }

      /* Se valida contra el AUC (nuestro client ID) y el nonce recibido del front
        vs el hasheado recibido en el token */
      $expectedAud = $GLOBALS['config']['apple']['client_id'];
      $oAuthResponse = $this->_validateAppleToken($idToken, $expectedAud, $rawNonce);
      if ($oAuthResponse === false) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SSO_INVALID_TOKEN",
            "desc" => "Invalid Apple token"
          ]
        ]);
      }
      $oAuthID = $oAuthResponse -> sub;
      $email = $oAuthResponse -> email ?? null;

      return $this->_registerGenericSSO(
        $response, $oAuthID, 'apple', $email, $altEmail, $userName,
        $acceptedTerms, $acceptedPrivacy, $tycVersion, $privacyVersion,
        $receiveNewsletters, $referralCode, $clientIp, $userAgent
      );
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Lógica genérica de registro para todos los tipos de OAuth
   * @param  Response $response: objeto de response HTTP
   * @param  string $oAuthId: ID del proveedor OAuth
   * @param  string $provider: nombre del proveedor (google, facebook, apple)
   * @param  string $email: email del usuario
   * @param  string $altEmail: email alternativo si el proveedor no comparte uno
   * @param  string $userName: nombre de usuario
   * @param  int $acceptedTerms: flag de aceptación de términos
   * @param  int $acceptedPrivacy: flag de aceptación de privacidad
   * @param  string $tycVersion: versión de términos y condiciones
   * @param  string $privacyVersion: versión de política de privacidad
   * @param  bool $receiveNewsletters: flag de suscripción a newsletters
   * @param  string $referralCode: código de referido opcional
   * @param  string $clientIp: IP del cliente
   * @param  string $userAgent: user agent del cliente
   * @param  string $firstName: nombre del usuario (opcional)
   * @param  string $lastName: apellido del usuario (opcional)
   * @param  string $picture: URL de foto de perfil (opcional)
   * @return Response: JSON con token JWT y datos del usuario o error
   * @statusCode 200: registro exitoso
   * @statusCode 400: parámetros o consentimientos inválidos
   * @statusCode 404: código de referido inválido
   * @statusCode 409: usuario o email duplicado
   * @statusCode 422: email no validado
   * @statusCode 500: error del servidor
   **/
  private function _registerGenericSSO(Response $response, $oAuthID, $provider,
    $email, $altEmail, $userName, $acceptedTerms, $acceptedPrivacy, $tycVersion,
    $privacyVersion, $receiveNewsletters, $referralCode, $clientIp, $userAgent,
    $firstName = null, $lastName = null, $picture = null
  ) {
    try {
      # Verificar si el usuario ya existe
      $user = $this->user->getUserByOAuthID($oAuthID, $provider);
      if($user){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "USER_ALREADY_EXISTS",
            "desc" => "User already registered with this " . ucfirst($provider) . " account"
          ]
        ]);
      }

      # Si el SSO no trajo email usar el recibido desde el front
      if(!$email){
        if(!$altEmail){
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "EMAIL_NOT_PROVIDED",
              "desc" => "You must provide an email because the SSO service does not provide one"
            ]
          ]);
        }
        # Verificar que el email alternativo esté validado
        $otpJson = $this->redis->get("otp:{$altEmail}");
        $otpData = @json_decode($otpJson);
        if (!$otpData || !$otpData->validated) {
          return $response->withStatus(422)->withJson([
            "error" => [
              "code" => "EMAIL_NOT_VALIDATED",
              "desc" => "The email address is not validated."
            ]
          ]);
        }
        $email = $altEmail;
      }

      # Validar consentimientos
      if ($acceptedTerms !== 1 || $acceptedPrivacy !== 1) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "CONSENT_REQUIRED",
            "desc" => "AcceptedTerms and AcceptedPrivacyPolicy must both be accepted."
          ]
        ]);
      }

      # Validar referral code si existe
      $referrerUserID = null;
      if (!empty($referralCode)) {
        $result = $this->user->getUserByRefCode($referralCode);
        if (!$result) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_REFERRAL_CODE",
              "desc" => "The provided referral code is not valid"
            ]
          ]);
        }
        $referrerUserID = $result['UserID'];
      }

      # Verificar que email y username no existan
      if($this->user->getUserByEmail($email)){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DUPLICATED_EMAIL",
            "desc" => "A user with the specified email address already exists"
          ]
        ]);
      }
      if($this->user->getUserByUserName($userName)){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DUPLICATED_USERNAME",
            "desc" => "A user with the specified username already exists"
          ]
        ]);
      }

      # Validar versiones de documentos legales
      $result = $this->auth->checkLegalDocuments($tycVersion, $privacyVersion);
      if ((int)$result['total'] < 2) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_LEGAL_DOCUMENT_VERSION",
            "desc" => "One or both legal document versions are invalid."
          ]
        ]);
      }

      # Registrar usuario SSO
      $user = $this->auth->registerSSO($this->user, $oAuthID, $provider, $email,
        $firstName, $lastName, $picture, $userName, $tycVersion, $privacyVersion,
        $receiveNewsletters, $clientIp, $userAgent
      );

      $jwt = $this -> _JWTgen($user); # Genero el token

      # Limpiar OTP de Redis si existe
      $this->redis->del("otp:{$altEmail}");

      # Manejar recompensa de referral
      if($referrerUserID){
        $this->_handleReferralReward($referrerUserID, $user['UserID']);
      }

      $userSettings = $this->user->getSettings($user['UserID']) ?:
        throw new DatabaseException("Failed to retrieve the user settings");

      return $response->withStatus(200)->withJson([
        'Token' => $jwt,
        'UserData' => $user,
        'UserPlan' => null,
        'UserSettings' => $userSettings
      ]);

    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Envía un código OTP por email para validación en el proceso de registración
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP enviado exitosamente
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: reCaptcha inválido
   * @statusCode 500: error del servidor o fallo al enviar email
   **/
  public function sendOtpMail(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','send_otp_mail', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $otpCode = $this->_setOtpCodeRedis($params['Email']);
      $event = $this->notification->getEventType("SEND_OTP", "es");
      if(!empty($event)){
        $payload = [
          "ACTION"       => "Valida tu cuenta de correo",
          "YEAR"         => date('Y'),
          "OTP_CODE"     => $otpCode,
          "USERNAME"     => $params['Email']
        ];
        $subject = $this->notification->renderTemplate($event[0]['TemplateSubject'], $payload);
        $template = $this->notification->renderTemplate($event[0]['TemplateBody'], $payload);
        EmailHelper::send($params['Email'], $subject, $template);
      }
      return $response->withStatus(200)->withJson("OTP code sent");
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Valida un código OTP recibido por email durante el proceso de registro
   * @param  Request $request: objeto de request HTTP (requiere JWT opcional)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP validado exitosamente
   * @statusCode 400: parámetros inválidos o OTP expirado
   * @statusCode 401: reCaptcha o código OTP inválido
   * @statusCode 500: error del servidor
   **/
  public function validateOtpMail(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','validate_otp_mail', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $result = $this->_validateOtpMail($response, $params['Email'], $params['OTPCode']);
      if(!$result->valid){
        return $result->response;
      }
      return $response->withStatus(200)->withJson("OTP code validated successfully");
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Envía un código OTP por SMS para solicitar un cambio de email
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP enviado exitosamente
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: reCaptcha inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor o fallo al enviar email
   **/
  public function requestEmailChange(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','send_otp_mail', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $user = $this->user->getUserById($jwt->data->UserID);
      if(empty($user)){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(404)->withJson([
            "error" => [
              "code" => "USER_NOT_FOUND",
              "desc" => "No user associated with the specified id was found"
            ]
          ])
        ];
      }

      if($params['Email'] === $user['Email']){
        return $response->withStatus(422 )->withJson([
          "error" => [
            "code" => "UNCHANGED_EMAIL",
            "desc" => "The new email is the same as the current one"
          ]
        ]);
      }

      # Valido que no se repita el email en otro usuario
      $check = $this->user->getUserByEmail($params['Email']);
      if($check && $check['UserID'] !== $jwt->data->UserID){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DUPLICATED_EMAIL",
            "desc" => "Another user with the specified email already exists"
          ]
        ]);
      }

      $otpCode = $this->_setOtpCodeRedis($params['Email']);
      $event = $this->notification->getEventType("SEND_OTP", "es");
      if(!empty($event)){
        $payload = [
          "YEAR"         => date('Y'),
          "OTP_CODE"     => $otpCode,
          "USERNAME"     => $params['Email']
        ];
        $subject = $this->notification->renderTemplate($event[0]['TemplateSubject'], $payload);
        $template = $this->notification->renderTemplate($event[0]['TemplateBody'], $payload);
        EmailHelper::send($params['Email'], $subject, $template);
      }
      return $response->withStatus(200)->withJson('Email change requested, OTP sent');
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Verifica un código OTP enviado por email para confirmar un cambio de email
   * @param  Request $request: objeto de request HTTP (requiere JWT opcional)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP validado exitosamente
   * @statusCode 400: parámetros inválidos o OTP expirado
   * @statusCode 401: reCaptcha o código OTP inválido
   * @statusCode 500: error del servidor
   **/
  public function validateEmailChange(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','validate_otp_mail', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $result = $this->_validateOtpMail($response, $params['Email'], $params['OTPCode']);
      if(!$result->valid){
        return $result->response;
      }
      $this->auth->changeUserMail($jwt->data->UserID, $params['Email']);
      return $response->withStatus(200)->withJson('Email change confirmed');
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Valida código OTP contra Redis
   * @param  Response $response: objeto de response HTTP
   * @param  string $email: email del usuario
   * @param  string $otpCode: código OTP a validar
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP validado exitosamente
   * @statusCode 401: código OTP inválido, expirado o máximo de intentos
   **/
  private function _validateOtpMail($response, $email, $otpCode){
    $MAX_ATTEMPTS = 5;

    $otpJson = $this->redis->get("otp:{$email}");
    # Decodificar JSON
    $otpData = @json_decode($otpJson);
    if (!$otpData) {
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(404)->withJson([
          "error" => [
            "code" => "OTP_CODE_NOT_FOUND",
            "desc" => "OTP code is not set. Please request a new OTP."
          ]
        ])
      ];
    }
    $attempts = $otpData -> attempts;
    $hashedOtp = $otpData -> otp_hash;
    $expiresAt = $otpData -> expires_at;

    # Verificar si expiro
    if (time() > $expiresAt) {
      $this->redis->del("otp:{$email}");
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(401)->withJson([
          "error" => [
            "code" => "EXPIRED_OTP",
            "desc" => "OTP has expired. Please request a new OTP."
          ]
        ])
      ];
    }

    # Verificar intentos fallidos
    if ($attempts >= $MAX_ATTEMPTS) {
      $this->redis->del("otp:{$email}");
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(401)->withJson([
          "error" => [
            "code" => "OTP_MAX_ATTEMPTS",
            "desc" => "Maximum OTP attempts reached. Please request a new OTP."
          ]
        ])
      ];
    }

    # Verificar OTP
    if (!password_verify($otpCode, $hashedOtp)) { # No es valido
      # Incrementar intentos en el JSON
      $otpData -> attempts++;
      $this->redis->setex("otp:{$email}", 86400, json_encode($otpData));
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(401)->withJson([
          "error" => [
            "code" => "OTP_CODE_INVALID",
            "desc" => "Invalid OTP code"
          ]
        ])
      ];
    }

    # OTP válido
    $otpData -> validated = true;
    $this->redis->setex("otp:{$email}", 86400, json_encode($otpData));
    return (object)["valid" => true, "response" => null];
  }

  /**
   * Envía un código OTP por SMS para solicitar un cambio de teléfono
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP enviado exitosamente
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: reCaptcha inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor o fallo al enviar email
   **/
  public function requestPhoneChange(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','send_otp_phone', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $user = $this->user->getUserById($jwt->data->UserID);
      if(empty($user)){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(404)->withJson([
            "error" => [
              "code" => "USER_NOT_FOUND",
              "desc" => "No user associated with the specified id was found"
            ]
          ])
        ];
      }

      $newPhone = $this->twilio->fixArgPhone($params['Phone']);
      $currentPhone = $user['Phone'] ? $this->twilio->fixArgPhone($user['Phone']) : null;

      if($currentPhone && $newPhone === $currentPhone){
        return $response->withStatus(422 )->withJson([
          "error" => [
            "code" => "UNCHANGED_PHONE",
            "desc" => "The new phone is the same as the current one"
          ]
        ]);
      }

      # Valido que no se repita el email en otro usuario
      $check = $this->user->getUserByPhone($newPhone);
      if($check && $check['UserID'] !== $jwt->data->UserID){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "DUPLICATED_PHONE",
            "desc" => "Another user with the specified phone already exists"
          ]
        ]);
      }

      $this->twilio->sendOtpSms($newPhone);
      return $response->withStatus(200)->withJson('Phone change requested, SMS OTP sent');
    } catch (\Twilio\Exceptions\RestException $e) {
      $error = $this->twilio->handleTwilioException($e->getCode() ?: 0);
      return $response->withStatus($error->status)->withJson([
        "error" => [
          "code" => $error->code,
          "desc" => $error->desc,
        ]
      ]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Verifica un código OTP enviado por SMS para confirmar un cambio de teléfono
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP verificado exitosamente
   * @statusCode 400: parámetros inválidos o formato incorrecto
   * @statusCode 401: código OTP inválido o expirado
   * @statusCode 500: error del servidor
   **/
  public function validatePhoneChange(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','send_otp_phone', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    try{
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      $user = $this->user->getUserById($jwt->data->UserID);
      if(empty($user)){
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(404)->withJson([
            "error" => [
              "code" => "USER_NOT_FOUND",
              "desc" => "No user associated with the specified id was found"
            ]
          ])
        ];
      }

      $newPhone = $this->twilio->fixArgPhone($params['Phone']);
      $valid = $this->twilio->validateOtpSms($newPhone, $params['OTPCode']);
      if (!$valid) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "OTP_CODE_INVALID",
            "desc" => "Invalid OTP code"
          ]
        ]);
      }

      $this->auth->changeUserPhone($jwt->data->UserID, $newPhone);
      return $response->withStatus(200)->withJson('Phone change confirmed');
    } catch (\Twilio\Exceptions\RestException $e) {
      $error = $this->twilio->handleTwilioException($e->getCode() ?: 0);
      return $response->withStatus($error->status)->withJson([
        "error" => [
          "code" => $error->code,
          "desc" => $error->desc,
        ]
      ]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Resetea la contraseña de un usuario usando código OTP
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: contraseña reseteada exitosamente
   * @statusCode 400: parámetros inválidos o OTP inválido
   * @statusCode 401: reCaptcha inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function resetPassword(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $email = $data['Email'] ?? '';
    $userName = $data['UserName'] ?? '';
    $password = $data['Password'] ?? '';
    $otpCode = $data['OTPCode'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if ((empty($email) && empty($userName)) || empty($recaptchaToken) || empty($password) || empty($otpCode)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response,  $recaptchaToken, $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Buscar por email o username
      $user = !empty($email) ?
        $this->user->getUserByEmail($email) : $this->user->getUserByUserName($userName);

      if (empty($user)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified email or username was found"
          ]
        ]);
      }

      $userID = $user['UserID'];

      # Validar OTP - si falla, retorna el error
      $validation = $this->_validateOtpCode($response, $userID, $otpCode);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Si llegamos aquí, el OTP es válido
      $this->auth->clearUserOtp($userID);
      $this->auth->resetPassword($userID, $password);

      return $response->withStatus(200)->withJson("Password reset successful");

    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Valida un código OTP genérico
   * @param  Response $response: objeto de response HTTP
   * @param  int $userId: ID del usuario
   * @param  string $otpCode: código OTP a validar
   * @return object: {valid: bool, response: Response|null}
   **/
  private function _validateOtpCode($response, $userID, $otpCode) {
    try {
      $otpExptime = $GLOBALS['config']['otp_exptime'];
      $user = $this->auth->getUserOtp($userID);

      if (empty($user)) {
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(404)->withJson([
            "error" => [
              "code" => "USER_NOT_FOUND",
              "desc" => "No user was found with the specified Id."
            ]
          ])
        ];
      }

      if (is_null($user['OTPCode'])) {
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(404)->withJson([
            "error" => [
              "code" => "OTP_CODE_NOT_FOUND",
              "desc" => "OTP code is not set. Please request a new OTP."
            ]
          ])
        ];
      }

      # Verificar si el OTP ha expirado
      $otpDate = new DateTime($user['OTPDate']);
      $now = new DateTime();
      $interval_in_seconds = $now->getTimestamp() - $otpDate->getTimestamp();

      if ($interval_in_seconds > $otpExptime) {
        $this->auth->clearUserOtp($userID);
        return (object)[
          "valid" => false,
          "response" => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "EXPIRED_OTP",
              "desc" => "OTP has expired. Please request a new OTP."
            ]
          ])
        ];
      }

      # Comparar el código OTP recibido con el código generado
      if ($otpCode !== $user['OTPCode']) {
        $this->auth->incrementUserOtpAttempts($userID);

        # Verificar si ya ha alcanzado el límite de intentos fallidos
        if ($this->auth->getUserOtpAttempts($userID) > 3) {
          $this->auth->clearUserOtp($userID);
          return (object)[
            "valid" => false,
            "response" => $response->withStatus(401)->withJson([
              "error" => [
                "code" => "OTP_MAX_ATTEMPTS",
                "desc" => "Maximum OTP attempts reached. Please request a new OTP."
              ]
            ])
          ];
        }

        return (object)[
          "valid" => false,
          "response" => $response->withStatus(401)->withJson([
            "error" => [
              "code" => "OTP_CODE_INVALID",
              "desc" => "Invalid OTP code"
            ]
          ])
        ];
      }

      return (object)["valid" => true, "response" => null];
    } catch (Throwable $e) {
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(500)->withJson([
          "error" => [
            "code" => "INTERNAL_SERVER_ERROR",
            "desc" => $e->getMessage()
          ]
        ])
      ];
    }
  }

  /**
   * Refresca el token JWT de un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con nuevo token JWT y datos del usuario o error
   * @statusCode 200: token refrescado exitosamente
   * @statusCode 400: JWT inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function refreshToken(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');

    try {
      $userID = $jwt->data->UserID;
      $user = $this->user->getUserById($userID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified id was found"
          ]
        ]);
      }
      $userPlan = $this->subscription->getSubscriptionByUser($userID);
      $userSettings = $this->user->getSettings($userID) ?:
        throw new DatabaseException("Failed to retrieve the user settings");

      $jwt = $this -> _JWTgen($user);
      return $response->withStatus(200)->withJson([
        'Token' => $jwt,
        'UserData' => $user,
        'UserPlan' => $userPlan,
        'UserSettings' => $userSettings
      ]);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Valida un token reCaptcha
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con resultado de validación
   * @statusCode 200: reCaptcha válido
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: reCaptcha inválido o score bajo
   * @statusCode 500: error del servidor
   **/
  public function validateReCaptcha(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if (empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_RECAPTCHA_TOKEN",
          "desc" => "Missing reCaptcha token"
        ]
      ]);
    }

    try{
      $validation = validateReCaptcha($response, $recaptchaToken, $clientIp);
      return $validation->response;
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Solicita el reseteo de contraseña enviando código OTP
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: OTP enviado exitosamente
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: reCaptcha inválido
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function requestPasswordReset(Request $request, Response $response, $args) {
    $params = $request->getParsedBody();

    $pValidation = ParameterValidator::validate($response, 'auth','request_password_reset', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    if((empty($params['Email']) && empty($params['UserName']))){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "You must provide either username or email"
        ]
      ]);
    }

    try {
      # Valido recaptcha
      $validation = validateReCaptcha($response, $params['RecaptchaToken'], $clientIp);
      if (!$validation->valid) {
        return $validation->response;
      }

      # Busco por mail o username
      $user = !empty($params['Email']) ?
        $this->user->getUserByEmail($params['Email']) : $this->user->getUserByUserName($params['UserName']);
      if(empty($user)){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified email or username was found"
          ]
        ]);
      }

      $otpCode = $this->auth->setOtpCodeDB($user['UserID']);

      $origin = !empty($params['SubDomain']) ? "https://{$params['SubDomain']}.onesoul.app" : "https://onesoul.app";
      $resetData = base64_encode(json_encode(["otp_code" => $otpCode, "email" => $user['Email']]));

      # Mail de recuperacion
      $payload = [
        "LINK"         => "$origin/auth/recovery/reset/$resetData",
        "YEAR"         => date('Y'),
        "USERNAME"     => $user['UserName']
      ];
      $this->notification->createNotification(
        $user['UserID'],
        "PASSWORD_RECOVERY",
        $payload,
        "PASSWORD_RECOVERY." . time()
      );

      return $response->withStatus(200)->withJson("OTP code sent");
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Solicita generación de QR para configurar MFA
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con secret y QR o error
   * @statusCode 200: QR generado exitosamente
   * @statusCode 400: JWT inválido
   * @statusCode 401: MFA ya configurado
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function mfaReq(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');

    try{
      $user = $this->user->getUserById($jwt->data->UserID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified id was found"
          ]
        ]);
      }
      if($user['TwoFactorAuth']){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "MFA_ALREADY_SET",
            "desc" => "The user already have mfa configured"
          ]
        ]);
      }

      # Genero el QR y el secret
      $g2fa = new \PragmaRX\Google2FA\Google2FA();
      $secret = $g2fa -> generateSecretKey();
      $qr = $g2fa -> getQRCodeUrl("OneSoul.app", $user['UserName'],	$secret);
      return $response->withStatus(200)->withJson([
        "Secret" => $secret,
        "QR" => $qr,
      ]);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Configura MFA para un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: MFA configurado exitosamente
   * @statusCode 400: parámetros inválidos o JWT inválido
   * @statusCode 401: código MFA inválido o MFA ya configurado
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function mfaSet(Request $request, Response $response, $args){
    $params = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','mfa_set', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try{
      $user = $this->user->getUserById($jwt->data->UserID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified id was found"
          ]
        ]);
      }

      if($user['TwoFactorAuth']){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "MFA_ALREADY_SET",
            "desc" => "The user already have mfa configured"
          ]
        ]);
      }

      # Chequeo el codigo contra el secret
      $g2fa = new \PragmaRX\Google2FA\Google2FA();
      if(!$g2fa -> verifyKey($params['Secret'], $params['Code'])){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "INVALID_MFA_CODE",
            "desc" => "Cannot verify provided mfa code"
          ]
        ]);
      }

      $this->auth->mfaSet($jwt->data->UserID, $params['Secret']);
      return $response->withStatus(200)->withJson("MFA set");
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Desactiva MFA para un usuario autenticado
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: MFA desactivado exitosamente
   * @statusCode 400: JWT inválido
   * @statusCode 401: MFA no está configurado
   * @statusCode 404: usuario no encontrado
   * @statusCode 500: error del servidor
   **/
  public function mfaDel(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');

    try{
      $user = $this->user->getUserById($jwt->data->UserID);
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified id was found"
          ]
        ]);
      }

      if(!$user['TwoFactorAuth']){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "MFA_NOT_SET",
            "desc" => "The user does not have mfa configured"
          ]
        ]);
      }

      $this->auth->mfaDel($jwt->data->UserID);
      return $response->withStatus(200)->withJson("MFA unset");
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Verifica un código MFA durante login
   * @param  Request $request: objeto de request HTTP (requiere JWT)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta con 'code'
   * @return Response: JSON con confirmación o error
   * @statusCode 200: MFA verificado exitosamente
   * @statusCode 400: parámetros inválidos o JWT inválido
   * @statusCode 401: código MFA inválido
   * @statusCode 403: máximo de intentos alcanzado
   * @statusCode 500: error del servidor
   **/
  public function mfaCheck(Request $request, Response $response, $args){
    $params['Code'] = $args['Code'];
    $jwt = $request->getAttribute('jwt');

    $pValidation = ParameterValidator::validate($response, 'auth','mfa_check', $params);
    if(!$pValidation->valid){
      return $pValidation->response;
    }
    $params = $pValidation->values;

    try{
      $mfa = $this->auth->getMfa($jwt->data->UserID);
      if(!$mfa){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "MFA_NOT_SET",
            "desc" => "The user does not have mfa configured"
          ]
        ]);
      }

      # Verifico el OTP
      $validation = $this -> _mfaCheck($response, $jwt->data->UserID, $params['Code'], $mfa['MfaSecret'], $mfa['FailedLoginAttempts'], $mfa['LockedUntil']);
      if(!$validation->valid){
        return $validation->response;
      }
      return $response->withStatus(200)->withJson("MFA Verified");
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Verifica un código MFA genérico
   * @param  Response $response: objeto de response HTTP
   * @param  array $mfa: datos de MFA del usuario
   * @return object: {valid: bool, response: Response|null}
   **/
  private function _mfaCheck($response, $userID, $code, $secret, $failedLoginAttempt, $lockedUntil){
    try{
      $g2fa = new \PragmaRX\Google2FA\Google2FA();

      if (!$g2fa->verifyKey($secret, $code)) {
        # Incrementar intentos fallidos y actualizar bloqueo si es necesario
        $failedAttempts = $failedLoginAttempt + 1;
        $lockTime = $this->auth->calculateLockTime($failedAttempts);

        $this->auth->updateFailedLogin($userID, $failedAttempts, $lockTime);

        if ($lockTime !== null) {
          return (object)[
            "valid" => false,
            "response" => $response->withStatus(403)->withJson([
              "error" => [
                "code" => "MFA_MAX_ATTEMPTS",
                "desc" => "Maximum MFA attempts reached. Account is now locked."
              ]
            ])
          ];
        }

        return (object)[
          "valid" => false,
          "response" => $response->withStatus(401)->withJson([
            "error" => [
              "code" => "INVALID_MFA_CODE",
              "desc" => "Cannot verify provided MFA code"
            ]
          ])
        ];
      }

      # MFA verificado, resetear intentos fallidos y bloqueo
      $this->auth->updateFailedLogin($userID, 0, null);

      return (object)["valid" => true, "response" => null];
    } catch (Throwable $e) {
      return (object)[
        "valid" => false,
        "response" => $response->withStatus(500)->withJson([
          "error" => [
            "code" => "INTERNAL_SERVER_ERROR",
            "desc" => $e->getMessage()
          ]
        ])
      ];
    }
  }

  /**
   * Carga documentos legales (términos y políticas) en el sistema
   * @param  Request $request: objeto de request HTTP (requiere JWT de admin)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con confirmación o error
   * @statusCode 200: documento cargado exitosamente
   * @statusCode 400: parámetros inválidos
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos de administrador
   * @statusCode 500: error del servidor
   **/
  public function uploadLegalDocuments(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el body es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    # Verificar si el usuario autenticado es un administrador
    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You don't have permission to create legal documents."
        ]
      ]);
    }

    # Validación de campos requeridos
    if (!isset($data['Type']) || !isset($data['Version']) || !isset($data['ReleaseDate'])) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "VALIDATION_ERROR",
          "desc" => "Both 'Type' and 'Version' fields are required."
        ]
      ]);
    }

    $type = $data['Type'];
    $version = $data['Version'];
    $releaseDate = $data['ReleaseDate'];
    $content = is_array($data['Content']) ? json_encode($data['Content'], JSON_UNESCAPED_UNICODE) : $data['Content'];

    try {
      $consent = $this->auth->uploadLegalDocuments($type, $version, $releaseDate, $content);
      return $response->withStatus(200)->withJson($consent);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Obtiene documentos legales disponibles en el sistema
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con documentos legales o error
   * @statusCode 200: documentos obtenidos exitosamente
   * @statusCode 500: error del servidor
   **/
  public function legalDocuments(Request $request, Response $response, $args) {
    try {
      $documents = $this->auth->legalDocuments();
      return $response->withStatus(200)->withJson($documents);
    } catch (Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /**
   * Genera un token JWT para un usuario autenticado
   * @param  array $user: datos del usuario
   * @return string: token JWT
   **/
  private function _JWTgen($user){
    $payload = [
      'issued' => time(),
      'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
      'data' => [
        'UserID' => $user['UserID'],
        'UserName' => $user['UserName'],
        'UserType' => $user['UserType'],
        'UserLevel' => $user['UserLevel'],
        'ValidatedEmail' => (bool)$user['ValidatedEmail'],
        'ValidatedPhone' => (bool)$user['ValidatedPhone'],
        'TwoFactorAuth' => (bool)$user['TwoFactorAuth'],
        'IsAdmin' => (bool)$user['IsAdmin']
      ]
    ];
    $secret = $GLOBALS['config']['jwt']['secret'];
    return JWT::encode($payload, $secret, 'HS256');
  }

  /**
   * Valida firma y claims del ID token de Apple
   * @param  string $idToken: token firmado por Apple
   * @param  string $expectedAud: audience esperado (ej: 'com.onesoul.app.web')
   * @param  string $rawNonce: nonce original para validación
   * @return object|false: claims normalizados o false si falla
   **/
  private function _validateAppleToken($idToken, $expectedAud, $rawNonce) {
    try {
      # Tolerancia por drift de reloj
      JWT::$leeway = 120;

      # Descargar JWKS
      $jwksJson = @file_get_contents('https://appleid.apple.com/auth/keys');
      if ($jwksJson === false) {
        return false;
      }

      # Extraigo las keys
      $jwks = json_decode($jwksJson, true);
      if (!isset($jwks['keys'])) {
        return false;
      }

      # Decodifico token
      $keys = JWK::parseKeySet($jwks);
      $decoded = JWT::decode($idToken, $keys);
      # Validaciones de claims
      $iss = $decoded->iss ?? null;
      if ($iss !== 'https://appleid.apple.com') {
        return false;
      }

      # Valido AUD
      if ($decoded->aud !== $expectedAud) {
        return false;
      }

      # Valido nonce
      if ($decoded->nonce !== hash('sha256',$rawNonce)) {
        return false;
      }

      # Normalizar salida
      $emailVerifiedRaw = $decoded->email_verified ?? null;
      $emailVerified = ($emailVerifiedRaw === true || $emailVerifiedRaw === 'true');

      return (object)[
        'sub'            => $decoded->sub ?? null,
        'email'          => $decoded->email ?? null,
        'email_verified' => $emailVerified,
        'nonce'          => $decoded->nonce ?? null,
        'auth_time'      => $decoded->auth_time ?? null,
        'iat'            => $decoded->iat ?? null,
        'exp'            => $decoded->exp ?? null
      ];
    } catch (Throwable $e) {
      return false;
    }
  }

  /**
   * Intercambia un código de autorización por un ID token de Apple
   * @param  string $code: código de autorización de Apple
   * @return string|null: ID token o null si falla
   **/
  private function _appleExchangeCodeForIdToken($code) {
    $client_id = $GLOBALS['config']['apple']['client_id'];
    $team_id = $GLOBALS['config']['apple']['team_id'];
    $key_id = $GLOBALS['config']['apple']['key_id'];
    $private_key = base64_decode($GLOBALS['config']['apple']['private_key_b64']);

    # Generar client_secret como JWT
    $header = ['alg' => 'ES256', 'kid' => $key_id];
    $claims = [
      'iss' => $team_id,
      'iat' => time(),
      'exp' => time() + 3600,
      'aud' => 'https://appleid.apple.com',
      'sub' => $client_id
    ];

    $client_secret = \Firebase\JWT\JWT::encode($claims, $private_key, 'ES256', $key_id, $header);

    $params = [
      'client_id' => $client_id,
      'client_secret' => $client_secret,
      'code' => $code,
      'grant_type' => 'authorization_code'
    ];

    $ch = curl_init('https://appleid.apple.com/auth/token');
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => http_build_query($params),
      CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 15,
    ]);
    $result = curl_exec($ch);

    $json = json_decode($result, true);
    return $json['id_token'] ?? null;
  }

  /**
   * Valida un JWT firmado por Facebook
   * @param  string $jwtToken: token JWT firmado por Facebook
   * @return object|false: datos decodificados del token o false si falla
   **/
  private function _validateFacebookJWT($jwtToken) {
    try {
      # Obtener JWKS de Facebook
      $jwksUrl = "https://www.facebook.com/.well-known/oauth/openid/jwks/";
      $jwks = json_decode(file_get_contents($jwksUrl), true);

      # Decodificar encabezado para obtener el kid
      $header = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $jwtToken)[0])), true);
      $kid = $header['kid'] ?? null;

      if (!$kid) return false;

      # Buscar clave pública que coincida
      $keyData = null;
      foreach ($jwks['keys'] as $key) {
        if ($key['kid'] === $kid) {
          $keyData = $key;
          break;
        }
      }
      if (!$keyData) return false;

      # Convertir a clave pública
      $publicKey = $this->_convertJWKToPEM($keyData);

      # Usar Firebase JWT para verificar
      $decoded = \Firebase\JWT\JWT::decode(
        $jwtToken,
        new \Firebase\JWT\Key($publicKey, $keyData['alg'])
      );

      # Validaciones adicionales
      $expectedIssuer = 'https://www.facebook.com';
      $expectedAudience = $GLOBALS['config']['facebook']['APP_ID'];

      if (($decoded->iss ?? '') !== $expectedIssuer) {
        throw new Exception("Invalid issuer");
      }

      if (($decoded->aud ?? '') !== $expectedAudience) {
        throw new Exception("Invalid audience");
      }

      if (isset($decoded->exp) && $decoded->exp < time()) {
        throw new Exception("Token expired");
      }

      return $decoded;
    } catch (Throwable $e) {
      return false;
    }
  }

  /**
   * Convierte una clave JWK a formato PEM
   * @param  array $jwk: estructura JWK con componentes n y e
   * @return string: clave pública en formato PEM
   **/
  private function _convertJWKToPEM($jwk) {
    $modulus = $this->_base64UrlDecode($jwk['n']);
    $exponent = $this->_base64UrlDecode($jwk['e']);
    $rsa = new \phpseclib3\Crypt\RSA();
    $rsa = $rsa->loadKey(['n' => $modulus, 'e' => $exponent]);
    return $rsa->getPublicKey();
  }

  /**
   * Decodifica una cadena en base64url
   * @param  string $input: cadena codificada en base64url
   * @return string: cadena decodificada
   **/
  private function _base64UrlDecode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) {
      $padlen = 4 - $remainder;
      $input .= str_repeat('=', $padlen);
    }
    return base64_decode(strtr($input, '-_', '+/'));
  }


  /**
   * Valida la complejidad de una contraseña
   * @param  string $newPassword: contraseña a validar
   * @return bool: true si cumple los requisitos, false en caso contrario
   **/
  private function _passwordComplexity($newPassword) {
    $password = trim($newPassword);

    return strlen($password) >= 8 &&
      preg_match('/[A-Z]/', $password) &&   # Debe tener al menos una mayúscula
      preg_match('/[a-z]/', $password) &&   # Debe tener al menos una minúscula
      (preg_match('/[0-9]/', $password) || preg_match('/\W/', $password));  # Debe tener un número O un símbolo
  }

  /**
   * Maneja la recompensa de referido cuando un nuevo usuario se registra
   * @param  int $referrerUserId: ID del usuario referidor
   * @param  int $userId: ID del usuario nuevo
   **/
  private function _handleReferralReward($referrerUserID, $userID) {
    # Genero los rewards si corresponde
    $rewardTriggered = $this->auth->handleReferralReward($referrerUserID, $userID);
    if ($rewardTriggered) {
      $subscription = $this->subscription->getSubscriptionByUser($referrerUserID);
      $platformSubscriptionID = $subscription['PlatformSubscriptionID'] ?? null;
      $currentPlanID = $subscription['PlanDetails']['StripeID'] ?? null;

      $planStripe = $this->subscription->getSubscriptionPlanByStripeID($currentPlanID);
      $newPlanID = $planStripe['PlanID'];
      if ($platformSubscriptionID && $newPlanID) {
        \Stripe\Stripe::setApiKey($GLOBALS['config']['stripe']['STRIPE_SECRET_KEY']);
        \Stripe\Subscription::update($platformSubscriptionID, [
          'discounts' => [
            ['coupon' => '1MONTHFREE']
          ]
        ]);
      }
    }
  }

  /**
   * Genera y graba en Redis un código OTP para validar una cuenta
   * @param  string $email: correo del usuario
   **/
  private function _setOtpCodeRedis($email) {
    $otpCode = rand(100000, 999999); # Codigo que se enviara por mail
    $hashedOtp = password_hash((string)$otpCode, PASSWORD_BCRYPT); # Hasheo el OTP code

    # Json que guardo en redis
    $otpData = [
      'otp_hash' => $hashedOtp,
      'attempts' => 0, # Contador de intentos
      'validated' => false, # Indica si ya se valido el email
      'created_at' => time(),
      'expires_at' => time() + $GLOBALS['config']['otp_exptime'] # Expiracion
    ];

    $this->redis->setex("otp:{$email}", 86400, json_encode($otpData));

    return $otpCode;
  }
}