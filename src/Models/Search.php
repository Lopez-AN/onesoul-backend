<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Search {
    protected $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function searchOfferings($query) {
        try {
            $searchQuery = "%$query%";
            $stmt = $this->pdo->prepare('SELECT * FROM Offerings WHERE Title LIKE ? OR Description LIKE ? OR Tags LIKE ?');
            $stmt->execute([$searchQuery, $searchQuery, $searchQuery]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function searchUsers($query) {
        try {
            $searchQuery = "%$query%";
            $stmt = $this->pdo->prepare('SELECT * FROM Users WHERE FirstName LIKE ? OR LastName LIKE ? OR Biography LIKE ?');
            $stmt->execute([$searchQuery, $searchQuery, $searchQuery]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }
}
