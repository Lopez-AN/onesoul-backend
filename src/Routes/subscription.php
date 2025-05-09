<?php

use Slim\App;
use App\Controllers\SubscriptionController;
use App\Models\Subscription;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $pdo = require __DIR__ . './../core/database.php';
  $subscription = new Subscription($pdo);
  $subscriptionController = new SubscriptionController($subscription);

  $app->get('/subscription/plans', [$subscriptionController, 'getSubscriptionPlans']);
  $app->get('/subscription/plans/{id}', [$subscriptionController, 'getSubscriptionPlanByID']);
  $app->get('/subscription/{userID}', [$subscriptionController, 'getSubscriptionByUser']);
  $app->get('/subscription/priceInfo/{planID}', [$subscriptionController, 'getPriceInfo'])->add($jwtMiddleware);
  $app->post('/subscription/plans/{id}', [$subscriptionController, 'updateSubscriptionPlan'])->add($jwtMiddleware);
  $app->patch('/subscription/{userID}', [$subscriptionController, 'updateSubscriptionByUser'])->add($jwtMiddleware);
  $app->put('/subscription/features/{featureCode}/status', [$subscriptionController, 'updateFeatureStatus'])->add($jwtMiddleware);
};