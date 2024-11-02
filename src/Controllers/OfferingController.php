<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Offering;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Firebase\JWT\JWT;

require_once(ROOT . '/src/Utils/Paginator.php');
require_once(ROOT . '/src/Utils/OptimizeImg.php');
require_once(ROOT . '/src/Utils/PerspectiveText.php');

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
        $jwt = $request->getAttribute('jwt');
        $userID = $jwt['data']->UserID;

        if (!isset($jwt['data']) || !property_exists($jwt['data'], 'UserID') || !property_exists($jwt['data'], 'UserType')) {
            return $response->withStatus(401)->withJson([
              "error" => [
                "code" => "INVALID_TOKEN",
                "desc" => "Invalid JWT token"
              ]
            ]);
        }
    
        $data = $request->getParsedBody();

        $data['UserID'] = $userID;
        $data['CreationDate'] = date("YmdHis");
        $data['IsActive'] = 0;
        $data['TotalReviews'] = 0;
        $data['Status'] = "Pending";

        try {
            # Verificar si el usuario autenticado es el mismo que el que se intenta crear, o si es un administrador
            if ($jwt['data']->UserID != $userID && $jwt['data']->UserType != 'Admin') {
                return $response->withStatus(401)->withJson([
                    "error" => [
                        "code" => "UNAUTHORIZED",
                        "desc" => "You don't have permission to create offerings."
                    ]
                ]);
            }

            // Valida categoryID contra suscripción del usuario
            if (!$this->userBelongsToCategory($userID, $data['CategoryID'])) {
                return $response->withStatus(400)->withJson([
                    "code" => "WRONG_CATEGORY",
                    "desc" => "The user does not belong to selected category"
                ]);
            }

            // Valida contenido con Perspective API
            if ($this->containsInappropriateContent($data['Title'])) {
                return $response->withStatus(400)->withJson([
                    "code" => "INAPPROPRIATE_CONTENT",
                    "desc" => "Please remove inappropriate content and try again."
                ]);
            }
            
            // Valida contenido con Perspective API
            if ($this->containsInappropriateContent($data['Description'])) {
                return $response->withStatus(400)->withJson([
                    "code" => "INAPPROPRIATE_CONTENT",
                    "desc" => "Please remove inappropriate content and try again."
                ]);
            }
        
            $offering = $this->offering->createOffering($data);
            
            return $response->withStatus(201)->withJson([
                "message" => "Offering created successfully",
                "offering" => $offering
            ]);
            
        }catch (DatabaseException $e) {
            return $response->withStatus(500)->withJson(["error" => $e->getMessage()]);
        }
    }

    public function updateOffering(Request $request, Response $response, $args) {
        $id = $args['id'];
        $data = $request->getParsedBody();
        try {
            $offering = $this->offering->updateOffering($id, $data);
            $response = $response->withStatus(200);
            $message = [
                'message' => "Offering updated successfully",
                'offering' => $offering
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

    public function deleteOffering(Request $request, Response $response, $args) {
        $id = $args['id'];
        try {
            $this->offering->deleteOffering($id);
            $response = $response->withStatus(200);
            $response->getBody()->write(json_encode(['message' => 'Offering deleted successfully']));
        } catch (NotFoundException $e) {
            $response = $response->withStatus(404);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Función para verificar que el usuario esté suscrito a la categoría
    private function userBelongsToCategory($userID, $categoryID) {
        return $this->offering->checkUserCategorySubscription($userID, $categoryID);
    }
    
    // Función para verificar contenido inapropiado utilizando Perspective API
    private function containsInappropriateContent($text) {
        return validateContentWithPerspective($text);
    }
}
