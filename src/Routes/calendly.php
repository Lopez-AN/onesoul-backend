<?php

use Slim\App;
use App\Controllers\CalendlyController;
use App\Models\CalendlyService;
use App\Models\User;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $calendlyService = new CalendlyService($pdo);
  $user = new User($pdo);
  $calendlyController = new CalendlyController($calendlyService, $user);

  $app->post('/calendly/connect', [$calendlyController, 'connect'])->add($jwtMiddleware);
  $app->delete('/calendly/disconnect', [$calendlyController, 'disconnect'])->add($jwtMiddleware);
  $app->get('/calendly/user/{id}', [$calendlyController, 'checkUser'])->add($jwtMiddleware);
  $app->get('/calendly/callback', [$calendlyController, 'callback']);
  $app->post('/calendly/webhook', [$calendlyController, 'handleWebhook']);
};