<?php

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Chatbot {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }
}