<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Auth;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Firebase\JWT\JWT;

class AuthController{
  protected $auth;

  public function __construct(Auth $auth){
    $this->auth = $auth;
  }

  /*
  * Logueo usuario
  */
  public function login(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if(empty($username) || empty($password)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->login($username);
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

    if(empty($token)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try {
      $auth = $this->auth->registerGoogle($token);
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

    if(empty($user_id) || empty($token)){
      $response->getBody()->write(json_encode(['error' => "Invalid parameters"]));
      $response = $response->withStatus(400);
      return $response->withHeader('Content-Type', 'application/json');
    }

    try{
      $auth = $this->auth->registerFacebook($user_id, $token);
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

  # Generador de token JWT
  private function JWTgen($user){
    $payload = [
      'issued' => time(),
      'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
      'data' => [
        'id' => $user['UserID'],
        'UserName' => $user['UserName'],
        'FirstName' => $user['FirstName'],
        'LastName' => $user['LastName'],
        'Email' => $user['Email'],
        'UserType' => $user['UserType'],
        'picture' => $user['URL']
      ]
    ];
    $secret = $GLOBALS['config']['jwt']['secret'];
    return JWT::encode($payload, $secret, 'HS256');
  }
}