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

  /**
   * Obtiene todas las categorías con paginación e imagen asociada
   * @param  object $paginator: objeto con limit y offset para paginación
   * @return object: objeto con data (array de categorías) y rows (total y fetched)
   **/
  public function getCategories($paginator) {
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS c.*,m.URL as imgURL
      FROM Categories AS c
      LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
      ORDER BY c.CategoryID
      LIMIT ? OFFSET ?");

    $stmt->execute([$paginator->limit, $paginator->offset]);

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
  }


  /**
   * Busca categorías por término de búsqueda con paginación
   * @param  object $paginator: objeto con limit y offset
   * @param  string $query: término(s) de búsqueda
   * @return object: { data: [], rows: { total: int, fetched: int } }
   **/
  public function searchCategories($paginator, $query) {
    $searchQuery = "%$query%";
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS c.*,m.URL AS ImgURL
      FROM Categories AS c
      LEFT JOIN Media AS m ON c.CategoryID = m.CategoryID
      WHERE (c.Name LIKE ? OR c.Description LIKE ?) AND c.IsActive = 1
      ORDER BY c.CategoryID
      LIMIT ? OFFSET ?");

    $stmt->execute([$searchQuery, $searchQuery, $paginator->limit, $paginator->offset]);

    $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return (object) [
      "data" => $rs,
      "rows" => [
        "total" => $total['total'],
        "fetched" => count($rs)
      ]
    ];
  }

  /**
   * Obtiene una categoría por su ID con imagen asociada
   * @param  int $categoryID: ID de la categoría
   * @return array|false: array asociativo con datos de la categoría o false si no existe
   **/
  public function getCategoryById($categoryID) {
    $stmt = $this->db->prepare("SELECT c.*, m.URL as imgURL
      FROM Categories AS c
      LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
      WHERE c.CategoryID = ?");

    $stmt->execute([$categoryID]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Obtiene categorías por lista de IDs
   * @param  array $categories: array de IDs de categorías
   * @return array: array de categorías encontradas
   **/
  public function getCategoriesByIds($categories) {
    # Usar placeholders dinámicos para la query
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $query = "SELECT CategoryID FROM Categories WHERE CategoryID IN ($placeholders)";
    $stmt = $this->db->prepare($query);
    $stmt->execute($categories);

    return $stmt->fetchAll();
  }

  /**
   * Obtiene categorías hijo por ID de categoría padre con paginación
   * @param  object $paginator: objeto con limit y offset para paginación
   * @param  int|null $categoryID: ID de categoría padre (null para categorías raíz)
   * @return object: objeto con data (array de categorías) y rows (total y fetched)
   **/
  public function getCategoriesByParentId($paginator, $categoryID) {
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS c.*, m.URL as imgURL
      FROM Categories AS c
      LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
      WHERE (c.ParentCategoryID = ? OR (? IS NULL AND c.ParentCategoryID IS NULL))
      ORDER BY c.CategoryID
      LIMIT ? OFFSET ?");

    $stmt->execute([$categoryID, $categoryID, $paginator->limit, $paginator->offset]);

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
  }

  /**
   * Crea una nueva categoría en la base de datos
   * @param  int|null $parentCategoryID: ID de categoría padre (null para raíz)
   * @param  string $name: nombre de la categoría
   * @param  string $description: descripción de la categoría
   * @param  bool $isActive: estado activo de la categoría
   * @return array: array con datos de la categoría creada
   * @throws DatabaseException: si hay error en la base de datos
   **/
  public function createCategory($parentCategoryID, $name, $description, $isActive) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("INSERT INTO Categories (ParentCategoryID, Name, Description, CreationDate, IsActive)
        VALUES (?, ?, ?, ?, ?)");
      $stmt->execute([$parentCategoryID, $name, $description, date('Y-m-d H:i:s'), $isActive]);
      $category = $this->getCategoryById($this->db->lastInsertId());

      $this->db->commit(); # Confirmo transacción
      return $category;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Actualiza una categoría existente
   * @param  int $categoryID: ID de la categoría a actualizar
   * @param  int|null $parentCategoryID: ID de categoría padre (null para raíz)
   * @param  string $name: nuevo nombre de la categoría
   * @param  string $description: nueva descripción de la categoría
   * @param  bool $isActive: nuevo estado activo de la categoría
   * @return array: array con datos de la categoría actualizada
   * @throws DatabaseException: si hay error en la base de datos
   **/
  public function updateCategory($categoryID, $parentCategoryID, $name, $description, $isActive) {
    try{
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE Categories SET ParentCategoryID = ?, Name = ?, Description = ?,
        ModificationDate = ?, IsActive = ? WHERE CategoryID = ?");
      $stmt->execute([$parentCategoryID, $name, $description, date('Y-m-d H:i:s'), $isActive]);
      $category = $this->getCategoryById($categoryID);

      $this->db->commit(); # Confirmo transacción
      return $category;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Elimina una categoría de la base de datos
   * @param  int $categoryID: ID de la categoría a eliminar
   **/
  public function deleteCategory($categoryID) {
    $stmt = $this->db->prepare("DELETE FROM Categories WHERE CategoryID = ?");
    $stmt->execute([$categoryID]);
  }
}
