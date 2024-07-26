<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Offering;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class OfferingController {
    protected $offering;

    public function __construct(Offering $offering) {
        $this->offering = $offering;
    }

    public function getOfferings(Request $request, Response $response, $args) {
        try {
            $offerings = $this->offering->getOfferings();
            $response->getBody()->write(json_encode($offerings));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getOfferingById(Request $request, Response $response, $args) {
        $id = $args['id'];
        try {
            $offering = $this->offering->getOfferingById($id);
            if ($offering) {
                $response->getBody()->write(json_encode($offering));
            } else {
                throw new NotFoundException('Offering not found');
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

    public function getOfferingsByCategoryId(Request $request, Response $response, $args) {
        $categoryId = $args['categoryID'];
        try {
            $offerings = $this->offering->getOfferingsByCategoryId($categoryId);
            $response->getBody()->write(json_encode($offerings));
        } catch (\Exception $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => 'Internal Server Error', 'error' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getOfferingsByUserId(Request $request, Response $response, $args) {
        $userId = $args['userID'];
        try {
            $offerings = $this->offering->getOfferingsByUserId($userId);
            $response->getBody()->write(json_encode($offerings));
        } catch (\Exception $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => 'Internal Server Error', 'error' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function createOffering(Request $request, Response $response, $args) {
        $data = $request->getParsedBody();
        try {
            $offering = $this->offering->createOffering($data);
            $response = $response->withStatus(201);
            $response->getBody()->write(json_encode($offering));
        } catch (ValidationException $e) {
            $response = $response->withStatus(422);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateOffering(Request $request, Response $response, $args) {
        $id = $args['id'];
        $data = $request->getParsedBody();
        try {
            $offering = $this->offering->updateOffering($id, $data);
            $response->getBody()->write(json_encode($offering));
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

    public function deleteOffering(Request $request, Response $response, $args) {
        $id = $args['id'];
        try {
            $this->offering->deleteOffering($id);
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
