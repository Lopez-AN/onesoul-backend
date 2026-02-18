<?php

use Slim\App;
use App\Controllers\OfferingController;
use App\Models\Offering;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Currency;
use JimTools\JwtAuth\Middleware\JwtAuthentication;
use JimTools\JwtAuth\Decoder\FirebaseDecoder;
use JimTools\JwtAuth\Options;
use JimTools\JwtAuth\Secret;
use App\Middleware\JwtTokenMiddleware;
use App\Enums\JwtValidationMode;

return function (App $app) {
  $jwtMiddleware = new JwtAuthentication(
    new Options(),
    new FirebaseDecoder(new Secret($GLOBALS['config']['jwt']['secret'], 'HS256'))
  );

  $requiredJwt = new JwtTokenMiddleware($jwtMiddleware, JwtValidationMode::REQUIRED);

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
	$offering = new Offering($pdo);
  $user = new User($pdo);
  $subscription = new Subscription($pdo);
  $currency = new Currency($pdo);
	$offeringController = new OfferingController($offering, $user, $subscription, $currency);

  $app->get('/offerings', [$offeringController, 'getOfferings']);
  $app->get('/categories/{CategoryID}/offerings', [$offeringController, 'getOfferingsByCategory']);
  $app->get('/users/{UserID}/offerings', [$offeringController, 'getOfferingsByUserId']);
  $app->get('/search/offerings', [$offeringController, 'searchOfferings']);
  $app->get('/offerings/{OfferingID}', [$offeringController, 'getOfferingById']);
  $app->post('/offerings', [$offeringController, 'createOffering'])->add($requiredJwt);
  $app->patch('/offerings/{OfferingID}', [$offeringController, 'updateOffering'])->add($requiredJwt);
  $app->patch('/offerings/{OfferingID}/enable', [$offeringController, 'enableOffering'])->add($requiredJwt);
  $app->patch('/offerings/{OfferingID}/disable', [$offeringController, 'disableOffering'])->add($requiredJwt);
  $app->delete('/offerings/{OfferingID}', [$offeringController, 'deleteOffering'])->add($requiredJwt);
  $app->post('/offerings/{OfferingID}/media', [$offeringController, 'createOfferingMedia'])->add($requiredJwt);
  $app->post('/offerings/{OfferingID}/media/{MediaID}', [$offeringController, 'updateOfferingMedia'])->add($requiredJwt);
  $app->delete('/offerings/{OfferingID}/media/{MediaID}', [$offeringController, 'deleteOfferingMedia'])->add($requiredJwt);
  $app->patch('/offerings/approve/{OfferingID}', [$offeringController, 'approveOffering'])->add($requiredJwt);
};
