<?php

use Slim\App;
use App\Controllers\ChatbotController;
use App\Models\Chatbot;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use JimTools\JwtAuth\Decoder\FirebaseDecoder;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Secret;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication(
    new Options(),
    new FirebaseDecoder(new Secret($GLOBALS['config']['jwt']['secret'], 'HS256'))
  );

  $optionalJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::OPTIONAL);

  $pdo = $app->getContainer()->get('pdo');
  $redis = $app->getContainer()->get('redis'); # Base de datos en RAM

  $chatbot = new Chatbot($pdo);
  $chatbotController = new ChatbotController($chatbot, $redis);

  $app->post('/chatbot/message', [$chatbotController, 'sendMessage']);
};
