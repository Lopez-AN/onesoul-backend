<?php

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

# Leo la config
$GLOBALS['config'] = @json_decode(file_get_contents(__DIR__ . '/../config/config.json'),true);
if(!$GLOBALS['config']){
  http_response_code(500);
  exit("Error reading ~/config/config.json");
}

use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

(require __DIR__ . '/../src/Routes/categories.php')($app);
(require __DIR__ . '/../src/Routes/offerings.php')($app);
(require __DIR__ . '/../src/Routes/users.php')($app);
(require __DIR__ . '/../src/Routes/search.php')($app);
(require __DIR__ . '/../src/Routes/auth.php')($app);

$app->run();