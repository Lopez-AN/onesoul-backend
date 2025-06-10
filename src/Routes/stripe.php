<?php

use Slim\App;
use App\Controllers\StripeController;
use App\Models\StripeService;
use App\Models\User;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $pdo = require __DIR__ . './../core/database.php';
  $stripeService = new StripeService($pdo);
  $user = new User($pdo);
  $stripeController = new StripeController($stripeService, $user);

  $app->post('/stripe/subscribe', [$stripeController, 'createCheckoutSession'])->add($jwtMiddleware);
  $app->post('/stripe/webhook', [$stripeController, 'handleWebhook']);
};