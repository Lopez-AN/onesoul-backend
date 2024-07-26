<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Category;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class CategoryController {
    protected $category;

    public function __construct(Category $category) {
        $this->category = $category;
    }

    public function getCategories(Request $request, Response $response, $args) {
        try {
            $categories = $this->category->getCategories();
            $response->getBody()->write(json_encode($categories));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getCategoryById(Request $request, Response $response, $args) {
        $id = $args['id'];
        try {
            $category = $this->category->getCategoryById($id);
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

    public function getCategoryByParentId(Request $request, Response $response, $args) {
	$id = $args['id'] ?? null;
        try {
            $categories = $this->category->getCategoryByParentId($id);
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

    public function createCategory(Request $request, Response $response, $args) {
        $data = $request->getParsedBody();
        try {
            $category = $this->category->createCategory($data);
            $response = $response->withStatus(201);
            $response->getBody()->write(json_encode($category));
        } catch (ValidationException $e) {
            $response = $response->withStatus(422);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateCategory(Request $request, Response $response, $args) {
        $id = $args['id'];
        $data = $request->getParsedBody();
        try {
            $category = $this->category->updateCategory($id, $data);
            $response->getBody()->write(json_encode($category));
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

    public function deleteCategory(Request $request, Response $response, $args) {
        $id = $args['id'];
        try {
            $this->category->deleteCategory($id);
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
