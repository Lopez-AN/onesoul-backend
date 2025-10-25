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

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class AuthController{

  protected $user;
  protected $auth;
  protected $subscription;

  public function __construct(User $user, Auth $auth, Subscription $subscription){
    $this->user = $user;
    $this->auth = $auth;
    $this->subscription = $subscription;
  }

  /*
  * Logueo usuario
  */
  public function login(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['Email'] ?? '';
    $username = $data['UserName'] ?? '';
    $password = $data['Password'] ?? '';
    $mfa_id = $data['MfaID'] ?? '';
    $mfa_code = $data['MfaCode'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    // Validar credenciales básicas
    if((empty($email) && empty($username)) || empty($password) || empty($recaptchaToken)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    // Valido recaptcha
    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    try {
      // valido credenciales
      $userAuth = $this->auth->login($username, $email);
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
      return $this->_loginGeneric($response, $request, $user, $mfa_id, $mfa_code, $clientIp);
    } catch (\Exception $e) {
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

    if (empty($token) || empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
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
            "FirstName" => !empty($oAuthResponse -> given_name) ? $oAuthResponse -> given_name : null,
            "LastName" => !empty($oAuthResponse -> family_name) ? $oAuthResponse -> family_name : null,
            "Email" => !empty($oAuthResponse -> email) ? $oAuthResponse -> email : null,
            "Picture" => !empty($oAuthResponse -> picture) ? $oAuthResponse -> picture : null
          ]
        ]);
      }

      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (\Exception $e) {
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
    $token = $data['Token'] ?? null;
    $mfaID = $data['MfaID'] ?? null;
    $mfaCode = $data['MfaCode'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? null;
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];



    if (empty($oAuthID) || empty($token) || empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      $oAuthResponse = $this -> _validateToken("https://graph.facebook.com/$oAuthID?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
      if($oAuthResponse === false){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "SSO_INVALID_TOKEN",
            "desc" => "Invalid Facebook token"
          ]
        ]);
      }

      // Traigo el resto de los datos del usuario
      $user = $this->user->getUserByOAuthID($oAuthID, "facebook");
      if(!$user){
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified Facebook account was found"
          ],
          "data" => [
            "FirstName" => !empty($oAuthResponse -> first_name) ? $oAuthResponse -> first_name : null,
            "LastName" => !empty($oAuthResponse -> last_name) ? $oAuthResponse -> last_name : null,
            "Email" => !empty($oAuthResponse -> email) ? $oAuthResponse -> email : null,
            "Picture" => !empty($oAuthResponse -> picture -> data -> url) ? $oAuthResponse -> picture -> data -> url : null
          ]
        ]);
      }

      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (\Exception $e) {
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

    if ((empty($idToken) && empty($code)) || empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try{
      $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      // Si no vino id_token, hacer exchange con code
      if (empty($idToken) && !empty($code)) {
        $idToken = $this->_appleExchangeCodeForIdToken($code);
        if (!$idToken) {
          return (object)[
            "http_code" => 401,
            "error" => [
              "code" => "SSO_INVALID_CODE",
              "desc" => "Invalid Apple authorization code"
            ]
          ];
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
      $user = $this->user->getUserByOAuthID($oAuthID, "apple");
      if(!$user){
        return (object)[
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user associated with the specified Apple account was found"
          ],
          "data" => [
            "Email" => $response['email'] ?? null
          ]
        ];
      }
      # El resto del login es generico para todos los tipos de login
      return $this->_loginGeneric($response, $request, $user, $mfaID, $mfaCode, $clientIp);
    } catch (\Exception $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
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

  /*
  * Registro usuario
  */
  public function register(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['Email'] ?? '';
    $username = $data['UserName'] ?? '';
    $password = $data['Password'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? null;

    if(empty($email) || empty($username) || empty($password) || empty($recaptchaToken) || !isset($data['ReceiveNewsletters'])){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    try {
      $result = $this->auth->register($this->user, $email, $username, $password, $clientIp, $request, $referralCode, $receiveNewsletters);

      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data);
          $userID = $result->data['UserID'] ?? null;

          if (!empty($referralCode)) {
            $referrerResult = $this->user->getUserByRefCode($referralCode);

            if ($referrerResult->http_code !== 200 || empty($referrerResult->data['UserID'])) {
              return $response->withStatus(400)->withJson([
                "error" => [
                  "code" => "INVALID_REFERRAL_CODE",
                  "desc" => "The provided referral code is not valid"
                ]
              ]);
            }

            $referrerUserID = $referrerResult->data['UserID'];

            if ($referrerUserID) {
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
              return $response->withStatus(200)->withJson($referralResult);
            }
          }

          return $response->withStatus(200)->withJson([
            'Token' => $jwt,
            'UserData' => $result -> data,
            'UserPlan' => null
          ]);

        default: #errores

        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);

      }
    } catch (\Exception $e) {
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
    $token = $data['Token'] ?? '';
    $username = $data['UserName'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? null;
    $altEmail = $data['Email'] ?? null; // Opcional cuando el SSO no comparte el correo

    if(empty($token) || empty($username) || empty($recaptchaToken) || !isset($data['ReceiveNewsletters'])){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    try {
      $result = $this->auth->registerGoogle(
        $this->user, $token, $username, $clientIp, $request, $referralCode, $receiveNewsletters, $altEmail
      );
      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data);
          $userID = $result->data['UserID'] ?? null;
          $userPlan = $this->subscription->getSubscriptionByUser($userID);

          if (!empty($referralCode)) {
            $referrerResult = $this->user->getUserByRefCode($referralCode);

            if ($referrerResult->http_code !== 200 || empty($referrerResult->data['UserID'])) {
              return $response->withStatus(400)->withJson([
                "error" => [
                  "code" => "INVALID_REFERRAL_CODE",
                  "desc" => "The provided referral code is not valid"
                ]
              ]);
            }

            $referrerUserID = $referrerResult->data['UserID'];

            if ($referrerUserID) {
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
              return $response->withStatus(200)->withJson($referralResult);
            }
          }

          return $response->withStatus(200)->withJson([
            'Token' => $jwt,
            'UserData' => $result -> data,
            'UserPlan' => $userPlan
          ]);
        default: # Otros, ejemplo Token inválido
          return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
    } catch (\Exception $e) {
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
    $isJwt = $data['Jwt'] ?? false; // true = Native, false = Web
    $user_id = $data['UserID'] ?? '';
    $token = $data['Token'] ?? '';
    $username = $data['UserName'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? null;
    $altEmail = $data['Email'] ?? null; // Opcional cuando el SSO no comparte el correo

    if(empty($user_id) || empty($token) || empty($username) || empty($recaptchaToken) || !isset($receiveNewsletters)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    try{
      if ($isJwt) {
        // Flujo Nativo (JWT)
        $jwtToken = $data['JwtToken'] ?? '';
        if (empty($jwtToken)) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "JwtToken is required when Jwt=true"
            ]
          ]);
        }
        $result = $this->auth->registerFacebookNative(
          $this->user, $jwtToken, $username, $clientIp, $request, $referralCode, $receiveNewsletters, $altEmail
        );
      } else {
        // Flujo Web (Graph API)
        $user_id = $data['UserID'] ?? '';
        $token = $data['Token'] ?? '';
        if (empty($user_id) || empty($token)) {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "UserID and Token are required when Jwt=false"
            ]
          ]);
        }

        $result = $this->auth->registerFacebook(
          $this->user, $user_id, $token, $username, $clientIp, $request, $referralCode, $receiveNewsletters, $altEmail
        );
      }

      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data);
          $userData = $this->user->getUserById($result -> data['UserID']);
          $userID = $result->data['UserID'] ?? null;
          $userPlan = $this->subscription->getSubscriptionByUser($userID);

          if (!empty($referralCode)) {
            $referrerResult = $this->user->getUserByRefCode($referralCode);

            if ($referrerResult->http_code !== 200 || empty($referrerResult->data['UserID'])) {
              return $response->withStatus(400)->withJson([
                "error" => [
                  "code" => "INVALID_REFERRAL_CODE",
                  "desc" => "The provided referral code is not valid"
                ]
              ]);
            }

            $referrerUserID = $referrerResult->data['UserID'];

            if ($referrerUserID) {
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
              return $response->withStatus(200)->withJson($referralResult);
            }
          }

          return $response->withStatus(200)->withJson([
            'Token' => $jwt,
            'UserData' => $result -> data,
            'UserPlan' => $userPlan
          ]);
        default: # errores
          return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }

    } catch (\Exception $e) {
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
    $code = $data['Code'] ?? '';
    $idToken = $data['IdToken'] ?? '';
    $rawNonce = $data['RawNonce'] ?? '';
    $username = $data['UserName'] ?? '';
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? null;
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $altEmail = $data['Email'] ?? null; // Opcional cuando el SSO no comparte el correo

    if((empty($id_token) && empty($code)) || empty($username) || empty($recaptchaToken) || !isset($data['ReceiveNewsletters'])){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    try {
      $result = $this->auth->registerApple(
        $this->user, $code, $idToken, $rawNonce, $username, $clientIp, $request, $referralCode, $receiveNewsletters, $altEmail
      );
      switch($result->http_code) {
        case 200: # Usuario registrado o ya existente
          $jwt = $this -> JWTgen($result -> data);
          $userID = $result->data['UserID'] ?? null;
          $userPlan = $this->subscription->getSubscriptionByUser($userID);

          if (!empty($referralCode)) {
            $referrerResult = $this->user->getUserByRefCode($referralCode);

            if ($referrerResult->http_code !== 200 || empty($referrerResult->data['UserID'])) {
              return $response->withStatus(400)->withJson([
                "error" => [
                  "code" => "INVALID_REFERRAL_CODE",
                  "desc" => "The provided referral code is not valid"
                ]
              ]);
            }

            $referrerUserID = $referrerResult->data['UserID'];

            if ($referrerUserID) {
              $referralResult = $this->auth->handleReferralReward($referrerUserID, $userID);
              return $response->withStatus(200)->withJson($referralResult);
            }
          }

          return $response->withStatus(200)->withJson([
            "Token" => $jwt,
            "UserData" => $result -> data,
            "UserPlan" => $userPlan
          ]);
        default: # Token inválido u otros errores
          return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
    } catch (\Exception $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function callback(Request $request, Response $response, array $args) {
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

  public function sendOtpMail(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $email = filter_var($data['Email'] ?? '', FILTER_VALIDATE_EMAIL);
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
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
        $result = $this->auth->sendOtpMailNoUser($data['Email']);
      }else{ # MODO CON TOKEN (usa la base, para usuarios existentes)
        $result = $this->auth->sendOtpMailExistingUser($jwt['data'] -> UserID);
      }

      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson($result);
      }
      return $response->withStatus(200)->withJson(
        ["Message" => "OTP code sent successfully"]
      );
    } catch (\Exception $e) {
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

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
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
        $result = $this->auth->validateOTPRedis($data['Email'], $otpCode);
      }else{ # MODO CON TOKEN (usa la base, para usuarios existentes)
        $result = $this->auth->validateOTP($jwt['data']->UserID, $otpCode);
      }
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      # OTP válido
      return $response->withStatus(200)->withJson([
        "Message" => "OTP code validated successfully"
      ]);
    } catch (\Exception $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
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

    $userID = $jwt['data'] -> UserID;
    $userData = $this->user->getUserById($userID);
    $userPlan = $this->subscription->getSubscriptionByUser($userID);

    if ($userData->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    $token = $this->JWTgen($userData -> data);
    return $response->withStatus(200)->withJson([
      'Token' => $token,
      'UserData' => $userData -> data,
      'UserPlan' => $userPlan
    ]);
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

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    return $response->withStatus($validation->http_code)->withJson($validation->data);
  }

  # Solicitar reseteo de contraseña
  public function requestPasswordReset(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['Email'] ?? '';
    $username = $data['UserName'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if((empty($email) && empty($username)) || empty($recaptchaToken)){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }
    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    try {
      #Busco por mail o username
      $result = !empty($email) ?
        $this->user->getUserByEmail($email) : $this->user->getUserByUserName($username);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson($result);
      }
      $userID = $result->data['UserID'];

      // Envio el mail OTP
      $result = $this->auth->sendOtpMailExistingUser($userID, true);
      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson($result);
      }
      return $response->withStatus(200)->withJson([
        "Message" => "OTP code sent successfully"
      ]);
    } catch (\Exception $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
           "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  # Resetear contraseña
  public function resetPassword(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['Email'] ?? '';
    $username = $data['UserName'] ?? '';
    $password = $data['Password'] ?? '';
    $otpCode = $data['OTPCode'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    if ((empty($email) && empty($username)) || empty($recaptchaToken) || empty($password) || empty($otpCode)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      #Busco por mail o username
      $result = !empty($email) ?
        $this->user->getUserByEmail($email) : $this->user->getUserByUserName($username);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson($result);
      }
      $userID = $result->data['UserID'];

      # Llamar a la validación del OTP
      $result = $this->auth->validateOTP($userID, $otpCode, false);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson($result);
      }

      $result = $this->auth->resetPassword($userID, $password);
      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson($result);
      }
      return $response->withStatus(200)->withJson([
        "Message" => "Password was reset successfully"
      ]);
    } catch (\Exception $e) {
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
    $userData = $this->user->getUserById($userID);

    if ($userData->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    $userName = $userData -> data['UserName'];
    if($userData -> data['TwoFactorAuth']){
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
      $qr = $g2fa -> getQRCodeUrl("OneSoul.app", $userName,	$secret);
      return $response->withStatus($userData->http_code)->withJson([
        "Secret" => $secret,
        "QR" => $qr,
      ]);
    } catch (\Exception $e) {
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
    $userData = $this->user->getUserById($userID);

    if ($userData->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    if($userData -> data['TwoFactorAuth']){
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
    } catch (\Exception $e) {
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
    $userData = $this->user->getUserById($userID);

    if ($userData->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson($result);
    }

    if(!$userData -> data['TwoFactorAuth']){
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
    } catch (\Exception $e) {
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
    } catch (\Exception $e) {
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

    } catch (\Exception $e) {
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

      return $response->withStatus(200)->withJson($documents);

    } catch (\Exception $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }
}