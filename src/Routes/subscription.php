<?php

use Slim\App;
use App\Controllers\SubscriptionController;
use App\Models\Subscription;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ]
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $subscription = new Subscription($pdo);
  $subscriptionController = new SubscriptionController($subscription);

  $app->get('/subscription/plans', [$subscriptionController, 'getSubscriptionPlans']);
};