<?php

use Slim\App;
use App\Controllers\CategoryController;
use App\Models\Category;
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
