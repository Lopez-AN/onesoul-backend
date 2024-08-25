<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\User;
use App\Models\Auth;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

require_once(ROOT . '/src/Utils/Paginator.php');

class UserController
{
  protected $user;
  protected $auth;

  public function __construct(User $user, Auth $auth){
    $this->user = $user;
    $this->auth = $auth;
  }

  public function getUsers(Request $request, Response $response, $args){
    $paginator = paginator($request);

    try {
      $users = $this->user->getUsers($paginator);
      $response->getBody()->write(json_encode($users));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getUserById(Request $request, Response $response, $args){
    $paginator = paginator($request);

    $id = $args['id'];
    try {
      $user = $this->user->getUserById($paginator, $id);
      if ($user) {
        $response->getBody()->write(json_encode($user));
      } else {
        throw new NotFoundException('User not found');
      }
    } catch (NotFoundException $e) {
      $response = $response->withStatus(404);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getUserByEmail(Request $request, Response $response, $args){
    $email = $args['email'];
    try {
      $user = $this->auth->getUserByEmail($email);
      if ($user) {
        $response->getBody()->write(json_encode(empty($user) ? [] : $user[0]));
      } else {
        throw new NotFoundException('User not found');
      }
    } catch (NotFoundException $e) {
      $response = $response->withStatus(404);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getUserByUserName (Request $request, Response $response, $args){
    $username = $args['username'];
    try {
      $user = $this->auth->getUserByEmail($username);
      if ($user) {
        $response->getBody()->write(json_encode(empty($user) ? [] : $user[0]));
      } else {
        throw new NotFoundException('User not found');
      }
    } catch (NotFoundException $e) {
      $response = $response->withStatus(404);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getUsersByType(Request $request, Response $response, $args){
    $paginator = paginator($request);

    $type = $args['type'];
    try {
      $users = $this->user->getUsersByType($paginator, $type);
      $response->getBody()->write(json_encode($users));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function createUser(Request $request, Response $response, $args){
    $data = $request->getParsedBody();
    try {
      $user = $this->user->createUser($data);
      $response = $response->withStatus(201);
      $response->getBody()->write(json_encode($user));
    } catch (ValidationException $e) {
      $response = $response->withStatus(422);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function updateUser(Request $request, Response $response, $args){
    $id = $args['id'];
    $data = $request->getParsedBody();
    try {
      $user = $this->user->updateUser($id, $data);
      $response->getBody()->write(json_encode($user));
    } catch (ValidationException $e) {
      $response = $response->withStatus(422);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (NotFoundException $e) {
      $response = $response->withStatus(404);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function deleteUser(Request $request, Response $response, $args){
    $id = $args['id'];
    try {
      $this->user->deleteUser($id);
      $response = $response->withStatus(204);
    } catch (NotFoundException $e) {
      $response = $response->withStatus(404);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }
}
