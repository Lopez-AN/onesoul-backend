<?php

use Slim\App;
use App\Controllers\SearchController;
use App\Models\Search;

return function (App $app) {
    $pdo = require __DIR__ . '/../../config/database.php';
    $search = new Search($pdo);
    $searchController = new SearchController($search);

    $app->get('/search/users', [$searchController, 'searchUsers']);
    $app->get('/search/offerings', [$searchController, 'searchOfferings']);

};
