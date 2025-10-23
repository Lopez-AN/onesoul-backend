<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Category
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function getCategories($paginator)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS c.*,m.URL as imgURL
            FROM Categories AS c
            LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
            ORDER BY c.CategoryID
            LIMIT :_limit OFFSET :_offset");

      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      return (object) [
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

  public function getCategoryById($paginator, $id)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS c.*, m.URL as imgURL
            FROM Categories AS c
	        LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
            WHERE c.CategoryID = :id
            LIMIT :_limit OFFSET :_offset");

      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      return (object) [
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

  public function getCategoryByParentId($paginator, $id)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS c.*, m.URL as imgURL
            FROM Categories AS c
            LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
            WHERE (c.ParentCategoryID = :id OR (:id2 IS NULL AND c.ParentCategoryID IS NULL))
            ORDER BY c.CategoryID
            LIMIT :_limit OFFSET :_offset");

      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->bindParam(':id2', $id, PDO::PARAM_INT);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);

      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      return (object) [
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

  public function createCategory($data)
  {
    $this->validateCategory($data);
    $paginator = (object) [
      'limit' => 1,   // Limita a un solo registro
      'offset' => 0   // No usa ningún desplazamiento
    ];

    try {
      $stmt = $this->db->prepare("INSERT INTO Categories (ParentCategoryID, Name, Description, CreationDate, ModificationDate, IsActive) 
            VALUES (:ParentCategoryID, :Name, :Description, :CreationDate, :ModificationDate, :IsActive)");
      $stmt->execute($data);
      return $this->getCategoryById($paginator, $this->db->lastInsertId());
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateCategory($id, $data)
  {
    $this->validateCategory($data);
    $paginator = (object) [
      'limit' => 1,   // Limita a un solo registro
      'offset' => 0   // No usa ningún desplazamiento
    ];
    try {
      $stmt = $this->db->prepare("UPDATE Categories SET ParentCategoryID = :ParentCategoryID, Name = :Name, 
            Description = :Description, CreationDate = :CreationDate, ModificationDate = :ModificationDate, IsActive = :IsActive
            WHERE CategoryID = :CategoryID");
      $data['CategoryID'] = $id;
      $stmt->execute($data);
      return $this->getCategoryById($paginator, $id);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function deleteCategory($id)
  {
    try {
      $stmt = $this->db->prepare("DELETE FROM Categories WHERE CategoryID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  private function validateCategory($data)
  {
    if (empty($data['Name'])) {
      throw new ValidationException('Category name is required');
    }
    // Agregar más validaciones según sea necesario
  }
}
