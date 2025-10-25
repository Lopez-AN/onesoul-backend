<?php

class Database {
  private static $instance = null;
  private $pdo;

  private function __construct() {
    $host = $GLOBALS['config']['mysql']['host'];
    $db = $GLOBALS['config']['mysql']['db'];
    $port = $GLOBALS['config']['mysql']['port'];
    $user = $GLOBALS['config']['mysql']['username'];
    $pass = $GLOBALS['config']['mysql']['password'];

    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
      $this->pdo = new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
      throw new \PDOException($e->getMessage(), (int) $e->getCode());
    }
  }

  public static function getInstance() {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function getConnection() {
    return $this->pdo;
  }

  // Evitar clonación
  public function __clone() {}

  // Evitar deserialización
  public function __wakeup() {}
}