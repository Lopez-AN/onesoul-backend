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
require_once(ROOT . '/src/Utils/OptimizeImg.php');
require_once(ROOT . '/src/Utils/PerspectiveText.php');

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
      return $response->withStatus(200)->withJson($users);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getUserById(Request $request, Response $response, $args){
    $id = $args['id'];

    try {
      $result = $this->user->getUserById($id);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson($result->data);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getUserByEmail(Request $request, Response $response, $args){
    $email = $args['email'];
    try {
      $result = $this->auth->getUserByEmail($email);
      if(!$result) {
        return $response->withStatus(404)->withJson((object)["error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified email"
        ]]);
      }
      return $response->withStatus(200)->withJson($result);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getUserByUserName(Request $request, Response $response, $args){
    $username = $args['username'];
    try {
      $result = $this->auth->getUserByUserName($username);
      if(!$result) {
        return $response->withStatus(404)->withJson((object)["error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified username"
        ]]);
      }
      return $response->withStatus(200)->withJson($result);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getUsersByType(Request $request, Response $response, $args){
    $paginator = paginator($request);

    $type = $args['type'];
    try {
      $users = $this->user->getUsersByType($paginator, $type);
      return $response->withStatus(200)->withJson($users);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
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

      // Valida contenido con Perspective API
      if ($this->containsInappropriateContent($data['Biography'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
        ]);
      }

      // Valida contenido con Perspective API
      if ($this->containsInappropriateContent($data['shortDescription'])) {
        return $response->withStatus(400)->withJson([
          "code" => "INAPPROPRIATE_CONTENT",
            "desc" => "Please remove inappropriate content and try again."
        ]);
      }      

      $result = $this->user->updateUser($userId, $data);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      # Retornar el usuario actualizado
      return $response->withStatus(200)->withJson($result->data);
    } catch (\Throwable $e) {
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
      if ($jwt['data']->UserID != $id && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to modify this user"
          ]
        ]);
      }

      $result = $this->user->deleteUser($id);
      if($result -> http_code != 200){
        return $response->withStatus($result -> http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson($result->data);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
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

      $result = $this->user->updateProfilePhoto($userId, $uploadedFile);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }

      # Retornar el usuario actualizado
      return $response->withStatus(200)->withJson($result->data);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function deleteProfilePhoto(Request $request, Response $response, $args) {
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
      # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
      if ($jwt['data']->UserID != $userId && $jwt['data']->UserType != 'Admin') {
        return $response->withStatus(401)->withJson([
          "error" => [
            "code" => "UNAUTHORIZED",
            "desc" => "You do not have permission to delete this user's profile photo"
          ]
        ]);
      }

      $result = $this->user->deleteProfilePhoto($userId);
      if ($result->http_code != 200) {
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson($result -> data);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function updateUserCategories(Request $request, Response $response, $args) {
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

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserID != $userId && $jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to modify this user's categories"
        ]
      ]);
    }

    $data = $request->getParsedBody();
    $categories = $data['categories'] ?? [];

    if (!is_array($categories)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_DATA",
          "desc" => "Categories should be an array"
        ]
      ]);
    }

    try {
      # Obtener las categorías actuales del usuario
      $existingCategories = $this->user->getUserCategories($userId);
      $existingCategoryIds = array_column($existingCategories, 'CategoryID');

      # Categorías a agregar y eliminar
      $categoriesToAdd = array_diff($categories, $existingCategoryIds);
      $categoriesToDelete = array_diff($existingCategoryIds, $categories);

      # Agregar nuevas asociaciones
      if (!empty($categoriesToAdd)) {
        foreach ($categoriesToAdd as $categoryId) {
          $this->user->addUserCategory($userId, $categoryId);
        }
      }

      # Eliminar asociaciones que no están en el array enviado
      if (!empty($categoriesToDelete)) {
        foreach ($categoriesToDelete as $categoryId) {
          $this->user->deleteUserCategory($userId, $categoryId);
        }
      }

      $result = $this->user->getUserById($userId);
      if($result->http_code != 200){
        return $response->withStatus($result->http_code)->withJson(["error" => $result->error]);
      }
      return $response->withStatus(200)->withJson($result->data);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  private function containsInappropriateContent($text) {
    return validateContentWithPerspective($text);
  }
}
