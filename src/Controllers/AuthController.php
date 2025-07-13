<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Auth;
use App\Models\User;
use Firebase\JWT\JWT;

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

class AuthController{

  protected $user;
  protected $auth;

  public function __construct(User $user, Auth $auth){
    $this->user = $user;
    $this->auth = $auth;
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
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try {
      // Consultar usuario
      $result = $this->auth->login($username, $email);
      if(empty($result)){
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "Invalid credentials"
          ]
        ]);
      }

      $user = $result[0];

      # Verificar si el usuario esta bloqueado
      if (!is_null($user['LockedUntil']) && strtotime($user['LockedUntil']) > time()) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporaly locked until " . $user['LockedUntil']
          ]
        ]);
      }

      if (!password_verify($password, $user['PasswordHash'])) {
        # Logueo fallido actualizar contador de erroneos y tiempo bloqueo si corresponde
        $failedAttempts = $user['FailedLoginAttempts'] + 1;
        $lockTime = $this->auth->calculateLockTime($failedAttempts);

        $this->auth->updateFailedLogin($user['UserID'], $failedAttempts, $lockTime);

        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "The password is invalid"
          ]
        ]);
      }

      $newMfaId = null;
      if($user['TwoFactorAuth'] == 1){
        // Validar MFA (mfa_id o mfa_code)
        if (!empty($mfa_id)) {
          if (!$this->auth->validateMfaId($user['UserID'], $mfa_id)) {
            return $response->withStatus(403)->withJson([
              "error" => [
                "code" => "INVALID_MFA_ID",
                "desc" => "MFA ID is not valid"
              ]
            ]);
          }
        } elseif (!empty($mfa_code)) {
          $result = $this->auth->mfaCheck($user['UserID'], $mfa_code);
          if ($result->http_code != 200) {
            return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
          }
        } else {
          return $response->withStatus(400)->withJson([
            "error" => [
              "code" => "MFA_REQUIRED",
              "desc" => "MFA validation is required"
            ]
          ]);
        }
        if(empty($mfa_id)){
          $newMfaId = uniqid();
        }
      }

      # Login exitoso, resetear intentos fallidos y bloqueo
      $this->auth->updateFailedLogin($user['UserID'], 0, null);

      $jwt = $this -> JWTgen($user);

      // Si se esta vinculando un nuevo navegador guardarlo
      if($newMfaId !== null){
        // Guardar datos del navegador
        $this->auth->storeBrowserData($user['UserID'], $request, $newMfaId, $clientIp);
      }

      $userData = $this->user->getUserById($user['UserID']);

      return $response->withStatus(200)->withJson([
        "Token" => $jwt,
        "MfaID" => $newMfaId,
        "UserData" => $userData -> data
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

  public function loginGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $token = $data['Token'] ?? '';
    $mfa_id = $data['MfaID'] ?? '';
    $mfa_code = $data['MfaCode'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if (empty($token) || empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try {
      // Validar el token de Google
      $result = $this->auth->loginGoogle($this -> user, $token);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $user = $result->data;

      // Verificar si el usuario está bloqueado
      if (!is_null($user['LockedUntil']) && strtotime($user['LockedUntil']) > time()) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporarily locked until " . $user['LockedUntil']
          ]
        ]);
      }

      // Manejar MFA si está habilitado
      $newMfaId = null;
      if ($user['TwoFactorAuth'] == 1) {
        if (!empty($mfa_id)) {
          if (!$this->auth->validateMfaId($user['UserID'], $mfa_id)) {
            return $response->withStatus(403)->withJson([
              "error" => [
                "code" => "INVALID_MFA_ID",
                "desc" => "MFA ID is not valid"
              ]
            ]);
          }
        } elseif (!empty($mfa_code)) {
          $result = $this->auth->mfaCheck($user['UserID'], $mfa_code);
          if ($result->http_code != 200) {
            return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
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

      return $response->withStatus(200)->withJson([
        'Token' => $jwt,
        'MfaID' => $newMfaId,
        'UserData' => $user
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

  public function loginFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $user_id = $data['UserID'] ?? '';
    $token = $data['Token'] ?? '';
    $mfa_id = $data['MfaID'] ?? '';
    $mfa_code = $data['MfaCode'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];



    if (empty($user_id) || empty($token) || empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try {
      $result = $this->auth->loginFacebook($this -> user, $user_id, $token);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $user = $result->data;

      // Verificar si el usuario está bloqueado
      if (!is_null($user['LockedUntil']) && strtotime($user['LockedUntil']) > time()) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporarily locked until " . $user['LockedUntil']
          ]
        ]);
      }

      // Manejar MFA si está habilitado
      $newMfaId = null;
      if ($user['TwoFactorAuth'] == 1) {
        if (!empty($mfa_id)) {
          if (!$this->auth->validateMfaId($user['UserID'], $mfa_id)) {
            return $response->withStatus(403)->withJson([
              "error" => [
                "code" => "INVALID_MFA_ID",
                "desc" => "MFA ID is not valid"
              ]
            ]);
          }
        } elseif (!empty($mfa_code)) {
          $result = $this->auth->mfaCheck($user['UserID'], $mfa_code);
          if ($result->http_code != 200) {
            return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
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

      // Obtener datos completos del usuario
      $userData = $this->user->getUserById($user['UserID']);

      return $response->withStatus(200)->withJson([
        'Token' => $jwt,
        'MfaID' => $newMfaId,
        'UserData' => $userData->data
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
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try {
      $result = $this->auth->register($this->user, $email, $username, $password, $clientIp, $request, $referralCode, $receiveNewsletters);
      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data);
          return $response->withStatus(200)->withJson([
            'Token' => $jwt,
            'UserData' => $result -> data
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
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try {
      $result = $this->auth->registerGoogle($this->user, $token, $username, $clientIp, $request, $referralCode, $receiveNewsletters);
      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data);
          return $response->withStatus(200)->withJson([
            "Token" => $jwt,
            "UserData" => $result -> data
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
    $user_id = $data['UserID'] ?? '';
    $token = $data['Token'] ?? '';
    $username = $data['UserName'] ?? '';
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];
    $referralCode = $data['ReferralCode'] ?? null;
    $receiveNewsletters = $data['ReceiveNewsletters'] ?? null;

    if(empty($user_id) || empty($token) || empty($username) || empty($recaptchaToken) || !isset($data['ReceiveNewsletters'])){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try{
      $result = $this->auth->registerFacebook($this->user, $user_id, $token, $username, $clientIp, $request, $referralCode, $receiveNewsletters);
      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data);
          $userData = $this->user->getUserById($result -> data['UserID']);
          return $response->withStatus(200)->withJson([
            'Token' => $jwt,
            'UserData' => $result -> data
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

  public function sendOtpMail(Request $request, Response $response, $args) {
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
    $recaptchaToken = $data['RecaptchaToken'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    $result = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($result->http_code !== 200) {
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try {
      $result = $this->auth->sendOtpMail($jwt['data'] -> UserID);
      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson(["Message" => "OTP code sent successfully"]);
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
    $otpCode = $data['OTPCode'] ?? '';
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
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }


    try {
      # Llamar a la validación del OTP
      $result = $this->auth->validateOTP($jwt['data']->UserID, $otpCode);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
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

    if ($userData->http_code !== 200) {
      return $response->withStatus($userData->http_code)->withJson($userData->error);
    }

    $token = $this->JWTgen($userData -> data);
    return $response->withStatus(200)->withJson([
      'Token' => $token,
      'UserData' => $userData -> data
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
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
    }

    try {
      #Busco por mail o username
      $result = !empty($email) ?
        $this->user->getUserByEmail($email) : $this->user->getUserByUserName($username);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $userID = $result->data['UserID'];

      // Envio el mail OTP
      $result = $this->auth->sendOtpMail($userID, true);
      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
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
      return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
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
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      $userID = $result->data['UserID'];

      # Llamar a la validación del OTP
      $result = $this->auth->validateOTP($userID, $otpCode, false);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }

      $result = $this->auth->resetPassword($userID, $password);
      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
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
      return $response->withStatus($userData->http_code)->withJson($userData->error);
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
      return $response->withStatus($userData->http_code)->withJson($userData->error);
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
      return $response->withStatus($userData->http_code)->withJson($userData->error);
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
        return $response->withStatus($result -> http_code)->withJson(["error" => $result->error]);
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