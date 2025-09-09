<?php

use Slim\App;
use App\Controllers\StripeController;
use App\Models\StripeService;
use App\Models\User;
use App\Models\Subscription;
use Tuupola\Middleware\JwtAuthentication;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "attribute" => "jwt"
  ]);

  $pdo = require __DIR__ . './../core/database.php';
  $stripeService = new StripeService($pdo);
  $user = new User($pdo);
  $subscription = new Subscription($pdo);
  $stripeController = new StripeController($stripeService, $user, $subscription);

  $app->post('/stripe/subscribe', [$stripeController, 'createCheckoutSession'])->add($jwtMiddleware);
  $app->post('/stripe/webhook', [$stripeController, 'handleWebhook']);
  $app->get('/stripe/session/{sessionID}', [$stripeController, 'getStripeSession'])->add($jwtMiddleware); 
  $app->post('/subscription/stripe/upgrade/info/{subId}', [$stripeController, 'upgradeInfo'])->add($jwtMiddleware);
  $app->post('/subscription/stripe/upgrade/apply/{subId}', [$stripeController, 'upgradeApply'])->add($jwtMiddleware);
  $app->post('/subscription/stripe/downgrade/apply/{subId}', [$stripeController, 'downgradeApply'])->add($jwtMiddleware);
  $app->post('/subscription/stripe/cancel', [$stripeController, 'cancelSubscription'])->add($jwtMiddleware);
  $app->get('/subscription/stripe/users/payment-method', [$stripeController, 'getUserPaymentMethod'])->add($jwtMiddleware);
  $app->post('/subscription/stripe/users/payment-method', [$stripeController, 'updateUserPaymentMethod'])->add($jwtMiddleware);
  $app->post('/subscription/stripe/users/payment-method/billingSession', [$stripeController, 'createBillingPortalSession'])->add($jwtMiddleware);
};