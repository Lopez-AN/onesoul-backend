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

  public function __construct(User $user, Auth $auth)
  {
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
    $id = $args['id'];

    try {
      $user = $this->user->getUserById($id);
      switch ($user->http_code) {
        case 200: // Logueo correcto o usuario existente
          $response->getBody()->write(json_encode($user->data));
          break;
        default: // Otros, ejemplo Token inválido
          $response->getBody()->write(json_encode($user->error));
          $response = $response->withStatus($user->http_code);
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

  public function getUserByUserName(Request $request, Response $response, $args){
    $username = $args['username'];
    try {
      $user = $this->auth->getUserByUserName($username);
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

  public function updateUser(Request $request, Response $response, $args){
    $userId = $args['id'];
    $data = $request->getParsedBody();

    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      # Ver si estan las propiedades del token jwt
      if (!property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "Invalid token"
          ]
        ]);
      }
      # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
      if ($jwt['data']->UserID != $userId && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      $result = $this->user->updateUser($userId, $data);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson($result->error);
      }

      # Retornar el usuario actualizado
      return $response->withStatus(200)->withJson($result->data);
    } catch (DatabaseException $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function deleteUser(Request $request, Response $response, $args)  {
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

  public function updateProfilePhoto(Request $request, Response $response, $args)  {
    $userId = $args['id'];
    $jwt = $request->getAttribute('jwt');
    if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "INVALID_TOKEN",
          "desc" => "Invalid JWT token"
        ]
      ]);
    }

    try {
      # Ver si estan las propiedades del token jwt
      if (!property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "Invalid token"
          ]
        ]);
      }
      # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
      if ($jwt['data']->UserID != $userId && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      # Obtengo el archivo del body del request
      $uploadedFiles = $request->getUploadedFiles();
      $uploadedFile = $uploadedFiles['profilePhoto'] ?? null;

      if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
        return $response->withStatus(400)->withJson([
          "error" => [
            "code" => "UPLOAD_ERROR",
            "desc" => "Cannot read the attached file"
          ]
        ]);
      }

      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];
      $fileName = $uploadedFile->getClientFilename();
      $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
      $filePath = $uploadDirectory."/user/".$userId.".".$fileExtension;

      $uploadedFile->moveTo($filePath);

      $fileURL = $GLOBALS['config']['media_folder']['url']."/user/".$userId.".".$fileExtension;

      $result = $this->user->updateProfilePhoto($userId, $fileURL);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson($result->error);
      }

      # Retornar el usuario actualizado
      return $response->withStatus(200)->withJson($result->data);
    } catch (Exception $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }
}
