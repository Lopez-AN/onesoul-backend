<?php

$host = $GLOBALS['config']['mysql']['host'];
$db = $GLOBALS['config']['mysql']['db'];
$port = $GLOBALS['config']['mysql']['port'];
$user = $GLOBALS['config']['mysql']['username'];
$pass = $GLOBALS['config']['mysql']['password'];
$charset = 'utf8mb4';

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
  throw new \PDOException($e->getMessage(), (int) $e->getCode());
}
return $pdo;
