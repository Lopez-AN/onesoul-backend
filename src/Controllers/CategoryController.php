<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Category;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

require_once(ROOT . '/src/Utils/Paginator.php');

class CategoryController {
  protected $category;

  public function __construct(Category $category) {
    $this->category = $category;
  }

  public function getCategories(Request $request, Response $response, $args) {
    $paginator = paginator($request);

    try {
      $categories = $this->category->getCategories($paginator);
      return $response->withStatus(200)->withJson($categories);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getCategoryById(Request $request, Response $response, $args) {
    $id = $args['id'];

    try {
      $category = $this->category->getCategoryById($id);
      if (!$category) {
        return $response->withStatus(404)->withJson([
          "error" => [
            "code" => "CATEGORY_NOT_FOUND",
            "desc" => "No category associated with the specified id was found"
          ]
        ]);
      }
      return $response->withStatus(200)->withJson($category);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function getCategoryByParentId(Request $request, Response $response, $args) {
    $id = $args['id'] == -1 ? null : $args['id'];
    $paginator = paginator($request);

    try {
      $categories = $this->category->getCategoryByParentId($paginator, $id);
      return $response->withStatus(200)->withJson($categories);
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function createCategory(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    // Verificar si es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $parentCategoryID = $data['ParentCategoryID'] ?? null;
    $name = $data['Name'] ?? null;
    $description = $data['Description'] ?? null;
    $creationDate = $data['CreationDate'] ?? null;
    $modificationDate = $data['ModificationDate'] ?? null;
    $isActive = $data['IsActive'] ?? null;

    if(!array_key_exists('ParentCategoryID', $data) || empty($name) || empty($description)
      || empty($creationDate) || empty($modificationDate) || $isActive === null
    ){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    if ($jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create categories"
        ]
      ]);
    }

    try {
      $this->category->createCategory(
        $parentCategoryID, $name, $description, $creationDate, $modificationDate, $isActive);
      return $response->withStatus(200)->withJson("Category created successfully");
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function updateCategory(Request $request, Response $response, $args) {
    $id = $args['id'];
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    // Verificar si es un array/object válido
    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    $parentCategoryID = $data['ParentCategoryID'] ?? null;
    $name = $data['Name'] ?? null;
    $description = $data['Description'] ?? null;
    $creationDate = $data['CreationDate'] ?? null;
    $modificationDate = $data['ModificationDate'] ?? null;
    $isActive = $data['IsActive'] ?? null;

    if(!array_key_exists('ParentCategoryID', $data) || empty($name) || empty($description)
      || empty($creationDate) || empty($modificationDate) || $isActive === null
    ){
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    if ($jwt['data']->IsAdmin) {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to update categories"
        ]
      ]);
    }

    try {
      $this->category->updateCategory($id, $parentCategoryID, $name, $description, $creationDate, $modificationDate, $isActive);
      return $response->withStatus(200)->withJson("Category updated successfully");
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }

  public function deleteCategory(Request $request, Response $response, $args) {
    $id = $args['id'];
    $jwt = $request->getAttribute('jwt');

    if ($jwt['data']->UserType != 'Admin') {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to delete categories"
        ]
      ]);
    }

    try {
      $this->category->deleteCategory($id);
      return $response->withStatus(200)->withJson("Category deleted successfully");
    } catch (\Throwable $e) {
      return $response->withStatus(500)->withJson([
        "error" => [
          "code" => "INTERNAL_SERVER_ERROR",
          "desc" => $e->getMessage()
        ]
      ]);
    }
  }
}
