<?php

use Slim\App;
use App\Controllers\OfferingController;
use App\Models\Offering;
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
	$offering = new Offering($pdo);
  $subscription = new Subscription($pdo);
	$offeringController = new OfferingController($offering, $subscription);

  $app->get('/offerings', [$offeringController, 'getOfferings']);
  $app->get('/categories/{categoryID}/offerings', [$offeringController, 'getOfferingsByCategory']);
  $app->get('/users/{userID}/offerings', [$offeringController, 'getOfferingsByUserId']);
  $app->get('/search/offerings', [$offeringController, 'searchOfferings']);
  $app->get('/offerings/{id}', [$offeringController, 'getOfferingById']);
  $app->post('/offerings', [$offeringController, 'createOffering'])->add($requiredJwt);
  $app->patch('/offerings/{id}', [$offeringController, 'updateOffering'])->add($requiredJwt);
  $app->delete('/offerings/{id}', [$offeringController, 'deleteOffering'])->add($requiredJwt);
  $app->post('/offerings/{id}/media', [$offeringController, 'createOfferingMedia'])->add($requiredJwt);
  $app->post('/offerings/{id}/media/{mediaID}/{position}', [$offeringController, 'updateOfferingMedia'])->add($requiredJwt);
  $app->delete('/offerings/{id}/media/{mediaID}', [$offeringController, 'deleteOfferingMedia'])->add($requiredJwt);
  $app->patch('/offerings/approve/{id}', [$offeringController, 'approveOffering'])->add($requiredJwt);
};
