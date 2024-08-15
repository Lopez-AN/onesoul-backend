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
        $payload = [
          'issued' => time(),
          'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
          'data' => [
            'userID' => $auth[0]['UserID'],
            'FirstName' => $auth[0]['FirstName'],
            'LastName' => $auth[0]['LastName'],
            'Email' => $auth[0]['Email'],
            'UserType' => $auth[0]['UserType']
          ]
        ];
        $secret = $GLOBALS['config']['jwt']['secret'];
        $jwt = JWT::encode($payload, $secret, 'HS256');
        $response->getBody()->write(json_encode(['token' => $jwt]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (DatabaseException $e) {
        $response = $response->withStatus(500);
        $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }
}


