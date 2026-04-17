<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die('Este script solo puede ejecutarse desde la línea de comandos');
}

require ROOT.'/vendor/autoload.php';

use Predis\Client as RedisClient;

# Definir zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

# Leo la config
$GLOBALS['config'] = @json_decode(file_get_contents(ROOT.'/config/config.json'),true);
if(!$GLOBALS['config']){
  http_response_code(500);
  exit("Error reading ~/config/config.json");
}

# Conexión a la base de datos usando la config
try {
  require ROOT.'/src/Core/Database.php';
  $pdo = Database::getInstance()->getConnection();
} catch (PDOException $e) {
  die("❌ Cannot connect to MySQL: " . $e->getMessage() . "\n");
}

# Conexión Redis (opcional para locks de workers)
try {
  $redis = new RedisClient([
    'scheme' => 'tcp',
    'host' => 'localhost',
    'port' => 6379,
  ]);
} catch (\Throwable $e) {
  $redis = null;
  print("⚠️ Cannot connect to Redis: {$e->getMessage()}\n");
}
