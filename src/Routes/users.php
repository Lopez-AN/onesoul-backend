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
                "path" => "/users",
                "ignore" => []
            ]),
            new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
                "ignore" => ["OPTIONS"]
            ])
        ]
    ]));

    $pdo = require __DIR__ . './../core/database.php';
    $user = new User($pdo);
    $userController = new UserController($user);

    $app->get('/users', [$userController, 'getUsers']);
    $app->get('/users/{id}', [$userController, 'getUserById']);
    $app->get('/users/type/{type}', [$userController, 'getUsersByType']);
    $app->post('/users', [$userController, 'createUser']);
    $app->put('/users/{id}', [$userController, 'updateUser']);
    $app->delete('/users/{id}', [$userController, 'deleteUser']);
};
