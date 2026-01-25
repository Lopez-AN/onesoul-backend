<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Models\User;
use App\Models\Auth;
use App\Models\Notification;
use App\Models\Subscription;
use App\Services\TwilioService;
use Tuupola\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $optionalJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::OPTIONAL);
  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);
  $noExpireJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::NO_EXPIRE);

  # Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $redis = $app->getContainer()->get('redis'); # Base de datos en RAM
  $user = new User($pdo);
  $notification = new Notification($pdo);
  $auth = new Auth($pdo);
  $subscription = new Subscription($pdo);
  $twilio = new TwilioService();
  $authController = new AuthController($auth, $user, $notification, $subscription, $redis, $twilio);

  $app->post('/login', [$authController, 'login']);
  $app->post('/login/facebook', [$authController, 'loginFacebook']);
  $app->post('/login/google', [$authController, 'loginGoogle']);
  $app->post('/login/apple', [$authController, 'loginApple']);
  $app->post('/auth/apple/callback', [$authController, 'appleLoginCallback']);
  $app->post('/register', [$authController, 'register']);
  $app->post('/register/facebook', [$authController, 'registerFacebook']);
  $app->post('/register/google', [$authController, 'registerGoogle']);
  $app->post('/register/apple', [$authController, 'registerApple']);
  $app->post('/register/send_otp_mail', [$authController, 'sendOtpMail']);
  $app->post('/register/validate_otp_mail', [$authController, 'validateOtpMail']);

  $app->post('/recaptcha', [$authController, 'validateReCaptcha']);
  $app->get('/auth/refresh_token', [$authController, 'refreshToken'])->add($noExpireJwt);
  $app->post('/auth/request_password_reset', [$authController, 'requestPasswordReset']);
  $app->post('/auth/password_reset', [$authController, 'resetPassword']);
  $app->get('/auth/mfa_req', [$authController, 'mfaReq'])->add($requiredJwt);
  $app->post('/auth/mfa_set', [$authController, 'mfaSet'])->add($requiredJwt);
  $app->delete('/auth/mfa_del', [$authController, 'mfaDel'])->add($requiredJwt);
  $app->get('/auth/mfa_check/{Code}', [$authController, 'mfaCheck'])->add($requiredJwt);
  $app->post('/legal', [$authController, 'uploadLegalDocuments'])->add($requiredJwt);
  $app->get('/legal', [$authController, 'legalDocuments']);

  $app->patch('/profile/email/change/request', [$authController, 'requestEmailChange'])->add($requiredJwt);
  $app->patch('/profile/email/change/validate', [$authController, 'validateEmailChange'])->add($requiredJwt);
  $app->patch('/profile/phone/change/request', [$authController, 'requestPhoneChange'])->add($requiredJwt);
  $app->patch('/profile/phone/change/validate', [$authController, 'validatePhoneChange'])->add($requiredJwt);
};
