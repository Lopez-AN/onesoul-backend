<?php

use Slim\App;
use App\Controllers\SubscriptionController;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Auth;
use App\Models\StripeService;
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
  $subscription = new Subscription($pdo);
  $user = new User($pdo);
    $auth = new Auth($pdo, $redis);
  $stripe = new StripeService($pdo);
  $subscriptionController = new SubscriptionController($subscription, $user, $auth, $stripe);

  $app->get('/subscription/plans', [$subscriptionController, 'getSubscriptionPlans']);
  $app->get('/subscription/plans/{id}', [$subscriptionController, 'getSubscriptionPlanByID']);
  $app->get('/subscription/plans/stripe/{stripeID}', [$subscriptionController, 'getSubscriptionPlanByStripeID']);
  $app->get('/subscription/{userID}', [$subscriptionController, 'getSubscriptionByUser'])->add($requiredJwt);
  $app->get('/subscription/user/{subId}', [$subscriptionController, 'getUserSubscriptionByPlatformSubID'])->add($requiredJwt);
  $app->patch('/subscription', [$subscriptionController, 'updateSubscriptionByUser'])->add($requiredJwt);
  $app->put('/subscription/features/{featureCode}/status', [$subscriptionController, 'updateFeatureStatus'])->add($requiredJwt);
  $app->get('/subscription/payments/{userID}', [$subscriptionController, 'getPaymentsByUser'])->add($requiredJwt);
  $app->post('/subscription/plans/{id}', [$subscriptionController, 'updateSubscriptionPlan'])->add($requiredJwt);
};