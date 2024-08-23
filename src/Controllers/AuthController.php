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

  public function loginGoogle(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $token = $data['token'] ?? '';
    try{
      $auth = $this->auth->loginGoogle($token);
      if(empty($auth)){
        $response->getBody()->write('Cant validate token');
        return $response->withStatus(401);
      }
      $response->getBody()->write(json_encode($auth));
    // if(!password_verify($password,$auth[0]['PasswordHash'])){
    //   $response->getBody()->write('Invalid credentials');
    //   return $response->withStatus(401);
    // }
    // $payload = [
    //   'issued' => time(),
    //   'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
    //   'data' => [
    //     'userID' => $auth[0]['UserID'],
    //     'sub' => $auth[0]['oauth2_id'],
    //     'given_name' => $auth[0]['FirstName'],
    //     'family_name' => $auth[0]['LastName'],
    //     'email' => $auth[0]['Email'],
    //     'email_verified' => $auth[0]['ValidatedEmail'],    
    //     'UserType' => $auth[0]['UserType']
    //     'picture' => $auth[0]['URL'] ?? COMO HAGO PARA PONER OTRA TABLA, ESTA DEBERIA SER Media
    //   ]
    // ];
    // $secret = $GLOBALS['config']['jwt']['secret'];
    // $jwt = JWT::encode($payload, $secret, 'HS256');
    // $response->getBody()->write(json_encode(['token' => $jwt]));
    // return $response->withHeader('Content-Type', 'application/json');
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');  
  }
  // {
//   "iss": "https://accounts.google.com",
//   "azp": "506306028342-rrcf6pk90c3vpdd44kjs0mv52ubd229j.apps.googleusercontent.com",
//   "aud": "506306028342-rrcf6pk90c3vpdd44kjs0mv52ubd229j.apps.googleusercontent.com",
//   "sub": "110318597697997381057",
//   "email": "alejandrolopez.exe@gmail.com",
//   "email_verified": "true",
//   "nbf": "1724445481",
//   "name": "Alejandro LF",
//   "picture": "https://lh3.googleusercontent.com/a/ACg8ocKiBmMYfiP0QTdFG9oTiwr9PVkZuNGZNCQfUSr8tC8meDHjIVBw=s96-c",
//   "given_name": "Alejandro",
//   "family_name": "LF",
//   "iat": "1724445781",
//   "exp": "1724449381",
//   "jti": "f857105b12cba9a68bcefa62dd753d2e8d53e93c",
//   "alg": "RS256",
//   "kid": "a49391bf52b58c1d560255c2f2a04e59e22a7b65",
//   "typ": "JWT"
// }

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
      $response->getBody()->write(json_encode($auth));
    // if(!password_verify($password,$auth[0]['PasswordHash'])){
    //   $response->getBody()->write('Invalid credentials');
    //   return $response->withStatus(401);
    // }
    // $payload = [
    //   'issued' => time(),
    //   'expire' => time() + $GLOBALS['config']['jwt']['lifetime'],
    //   'data' => [
    //     'userID' => $auth[0]['UserID'],
    //     'FirstName' => $auth[0]['FirstName'],
    //     'LastName' => $auth[0]['LastName'],
    //     'Email' => $auth[0]['Email'],
    //     'UserType' => $auth[0]['UserType']
    //   ]
    // ];
    // $secret = $GLOBALS['config']['jwt']['secret'];
    // $jwt = JWT::encode($payload, $secret, 'HS256');
    // $response->getBody()->write(json_encode(['token' => $jwt]));
    // return $response->withHeader('Content-Type', 'application/json');
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');  
  }
}


