<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Offering;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

require_once(ROOT . '/src/Utils/Paginator.php');

class OfferingController {
    protected $offering;

    public function __construct(Offering $offering) {
        $this->offering = $offering;
    }

    public function getOfferings(Request $request, Response $response, $args) {
        $paginator = paginator($request);
        try {
            $offerings = $this->offering->getOfferings($paginator);
            $response->getBody()->write(json_encode($offerings));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getOfferingById(Request $request, Response $response, $args) {
        $paginator = paginator($request);
        $id = $args['id'];
        try {
            $offering = $this->offering->getOfferingById($paginator, $id);
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
        $paginator = paginator($request);
        $categoryId = $args['categoryID'];
        try {
            $offerings = $this->offering->getOfferingsByCategoryId($paginator, $categoryId);
            $response->getBody()->write(json_encode($offerings));
        } catch (\Exception $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => 'Internal Server Error', 'error' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getOfferingsByUserId(Request $request, Response $response, $args) {
        $paginator = paginator($request);
        $userId = $args['userID'];
        try {
            $offerings = $this->offering->getOfferingsByUserId($paginator, $userId);
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
