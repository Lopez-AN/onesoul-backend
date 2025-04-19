<?php

use Slim\App;
use App\Controllers\UserController;
use App\Models\User;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => [
          "/users",
          "/users/{id}"
        ],
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
  $userController = new UserController($user);

  $app->get('/users', [$userController, 'getUsers']);
  $app->get('/users/{id}', [$userController, 'getUserById']);
  $app->get('/users/type/{type}', [$userController, 'getUsersByType']);
  $app->get('/users/email/{email}', [$userController, 'getUserByEmail']);
  $app->get('/users/username/{username}', [$userController, 'getUserByUserName']);
  $app->get('/users/category/{id}', [$userController, 'getUserByCategory']);
  $app->post('/users/profile_photo/{id}', [$userController, 'updateProfilePhoto']);
  $app->delete('/users/profile_photo/{id}', [$userController, 'deleteProfilePhoto']);
  $app->patch('/users/{id}', [$userController, 'updateUser']);
  $app->delete('/users/{id}', [$userController, 'deleteUser']);
  $app->post('/users/categories/{id}', [$userController, 'updateUserCategories']);
};
