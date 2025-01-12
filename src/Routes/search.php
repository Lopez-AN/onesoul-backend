<?php

use Slim\App;
use App\Controllers\SearchController;
use App\Models\Search;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => "/search",
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ]
  ]));

  $pdo = require __DIR__ . './../core/database.php';
  $search = new Search($pdo);
  $searchController = new SearchController($search);

  $app->get('/search/users', [$searchController, 'searchUsers']);
  $app->get('/search/offerings', [$searchController, 'searchOfferings']);
  $app->get('/search/categories', [$searchController, 'searchCategories']);

};
