<?php

use Slim\App;
use App\Controllers\ChatbotController;
use App\Models\Chatbot;
use Tuupola\Middleware\JwtAuthentication;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $optionalJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::OPTIONAL);

  $chatbotController = new ChatbotController();

  // $app->get('/categories', [$categoryController, 'getCategories']);
};
