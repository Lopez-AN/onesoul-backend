<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Exceptions\DatabaseException;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;

class Chatbot {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }
}