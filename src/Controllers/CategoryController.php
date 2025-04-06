<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Category;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

require_once(ROOT . '/src/Utils/Paginator.php');

class CategoryController
{
  protected $category;

  public function __construct(Category $category)
  {
    $this->category = $category;
  }

  public function getCategories(Request $request, Response $response, $args)
  {
    $paginator = paginator($request);

    try {
      $categories = $this->category->getCategories($paginator);
      $response->getBody()->write(json_encode($categories));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function getCategoryById(Request $request, Response $response, $args)
  {
    $paginator = paginator($request);
    $id = $args['id'];
    try {
      $category = $this->category->getCategoryById($paginator, $id);
      if ($category) {
        $response->getBody()->write(json_encode($category));
      } else {
        throw new NotFoundException('Category not found');
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

  public function getCategoryByParentId(Request $request, Response $response, $args)
  {
    $paginator = paginator($request);
    $id = $args['id'] == -1 ? null : $args['id'];
    try {
      $categories = $this->category->getCategoryByParentId($paginator, $id);
      if ($categories) {
        $response->getBody()->write(json_encode($categories));
      } else {
        throw new NotFoundException('Categories not found for the given parent ID');
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

  public function createCategory(Request $request, Response $response, $args)
  {    
    $jwt = $request->getAttribute('jwt');
    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create categories"
        ]
      ]);
    }

    $data = $request->getParsedBody();
    try {
      $category = $this->category->createCategory($data);
      $response = $response->withStatus(200);
      $message = [
        'message' => "Category created successfully",
        'category' => $category
      ];
      $response->getBody()->write(json_encode($message));
    } catch (ValidationException $e) {
      $response = $response->withStatus(422);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    } catch (DatabaseException $e) {
      $response = $response->withStatus(500);
      $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
    }
    return $response->withHeader('Content-Type', 'application/json');
  }

  public function updateCategory(Request $request, Response $response, $args)
  {
    $id = $args['id'];
    $data = $request->getParsedBody();
    $jwt = $request->getAttribute('jwt');

    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create categories"
        ]
      ]);
    }

    try {
      $category = $this->category->updateCategory($id, $data);
      $response = $response->withStatus(200);
      $message = [
        'message' => "Category updated successfully",
        'category' => $category
      ];
      $response->getBody()->write(json_encode($message));
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

  public function deleteCategory(Request $request, Response $response, $args)
  {
    $id = $args['id'];
    $jwt = $request->getAttribute('jwt');
    
    # Verificar si el usuario autenticado es el mismo que el que se intenta modificar, o si es un administrador
    if ($jwt['data']->UserType != 'Admin') {
      return $response->withStatus(401)->withJson([
        "error" => [
          "code" => "UNAUTHORIZED",
          "desc" => "You do not have permission to create categories"
        ]
      ]);
    }

    try {
      $this->category->deleteCategory($id);
      $response = $response->withStatus(200);
      $message = [
        'message' => "Category deleted successfully"
      ];
      $response->getBody()->write(json_encode($message));
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
