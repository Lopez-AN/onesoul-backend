<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Auth;
use App\Models\User;
use App\Models\Subscription;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Stripe\Stripe;
use \DateTime;
use Predis\Client as RedisClient;

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class AuthController{

  protected $user;
  protected $auth;
  protected $subscription;
  protected $redis;

  public function __construct(Auth $auth, User $user, Subscription $subscription, RedisClient $redisClient){
    $this->auth = $auth;
    $this->user = $user;
    $this->subscription = $subscription;
    $this->redis = $redisClient;
  }

  /*
  * Logueo usuario
  */
  public function login(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['Email'] ?? null;
    $userName = $data['UserName'] ?? null;
    $password = $data['Password'] ?? null;
    $mfaId = $data['MfaID'] ?? null;
    $mfaCode = $data['MfaCode'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    // Validar credenciales básicas
    if(($email === null && $userName === null) || $password === null || $recaptchaToken === null){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    // Valido recaptcha
    $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    try {
      // valido credenciales
      $userAuth = $this->auth->login($userName, $email);
      if(empty($userAuth)){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "Invalid credentials"
          ]
        ]);
      }
      if (!password_verify($password, $userAuth['PasswordHash'])) {
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
      // Traigo el resto de los datos del usuario
      $user = $this->user->getUserById($userAuth['UserID']);
      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaId, $mfaCode, $clientIp);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function loginGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // Validar el token de Google
      $oAuthResponse = $this -> _validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
      if($oAuthResponse === false || !$oAuthResponse -> sub){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SSO_INVALID_TOKEN",
            "desc" => "Invalid Google token"
          ]
        ]);
      }
      $oAuthID = $oAuthResponse -> sub;

      // Traigo el resto de los datos del usuario
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
            "Picture"   => $oAuthResponse->picture ?? null,
          ]
        ]);
      }

      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function loginFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $oAuthID = $data['UserID'] ?? null;
    $jwtToken = $data['JwtToken'] ?? null; // null = web, not null = native
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // NATIVE
      if($jwtToken){
        // 1. Validar JWT contra las claves públicas de Facebook
        $oAuthResponse = $this->_validateFacebookJWT($jwtToken);
        if ($oAuthResponse === false) {
          return (object)[
            "http_code" => 401,
            "error" => [
              "code" => "SSO_INVALID_TOKEN",
              "desc" => "Invalid Facebook JWT token"
            ]
          ];
        }

        # Me traigo el ID de facebook
        $oAuthID = $oAuthResponse->sub ?? null;
      }else{ // WEB
        $oAuthResponse = $this -> _validateToken("https://graph.facebook.com/$oAuthID?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
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

      // Traigo el resto de los datos del usuario
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
            "Picture"   => $oAuthResponse->picture->data->url ?? null,
          ]
        ]);
      }

      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function loginApple(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // Si no vino id_token, hacer exchange con code
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
            "Email" => $oAuthResponse -> email ?? null
          ]
        ]);
      }
      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

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

    // Manejar MFA si está habilitado
    $newMfaId = null;
    if ($user['TwoFactorAuth'] == 1) {
      if (!empty($mfa_id)) {
        if (!$this->auth->validateMfaId($user['UserID'], $mfa_id)) {
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "INVALID_MFA_ID",
              "desc" => "MFA ID is not valid"
            ]
          ]);
        }
      } elseif (!empty($mfa_code)) {
        $result = $this->auth->mfaCheck($user['UserID'], $mfa_code);
        if ($result->http_code != 200) {
          return $response->withStatus($result->http_code)->withJson($result);
        }
      } else {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "MFA_REQUIRED",
            "desc" => "MFA validation is required"
          ]
        ]);
      }

      if (empty($mfa_id)) {
        $newMfaId = uniqid();
      }
    }

    // Login exitoso: resetear intentos fallidos y desbloquear cuenta
    $this->auth->updateFailedLogin($user['UserID'], 0, null);

    // Generar JWT
    $jwt = $this->JWTgen($user);

    // Guardar datos del navegador si es necesario
    if ($newMfaId !== null) {
      $this->auth->storeBrowserData($user['UserID'], $request, $newMfaId, $clientIp);
    }

    $userPlan = $this->subscription->getSubscriptionByUser($user['UserID']);

    return $response->withStatus(200)->withJson([
      'Token' => $jwt,
      'MfaID' => $newMfaId,
      'UserData' => $user,
      'UserPlan' => $userPlan
    ]);
  }

  /*
  * Registro usuario
  */
  public function register(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      $otpJson = $this->redis->get("otp:{$email}");
      // Decodificar JSON
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

      // Validar que AcceptedTerms y AcceptedPrivacyPolicy esten aceptados
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
        $referrerUserID = $result->data['UserID'];
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

      $passwordHash = password_hash($password,PASSWORD_BCRYPT); #El password se guarda hasheado (obvio!)

      # Registro al usuario
      $userID = $this->auth->register($email, $userName, $passwordHash, $tycVersion,
        $privacyVersion, $receiveNewsletters, $clientIp, $userAgent);

      $user = $this->user->getUserById($userID);
      $jwt = $this -> JWTgen($user);
      $this->redis->del("otp:{$email}");

      if($referrerUserID){
        $this-> _handleReferralReward($referrerUserID, $userID);
      }

      return $response->withStatus(200)->withJson([
        'Token' => $jwt,
        'UserData' => $user,
        'UserPlan' => null
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

  public function registerGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $altEmail = $data['AltEmail'] ?? null; // Opcional cuando el SSO no comparte el correo
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // Validar el token de Google
      $oAuthResponse = $this -> _validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
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
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function registerFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $altEmail = $data['AltEmail'] ?? null; // Opcional cuando el SSO no comparte el correo
    $token = $data['Token'] ?? null;
    $oAuthID = $data['UserID'] ?? null;
    $jwtToken = $data['JwtToken'] ?? null; // null = web, not null = native
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // NATIVE
      if($jwtToken){
        // 1. Validar JWT contra las claves públicas de Facebook
        $decoded = $this->_validateFacebookJWT($jwtToken);
        if ($decoded === false) {
          return (object)[
            "http_code" => 401,
            "error" => [
              "code" => "SSO_INVALID_TOKEN",
              "desc" => "Invalid Facebook JWT token"
            ]
          ];
        }

        # Me traigo el ID de facebook
        $oAuthID = $decoded->sub ?? null;
      }else{ // WEB
        $oAuthResponse = $this -> _validateToken("https://graph.facebook.com/$oAuthID?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
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
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function registerApple(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $altEmail = $data['AltEmail'] ?? null; // Opcional cuando el SSO no comparte el correo
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // Si no vino id_token, hacer exchange con code
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
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  private function _registerGenericSSO(Response $response, $oAuthID, $provider,
    $email, $altEmail, $userName, $acceptedTerms, $acceptedPrivacy, $tycVersion,
    $privacyVersion, $receiveNewsletters, $referralCode, $clientIp, $userAgent,
    $firstName = null, $lastName = null, $picture = null
  ) {
    try {
      // Verificar si el usuario ya existe
      $user = $this->user->getUserByOAuthID($oAuthID, $provider);
      if($user){
        return $response->withStatus(409)->withJson([
          "error" => [
            "code" => "USER_ALREADY_EXISTS",
            "desc" => "User already registered with this " . ucfirst($provider) . " account"
          ]
        ]);
      }

      // Si el SSO no trajo email usar el recibido desde el front
      if(!$email){
        if(!$altEmail){
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "EMAIL_NOT_PROVIDED",
              "desc" => "You must provide an email because the SSO service does not provide one"
            ]
          ]);
        }
        // Verificar que el email alternativo esté validado
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

      // Validar consentimientos
      if ($acceptedTerms !== 1 || $acceptedPrivacy !== 1) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "CONSENT_REQUIRED",
            "desc" => "AcceptedTerms and AcceptedPrivacyPolicy must both be accepted."
          ]
        ]);
      }

      // Validar referral code si existe
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
        $referrerUserID = $result->data['UserID'];
      }

      // Verificar que email y username no existan
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

      // Validar versiones de documentos legales
      $result = $this->auth->checkLegalDocuments($tycVersion, $privacyVersion);
      if ((int)$result['total'] < 2) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "INVALID_LEGAL_DOCUMENT_VERSION",
            "desc" => "One or both legal document versions are invalid."
          ]
        ]);
      }

      // Registrar usuario SSO
      $userID = $this->auth->registerSSO($oAuthID, $provider, $email,
        $firstName, $lastName, $picture, $userName, $tycVersion, $privacyVersion,
        $receiveNewsletters, $clientIp, $userAgent
      );

      $user = $this->user->getUserById($userID);
      $jwt = $this->JWTgen($user);

      // Limpiar OTP de Redis si existe
      if($altEmail && $this->redis->get("otp:{$altEmail}")){
        $this->redis->del("otp:{$altEmail}");
      }

      // Manejar recompensa de referral
      if($referrerUserID){
        $this->_handleReferralReward($referrerUserID, $userID);
      }

      return $response->withStatus(200)->withJson([
        'Token' => $jwt,
        'UserData' => $user,
        'UserPlan' => null
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

  public function sendOtpMail(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $email = filter_var($data['Email'] ?? null, FILTER_VALIDATE_EMAIL);
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    $jwt = $request->getAttribute('jwt');

    try {
      # MODO SIN TOKEN (usa redis, para usuarios no existentes)
      if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
        if(!$email){ # Si no hay token tiene que haber email
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "Parameters are missing or invalid"
            ]
          ]);
        }
        $result = $this->auth->sendOtpMailNoUser($email);
      }else{ # MODO CON TOKEN (usa la base, para usuarios existentes)
        $user = $this->user->getUserById($jwt['data'] -> UserID);
        if(empty($user)){
          return $response->withStatus(404)->withJson([
            "error" => [
              "code" => "USER_NOT_FOUND",
              "desc" => "No user associated with the specified id was found"
            ]
          ]);
        }
        $result = $this->auth->sendOtpMailExistingUser($user['UserID'], $user['Email'], $user['UserName']);
      }

      if(!$result){
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "OTP_NOT_SENT",
            "desc" => "Cannot send the OTP email, try again later"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson("OTP code sent successfully");
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function validateOTP(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $otpCode = $data['OTPCode'] ?? '';
    $email = filter_var($data['Email'] ?? '', FILTER_VALIDATE_EMAIL);
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($otpCode) || empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    $jwt = $request->getAttribute('jwt');

    try {
      # MODO SIN TOKEN (usa redis, para usuarios no existentes)
      if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
        if(!$email){ # Si no hay token tiene que haber email
          return $response->withStatus(401)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "Parameters are missing or invalid"
            ]
          ]);
        }
        return $this->_validateOTPRedis($response, $email, $otpCode);
      }
      # MODO CON TOKEN (usa la base, para usuarios existentes)
      return $this->_validateOTP($response, $jwt['data']->UserID, $otpCode);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  /* Validacion OTP email contra el redis para usuarios que aun estan en el proceso de registro */
  private function _validateOTPRedis($response, $email, $otpCode){
    try{
      $MAX_ATTEMPTS = 5;

      $otpJson = $this->redis->get("otp:{$email}");
      // Decodificar JSON
      $otpData = @json_decode($otpJson);
      if (!$otpData) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "OTP_CODE_NOT_FOUND",
            "desc" => "OTP code is not set. Please request a new OTP."
          ]
        ]);
      }
      $attempts = $otpData -> attempts;
      $hashedOtp = $otpData -> otp_hash;
      $expiresAt = $otpData -> expires_at;

      // Verificar si expiro
      if (time() > $expiresAt) {
        $this->redis->del("otp:{$email}");
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "EXPIRED_OTP",
            "desc" => "OTP has expired. Please request a new OTP."
          ]
        ]);
      }

      // Verificar intentos fallidos
      if ($attempts >= $MAX_ATTEMPTS) {
        $this->redis->del("otp:{$email}");
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "OTP_MAX_ATTEMPTS",
            "desc" => "Maximum OTP attempts reached. Please request a new OTP."
          ]
        ]);
      }

      // Verificar OTP
      if (!password_verify($otpCode, $hashedOtp)) { // No es valido
        // Incrementar intentos en el JSON
        $otpData -> attempts++;
        $this->redis->setex("otp:{$email}", 86400, json_encode($otpData));
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "OTP_CODE_INVALID",
            "desc" => "Invalid OTP code"
          ]
        ]);
      }

      # OTP válido
      $otpData -> validated = true;
      $this->redis->setex("otp:{$email}", 86400, json_encode($otpData));
      return $response->withStatus(200)->withJson("OTP code validated successfully");
    } catch (\Throwable $e) {
      return (object)[
        "http_code" => 500,
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ];
    }
  }

  /* Validacion OTP email contra la base para usuarios registrados */
  private function _validateOTP($response, $userID, $otpCode) {
    $validation = $this->_validateOtpCode($response, $userID, $otpCode);

    if (!$validation['valid']) {
      return $validation['response'];
    }

    $this->auth->clearUserOtp($userID);
    $this->auth->validateUserEmail($userID);

    return $response->withStatus(200)->withJson("OTP code validated successfully");
  }


  public function resetPassword(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
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
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // Buscar por email o username
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

      // Validar OTP - si falla, retorna el error
      $validation = $this->_validateOtpCode($response, $userID, $otpCode);
      if (!$validation['valid']) {
        return $validation['response'];
      }

      // Si llegamos aquí, el OTP es válido
      $this->auth->clearUserOtp($userID);
      $this->auth->resetPassword($userID, $password);

      return $response->withStatus(200)->withJson("Password reset successful");

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Valida un código OTP genérico
   * Retorna un array con ['valid' => bool, 'response' => Response|null]
   * Si es válido, retorna ['valid' => true, 'response' => null]
   * Si es inválido, retorna ['valid' => false, 'response' => Response con error]
   */
  private function _validateOtpCode($response, $userID, $otpCode) {
    try {
      $otpExptime = $GLOBALS['config']['otp_exptime'];
      $user = $this->auth->getUserOtp($userID);

      if (empty($user)) {
        return [
          'valid' => false,
          'response' => $response->withStatus(404)->withJson([
            "error" => [
              "code" => "USER_NOT_FOUND",
              "desc" => "No user was found with the specified Id."
            ]
          ])
        ];
      }

      if (is_null($user['OTPCode'])) {
        return [
          'valid' => false,
          'response' => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "OTP_CODE_NOT_FOUND",
              "desc" => "OTP code is not set. Please request a new OTP."
            ]
          ])
        ];
      }

      // Verificar si el OTP ha expirado
      $otpDate = new DateTime($user['OTPDate']);
      $now = new DateTime();
      $interval_in_seconds = $now->getTimestamp() - $otpDate->getTimestamp();

      if ($interval_in_seconds > $otpExptime) {
        $this->auth->clearUserOtp($userID);
        return [
          'valid' => false,
          'response' => $response->withStatus(400)->withJson([
            "error" => [
              "code" => "EXPIRED_OTP",
              "desc" => "OTP has expired. Please request a new OTP."
            ]
          ])
        ];
      }

      // Comparar el código OTP recibido con el código generado
      if ($otpCode != $user['OTPCode']) {
        $this->auth->incrementUserOtpAttempts($userID);

        // Verificar si ya ha alcanzado el límite de intentos fallidos
        if ($this->auth->getUserOtpAttempts($userID) > 3) {
          $this->auth->clearUserOtp($userID);
          return [
            'valid' => false,
            'response' => $response->withStatus(401)->withJson([
              "error" => [
                "code" => "OTP_MAX_ATTEMPTS",
                "desc" => "Maximum OTP attempts reached. Please request a new OTP."
              ]
            ])
          ];
        }

        return [
          'valid' => false,
          'response' => $response->withStatus(401)->withJson([
            "error" => [
              "code" => "OTP_CODE_INVALID",
              "desc" => "Invalid OTP code"
            ]
          ])
        ];
      }

      return ['valid' => true, 'response' => null];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function refreshToken(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      $userID = $jwt['data'] -> UserID;
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

      $token = $this->JWTgen($user);
      return $response->withStatus(200)->withJson([
        'Token' => $token,
        'UserData' => $user,
        'UserPlan' => $userPlan
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

  public function validateReCaptcha(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
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

    $validation = $this->_validateReCaptcha($recaptchaToken, $clientIp);
    return $response->withStatus($validation->http_code)->withJson($validation->data);
  }

  # Solicitar reseteo de contraseña
  public function requestPasswordReset(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['Email'] ?? '';
    $userName = $data['UserName'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if((empty($email) && empty($userName)) || empty($recaptchaToken)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      $result = $this->_validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      #Busco por mail o username
      $user = !empty($email) ?
        $this->user->getUserByEmail($email) : $this->user->getUserByUserName($userName);
      if(empty($user)){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified email or username was found"
          ]
        ]);
      }

      // Envio el mail OTP
      $result = $this->auth->sendOtpMailExistingUser($user['UserID'], $user['Email'], $user['UserName'], true);
      if(!$result){
        return $response->withStatus(500)->withJson([
          "error" => [
            "code" => "OTP_NOT_SENT",
            "desc" => "Cannot send the OTP email, try again later"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson([
        "Message" => "OTP code sent successfully"
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

  # Solicita el QR para asociar un MFA
  public function mfaReq(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data'] -> UserID;
    $user = $this->user->getUserById($userID);
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
    try{
      $g2fa = new \PragmaRX\Google2FA\Google2FA();
      $secret = $g2fa -> generateSecretKey();
      $qr = $g2fa -> getQRCodeUrl("OneSoul.app", $user['UserName'],	$secret);
      return $response->withStatus(200)->withJson([
        "Secret" => $secret,
        "QR" => $qr,
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

  public function mfaSet(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data'] -> UserID;
    $user = $this->user->getUserById($userID);
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

    $data = $request->getParsedBody();
    $secret = $data['Secret'] ?? '';
    $code = $data['Code'] ?? '';

    if(empty($secret) && empty($code)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    # Chequeo el codigo contra el secret
    try{
      $g2fa = new \PragmaRX\Google2FA\Google2FA();
      if(!$g2fa -> verifyKey($secret, $code)){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "INVALID_MFA_CODE",
            "desc" => "Cannot verify provided mfa code"
          ]
        ]);
      }

      $this->auth->mfaSet($userID,$secret);
      return $response->withStatus(200)->withJson([
        "Message" => "MFA is set"
      ]);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "debug" => [
            "code" => $code,
            "secret" => $secret
          ],
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function mfaDel(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data'] -> UserID;
    $user = $this->user->getUserById($userID);
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

    try{
      $this->auth->mfaDel($userID);
      return $response->withStatus(200)->withJson([
        "Message" => "MFA unset"
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

  public function mfaCheck(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $userID = $jwt['data'] -> UserID;
    $code = $args['code'];

    if(empty($code) || !is_numeric($code)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try{
      $result = $this->auth->mfaCheck($userID, $code);
      if($result -> http_code != 200){
        return $response->withStatus($result->http_code)->withJson($result);
      }
      return $response->withStatus(200)->withJson([
        "Message" => "MFA Verified"
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

  # Generador de token JWT
  private function JWTgen($user){
    $payload = [
      'issued' => time(),
      'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
      'data' => [
        'UserID' => $user['UserID'],
        'UserName' => $user['UserName'],
        'UserType' => $user['UserType'],
        'UserLevel' => $user['UserLevel'],
        'ValidatedEmail' => $user['ValidatedEmail'],
        'TwoFactorAuth' => $user['TwoFactorAuth']
      ]
    ];
    $secret = $GLOBALS['config']['jwt']['secret'];
    return JWT::encode($payload, $secret, 'HS256');
  }

  public function uploadLegalDocuments(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');

    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    $data = $request->getParsedBody();
    $userType = $jwt['data']->UserType;

    // Validar permisos
    if (($userType !== 'Admin')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED_ACTION",
          "desc" => "You don't have permission to create legal documents."
        ]
      ]);
    }

    // Validación de campos requeridos
    if (!isset($data['Type']) || !isset($data['Version'])) {
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

    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }


  public function legalDocuments(Request $request, Response $response, $args) {
    try {
      $documents = $this->auth->legalDocuments();
      return $response->withStatus(200)->withJson([
        "Message" => "Legal document uploaded successfully",
        "Document" => [
          "Type" => $type,
          "Version" => $version
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

  private function _validateReCaptcha($recaptchaToken, $clientIp) {
    # Si esta el modo debug no se valida esto
    if(!empty($GLOBALS['config']['debug_mode']) && $GLOBALS['config']['debug_mode']){
      return (object)["http_code" => 200, "data" => []];
    }

    $secret = $GLOBALS['config']['recaptcha']['secret'];
    $minScore = $GLOBALS['config']['recaptcha']['min_score'];
    $url = "https://www.google.com/recaptcha/api/siteverify?secret=$secret&response=$recaptchaToken&remoteip=$clientIp";

    # Hacer la petición a la API de reCAPTCHA
    $response = $this -> _validateToken($url);
    if($response === false || empty($response -> success)){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "INVALID_RECAPTCHA_TOKEN",
          "desc" => "Invalid reCaptcha token"
        ]
      ];
    }

    # Si el score es muy bajo
    if ($response -> score < $minScore) {
      return (object)["http_code" => 401,
        "error" => [
          "code" => "RECAPTCHA_LOW_SCORE",
          "desc" => "reCaptcha score is too low"
        ]
      ];
    }

    # Validación exitosa
    return (object)["http_code" => 200, "data" => []];
  }


  # Valida un token generado por el login SSO o reCaptcha
  private function _validateToken($url){
    $ch = curl_init();

    # Configuración de cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    $response = curl_exec($ch);

    # Verifica si hubo un error en la solicitud
    if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200){
      return false;
    }
    curl_close($ch);
    return json_decode($response);
  }


  /**
  * Valida firma y claims del id_token de Apple y devuelve claims normalizados.
  *
  * @param    $idToken
  * @param    $expectedAud  p.ej. 'com.onesoul.app.web'
  * @param    $rawNonce, enviado por el front y se compara contra el token
  * @return array|false
  */
  private function _validateAppleToken($idToken, $expectedAud, $rawNonce) {
    try {
      // Tolerancia por drift de reloj
      JWT::$leeway = 120;

      // Descargar JWKS
      $jwksJson = @file_get_contents('https://appleid.apple.com/auth/keys');
      if ($jwksJson === false) {
        return false;
      }

      // Extraigo las keys
      $jwks = json_decode($jwksJson, true);
      if (!isset($jwks['keys'])) {
        return false;
      }

      // Decodifico token
      $keys = JWK::parseKeySet($jwks);
      $decoded = JWT::decode($idToken, $keys, ['RS256']);

      // Validaciones de claims
      $iss = $decoded->iss ?? null;
      if ($iss !== 'https://appleid.apple.com') {
        return false;
      }

      // Valido AUD
      if ($decoded->aud !== $expectedAud) {
        return false;
      }

      // Valido nonce
      if ($decoded->nonce !== hash('sha256',$rawNonce)) {
        return false;
      }

      // Normalizar salida
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
    } catch (\Throwable $e) {
      file_put_contents(ROOT."/debug.log", json_encode($e), FILE_APPEND);
      return false;
    }
  }

  /**
  * Intercambia un CODE por un IDToken en la API de apple
  *
  * @param    $idToken
  * @param    $expectedAud  p.ej. 'com.onesoul.app.web'
  * @param    $rawNonce, enviado por el front y se compara contra el token
  * @return array|false
  */
  private function _appleExchangeCodeForIdToken($code) {
    $client_id = $GLOBALS['config']['apple']['client_id'];
    $team_id = $GLOBALS['config']['apple']['team_id'];
    $key_id = $GLOBALS['config']['apple']['key_id'];
    $private_key = base64_decode($GLOBALS['config']['apple']['private_key_b64']);
    //$redirect_uri = $GLOBALS['config']['apple']['redirect_url'];

    // Generar client_secret como JWT
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
    curl_close($ch);

    $json = json_decode($result, true);
    return $json['id_token'] ?? null;
  }

  private function _validateFacebookJWT($jwtToken) {
    try {
      // Obtener JWKS de Facebook
      $jwksUrl = "https://www.facebook.com/.well-known/oauth/openid/jwks/";
      $jwks = json_decode(file_get_contents($jwksUrl), true);

      // Decodificar encabezado para obtener el kid
      $header = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $jwtToken)[0])), true);
      $kid = $header['kid'] ?? null;

      if (!$kid) return false;

      // Buscar clave pública que coincida
      $keyData = null;
      foreach ($jwks['keys'] as $key) {
        if ($key['kid'] === $kid) {
          $keyData = $key;
          break;
        }
      }
      if (!$keyData) return false;

      // Convertir a clave pública
      $publicKey = $this->_convertJWKToPEM($keyData);

      // Usar Firebase JWT para verificar
      $decoded = \Firebase\JWT\JWT::decode(
        $jwtToken,
        new \Firebase\JWT\Key($publicKey, $keyData['alg'])
      );

      // Validaciones adicionales
      $expectedIssuer = 'https://www.facebook.com';
      $expectedAudience = $GLOBALS['config']['facebook']['APP_ID'];

      if (($decoded->iss ?? '') !== $expectedIssuer) {
        throw new \Exception("Invalid issuer");
      }

      if (($decoded->aud ?? '') !== $expectedAudience) {
        throw new \Exception("Invalid audience");
      }

      if (isset($decoded->exp) && $decoded->exp < time()) {
        throw new \Exception("Token expired");
      }

      return $decoded;

    } catch (\Throwable $e) {
      error_log("Facebook JWT validation failed: " . $e->getMessage());
      return false;
    }
  }

  private function _convertJWKToPEM($jwk) {
    $modulus = $this->_base64UrlDecode($jwk['n']);
    $exponent = $this->_base64UrlDecode($jwk['e']);
    $rsa = new \phpseclib3\Crypt\RSA();
    $rsa = $rsa->loadKey(['n' => $modulus, 'e' => $exponent]);
    return $rsa->getPublicKey();
  }

  private function _base64UrlDecode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) {
      $padlen = 4 - $remainder;
      $input .= str_repeat('=', $padlen);
    }
    return base64_decode(strtr($input, '-_', '+/'));
  }

  private function _passwordComplexity($newPassword) {
    $password = trim($newPassword);

    return strlen($password) >= 8 &&
      preg_match('/[A-Z]/', $password) &&   // Debe tener al menos una mayúscula
      preg_match('/[a-z]/', $password) &&   // Debe tener al menos una minúscula
      (preg_match('/[0-9]/', $password) || preg_match('/\W/', $password));  // Debe tener un número O un símbolo
  }

  private function _handleReferralReward($referrerUserID, $userID) {
    # Genero los rewards si corresponde
    $referralResult = $this->auth->handleReferralReward($referrerUserID, $userID);
    if ($referralResult['RewardTriggered']) {
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
}