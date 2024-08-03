<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Category {
    protected $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getCategories($paginator) {
        try {
            $stmt = $this->db->prepare("SELECT c.*, m.URL FROM Categories AS c
            LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getCategoryById($paginator, $id) {
       try {
            $stmt = $this->db->prepare("SELECT c.*, m.URL FROM Categories AS c
	        LEFT JOIN Media as m ON c.CategoryID = m.CategoryID WHERE c.CategoryID = :id
            LIMIT :_limit OFFSET :_offset");

            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
            $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function getCategoryByParentId($paginator, $id) {
        try {
            if(is_null($id)){
                $stmt = $this->db->prepare("SELECT c.*, m.URL FROM Categories AS c
	            LEFT JOIN Media as m ON c.CategoryID = m.CategoryID WHERE c.ParentCategoryID is null
                LIMIT :_limit OFFSET :_offset");

                $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
                $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            }else{
                $stmt = $this->db->prepare("SELECT c.*, m.URL FROM Categories AS c
	            LEFT JOIN Media as m ON c.CategoryID = m.CategoryID WHERE c.ParentCategoryID = :id
                LIMIT :_limit OFFSET :_offset");

                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
                $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
            }

            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function createCategory($data) {
        $this->validateCategory($data);
        try {
            $stmt = $this->db->prepare("INSERT INTO Categories (CategoryName, Description, ParentCategoryID) VALUES (:CategoryName, :Description, :ParentCategoryID)");
            $stmt->execute($data);
            return $this->getCategoryById($this->db->lastInsertId());
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function updateCategory($id, $data) {
        $this->validateCategory($data);
        try {
            $stmt = $this->db->prepare("UPDATE Categories SET CategoryName = :CategoryName, Description = :Description, ParentCategoryID = :ParentCategoryID WHERE CategoryID = :CategoryID");
            $data['CategoryID'] = $id;
            $stmt->execute($data);
            return $this->getCategoryById($id);
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    public function deleteCategory($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM Categories WHERE CategoryID = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new DatabaseException($e->getMessage());
        }
    }

    private function validateCategory($data) {
        if (empty($data['CategoryName'])) {
            throw new ValidationException('Category name is required');
        }
        // Agregar más validaciones según sea necesario
    }
}
