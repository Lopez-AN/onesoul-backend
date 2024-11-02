<?php

use Slim\App;
use App\Controllers\OfferingController;
use App\Models\Offering;

return function (App $app) {
    # Proteccion de rutas
    $app->add(new Tuupola\Middleware\JwtAuthentication([
        "secret" => $GLOBALS['config']['jwt']['secret'],
        "rules" => [
            new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
                "path" => "/offerings",
                "ignore" => []
            ]),
            new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
                "ignore" => ["OPTIONS","GET"]
            ])
            ],
        "attribute" => "jwt", // Este atributo lo podes usar para leer el token desde el controller
    ]));

    $pdo = require __DIR__ . './../core/database.php';
	$offering = new Offering($pdo);
	$offeringController = new OfferingController($offering);

    $app->get('/offerings', [$offeringController, 'getOfferings']);
    $app->get('/offerings/{id}', [$offeringController, 'getOfferingById']);
    $app->get('/categories/{categoryID}/offerings', [$offeringController, 'getOfferingsByCategoryId']);
    $app->get('/users/{userID}/offerings', [$offeringController, 'getOfferingsByUserId']);
    $app->post('/offerings', [$offeringController, 'createOffering']);
    $app->put('/offerings/{id}', [$offeringController, 'updateOffering']);
    $app->delete('/offerings/{id}', [$offeringController, 'deleteOffering']);
};
