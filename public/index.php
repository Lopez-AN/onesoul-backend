<?php

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT', dirname(__FILE__)."/..");

# Leo la config
$GLOBALS['config'] = @json_decode(file_get_contents(ROOT.'/config/config.json'),true);
if(!$GLOBALS['config']){
  http_response_code(500);
  exit("Error reading ~/config/config.json");
}

use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

(require ROOT . '/src/Routes/categories.php')($app);
(require ROOT . '/src/Routes/offerings.php')($app);
(require ROOT . '/src/Routes/users.php')($app);
(require ROOT . '/src/Routes/search.php')($app);
(require ROOT . '/src/Routes/auth.php')($app);
(require ROOT . '/src/Routes/subscription.php')($app);
(require ROOT . '/src/Routes/bookings.php')($app);

$app->run();

