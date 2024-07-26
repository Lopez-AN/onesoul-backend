<?php

use Slim\App;
use App\Controllers\CategoryController;
use App\Models\Category;

return function (App $app) {
    $pdo = require __DIR__ . '/../../config/database.php';
	$category = new Category($pdo);
	$categoryController = new CategoryController($category);

    $app->get('/categories', [$categoryController, 'getCategories']);
    $app->get('/categories/{id}', [$categoryController, 'getCategoryById']);
    $app->get('/categories/parent/{id}', [$categoryController, 'getCategoryByParentId']);
    $app->post('/categories', [$categoryController, 'createCategory']);
    $app->put('/categories/{id}', [$categoryController, 'updateCategory']);
    $app->delete('/categories/{id}', [$categoryController, 'deleteCategory']);
};
