<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Search {
    protected $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function searchOfferings($paginator, $query) {
        try {
            $searchQuery = "%$query%";
            $stmt = $this->pdo->prepare('SELECT * FROM Offerings
            WHERE Title LIKE :search1 OR Description LIKE :search2 OR Tags LIKE :search3
            LIMIT :_limit OFFSET :_offset');

            $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search3', $searchQuery, PDO::PARAM_STR);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function searchUsers($paginator, $query) {
        try {
            $searchQuery = "%$query%";
            $stmt = $this->pdo->prepare('SELECT * FROM Users
            WHERE FirstName LIKE :search1 OR LastName LIKE :search2 OR Biography LIKE :search3
            LIMIT :_limit OFFSET :_offset');

            $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
            $stmt->bindParam(':search3', $searchQuery, PDO::PARAM_STR);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }
}
