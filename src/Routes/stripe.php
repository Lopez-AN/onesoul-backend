<?php

use Slim\App;
use App\Controllers\StripeController;
use App\Models\StripeService;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $pdo = require __DIR__ . './../core/database.php';
  $StripeService = new StripeService($pdo);
  $stripeController = new StripeController($StripeService);

  $app->post('/api/stripe/subscribe', [$stripeController, 'createCheckoutSession']);
};