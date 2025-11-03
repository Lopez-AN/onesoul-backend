<?php

use Slim\App;
use App\Controllers\CategoryController;
use App\Models\Category;
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
  $category = new Category($pdo);
  $categoryController = new CategoryController($category);

  $app->get('/categories', [$categoryController, 'getCategories']);
  $app->get('/categories/parent/{id}', [$categoryController, 'getCategoriesByParentId']);
  $app->get('/search/categories', [$categoryController, 'searchCategories']);
  $app->get('/categories/{id}', [$categoryController, 'getCategoryById']);
  $app->post('/categories', [$categoryController, 'createCategory'])->add($requiredJwt);
  $app->put('/categories/{id}', [$categoryController, 'updateCategory'])->add($requiredJwt);
  $app->delete('/categories/{id}', [$categoryController, 'deleteCategory'])->add($requiredJwt);
};
