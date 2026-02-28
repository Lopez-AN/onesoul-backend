<?php
namespace App\Helpers;

class CategoryTreeHelper {

  /**
   * SQL CTE para obtener la categoría raíz de cada categoría
   *
   * @param bool: addRecursive: agrega WITH RECURSVE al CTE
   * @return string: Fragmento SQL con el CTE
   */
  public static function getRootCategoryCTE(bool $addRecursive = false): string {
    return ($addRecursive ? "WITH RECURSIVE " : "").
      "category_up AS (
      SELECT CategoryID,
        ParentCategoryID,
        Name,
        Name as OriginName,
        CategoryID AS OriginCategoryID
      FROM Categories
      UNION ALL
      SELECT
        p.CategoryID,
        p.ParentCategoryID,
        p.Name,
        cu.OriginName,
        cu.OriginCategoryID
      FROM Categories as p
      INNER JOIN category_up as cu
        ON cu.ParentCategoryID = p.CategoryID
    ),
    category_root AS (
      SELECT
        OriginCategoryID AS CategoryID,
        OriginName       AS CategoryName,
        CategoryID       AS RootCategoryID,
        Name             AS RootCategoryName
      FROM category_up
      WHERE ParentCategoryID IS NULL
    ) ";
  }

  /**
   * SQL CTE para filtrar categorías (incluye subcategorías recursivamente)
   *
   * @param bool: addRecursive: agrega WITH RECURSVE al CTE
   * @return string: Fragmento SQL con el CTE
   */
  public static function getFilterCategoryCTE(bool $addRecursive = false): string {
    return ($addRecursive ? "WITH RECURSIVE " : "").
      "category_filter_tree AS (
      SELECT c.CategoryID
      FROM Categories c
      WHERE c.CategoryID = ?
      UNION ALL
      SELECT ch.CategoryID
      FROM Categories ch
      INNER JOIN category_filter_tree ft
        ON ch.ParentCategoryID = ft.CategoryID
    ) ";
  }
}