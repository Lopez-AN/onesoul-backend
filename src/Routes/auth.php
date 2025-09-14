<?php

use Slim\App;
use App\Controllers\AuthController;
use App\Models\User;
use App\Models\Auth;
use App\Models\Subscription;
use Tuupola\Middleware\JwtAuthentication;

// QUITAR
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// ------

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

  $app->group('/auth/apple', function (RouteCollectorProxy $group) {
    $group->post('/callback', function (Request $request, Response $response) {
      // Esta misma vista de arriba, si preferís, renderízala con Twig o echo tal cual
      $parsed = $request->getParsedBody() ?? [];
      $code     = $parsed['code']     ?? null;
      $id_token = $parsed['id_token'] ?? null;
      $state    = $parsed['state'] ? @json_decode($parsed['state']) : null;
      $error    = $parsed['error']    ?? null;

      if(!$state){
        $html = '<!doctype html><html><body><script>(function(){window.close()})();</script></body></html>';
      }else{
        $html = '<!doctype html><html><body><script>(function(){var p='.
        json_encode([
          'provider' => 'apple',
          'code' => $code,
          'id_token' => $id_token,
          'uuid' => $state -> uuid,
          'error' => $error,
        ]).
        ';try{window.opener&&window.opener.postMessage(p,"https://'. $state -> origin .'")}catch(e){}window.close()})();</script></body></html>';
      }
      file_put_contents(ROOT."/debug.log", $html);
      $response->getBody()->write($html);
      return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    });
  });
};
