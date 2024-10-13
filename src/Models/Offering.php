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

    public function getOfferings($paginator) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingById($paginator, $id) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE o.OfferingID = :id
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingsByCategoryId($paginator, $categoryId) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE o.CategoryID = :categoryId
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getOfferingsByUserId($paginator, $userId) {
        try {
            $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, m1.URL as imgURL,
            u.UserID as author_UserID, u.FirstName as author_FirstName,
            u.LastName as author_LastName, m2.URL as author_imgURL
            FROM Offerings AS o
            INNER JOIN Users AS u ON u.UserID = o.UserID
            LEFT JOIN Media AS m1 ON o.OfferingID = m1.OfferingID
            LEFT JOIN Media AS m2 ON u.UserID = m2.UserID
            WHERE o.UserID = :userId
            ORDER BY o.OfferingID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);

            $rs = array_map(function($e){
                $e['author'] = [
                    "UserID" => $e['author_UserID'],
                    "FirstName" => $e['author_FirstName'],
                    "LastName" => $e['author_LastName'],
                    "imgURL" => $e['author_imgURL']
                ];
                unset($e['author_UserID']);
                unset($e['author_FirstName']);
                unset($e['author_LastName']);
                unset($e['author_imgURL']);
                return $e;
            },$rs);

            return [
                "data" => $rs,
                "rows" => [
                    "total" => $total['total'],
                    "fetched" => count($rs)
                ]
            ];
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function createOffering($data) {
        $this->validateOffering($data);
        $paginator = (object) [
            'limit' => 1,   // Limita a un solo registro
            'offset' => 0   // No usa ningún desplazamiento
        ];

        try {
            $stmt = $this->db->prepare("INSERT INTO Offerings (Title, Description, CategoryID, CreationDate, ModificationDate, Tags, SKU, Stock, ServiceType)
            VALUES (:Title, :Description, :CategoryID, :CreationDate, :ModificationDate, :Tags, :SKU, :Stock, :ServiceType)");
            $stmt->execute($data);
            return $this->getOfferingById($paginator, $this->db->lastInsertId());
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateOffering($id, $data) {
        $this->validateOffering($data);
        $paginator = (object) [
            'limit' => 1,   // Limita a un solo registro
            'offset' => 0   // No usa ningún desplazamiento
        ];

        try {
            $stmt = $this->db->prepare("UPDATE Offerings SET Title = :Title, Description = :Description, CategoryID = :CategoryID, 
            UserID = :UserID, Status = :Status, CreationDate = :CreationDate, ModificationDate = :ModificationDate, 
            AverageRating = :AverageRating, TotalReviews = :TotalReviews, IsActive = :IsActive, Tags = :Tags, SKU =:SKU, 
            Stock = :Stock, ServiceType =:ServiceType
            WHERE OfferingID = :OfferingID");
            $data['OfferingID'] = $id;
            $stmt->execute($data);
            return $this->getOfferingById($paginator, $id);
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
