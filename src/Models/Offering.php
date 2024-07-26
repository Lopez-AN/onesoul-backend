<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Offering {
    protected $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getOfferings() {
        try {
            $stmt = $this->db->query("SELECT o.*, m.URL FROM Offerings AS o 
            LEFT JOIN Media as m ON o.OfferingID = m.OfferingID");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingById($id) {
        try {
            $stmt = $this->db->prepare("SELECT o.*, m.URL FROM Offerings AS o 
            LEFT JOIN Media as m ON o.OfferingID = m.OfferingID WHERE o.OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingsByCategoryId($categoryId) {
        try {
            $stmt = $this->db->prepare("SELECT o.*, m.URL FROM Offerings AS o 
            LEFT JOIN Media as m ON o.OfferingID = m.OfferingID WHERE o.CategoryID = :categoryId");
            $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingsByUserId($userId) {
        try {
            $stmt = $this->db->prepare("SELECT o.*, m.URL FROM Offerings AS o 
            LEFT JOIN Media as m ON o.OfferingID = m.OfferingID WHERE o.UserID = :userId");
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }
    
    public function createOffering($data) {
        $this->validateOffering($data);
        try {
            $stmt = $this->db->prepare("INSERT INTO Offerings (Title, Description, CategoryID, Price, CreatedAt, UpdatedAt) 
            VALUES (:Title, :Description, :CategoryID, :Price, :CreatedAt, :UpdatedAt)");
            $stmt->execute($data);
            return $this->getOfferingById($this->db->lastInsertId());
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateOffering($id, $data) {
        $this->validateOffering($data);
        try {
            $stmt = $this->db->prepare("UPDATE Offerings SET Title = :Title, Description = :Description, CategoryID = 
            :CategoryID, Price = :Price, CreatedAt = :CreatedAt, UpdatedAt = :UpdatedAt WHERE OfferingID = :OfferingID");
            $data['OfferingID'] = $id;
            $stmt->execute($data);
            return $this->getOfferingById($id);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteOffering($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM Offerings WHERE OfferingID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    private function validateOffering($data) {
        if (empty($data['Title'])) {
            throw new ValidationException('Title is required');
        }
    }
}
