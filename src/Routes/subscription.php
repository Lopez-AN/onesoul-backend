<?php

use Slim\App;
use App\Controllers\SubscriptionController;
use App\Models\Subscription;
use App\Models\User;
use App\Models\StripeService;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $pdo = require __DIR__ . './../core/database.php';
  $subscription = new Subscription($pdo);
  $user = new User($pdo);
  $stripe = new StripeService($pdo);
  $subscriptionController = new SubscriptionController($subscription, $user, $stripe);

  $app->get('/subscription/plans', [$subscriptionController, 'getSubscriptionPlans']);
  $app->get('/subscription/plans/{id}', [$subscriptionController, 'getSubscriptionPlanByID']);
  $app->get('/subscription/{userID}', [$subscriptionController, 'getSubscriptionByUser'])->add($jwtMiddleware);
  $app->get('/subscription/priceInfo/{planID}', [$subscriptionController, 'getPriceInfo'])->add($jwtMiddleware);
  $app->post('/subscription/plans/{id}', [$subscriptionController, 'updateSubscriptionPlan'])->add($jwtMiddleware);
  $app->patch('/subscription', [$subscriptionController, 'updateSubscriptionByUser'])->add($jwtMiddleware);
  $app->put('/subscription/features/{featureCode}/status', [$subscriptionController, 'updateFeatureStatus'])->add($jwtMiddleware);
};