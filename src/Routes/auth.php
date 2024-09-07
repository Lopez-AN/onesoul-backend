<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Models\Auth;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => [
          "/register/otp",
          "/register/send_otp_mail"
        ],
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS"]
      ])
    ],
    "attribute" => "jwt", // Asegúrate de que el atributo se llame 'jwt'
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $auth = new Auth($pdo);
  $authController = new AuthController($auth);

  $app->post('/login', [$authController, 'login']);
  $app->post('/login/facebook', [$authController, 'loginFacebook']);
  $app->post('/login/google', [$authController, 'loginGoogle']);

  $app->post('/register', [$authController, 'register']);
  $app->post('/register/facebook', [$authController, 'registerFacebook']);
  $app->post('/register/google', [$authController, 'registerGoogle']);
  $app->post('/register/otp', [$authController, 'validateOTP']);
  $app->post('/register/send_otp_mail', [$authController, 'sendOtpMail']);
  $app->post('/recaptcha', [$authController, 'validateReCaptcha']);
};
