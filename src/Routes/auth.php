<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Models\User;
use App\Models\Auth;
use App\Models\Subscription;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $pdo = require __DIR__ . './../core/database.php';
  $user = new User($pdo);
  $auth = new Auth($pdo);
  $subscription = new Subscription($pdo);
  $authController = new AuthController($user, $auth, $subscription);

  $app->post('/login', [$authController, 'login']);
  $app->post('/login/facebook', [$authController, 'loginFacebook']);
  $app->post('/login/google', [$authController, 'loginGoogle']);
  $app->post('/register', [$authController, 'register']);
  $app->post('/register/facebook', [$authController, 'registerFacebook']);
  $app->post('/register/google', [$authController, 'registerGoogle']);
  $app->post('/register/otp', [$authController, 'validateOTP'])->add($jwtMiddleware);
  $app->post('/register/send_otp_mail', [$authController, 'sendOtpMail'])->add($jwtMiddleware);
  $app->post('/recaptcha', [$authController, 'validateReCaptcha']);
  $app->get('/auth/refresh_token', [$authController, 'refreshToken'])->add($jwtMiddleware);
  $app->post('/auth/request_password_reset', [$authController, 'requestPasswordReset']);
  $app->post('/auth/password_reset', [$authController, 'resetPassword']);
  $app->get('/auth/mfa_req', [$authController, 'mfaReq'])->add($jwtMiddleware);
  $app->post('/auth/mfa_set', [$authController, 'mfaSet'])->add($jwtMiddleware);
  $app->delete('/auth/mfa_del', [$authController, 'mfaDel'])->add($jwtMiddleware);
  $app->get('/auth/mfa_check/{code}', [$authController, 'mfaCheck'])->add($jwtMiddleware);
  $app->post('/legal', [$authController, 'uploadLegalDocuments'])->add($jwtMiddleware);
  $app->get('/legal', [$authController, 'legalDocuments']);
};
