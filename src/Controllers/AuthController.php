<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Auth;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Firebase\JWT\JWT;
use \DateTime;

class AuthController{
  protected $auth;

  public function __construct(Auth $auth){
    $this->auth = $auth;
  }

  public function sendOtpMail(Request $request, Response $response, $args) {
    $jwt = $request->getAttribute('jwt');

    try {
      $auth = $this->auth->sendOtpMail($jwt['data'] -> id);
      if($auth->http_code != 200){
        $response->getBody()->write(json_encode($auth->data));
        $response = $response->withStatus($auth->http_code);
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
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
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->login($username, $email);
      if(empty($auth) || !password_verify($password,$auth[0]['PasswordHash'])){
        $response->getBody()->write(json_encode(['error' => "Invalid credentials"]));
        $response = $response->withStatus(401);
      }else{
        $jwt = $this -> JWTgen($auth[0]);
        $response->getBody()->write(json_encode(['token' => $jwt]));
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function loginGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $token = $data['token'] ?? '';

    if(empty($token)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->loginGoogle($token);
      switch($auth->http_code) {
        case 200: // Logueo correcto
          $jwt = $this->JWTgen($auth->data[0]);
          $response->getBody()->write(json_encode(['token' => $jwt]));
        break;
        case 404: // Usuario no encontrado
          $response->getBody()->write(json_encode($auth->data));
          $response = $response->withStatus(404);
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->data));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function loginFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $user_id = $data['user_id'] ?? '';
    $token = $data['token'] ?? '';

    if(empty($user_id) || empty($token)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try{
      $auth = $this->auth->loginFacebook($user_id, $token);
      switch($auth->http_code) {
        case 200: // Logueo correcto
          $jwt = $this->JWTgen($auth->data[0]);
          $response->getBody()->write(json_encode(['token' => $jwt]));
        break;
        case 404: // Usuario no encontrado
          $response->getBody()->write(json_encode($auth->data));
          $response = $response->withStatus(404);
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->data));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
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
    $password = $data['password'] ?? '';

    if(empty($email) || empty($username) || empty($password)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->register($email, $username, $password);
      switch($auth->http_code) {
        case 200: // Logueo correcto o usuario existente
          $jwt = $this->JWTgen($auth->data[0]);
          $response->getBody()->write(json_encode(['token' => $jwt]));
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->data));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function registerGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $token = $data['token'] ?? '';
    $username = $data['username'] ?? '';

    if(empty($token) || empty($username)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->registerGoogle($token, $username);
      switch($auth->http_code) {
        case 200: // Logueo correcto o usuario existente
          $jwt = $this->JWTgen($auth->data[0]);
          $response->getBody()->write(json_encode(['token' => $jwt]));
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->data));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function registerFacebook(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $user_id = $data['user_id'] ?? '';
    $token = $data['token'] ?? '';
    $username = $data['username'] ?? '';

    if(empty($user_id) || empty($token) || empty($username)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try{
      $auth = $this->auth->registerFacebook($user_id, $token, $username);
      switch($auth->http_code) {
        case 200: // Logueo correcto o usuario existente
          $jwt = $this->JWTgen($auth->data[0]);
          $response->getBody()->write(json_encode(['token' => $jwt]));
        break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($auth->data));
          $response = $response->withStatus($auth->http_code);
        break;
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function validateOTP(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $user_id = $data['user_id'] ?? '';
    $otp_code = $data['otp_code'] ?? '';

    if(empty($user_id) || empty($otp_code)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->validateOTP($user_id, $otp_code);
      if(empty($auth)){
        $response->getBody()->write(json_encode(['error' => "Invalid OTP"]));
        $response = $response->withStatus(401);
      }else{
        # Los otp expiran luego del tiempo configurado
        $otp_date = new DateTime($auth[0]['OTP_Date']);
        if(time() - $otp_date->getTimestamp() > $GLOBALS['config']['otp_exptime']){
          $response->getBody()->write(json_encode(['error' => "Expired OTP"]));
          $response = $response->withStatus(401);
        }else{
          $response->getBody()->write(json_encode(['msg' => "Verified email"]));
          $response = $response->withStatus(200);
        }
      }
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  # Generador de token JWT
  private function JWTgen($user){
    $payload = [
      'issued' => time(),
      'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
      'data' => [
        'id' => $user['UserID'],
        'username' => $user['UserName'],
        'first_name' => $user['FirstName'],
        'last_name' => $user['LastName'],
        'email' => $user['Email'],
        'user_type' => $user['UserType'],
        'profile_photo' => $user['URL']
      ]
    ];
    $secret = $GLOBALS['config']['jwt']['secret'];
    return JWT::encode($payload, $secret, 'HS256');
  }
}