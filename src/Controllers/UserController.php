<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\User;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class UserController {
    protected $user;

    public function __construct(User $user) {
        $this->user = $user;
    }

    public function getUsers(Request $request, Response $response, $args) {
        try {
            $users = $this->user->getUsers();
            $response->getBody()->write(json_encode($users));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getUserById(Request $request, Response $response, $args) {
        $id = $args['id'];
        try {
            $user = $this->user->getUserById($id);
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

    public function getUsersByType(Request $request, Response $response, $args) {
        $type = $args['type'];
        try {
            $users = $this->user->getUsersByType($type);
            $response->getBody()->write(json_encode($users));
        } catch (DatabaseException $e) {
            $response = $response->withStatus(500);
            $response->getBody()->write(json_encode(['message' => $e->getMessage()]));
        }
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createUser(Request $request, Response $response, $args) {
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

    public function updateUser(Request $request, Response $response, $args) {
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

    public function deleteUser(Request $request, Response $response, $args) {
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
