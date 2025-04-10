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
        "path" => [
          "/subscription/plans/{id}",
          "/subscription/{userID}",
          "/subscription/features/{featureCode}/status"
        ],
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ],
    "attribute" => "jwt", 
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $subscription = new Subscription($pdo);
  $subscriptionController = new SubscriptionController($subscription);

  $app->get('/subscription/plans', [$subscriptionController, 'getSubscriptionPlans']);
  $app->get('/subscription/plans/{id}', [$subscriptionController, 'getSubscriptionPlanByID']);
  $app->get('/subscription/{userID}', [$subscriptionController, 'getSubscriptionByUser']);
  $app->post('/subscription/plans/{id}', [$subscriptionController, 'updateSubscriptionPlan']);
  $app->patch('/subscription/{userID}', [$subscriptionController, 'updateSubscriptionByUser']);
  $app->put('/subscription/features/{featureCode}/status', [$subscriptionController, 'updateFeatureStatus']);
};