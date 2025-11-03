<?php

use Slim\App;
use App\Controllers\CalendlyController;
use App\Models\CalendlyService;
use App\Models\User;
use Tuupola\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $calendlyService = new CalendlyService($pdo);
  $user = new User($pdo);
  $calendlyController = new CalendlyController($calendlyService, $user);

  $app->post('/calendly/connect', [$calendlyController, 'connect'])->add($requiredJwt);
  $app->delete('/calendly/disconnect', [$calendlyController, 'disconnect'])->add($requiredJwt);
  $app->get('/calendly/user/{id}', [$calendlyController, 'checkUser'])->add($requiredJwt);
  $app->get('/calendly/callback', [$calendlyController, 'callback']);
  $app->post('/calendly/webhook', [$calendlyController, 'handleWebhook']);
};