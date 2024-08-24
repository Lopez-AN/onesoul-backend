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

  public function login(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    try {
        $auth = $this->auth->login($username);
        if(empty($auth)){
          $response->getBody()->write('Invalid credentials');
          return $response->withStatus(401);
        }
        if(!password_verify($password,$auth[0]['PasswordHash'])){
          $response->getBody()->write('Invalid credentials');
          return $response->withStatus(401);
        }

        $jwt = $this -> JWTgen($auth[0]);
        $response->getBody()->write(json_encode(['token' => $jwt]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (DatabaseException $e) {
        $response = $response->withStatus(500);
        $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function loginGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $token = $data['token'] ?? '';
    try{
      $auth = $this->auth->loginGoogle($token);
      if($auth -> http_code != 200){
        $response->getBody()->write($auth -> data);
        return $response->withStatus($auth -> http_code);
      }

      $jwt = $this -> JWTgen($auth -> data[0]);
      $response->getBody()->write(json_encode(['token' => $jwt]));
      return $response->withHeader('Content-Type', 'application/json');
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
    try{
      $auth = $this->auth->loginFacebook($user_id, $token);
      if(empty($auth)){
        $response->getBody()->write('Cant validate token');
        return $response->withStatus(401);
      }

      $jwt = $this -> JWTgen($auth -> data[0]);
      $response->getBody()->write(json_encode(['token' => $jwt]));
      return $response->withHeader('Content-Type', 'application/json');
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');  
  }

  private function JWTgen($user){
    $payload = [
      'issued' => time(),
      'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
      'data' => [
        'id' => $user['UserID'],
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

