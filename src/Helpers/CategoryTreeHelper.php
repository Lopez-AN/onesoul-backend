<?php
namespace App\Helpers;

class CategoryTreeHelper {

  /**
   * SQL CTE para obtener la categoría raíz de cada categoría
   *
   * @return string: Fragmento SQL con el CTE
   */
  public static function getRootCategoryCTE(): string {
    return "category_up AS (
      SELECT CategoryID,
        ParentCategoryID,
        CategoryID AS OriginCategoryID
      FROM Categories
      UNION ALL
      SELECT
        p.CategoryID,
        p.ParentCategoryID,
        cu.OriginCategoryID
      FROM Categories as p
      INNER JOIN category_up as cu
        ON cu.ParentCategoryID = p.CategoryID
    ),
    category_root AS (
      SELECT
        OriginCategoryID AS CategoryID,
        CategoryID       AS RootCategoryID
      FROM category_up
      WHERE ParentCategoryID IS NULL
    )";
  }

  /**
   * SQL CTE para filtrar categorías (incluye subcategorías recursivamente)
   *
   * @return string: Fragmento SQL con el CTE
   */
  public static function getFilterCategoryCTE(): string {
    return "category_filter_tree AS (
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