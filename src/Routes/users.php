<?php

use Slim\App;
use App\Controllers\UserController;
use App\Models\User;
use App\Models\Auth;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => "/users",
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ],
    "attribute" => "jwt", // Este atributo lo podes usar para leer el token desde el controller
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $user = new User($pdo);
  $auth = new Auth($pdo);
  $userController = new UserController($user, $auth);

  $app->get('/users', [$userController, 'getUsers']);
  $app->get('/users/{id}', [$userController, 'getUserById']);
  $app->get('/users/type/{type}', [$userController, 'getUsersByType']);
  $app->get('/users/email/{email}', [$userController, 'getUserByEmail']);
  $app->get('/users/username/{username}', [$userController, 'getUserByUserName']);
  $app->put('/users/{id}', [$userController, 'updateUser']);
  $app->delete('/users/{id}', [$userController, 'deleteUser']);
};
