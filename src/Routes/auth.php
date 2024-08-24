<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Models\Auth;

return function (App $app) {
    $pdo = require __DIR__ . './../core/database.php';
	  $auth = new Auth($pdo);
	  $authController = new AuthController($auth);

    $app->post('/login', [$authController, 'login']);
    $app->post('/login/facebook', [$authController, 'loginFacebook']);
    $app->post('/login/google', [$authController, 'loginGoogle']);
    // $app->post('/register', [$authController, 'register']);
    $app->post('/register/facebook', [$authController, 'registerFacebook']);
    $app->post('/register/google', [$authController, 'registerGoogle']);
};