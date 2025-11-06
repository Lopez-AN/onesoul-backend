<?php

use Slim\App;
use App\Controllers\CalController;
use App\Models\CalService;
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
  $calService = new CalService($pdo);
  $user = new User($pdo);
  $calController = new CalController($calService, $user);

  $app->post('/cal/connect', [$calController, 'connect'])->add($requiredJwt);
  $app->delete('/cal/disconnect', [$calController, 'disconnect'])->add($requiredJwt);
  $app->get('/cal/user/{id}', [$calController, 'checkUser'])->add($requiredJwt);
  $app->get('/cal/callback', [$calController, 'callback']);
  $app->post('/cal/webhook', [$calController, 'handleWebhook']);
};