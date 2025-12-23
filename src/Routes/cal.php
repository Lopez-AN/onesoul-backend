<?php

use Slim\App;
use App\Controllers\CalController;
use App\Models\Cal;
use App\Models\User;
use App\Models\Offering;
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
  $redis = $app->getContainer()->get('redis'); # Base de datos en RAM
  $cal = new Cal($pdo);
  $user = new User($pdo);
  $offering = new Offering($pdo);
  $calController = new CalController($cal, $user, $offering, $redis);

  $app->post('/cal/connect', [$calController, 'connect'])->add($requiredJwt);
  $app->delete('/cal/disconnect', [$calController, 'disconnect'])->add($requiredJwt);
  $app->get('/cal/callback', [$calController, 'callback']);
  $app->get('/cal/user/{UserID}', [$calController, 'checkUser']);
  $app->get('/cal/schedule/uuid/{AssocUUID}', [$calController, 'getScheduleByAssocUUID'])->add($requiredJwt);
  $app->get('/cal/availability/{UserID}', [$calController, 'getAvailability']);
  $app->patch('/cal/availability/{UserID}', [$calController, 'updateAvailability'])->add($requiredJwt);
  $app->post('/cal/webhook', [$calController, 'handleWebhook']);
};