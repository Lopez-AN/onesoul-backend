<?php

use Slim\App;
use App\Controllers\StripeController;
use App\Models\StripeService;
use App\Models\User;
use App\Models\Subscription;
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
  $stripeService = new StripeService($pdo);
  $user = new User($pdo);
  $subscription = new Subscription($pdo);
  $stripeController = new StripeController($stripeService, $user, $subscription);

  $app->post('/stripe/subscribe', [$stripeController, 'createCheckoutSession'])->add($requiredJwt);
  $app->post('/stripe/webhook', [$stripeController, 'handleWebhook']);
  $app->get('/stripe/session/{sessionID}', [$stripeController, 'getStripeSession'])->add($requiredJwt);
  $app->post('/subscription/stripe/upgrade/info', [$stripeController, 'upgradeInfo'])->add($requiredJwt);
  $app->post('/subscription/stripe/upgrade/apply', [$stripeController, 'upgradeApply'])->add($requiredJwt);
  $app->post('/subscription/stripe/downgrade/apply', [$stripeController, 'downgradeApply'])->add($requiredJwt);
  $app->get('/subscription/stripe/downgrade/cancel', [$stripeController, 'downgradeCancel'])->add($requiredJwt);
  $app->delete('/subscription/stripe/cancel', [$stripeController, 'cancelSubscription'])->add($requiredJwt);
  $app->get('/subscription/stripe/resume', [$stripeController, 'resumeSubscription'])->add($requiredJwt);
  $app->get('/subscription/stripe/users/payment-method', [$stripeController, 'getUserPaymentMethod'])->add($requiredJwt);
  $app->post('/subscription/stripe/users/payment-method/billingSession', [$stripeController, 'createBillingPortalSession'])->add($requiredJwt);
};