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
    $email = $data['email'] ?? '';
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $mfa_id = $data['mfa_id'] ?? '';
    $mfa_code = $data['mfa_code'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    // Validar credenciales básicas
    if((empty($email) && empty($username)) || empty($password) || empty($recaptchaToken)){
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "USER_INVALID_CREDENTIALS",
          "desc" => "Invalid credentials"
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
      if (!is_null($user['Locked_until']) && strtotime($user['Locked_until']) > time()) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporaly locked until " . $user['Locked_until']
          ]
        ]);
      }

      if (!password_verify($password, $user['PasswordHash'])) {
        # Logueo fallido actualizar contador de erroneos y tiempo bloqueo si corresponde
        $failedAttempts = $user['Failed_login_attempts'] + 1;
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
        $this->auth->storeBrowserData($user['UserID'], $request, $newMfaId);
      }

      $userData = $this->user->getUserById($user['UserID']);

      return $response->withStatus(200)->withJson([
        "token" => $jwt,
        "mfaID" => $newMfaId,
        "userData" => $userData -> data
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
    $token = $data['token'] ?? '';
    $mfa_id = $data['mfa_id'] ?? '';
    $mfa_code = $data['mfa_code'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
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
      $result = $this->auth->loginGoogle($token);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }

      $user = $result->data[0];

      // Verificar si el usuario está bloqueado
      if (!is_null($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporarily locked until " . $user['locked_until']
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
        $this->auth->storeBrowserData($user['UserID'], $request, $newMfaId);
      }

      // Obtener datos completos del usuario
      $userData = $this->user->getUserById($user['UserID']);

      return $response->withStatus(200)->withJson([
        'token' => $jwt,
        'mfaID' => $newMfaId,
        'userData' => $userData->data
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
    $user_id = $data['user_id'] ?? '';
    $token = $data['token'] ?? '';
    $mfa_id = $data['mfa_id'] ?? '';
    $mfa_code = $data['mfa_code'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
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
      $result = $this->auth->loginFacebook($user_id, $token);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }

      $user = $result->data[0];

      // Verificar si el usuario está bloqueado
      if (!is_null($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        return $response->withStatus(403)->withJson([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporarily locked until " . $user['locked_until']
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
        $this->auth->storeBrowserData($user['UserID'], $request, $newMfaId);
      }

      // Obtener datos completos del usuario
      $userData = $this->user->getUserById($user['UserID']);

      return $response->withStatus(200)->withJson([
        'token' => $jwt,
        'mfaID' => $newMfaId,
        'userData' => $userData->data
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
    $email = $data['email'] ?? '';
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($email) || empty($username) || empty($password) || empty($recaptchaToken)){
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
      $result = $this->auth->register($email, $username, $password);
      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data);
          $userData = $this->user->getUserById($result -> data['UserID']);
          return $response->withStatus(200)->withJson([
            'token' => $jwt,
            'userData' => $userData -> data
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
    $token = $data['token'] ?? '';
    $username = $data['username'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($token) || empty($username) || empty($recaptchaToken)){
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
      $result = $this->auth->registerGoogle($token, $username);
      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data[0]);
          $userData = $this->user->getUserById($result -> data[0]['UserID']);
          return $response->withStatus(200)->withJson([
            "token" => $jwt,
            "userData" => $userData -> data
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
    $user_id = $data['user_id'] ?? '';
    $token = $data['token'] ?? '';
    $username = $data['username'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($user_id) || empty($token) || empty($username) || empty($recaptchaToken)){
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
      $result = $this->auth->registerFacebook($user_id, $token, $username);
      switch($result->http_code) {
        case 200: # Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($result -> data[0]);
          $userData = $this->user->getUserById($result -> data[0]['UserID']);
          return $response->withStatus(200)->withJson([
            'token' => $jwt,
            'userData' => $userData -> data
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
    $recaptchaToken = $data['recaptcha_token'] ?? '';
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
      return $response->withStatus(200)->withJson(["message" => "OTP code sent successfully"]);
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
    $otpCode = $data['otp_code'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
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
        "message" => "OTP code validated successfully"
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
      'token' => $token,
      'userData' => $userData -> data
    ]);
  }

  public function validateReCaptcha(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $recaptchaToken = $data['recaptcha_token'] ?? '';
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
    $email = $data['email'] ?? '';
    $username = $data['username'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
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
        $this->auth->getUserByEmail($email) : $this->auth->getUserByUserName($username);
      if (empty($result)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified Email/Username"
          ]
        ]);
      }

      $result = $this->auth->sendOtpMail($result['UserID']);
      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson(["message" => "OTP code sent successfully"]);
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
    $email = $data['email'] ?? '';
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $otpCode = $data['otp_code'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
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
        $this->auth->getUserByEmail($email) : $this->auth->getUserByUserName($username);
      if (empty($result)) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified Email/Username"
          ]
        ]);
      }
      $userID = $result['UserID'];

      # Llamar a la validación del OTP
      $result = $this->auth->validateOTP($userID, $otpCode, false);
      if ($result->http_code !== 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }

      $result = $this->auth->resetPassword($userID, $password);
      if($result->http_code !== 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson(["message" => "Password was reset successfully"]);
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
        "secret" => $secret,
        "qr" => $qr,
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
    $secret = $data['secret'] ?? '';
    $code = $data['code'] ?? '';

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
      return $response->withStatus(200)->withJson(["message" => "MFA is set"]);
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
      return $response->withStatus(200)->withJson(["message" => "MFA unset"]);
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
      return $response->withStatus(200)->withJson($result->message);
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
}