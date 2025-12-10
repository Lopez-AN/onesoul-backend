<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT', dirname(__FILE__)."/..");

require ROOT.'/vendor/autoload.php';

#Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

# Leo la config
$GLOBALS['config'] = @json_decode(file_get_contents(ROOT.'/config/config.json'),true);
if(!$GLOBALS['config']){
  http_response_code(500);
  exit("Error reading ~/config/config.json");
}

# Cargar la clase Database
require ROOT.'/src/Core/Database.php';

use Slim\Factory\AppFactory;
use Predis\Client as RedisClient;
use DI\Container;

# Crear contenedor explícitamente
$container = new Container();

# Registrar Redis
$container->set('redis', function() {
  return new RedisClient([
    'scheme' => 'tcp',
    'host'   => 'localhost',
    'port'   => 6379,
  ]);
});

# Registrar PDO como servicio único
$container->set('pdo', function() {
  return Database::getInstance()->getConnection();
});

# Pasar el contenedor a AppFactory
AppFactory::setContainer($container);
$app = AppFactory::create();

# Evita bloqueos de imagenes
$app->add(function ($request, $handler) {
  $response = $handler->handle($request);
  return $response->withAddedHeader(
    'Content-Security-Policy',
    "img-src * data: blob:;"
  );
});

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

(require ROOT . '/src/Routes/categories.php')($app);
(require ROOT . '/src/Routes/offerings.php')($app);
(require ROOT . '/src/Routes/donations.php')($app);
(require ROOT . '/src/Routes/users.php')($app);
(require ROOT . '/src/Routes/auth.php')($app);
(require ROOT . '/src/Routes/subscription.php')($app);
(require ROOT . '/src/Routes/bookings.php')($app);
(require ROOT . '/src/Routes/util.php')($app);
(require ROOT . '/src/Routes/stripe.php')($app);
(require ROOT . '/src/Routes/cal.php')($app);
(require ROOT . '/src/Routes/notification.php')($app);

$app->run();