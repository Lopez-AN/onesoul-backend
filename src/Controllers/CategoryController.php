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

  /**
   * Obtiene todas las categorías con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con lista de categorías
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
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

  /**
   * Obtiene una categoría por su ID
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  string ?query: texto a buscar
   * @return Response: JSON con categorias o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function searchCategories(Request $request, Response $response, $args) {
    $paginator = paginator($request);
    $queryParams = $request->getQueryParams();
    $query = $queryParams['query'] ?? '';

    try {
      $categories = $this->search->searchCategories($paginator, $query);
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

  /**
   * Obtiene una categoría por su ID
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con datos de la categoría o error
   * @statusCode 200: éxito
   * @statusCode 404: categoría no encontrada
   * @statusCode 500: error del servidor
   **/
  public function getCategoryById(Request $request, Response $response, $args) {
    $categoryID = $args['id'];

    try {
      $category = $this->category->getCategoryById($categoryID);
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

  /**
   * Obtiene categorías por ID de categoría padre con paginación
   * @param  Request $request: objeto de request HTTP
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id, -1 para raíz)
   * @return Response: JSON con lista de categorías hijo o error
   * @statusCode 200: éxito
   * @statusCode 500: error del servidor
   **/
  public function getCategoriesByParentId(Request $request, Response $response, $args) {
    $categoryID = $args['id'] == -1 ? null : $args['id'];
    $paginator = paginator($request);

    try {
      $categories = $this->category->getCategoriesByParentId($paginator, $categoryID);
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

  /**
   * Crea una nueva categoría (requiere permisos de admin)
   * @param  Request $request: objeto de request HTTP (requiere JWT admin, body: ParentCategoryID, Name, Description, IsActive)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta
   * @return Response: JSON con categoría creada o error
   * @statusCode 200: éxito
   * @statusCode 400: parámetros inválidos o JSON inválido
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos de admin
   * @statusCode 500: error del servidor
   **/
  public function createCategory(Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create categories"
        ]
      ]);
    }

    $parentCategoryID = $data['ParentCategoryID'] ?? null;
    $name = $data['Name'] ?? null;
    $description = $data['Description'] ?? null;
    $isActive = $data['IsActive'] ?? null;

    if(!array_key_exists('ParentCategoryID', $data) || empty($name) || empty($description) || $isActive === null) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      $category = $this->category->createCategory($parentCategoryID, $name, $description, $isActive);
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

  /**
   * Actualiza una categoría existente (requiere permisos de admin)
   * @param  Request $request: objeto de request HTTP (requiere JWT admin, body: ParentCategoryID, Name, Description, IsActive)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con categoría actualizada o error
   * @statusCode 200: éxito
   * @statusCode 400: parámetros inválidos o JSON inválido
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos de admin
   * @statusCode 500: error del servidor
   **/
  public function updateCategory(Request $request, Response $response, $args) {
    $categoryID = $args['id'];
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    if (!is_array($data) && !is_object($data)) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_JSON",
          "desc" => "Request body must be valid JSON"
        ]
      ]);
    }

    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to update categories"
        ]
      ]);
    }

    $parentCategoryID = $data['ParentCategoryID'] ?? null;
    $name = $data['Name'] ?? null;
    $description = $data['Description'] ?? null;
    $isActive = $data['IsActive'] ?? null;

    if(!array_key_exists('ParentCategoryID', $data) || empty($name) || empty($description) || $isActive === null) {
      return $response->withStatus(400)->withJson([
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ]);
    }

    try {
      $category = $this->category->updateCategory($categoryID, $parentCategoryID, $name, $description, $isActive);
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

  /**
   * Elimina una categoría existente (requiere permisos de admin)
   * @param  Request $request: objeto de request HTTP (requiere JWT admin)
   * @param  Response $response: objeto de response HTTP
   * @param  array $args: argumentos de ruta (id)
   * @return Response: JSON con mensaje de éxito o error
   * @statusCode 200: éxito
   * @statusCode 401: JWT inválido
   * @statusCode 403: sin permisos de admin
   * @statusCode 500: error del servidor
   **/
  public function deleteCategory(Request $request, Response $response, $args) {
    $categoryID = $args['id'];
    $jwt = $request->getAttribute('jwt');

    if (!$jwt->data->IsAdmin) {
      return $response->withStatus(403)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to delete categories"
        ]
      ]);
    }

    try {
      $this->category->deleteCategory($categoryID);
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