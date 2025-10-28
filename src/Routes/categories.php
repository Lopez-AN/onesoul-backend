<?php

use Slim\App;
use App\Controllers\CategoryController;
use App\Models\Category;

return function (App $app) {
  # Proteccion de rutas
  $app->add(new Tuupola\Middleware\JwtAuthentication([
    "secret" => $GLOBALS['config']['jwt']['secret'],
    "rules" => [
      new Tuupola\Middleware\JwtAuthentication\RequestPathRule([
        "path" => "/categories",
        "ignore" => []
      ]),
      new Tuupola\Middleware\JwtAuthentication\RequestMethodRule([
        "ignore" => ["OPTIONS", "GET"]
      ])
    ],
    "attribute" => "jwt"
  ]));

  // Obtener PDO del contenedor DI
  $pdo = $app->getContainer()->get('pdo');
  $category = new Category($pdo);
  $categoryController = new CategoryController($category);

  $app->get('/categories', [$categoryController, 'getCategories']);
  $app->get('/categories/{id}', [$categoryController, 'getCategoryById']);
  $app->get('/categories/parent/{id}', [$categoryController, 'getCategoriesByParentId']);
  $app->post('/categories', [$categoryController, 'createCategory']);
  $app->put('/categories/{id}', [$categoryController, 'updateCategory']);
  $app->delete('/categories/{id}', [$categoryController, 'deleteCategory']);
};
