<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Auth{
  protected $db;

  public function __construct(PDO $db){
    $this->db = $db;
  }

  public function login($username, $password){
    try {
      $stmt = $this->db->prepare("SELECT u.* FROM Users AS u
	    WHERE u.UserName = ? AND u.PasswordHash = ?");
      $stmt->execute([$username, $password]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}
