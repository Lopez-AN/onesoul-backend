<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Auth;
use App\Models\User;
use App\Exceptions\DatabaseException;
use Firebase\JWT\JWT;
use \DateTime;

//Definir zona horaria
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

    if((empty($email) && empty($username)) || empty($password)){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "USER_INVALID_CREDENTIALS",
          "desc" => "Invalid credentials"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->login($username, $email);

      if(empty($auth)){
        $response->getBody()->write(json_encode([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "Invalid credentials"
          ]
        ]));
        $response = $response->withStatus(401);
      }

      $user = $auth[0];

      //Verificar si el usuario esta bloqueado
      if (!is_null($user['locked_until']) && strtotime($user['locked_until']) > date()) {
        $response->getBody()->write(json_encode([
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporaly locked until " . $user['locked_until']
          ]
        ]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
      }

      if (!password_verify($password, $user['PasswordHash'])) {
        $failedAttempts = $user['failed_login_attempts'] + 1;
        $lockTime = $this->calculateLockTime($failedAttempts);

        $this->auth->updateFailedLogin($user['UserID'], $failedAttempts, $lockTime);

        $response->getBody()->write(json_encode([
          "error" => [
            "code" => "USER_INVALID_CREDENTIALS",
            "desc" => "Invalid credentials"
          ]
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
      }

      // Login exitoso, resetear intentos fallidos y bloqueo
      $this->auth->updateFailedLogin($user['UserID'], 0, null);

      $jwt = $this -> JWTgen($auth[0]);
      $userData = $this->user->getUserById($auth[0]['UserID']);
      $response->getBody()->write(json_encode([
          'token' => $jwt,
          'userData' => $userData -> data
      ]));
    } catch (DatabaseException $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      $response = $response->withStatus(500);
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  // Función para calcular los tiempos de bloqueo
  private function calculateLockTime($failedAttempts) {
    $lockTime = null;
    switch ($failedAttempts) {
      case 5: $lockTime = "+1 minute"; break;
      case 6: $lockTime = "+2 minutes"; break;
      case 7: $lockTime = "+4 minutes"; break;
      case 8: $lockTime = "+8 minutes"; break;
      case 9: $lockTime = "+15 minutes"; break;
      case 10: $lockTime = "+30 minutes"; break;
    }
    return $lockTime ? date("Y-m-d H:i:s", strtotime($lockTime)) : null;
  }

  public function loginGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $token = $data['token'] ?? '';

    if(empty($token)){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->loginGoogle($token);
      switch($auth->http_code) {
        case 200: // Logueo correcto
          $jwt = $this -> JWTgen($auth -> data[0]);
          $userData = $this->user->getUserById($auth -> data[0]['UserID']);
          $response->getBody()->write(json_encode([
            'token' => $jwt,
            'userData' => $userData -> data
          ]));
        break;
        case 404: // Usuario no encontrado
          $response->getBody()->write(json_encode([$auth->error, $auth->data]));
          $response = $response->withStatus(404);
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->error));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      $response = $response->withStatus(500);
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function loginFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $user_id = $data['user_id'] ?? '';
    $token = $data['token'] ?? '';

    if(empty($user_id) || empty($token)){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try{
      $auth = $this->auth->loginFacebook($user_id, $token);
      switch($auth->http_code) {
        case 200: // Logueo correcto
          $jwt = $this -> JWTgen($auth -> data[0]);
          $userData = $this->user->getUserById($auth -> data[0]['UserID']);
          $response->getBody()->write(json_encode([
            'token' => $jwt,
            'userData' => $userData -> data
          ]));
        break;
        case 404: // Usuario no encontrado
          $response->getBody()->write(json_encode([$auth->error, $auth->data]));
          $response = $response->withStatus(404);
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->error));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      $response = $response->withStatus(500);
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  /*
  * Registro usuario
  */
  public function register(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';
    $username = $data['username'] ?? '';
    $newPassword = $data['newPassword'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($email) || empty($username) || empty($newPassword) || empty($recaptchaToken)){  
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($validation->http_code !== 200) {
      $response->getBody()->write(json_encode($validation->error));
      $response = $response->withStatus($validation->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->register($email, $username, $newPassword);
      switch($auth->http_code) {
        case 200: // Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($auth -> data[0]);
          $userData = $this->user->getUserById($auth -> data[0]['UserID']);
          $response->getBody()->write(json_encode([
            'token' => $jwt,
            'userData' => $userData -> data
          ]));
        break;
        case 400: // Contraseña débil
          $response->getbody()->write(json_encode($auth->error));
          $response = $response->withStatus(400);
        break;
        default: //Otros errores
          $response->getBody()->write(json_encode($auth->error));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      $response = $response->withStatus(500);
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function requestPasswordReset(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';
    $username = $data['username'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if (empty($email) && empty($username)) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Email or Username is required"
        ]
      ]));
      return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    if(empty($recaptchaToken)){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($validation->http_code !== 200) {
      $response->getBody()->write(json_encode($validation->error));
      $response = $response->withStatus($validation->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    } 

    try {
      $auth = $this->auth->sendOtpMailByEmail($email, $username);

      // Si el usuario no fue encontrado, devolver el error
      if (is_object($auth) && isset($auth->error)) {
        $response->getBody()->write(json_encode([
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified Email/Username"
          ]
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
      }

      // OTP enviado exitosamente
      return $response->withStatus(200)->withHeader('Content-Type', 'application/json')
        ->write(json_encode([
            "message" => "OTP sent to email"
      ]));
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            "error" => [
              "code" => "INTERNAL_SERVER_ERROR",
              "desc" => $e->getMessage()
            ]
        ]));
      return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
  }

  public function validateOtpByEmail(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';    
    $username = $data['username'] ?? '';    
    $otpCode = $data['otpCode'] ?? '';

    if (empty($email) && empty($username)) {
      $response->getBody()->write(json_encode([
          "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "Email or Username is required"
          ]
      ]));
      return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // Si solo hay username, obtener el email
    if (!empty($username)) {
      $user = $this->auth->getUserByUserName($username);
      if (!$user) {
          $response->getBody()->write(json_encode([
              "error" => [
                  "code" => "USER_NOT_FOUND",
                  "desc" => "User not found"
              ]
          ]));
          return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
      }
      $email = $user[0]['Email'];  // Asigna el email del usuario encontrado
    }

    if (empty($otpCode)) {
      $response->getBody()->write(json_encode([
          "error" => [
              "code" => "INVALID_PARAMETERS",
              "desc" => "OTP is required"
          ]
      ]));
      return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }


    try {
      // Llamar a la validación del OTP
      $validOtp = $this->auth->validateOTPByEmail($email, $otpCode);
  
      if (is_object($validOtp) && isset($validOtp->error)) {
        return $response->withStatus($validOtp->http_code)->withHeader('Content-Type', 'application/json')
        ->write(json_encode($validOtp));
      }
  
      // OTP válido
      return $response->withStatus(200)->withHeader('Content-Type', 'application/json')
        ->write(json_encode([
        "message" => "OTP validated successfully"
        ]));
      } catch (DatabaseException $e) {
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json')
          ->write(json_encode([
          "error" => [
            "code" => "INTERNAL_SERVER_ERROR",
            "desc" => $e->getMessage()
          ]
        ]));
      }
  }
  
  // Resetear contraseña
  public function resetPassword(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';    
    $username = $data['username'] ?? '';   
    $newPassword = $data['newPassword'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($validation->http_code !== 200) {
      $response->getBody()->write(json_encode($validation->error));
      $response = $response->withStatus($validation->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    } 

    
    if (empty($email) && empty($username) || empty($recaptchaToken)) { 
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    if (empty($newPassword)) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "New password is required"
        ]
      ]));
      return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        // Si solo hay username, obtener el email
        if (!empty($username)) {
          $user = $this->auth->getUserByUserName($username);
          if (!$user) {
              $response->getBody()->write(json_encode([
                  "error" => [
                      "code" => "USER_NOT_FOUND",
                      "desc" => "User not found"
                  ]
              ]));
              return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
          }
          $email = $user[0]['Email'];
        }

      $resetResponse = $this->auth->resetPassword($email, $newPassword);
      if ($resetResponse->http_code !== 200) {
        $response->getBody()->write(json_encode($resetResponse));
        return $response->withStatus($resetResponse->http_code)->withHeader('Content-Type', 'application/json');
      }

      $response->getBody()->write(json_encode([
        "message" => "Password reset successfully"
      ]));
      return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
  }

  public function registerGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $token = $data['token'] ?? '';
    $username = $data['username'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($token) || empty($username) || empty($recaptchaToken)){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($validation->http_code !== 200) {
      $response->getBody()->write(json_encode($validation->error));
      $response = $response->withStatus($validation->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->registerGoogle($token, $username);
      switch($auth->http_code) {
        case 200: // Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($auth -> data[0]);
          $userData = $this->user->getUserById($auth -> data[0]['UserID']);
          $response->getBody()->write(json_encode([
            'token' => $jwt,
            'userData' => $userData -> data
          ]));
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->error));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      $response = $response->withStatus(500);
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function registerFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $user_id = $data['user_id'] ?? '';
    $token = $data['token'] ?? '';
    $username = $data['username'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($user_id) || empty($token) || empty($username) || empty($recaptchaToken)){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($validation->http_code !== 200) {
      $response->getBody()->write(json_encode($validation->error));
      $response = $response->withStatus($validation->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try{
      $auth = $this->auth->registerFacebook($user_id, $token, $username);
      switch($auth->http_code) {
        case 200: // Logueo correcto o usuario existente
          $jwt = $this -> JWTgen($auth -> data[0]);
          $userData = $this->user->getUserById($auth -> data[0]['UserID']);
          $response->getBody()->write(json_encode([
            'token' => $jwt,
            'userData' => $userData -> data
          ]));
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->error));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      $response = $response->withStatus(500);
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function sendOtpMailJWT(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $data = $request->getParsedBody();
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($validation->http_code !== 200) {
      $response->getBody()->write(json_encode($validation->error));
      $response = $response->withStatus($validation->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->sendOtpMailJWT($jwt['data'] -> UserID);
      if($auth->http_code != 200){
        $response->getBody()->write(json_encode($auth->error));
        $response = $response->withStatus($auth->http_code);
      }
    } catch (DatabaseException $e) {
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]));
      $response = $response->withStatus(500);
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function validateOTPJWT(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $data = $request->getParsedBody();
    $otpCode = $data['otpCode'] ?? '';
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    $clientIp = $request->getServerParams()['REMOTE_ADDR'];

    if(empty($otpCode) || empty($recaptchaToken)) {   
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $validation = $this->auth->validateReCaptcha($recaptchaToken, $clientIp);
    if ($validation->http_code !== 200) {
      $response->getBody()->write(json_encode($validation->error));
      $response = $response->withStatus($validation->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      // Llamar a la validación del OTP
      $validOtp = $this->auth->validateOTPJWT($jwt['data']->UserID, $otpCode);

      if (is_object($validOtp) && isset($validOtp->error)) {
        return $response->withStatus($validOtp->http_code)->withHeader('Content-Type', 'application/json')
        ->write(json_encode($validOtp));
      }

      // OTP válido
      return $response->withStatus(200)->withHeader('Content-Type', 'application/json')
      ->write(json_encode([
        "message" => "OTP validated successfully"
      ]));
    } catch (DatabaseException $e) {
      return $response->withStatus(500)->withHeader('Content-Type', 'application/json')
          ->write(json_encode([
              "error" => [
                  "code" => "INTERNAL_SERVER_ERROR",
                  "desc" => $e->getMessage()
              ]
          ]));
    }
    // try {
    //   $auth = $this->auth->validateOTPJWT($jwt['data'] -> UserID, $otpCode);
    //   if(empty($auth)){
    //     $response->getBody()->write(json_encode([
    //       "error" => [
    //         "code" => "INVALID_OTP",
    //         "desc" => "The specified OTP code is invalid"
    //       ]
    //     ]));
    //     $response = $response->withStatus(401);
      // }else{
      //     $response->getBody()->write(json_encode([]));
      //     $response = $response->withStatus(200);
      // }
    // } catch (DatabaseException $e) {
    //   $response = $response->withStatus(500);
    //   $response->getBody()->write(json_encode([
    //     "error" => [
    //       "code" => "INTERNAL_SERVER_ERROR",
    //       "desc" => $e->getMessage()
    //     ]
    //   ]));
    // }
    // return $response->withHeader('Content-Type', 'application/json');
  }

  public function refreshToken(Request $request, Response $response, $args){
    $jwt = $request->getAttribute('jwt');
    if(!isset($jwt['data']) || !property_exists($jwt['data'],'UserID')){
      $response->getBody()->write(json_encode([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $userID = $jwt['data'] -> UserID;
    $userData = $this->user->getUserById($userID);

    if ($userData->http_code !== 200) {
      $response->getBody()->write(json_encode($userData->error));
      $response = $response->withStatus($userData->http_code);
      return $response->withHeader('Content-Type', 'application/json');
    }

    $token = $this->JWTgen($userData -> data);
    $response->getBody()->write(json_encode([
      'token' => $token,
      'userData' => $userData -> data
    ]));
    return $response->withHeader('Content-Type', 'application/json');
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

  public function validateReCaptcha(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $recaptchaToken = $data['recaptcha_token'] ?? '';

    if (empty($recaptchaToken)) {
      return $response->withStatus(400)->withJson(["error" => "Missing reCaptcha token"]);
    }

    $validation = $this->auth->validateReCaptcha($recaptchaToken);
    return $response->withStatus($validation->http_code)->withJson($validation->data);
  }
}