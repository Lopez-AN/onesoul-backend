<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

(require __DIR__ . '/../src/Routes/categories.php')($app);
(require __DIR__ . '/../src/Routes/offerings.php')($app);
(require __DIR__ . '/../src/Routes/users.php')($app);
(require __DIR__ . '/../src/Routes/search.php')($app);


$app->run();
