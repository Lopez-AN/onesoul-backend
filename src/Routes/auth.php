<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Models\User;
use App\Models\Auth;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => [
          "/register/otp",
          "/register/send_otp_mail",
          "/auth/refresh_token",
          "/auth/mfa_req",
          "/auth/mfa_set",
          "/auth/mfa_check",
          "/auth/mfa_del"
        ],
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS"]
      ])
    ],
    "attribute" => "jwt", // Este atributo lo podes usar para leer el token desde el controller
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $user = new User($pdo);
  $auth = new Auth($pdo);
  $authController = new AuthController($user, $auth);

  $app->post('/login', [$authController, 'login']);
  $app->post('/login/facebook', [$authController, 'loginFacebook']);
  $app->post('/login/google', [$authController, 'loginGoogle']);
  $app->post('/register', [$authController, 'register']);
  $app->post('/register/facebook', [$authController, 'registerFacebook']);
  $app->post('/register/google', [$authController, 'registerGoogle']);
  $app->post('/register/otp', [$authController, 'validateOTP']);
  $app->post('/register/send_otp_mail', [$authController, 'sendOtpMail']);
  $app->post('/recaptcha', [$authController, 'validateReCaptcha']);
  $app->get('/auth/refresh_token', [$authController, 'refreshToken']);
  $app->post('/auth/request_password_reset', [$authController, 'requestPasswordReset']);
  $app->post('/auth/password_reset', [$authController, 'resetPassword']);
  $app->get('/auth/mfa_req', [$authController, 'mfaReq']);
  $app->post('/auth/mfa_set', [$authController, 'mfaSet']);
  $app->delete('/auth/mfa_del', [$authController, 'mfaDel']);
  $app->get('/auth/mfa_check/{code}', [$authController, 'mfaCheck']);
};
