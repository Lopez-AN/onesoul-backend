<?php

use Slim\App;
use App\Controllers\OfferingController;
use App\Models\Offering;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      // Regla general para proteger todas las rutas excepto las que se especifican a continuación
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => [
          "/offerings",
          "/offerings/{id}",
          "/offerings/{id}/media",
          "/offerings/{id}/media/{mediaID}",
          "/categories/{categoryID}/offerings",
          "/users/{userID}/offerings",
          "/offerings/approve/{id}"
        ]
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ],
    "attribute" => "jwt"
  ]));

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
	$offering = new Offering($pdo);
	$offeringController = new OfferingController($offering);

  $app->get('/offerings', [$offeringController, 'getOfferings']);
  $app->post('/offerings', [$offeringController, 'createOffering']);
  $app->get('/offerings/{id}', [$offeringController, 'getOfferingById']);
  $app->patch('/offerings/{id}', [$offeringController, 'updateOffering']);
  $app->delete('/offerings/{id}', [$offeringController, 'deleteOffering']);
  $app->post('/offerings/{id}/media/{position}', [$offeringController, 'createOfferingMedia']);
  $app->post('/offerings/{id}/media/{mediaID}/{position}', [$offeringController, 'updateOfferingMedia']);
  $app->delete('/offerings/{id}/media/{mediaID}', [$offeringController, 'deleteOfferingMedia']);
  $app->get('/categories/{categoryID}/offerings', [$offeringController, 'getOfferingsByCategoryId']);
  $app->get('/users/{userID}/offerings', [$offeringController, 'getOfferingsByUserId']);
  $app->patch('/offerings/approve/{id}', [$offeringController, 'approveOffering']);
};
