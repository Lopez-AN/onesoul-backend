<?php

use Slim\App;
use App\Controllers\ChatbotController;
use App\Models\Chatbot;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $optionalJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::OPTIONAL);

  $pdo = $app->getContainer()->get('pdo');
  $redis = $app->getContainer()->get('redis'); # Base de datos en RAM

  $chatbot = new Chatbot($pdo);
  $chatbotController = new ChatbotController($chatbot, $redis);

  $app->post('/chatbot/message', [$chatbotController, 'sendMessage']);
};
