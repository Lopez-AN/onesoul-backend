<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Enums\MediaType;
use App\Helpers\CategoryTreeHelper;

class Offering {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene todas las publicaciones con paginación
   *
   * Retorna publicaciones activas con información del autor, media, FAQs y calificaciones.
   * Incluye detalles completos de images, videos y preguntas frecuentes.
   *
   * @param  object $paginator: objeto con limit y offset para paginación
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function getOfferings($paginator) {
    $stmt = $this->db->prepare(
      CategoryTreeHelper::getRootCategoryCTE(true).
      $this->_sqlMain().
      "WHERE o.Status = 'Active' AND o.Approved = 1
      GROUP BY o.OfferingID
      ORDER BY o.OfferingID
      LIMIT ? OFFSET ?"
    );

    $stmt->execute([$paginator->limit, $paginator->offset]);
    $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingsGenericMulti($offerings, $total['total']);
  }

  /**
   * Busca publicaciones por término de búsqueda con paginación
   *
   * Busca en título, descripción, descripción corta y tags. Retorna solo publicaciones
   * con estado 'Active' que coincidan con el término de búsqueda.
   *
   * @param  object $paginator: objeto con limit y offset
   * @param  string $query: término(s) de búsqueda
   * @param  int $categoryID: categoria a filtrar (default null)
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function searchOfferings($paginator, $query, $category = null) {
    $searchQuery = "%$query%";

    $stmt = $this->db->prepare(
      CategoryTreeHelper::getRootCategoryCTE(true).
      ($category !== null ? ','. CategoryTreeHelper::getFilterCategoryCTE() : '').
      $this->_sqlMain().
      ($category !== null ? ' INNER JOIN category_filter_tree as ct
        ON ct.CategoryID = o.CategoryID ' : '').
      "WHERE (o.Title LIKE ? OR o.Description LIKE ?
      OR o.ShortDescription LIKE ? OR o.Tags LIKE ?)
      AND o.Status = 'Active' AND o.Approved = 1
      GROUP BY o.OfferingID
      ORDER BY o.OfferingID
      LIMIT ? OFFSET ?"
    );

    $params = [$searchQuery, $searchQuery, $searchQuery, $searchQuery,
      $paginator->limit, $paginator->offset];
    if($category !== null){
      array_unshift($params, $category);
    }

    $stmt->execute($params);
    $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingsGenericMulti($offerings, $total['total']);
  }

  /**
   * Obtiene una publicación por su ID
   *
   * Retorna información completa de la publicación incluyendo autor, media, FAQs,
   * calificaciones y número total de bookings.
   *
   * @param  int $offeringID: ID de la publicación
   * @return array|false: datos de la publicación normalizados o false si no existe
   * @throws DatabaseException
   **/
  public function getOfferingById($offeringID) {
    $stmt = $this->db->prepare(
      CategoryTreeHelper::getRootCategoryCTE(true).
      $this->_sqlMainSingle().
      "WHERE o.OfferingID = ?
      GROUP BY o.OfferingID"
    );

    $stmt->execute([$offeringID]);
    $offering = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingGeneric($offering);
  }


  /**
   * Obtiene publicaciones de una categoría específica con paginación
   *
   * Utiliza búsqueda recursiva de categorías para incluir subcategorías.
   * Retorna solo publicaciones con estado 'Active'.
   *
   * @param  object $paginator: objeto con limit y offset
   * @param  int $category: ID de la categoría (incluye subcategorías)
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function getOfferingsByCategory($paginator, $category) {
    $stmt = $this->db->prepare(
      CategoryTreeHelper::getRootCategoryCTE(true).','.
      CategoryTreeHelper::getFilterCategoryCTE().
      $this->_sqlMain().
      "INNER JOIN category_filter_tree as ct
        ON ct.CategoryID = o.CategoryID
      WHERE o.Status = 'Active' AND o.Approved = 1
      GROUP BY o.OfferingID
      ORDER BY o.OfferingID
      LIMIT ? OFFSET ?"
    );

    $stmt->execute([$category, $paginator->limit, $paginator->offset]);

    $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingsGenericMulti($offerings, $total['total']);
  }

  /**
   * Obtiene publicaciones de un usuario específico con paginación
   *
   * Retorna todas las publicaciones creadas por un usuario, incluyendo
   * información del autor, media, FAQs y calificaciones.
   *
   * @param  object $paginator: objeto con limit y offset
   * @param  int $userID: ID del usuario (propietario de las publicaciones)
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function getOfferingsByUserId($paginator, $userID) {
    $stmt = $this->db->prepare(
      CategoryTreeHelper::getRootCategoryCTE(true).
      $this->_sqlMain().
      "WHERE o.UserID = ?
      GROUP BY o.OfferingID
      ORDER BY o.OfferingID
      LIMIT ? OFFSET ?"
    );

    $stmt->execute([$userID, $paginator->limit, $paginator->offset]);
    $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingsGenericMulti($offerings, $total['total']);
  }

  /**
   * Generaliza la consulta principal de obtener offerings
   *
   * @return string: consulta principal sin filtros
   **/
  private function _sqlMainSingle() {
    return str_replace('SQL_CALC_FOUND_ROWS ', '', $this->_sqlMain());
  }
  private function _sqlMain(){
    return "SELECT SQL_CALC_FOUND_ROWS o.*,
      c.Name as CategoryName,
      cr.RootCategoryID,
      cr.RootCategoryName,
      u.UserID      AS author_UserID,
      u.DisplayName AS author_DisplayName,
      u.UserName    AS author_UserName,
      ROUND(AVG(ru.Rating), 2)       AS author_Rating,
      COUNT(DISTINCT ru.ReviewID)    AS author_TotalReviews,
      au.author_ImgURL               AS author_ImgURL,
      mi.media_images                AS media_images,
      mv.media_videos                AS media_videos,
      fq.Faqs                        AS Faqs,
      ol.Locations                   AS Locations,
      ROUND(AVG(r.Rating), 2)        AS Rating,
      COUNT(DISTINCT r.ReviewID)     AS TotalReviews,
      COUNT(DISTINCT b.BookingID)    AS Bookings
    FROM Offerings o
    INNER JOIN Users u ON u.UserID = o.UserID
    INNER JOIN Categories as c ON c.CategoryID = o.CategoryID
    LEFT JOIN category_root as cr
      ON cr.CategoryID = o.CategoryID
    LEFT JOIN (
      SELECT UserID, MIN(URL) AS author_ImgURL
      FROM Media
      GROUP BY UserID
    ) au ON au.UserID = u.UserID
    LEFT JOIN (
      SELECT OfferingID,
        JSON_ARRAYAGG(JSON_OBJECT(
          'Id', MediaID,'Url',URL,'Title',Title,'Description',Description,'Position',Position
        )) AS media_images
      FROM Media
      WHERE MediaType='image'
      GROUP BY OfferingID
    ) mi ON mi.OfferingID = o.OfferingID
    LEFT JOIN (
      SELECT OfferingID,
        JSON_ARRAYAGG(JSON_OBJECT(
          'Id', MediaID,'Url',URL,'Title',Title,'Description',Description,'Position',Position
        )) AS media_videos
      FROM Media
      WHERE MediaType='video'
      GROUP BY OfferingID
    ) mv ON mv.OfferingID = o.OfferingID
    LEFT JOIN (
      SELECT OfferingID,
        JSON_ARRAYAGG(JSON_OBJECT(
          'Position',Position,'Question',Question,'Answer',Answer
        )) AS Faqs
      FROM OfferingsFaqs
      GROUP BY OfferingID
    ) fq ON fq.OfferingID = o.OfferingID
    LEFT JOIN (
      SELECT ol.OfferingID,
        JSON_ARRAYAGG(JSON_OBJECT(
          'LocationID', ul.LocationID,
          'LocationName', ul.LocationName,
          'CountryCode', ul.CountryCode,
          'State', ul.State,
          'City', ul.City
        )) AS Locations
      FROM OfferingsLocations ol
      INNER JOIN UsersLocations ul
        ON ul.UserID = ol.UserID
        AND ul.LocationID = ol.LocationID
      WHERE ul.IsActive = 1
      GROUP BY ol.OfferingID
    ) ol ON ol.OfferingID = o.OfferingID
    LEFT JOIN Reviews r  ON o.OfferingID = r.OfferingID
    LEFT JOIN Reviews ru ON u.UserID = ru.SeekerID
    LEFT JOIN Bookings b
      ON b.OfferingID = o.OfferingID
    AND b.LastBookingEvent IN ('completed','rated') ";
  }

  /**
   * Procesa y normaliza datos de una publicación individual
   *
   * Realiza conversiones de tipos de datos, decodificación de JSON de media y FAQs,
   * y agrupa información del autor. Los datos retornados no están filtrados.
   *
   * @param  array|null $offering: datos de la publicación obtenidos de la BD o null
   * @return array|false: datos de la publicación normalizados o false si no existe
   **/
  private function _getOfferingGeneric($offering){
    if (empty($offering)) {
      return false;
    }

    $offering['Approved'] = (bool)$offering['Approved'];
    $offering['Media'] = [
      'Images' => [],
      'Videos' => []
    ];

    $images = @json_decode($offering['media_images'], true);
    if($images){
      $offering['Media']['Images'] = $images;
    }
    unset($offering['media_images']);

    $videos = @json_decode($offering['media_videos'], true);
    if($videos){
      $offering['Media']['Videos'] = $videos;
    }
    unset($offering['media_videos']);

    $offering['Faqs'] = $offering['Faqs'] ? json_decode($offering['Faqs'], true) : [];
    $offering['Locations'] = $offering['Locations'] ? json_decode($offering['Locations'], true) : [];
    $offering['Tags'] = !empty($offering['Tags'])
      ? array_values(array_filter(array_map('trim', explode(',', $offering['Tags'])), function($tag){
        return $tag !== '';
      }))
      : [];

    $offering['Author'] = [
      "UserID" => $offering['author_UserID'],
      "DisplayName" => $offering['author_DisplayName'],
      "UserName" => $offering['author_UserName'],
      "Rating" => floatVal($offering['author_Rating']),
      "TotalReviews" => intval($offering['author_TotalReviews']),
      "ImgURL" => $offering['author_ImgURL']
    ];

    $offering['AverageRating'] = floatVal($offering['Rating']);

    $offering['Category'] = [
      "CategoryID" => $offering['CategoryID'],
      "Name" => $offering['CategoryName'],
    ];
    unset($offering['CategoryID'], $offering['CategoryName']);
    $offering['RootCategory'] = [
      "CategoryID" => $offering['RootCategoryID'],
      "Name" => $offering['RootCategoryName'],
    ];
    unset($offering['RootCategoryID'], $offering['RootCategoryName']);

    unset($offering['Rating'],
      $offering['author_UserID'],
      $offering['author_DisplayName'],
      $offering['author_UserName'],
      $offering['author_Rating'],
      $offering['author_TotalReviews'],
      $offering['author_ImgURL'],
      $offering['CountryCode'],
      $offering['City']);

    return $offering;
  }

  /**
   * Procesa y normaliza múltiples registros de publicaciones
   *
   * Realiza conversiones de tipos de datos, decodificación de JSON y agrupa
   * información para un conjunto de publicaciones. Retorna en formato paginado.
   *
   * @param  array $offerings: array de publicaciones obtenidas de la BD
   * @param  int $total: cantidad total de registros disponibles
   * @return object: { data: [], rows: { total: int, fetched: int } }
   **/
  private function _getOfferingsGenericMulti($offerings, $total){
    # Desagrupo los json traidos por MYSQL para armar el JSON anidado de respuesta
    $offerings = array_map(function ($e) {
      $e['Approved'] = (bool)$e['Approved'];
      $e['Media'] = [
        'Images' => [],
        'Videos' => []
      ];

      $images = @json_decode($e['media_images'], true);
      if($images){
        $e['Media']['Images'] = $images;
      }
      unset($e['media_images']);

      $videos = @json_decode($e['media_videos'], true);
      if($videos){
        $e['Media']['Videos'] = $videos;
      }
      unset($e['media_videos']);

      $e['Faqs'] = $e['Faqs'] ? json_decode($e['Faqs'], true) : [];
      $e['Locations'] = $e['Locations'] ? json_decode($e['Locations'], true) : [];
      $e['Tags'] = !empty($e['Tags'])
        ? array_values(array_filter(array_map('trim', explode(',', $e['Tags'])), function($tag){
          return $tag !== '';
        }))
        : [];

      $e['Author'] = [
        "UserID" => $e['author_UserID'],
        "DisplayName" => $e['author_DisplayName'],
        "UserName" => $e['author_UserName'],
        "Rating" => floatVal($e['author_Rating']),
        "TotalReviews" => intval($e['author_TotalReviews']),
        "ImgURL" => $e['author_ImgURL']
      ];

      $e['AverageRating'] = floatVal($e['Rating']);

      $e['Category'] = [
        "CategoryID" => $e['CategoryID'],
        "Name" => $e['CategoryName'],
      ];
      unset($e['CategoryID'], $e['CategoryName']);
      $e['RootCategory'] = [
        "CategoryID" => $e['RootCategoryID'],
        "Name" => $e['RootCategoryName'],
      ];
      unset($e['RootCategoryID'], $e['RootCategoryName']);

      unset($e['Rating'],
        $e['author_UserID'],
        $e['author_DisplayName'],
        $e['author_UserName'],
        $e['author_Rating'],
        $e['author_TotalReviews'],
        $e['author_ImgURL'],
        $e['CountryCode'],
        $e['City']);

      return $e;
    }, $offerings);

    return (object) [
      "data" => $offerings,
      "rows" => [
        "total" => $total,
        "fetched" => count($offerings)
      ]
    ];
  }

  /**
   * Obtiene las cantidad de publicaciones activas de un usuario
   *
   * @return integer: cantidad de publicaciones activas
   **/
  public function countUserActiveOfferings($userID) {
    $stmt = $this->db->prepare("SELECT count(*) as q
      FROM Offerings WHERE UserID = ? AND Status = 'Active'");
    $stmt->execute([$userID]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($result['q'] ?? 0);
  }

  /**
   * Desactiva todas publicaciones del guia
   *
   **/
  public function disableUserOfferings($userID) {
    $stmt = $this->db->prepare("UPDATE Offerings
      SET Status = 'Inactive' WHERE UserID = ? AND Status = 'Active'");
    $stmt->execute([$userID]);
  }

  /**
   * Desactiva las publicaciones con videos
   *
   **/
  public function disableUserOfferingsWithVideos($userID) {
    $stmt = $this->db->prepare("UPDATE Offerings
      SET Status = 'Inactive'
      WHERE UserID = ? AND Status = 'Active' AND OfferingID IN (
        SELECT o.OfferingID FROM Offerings as o
        INNER JOIN Media as m ON o.OfferingID = m.OfferingID
        WHERE MediaType = 'video'
        GROUP BY o.OfferingID
      )");
    $stmt->execute([$userID]);
  }

  /**
   * Crea una nueva publicación con FAQs asociadas
   *
   * Crea una publicación con estado 'Active' que requiere aprobación del admin (isActive = 0).
   * Inserta además todas las FAQs asociadas en una transacción atómica.
   *
   * @param  array $data: datos de la publicación (Title, ShortDescription, Description, etc)
   * @return array: datos de la publicación creada normalizados
   * @throws DatabaseException
   **/
  public function createOffering($data) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Inserto el offering
      $stmt = $this->db->prepare("INSERT INTO Offerings (Title, ShortDescription,
        Description, CategoryID, UserID, Status, CreationDate, Currency, Approved,
        Tags, SKU, Stock, ServiceType)
        VALUES (:Title, :ShortDescription, :Description, :CategoryID, :UserID, :Status,
        :CreationDate, :Currency, 0, :Tags, :SKU, :Stock, :ServiceType)");

      $stmt->execute([
        ':Title' => $data['Title'],
        ':ShortDescription' => $data['ShortDescription'],
        ':Description' => $data['Description'],
        ':CategoryID' => $data['CategoryID'],
        ':UserID' => $data['UserID'],
        ':Status' => 'Draft',
        ':CreationDate' => date('YmdHis'),
        ':Currency' => 'USD',
        ':Tags' => is_array($data['Tags']) ? implode(",", $data['Tags']) : $data['Tags'],
        ':SKU' => $data['SKU'] ?? null,
        ':Stock' => $data['Stock'] ?? null,
        ':ServiceType' => $data['ServiceType']
      ]);
      $offeringID = $this->db->lastInsertId();

      # Traigo el offering insertado
      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the created offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Aprueba una publicación cambiando su estado a 'Active'
   *
   * Cambia el estado de la publicación a Approved = 1.
   * Solo debe ser ejecutado por administradores.
   *
   * @param  int $offeringID: ID de la publicación
   * @return array: datos de la publicación activada
   * @throws DatabaseException
   **/
  public function approveOfferingById($offeringID) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE Offerings
        SET Approved = 1, ApprovalDate = now()
        WHERE OfferingID = ? AND Status = 'Active'");
      $stmt->execute([$offeringID]);

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Actualiza los datos de una publicación existente
   *
   * Actualiza campos permitidos y si se modifican ciertos campos (Title, Description, Faqs, Tags),
   * la publicacion debe volver a autorizarse
   * Actualiza también las FAQs si se incluyen.
   *
   * @param  int $offeringID: ID de la publicación
   * @param  array $data: array asociativo con campos a actualizar
   * @return array: datos de la publicación actualizada
   * @return array: faqs si los hubiera
   * @throws DatabaseException
   **/
  public function updateOffering($offeringID, $data) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Construcción dinámica de la consulta
      $fields = [];
      foreach ($data as $key => $value) {
        if(!is_array($value)){ # Parametros compuestos (array) no
          $fields[] = "$key = :$key";
        }
      }

      # Verificar si se han modificado campos que requieren volver a autorizar el offering
      $updateStatusRequired = isset($data['Title']) || isset($data['ShortDescription'])
        || isset($data['Description']) || isset($data['Faqs'])
        || isset($data['Description']) || isset($data['Tags']);
      $statusQuery = $updateStatusRequired ? ", Approved = 0, ApprovalDate = NULL " : "";

      # Siempre agregar ModificationDate
      $modificationDate = date("YmdHis");
      $fields[] = "ModificationDate = :ModificationDate";

      # Si cambian ciertos campos hay que volver a autorizar
      $stmt = $this->db->prepare("UPDATE Offerings SET " . implode(", ", $fields) . $statusQuery . " WHERE OfferingID = :offeringID");

      # Vincular los parámetros
      foreach ($data as $key => $value) {
        if(!is_array($value)){ # Parametros compuestos (array) no
          $stmt->bindValue(":$key", $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
      }

      $stmt->bindValue(':offeringID', $offeringID, PDO::PARAM_INT);
      $stmt->bindValue(':ModificationDate', $modificationDate, PDO::PARAM_STR);
      $stmt->execute();

      if (isset($data['Faqs']) && is_array($data['Faqs'])) {
        $this->_updateOfferingFaqs($offeringID, $data['Faqs']);
      }

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Elimina una publicación realizando soft delete
   *
   * Cambia el estado de la publicación a 'Deleted'
   * No elimina físicamente el registro de la BD.
   *
   * @param  int $offeringID: ID de la publicación
   * @return array: datos de la publicación actualizada
   * @throws DatabaseException
   **/
  public function deleteOffering($offeringID) {
    try{
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Deleted'
        WHERE OfferingID = ?");
      $stmt->execute([$offeringID]);

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Duplica una publicación existente
   *
   * Clona el registro principal en Offerings y también sus relaciones en
   * OfferingsLocations y OfferingsFaqs.
   *
   * @param  int $offeringID: ID de la publicación origen
   * @return array: datos de la publicación duplicada
   * @throws DatabaseException
   **/
  public function duplicateOffering($offeringID) {
    $copiedMediaPaths = [];

    try{
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("SELECT * FROM Offerings WHERE OfferingID = ?");
      $stmt->execute([$offeringID]);
      $sourceOffering = $stmt->fetch(PDO::FETCH_ASSOC);

      if(!$sourceOffering){
        $this->db->rollBack();
        throw new DatabaseException("Failed to retrieve source offering");
      }

      $newTitle = "[duplicado] " . ($sourceOffering['Title'] ?? '');
      if(strlen($newTitle) > 100){
        $newTitle = substr($newTitle, 0, 100);
      }

      $stmt = $this->db->prepare("INSERT INTO Offerings (Title, ShortDescription,
        Description, CategoryID, UserID, Status, CreationDate, Currency, Approved,
        Tags, SKU, Stock, ServiceType, Price, SessionType, Conditions, Duration)
        VALUES (:Title, :ShortDescription, :Description, :CategoryID, :UserID, :Status,
        :CreationDate, :Currency, 0, :Tags, :SKU, :Stock, :ServiceType,
        :Price, :SessionType, :Conditions, :Duration)");

      $stmt->execute([
        ':Title' => $newTitle,
        ':ShortDescription' => $sourceOffering['ShortDescription'] ?? '',
        ':Description' => $sourceOffering['Description'] ?? '',
        ':CategoryID' => $sourceOffering['CategoryID'],
        ':UserID' => $sourceOffering['UserID'],
        ':Status' => 'Draft',
        ':CreationDate' => date('YmdHis'),
        ':Currency' => $sourceOffering['Currency'] ?? 'USD',
        ':Tags' => $sourceOffering['Tags'] ?? null,
        ':SKU' => $sourceOffering['SKU'] ?? null,
        ':Stock' => $sourceOffering['Stock'] ?? null,
        ':ServiceType' => $sourceOffering['ServiceType'] ?? 'Service',
        ':Price' => $sourceOffering['Price'] ?? null,
        ':SessionType' => $sourceOffering['SessionType'] ?? null,
        ':Conditions' => $sourceOffering['Conditions'] ?? null,
        ':Duration' => $sourceOffering['Duration'] ?? null
      ]);

      $newOfferingID = $this->db->lastInsertId();

      $stmt = $this->db->prepare("INSERT INTO OfferingsLocations (UserID, LocationID, OfferingID)
        SELECT UserID, LocationID, :newOfferingID
        FROM OfferingsLocations
        WHERE OfferingID = :sourceOfferingID");
      $stmt->execute([
        ':newOfferingID' => $newOfferingID,
        ':sourceOfferingID' => $offeringID
      ]);

      $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, Position, Question, Answer)
        SELECT :newOfferingID, Position, Question, Answer
        FROM OfferingsFaqs
        WHERE OfferingID = :sourceOfferingID");
      $stmt->execute([
        ':newOfferingID' => $newOfferingID,
        ':sourceOfferingID' => $offeringID
      ]);

      # Clonar media (tabla + archivo físico)
      $stmt = $this->db->prepare("SELECT MediaID, Title, Description, URL, Path, MediaType, Position
        FROM Media
        WHERE OfferingID = ?
        ORDER BY Position ASC, MediaID ASC");
      $stmt->execute([$offeringID]);
      $sourceMedia = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if(!empty($sourceMedia)){
        $insertMediaStmt = $this->db->prepare("INSERT INTO Media
          (OfferingID, Title, Description, URL, Path, MediaType, Position)
          VALUES (:OfferingID, :Title, :Description, :URL, :Path, :MediaType, :Position)");

        foreach($sourceMedia as $media){
          $sourcePath = $media['Path'] ?? null;
          $sourceUrl = $media['URL'] ?? null;

          if(empty($sourcePath) || !is_file($sourcePath)){
            throw new DatabaseException("Failed to duplicate media file");
          }

          $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
          $uid = uniqid();
          $newFileName = $uid . ($extension ? ".{$extension}" : '');
          $newPath = rtrim(dirname($sourcePath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFileName;
          $newUrl = rtrim(dirname((string)$sourceUrl), '/') . '/' . $newFileName;

          if(!copy($sourcePath, $newPath)){
            throw new DatabaseException("Failed to duplicate media file");
          }

          $copiedMediaPaths[] = $newPath;

          $insertMediaStmt->execute([
            ':OfferingID' => $newOfferingID,
            ':Title' => $media['Title'] ?? '',
            ':Description' => $media['Description'] ?? '',
            ':URL' => $newUrl,
            ':Path' => $newPath,
            ':MediaType' => $media['MediaType'],
            ':Position' => $media['Position']
          ]);
        }
      }

      $offering = $this->getOfferingById($newOfferingID);
      if(!$offering){
        $this->db->rollBack();
        throw new DatabaseException("Failed to retrieve the created offering");
      }

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\Throwable $e) {
      if($this->db->inTransaction()){
        $this->db->rollBack(); # Revierto en caso de error
      }

      if(!empty($copiedMediaPaths)){
        foreach($copiedMediaPaths as $copiedMediaPath){
          if(!empty($copiedMediaPath) && is_file($copiedMediaPath)){
            @unlink($copiedMediaPath);
          }
        }
      }

      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Habilita una publicación
   *
   * Cambia el estado de la publicación a 'Active'
   *
   * @param  int $offeringID: ID de la publicación
   * @return array: datos de la publicación actualizada
   * @throws DatabaseException
   **/
  public function enableOffering($offeringID) {
    try{
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Active'
        WHERE OfferingID = ?");
      $stmt->execute([$offeringID]);

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Deshabilita una publicación
   *
   * Cambia el estado de la publicación a 'Inactive'
   *
   * @param  int $offeringID: ID de la publicación
   * @return array: datos de la publicación actualizada
   * @throws DatabaseException
   **/
  public function disableOffering($offeringID) {
    try{
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Inactive'
        WHERE OfferingID = ?");
      $stmt->execute([$offeringID]);

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene un archivo multimedia específico de una publicación
   *
   * Retorna información completa del archivo incluyendo ruta y URL.
   *
   * @param  int $offeringID: ID de la publicación
   * @param  int $mediaID: ID del archivo multimedia
   * @return array|false: datos del archivo o false si no existe
   **/
  public function getMediaById($offeringID, $mediaID) {
    $stmt = $this->db->prepare("SELECT * FROM Media
      WHERE MediaID = ? AND OfferingID = ?");
    $stmt->execute([$mediaID, $offeringID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Obtiene la cantidad de publicaciones activas del guia
   *
   * @param  int $userID: ID del guia propietario de las publicaciones
   * @return int: cantidad de publicaciones activas
   **/
  public function countActiveOfferings($userID) {
    $stmt = $this->db->prepare("SELECT count(*) as found FROM Offerings
      WHERE UserID = ? AND Status = 'Active'");
    $stmt->execute([$userID]);
    $offerings = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($offerings && isset($offerings['found'])) ? $offerings['found'] : 0;
  }

  /**
   * Agrega un archivo multimedia (imagen o video) a una publicación
   *
   * Inserta el archivo en la tabla Media con posicionamiento automático.
   * La primera imagen se asigna a posición 0 automáticamente.
   *
   * @param  int $offeringID: ID de la publicación
   * @param  string $title: título del archivo
   * @param  string $description: descripción del archivo (opcional)
   * @param  string $fileURL: URL pública del archivo optimizado
   * @param  string $filePath: ruta local completa del archivo
   * @param  MediaType $mediaType: tipo de archivo ('image' o 'video')
   * @param  $position: posicion del archivo (opcional)
   * @return array: datos de la publicación actualizada
   * @throws DatabaseException
   **/
  public function createOfferingMedia($offeringID, $title, $description, $fileURL,
    $filePath, MediaType $mediaType, $position
  ){
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Si no se especifico posicion calcular una nueva
      if(is_null($position)){
        # Calcular la próxima posición
        $stmt = $this->db->prepare("SELECT MAX(Position) AS max_position FROM Media
          WHERE OfferingID = ? AND MediaType = ?");
        $stmt->execute([$offeringID, $mediaType->value]);
        $maxPosition = $stmt->fetch(PDO::FETCH_ASSOC)['max_position'];
        $position = $maxPosition !== null ? $maxPosition + 1 : 0;
      }

      # Insertar en la tabla Media
      $stmt = $this->db->prepare("INSERT INTO Media
        (`OfferingID`, `Title`, `Description`, `URL`, `Path`, `MediaType`, `Position`)
        VALUES (:offeringID, :title, :description, :fileURL, :filePath, :mediaType, :position)");

      $stmt->execute([
        ':offeringID' => $offeringID,
        ':title' => $title,
        ':description' => $description,
        ':fileURL' => $fileURL,
        ':filePath' => $filePath,
        ':mediaType' => $mediaType->value,
        ':position' => $position
      ]);

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Actualiza un archivo multimedia de una publicación
   *
   * Actualiza metadatos (title, description, position) y opcionalmente la URL y ruta del archivo.
   * Si se actualiza cualquier campo, la publicacion debe volver a autorizarse
   *
   * @param  int $offeringID: ID de la publicación
   * @param  string $title: nuevo título del archivo
   * @param  string $description: nueva descripción del archivo
   * @param  int $position: nueva posición del archivo
   * @param  int $mediaID: ID del archivo multimedia
   * @param  string|null $fileURL: nueva URL del archivo o false si no se actualiza
   * @param  string|null $filePath: nueva ruta local del archivo o false si no se actualiza
   * @param  MediaType|null $mediaType: nuevo tipo de archivo ('image'/'video') o false si no se actualiza
   * @return array: datos de la publicación actualizada
   * @throws DatabaseException
   **/
  public function updateOfferingMedia($offeringID, $title, $description, $position,
    $mediaID, $fileURL, $filePath, MediaType|null $mediaType
  ){
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Diferente update según se adjuntó un archivo o no
      if ($fileURL) {
        # Actualización para Media con archivo
        $stmt = $this->db->prepare("UPDATE Media
          SET Title = :title, Description = :description,
          URL = :fileURL, Path = :filePath, MediaType = :mediaType,
          Position = :position
          WHERE MediaID = :mediaID AND OfferingID = :offeringID");
        $stmt->execute([
          ':title' => $title,
          ':description' => $description,
          ':position' => $position,
          ':mediaID' => $mediaID,
          ':offeringID' => $offeringID,
          ':fileURL' => $fileURL,
          ':filePath' => $filePath,
          ':mediaType' => $mediaType->value
        ]);
      } else {
        # Actualización para Media sin archivo
        $stmt = $this->db->prepare("UPDATE Media SET Title = :title,
          Description = :description, Position = :position
          WHERE MediaID = :mediaID AND OfferingID = :offeringID");
        $stmt->execute([
          ':title' => $title,
          ':description' => $description,
          ':position' => $position,
          ':mediaID' => $mediaID,
          ':offeringID' => $offeringID
        ]);
      }

      # Verificar si se han modificado campos que requieren cambiar el estado
      $updateStatusRequired = isset($title) || isset($description) || isset($fileURL) || isset($filePath);
      $statusQuery = $updateStatusRequired ? ", Approved = 0, ApprovalDate = NULL " : "";

      $stmt = $this->db->prepare("UPDATE Offerings SET ModificationDate = ? $statusQuery WHERE OfferingID = ?");
      $stmt->execute([date("YmdHis"), $offeringID]);

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Elimina un archivo multimedia de una publicación
   *
   * Elimina el registro de la tabla Media. El archivo físico debe ser eliminado
   * por el controller antes de llamar este método.
   *
   * @param  int $offeringID: ID de la publicación
   * @param  int $mediaID: ID del archivo multimedia
   * @return array: datos de la publicación actualizada
   * @throws DatabaseException
   **/
  public function deleteOfferingMedia($offeringID, $mediaID) {
    try{
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = ?");
      $stmt->execute([$mediaID]);

      $offering = $this->getOfferingById($offeringID) ?:
        throw new DatabaseException("Failed to retrieve the updated offering");

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene el archivo multimedia de una publicación por ID de publicación
   *
   * Retorna solo un archivo (LIMIT 1) con su ruta local y URL.
   *
   * @param  int $offeringID: ID de la publicación
   * @return array|false: datos del archivo o false si no existe
   **/
  public function getMediaByOfferingId($offeringID) {
    $stmt = $this->db->prepare("SELECT MediaID, Path FROM Media
      WHERE OfferingID = ?");
    $stmt->execute([$offeringID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Obtiene el conteo de archivos multimedia por tipo para una publicación
   *
   * Retorna cantidad de imágenes y videos asociados a una publicación.
   *
   * @param  int $offeringID: ID de la publicación
   * @return array: { 'image': int, 'video': int }
   **/
  public function getMediaCountByType($offeringID) {
    $stmt = $this->db->prepare("SELECT MediaType, COUNT(*) AS count FROM Media
      WHERE OfferingID = ? GROUP BY MediaType");
    $stmt->execute([$offeringID]);
    $mediaCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    # Organizar resultados en un arreglo asociativo
    $counts = ['image' => 0, 'video' => 0];
    foreach ($mediaCounts as $mediaCount) {
      if ($mediaCount['MediaType'] === 'image') {
        $counts['image'] = $mediaCount['count'];
      } elseif ($mediaCount['MediaType'] === 'video') {
        $counts['video'] = $mediaCount['count'];
      }
    }
    return $counts;
  }

  /**
   * Verifica si un usuario está suscrito a una categoría
   *
   * @param  int $userID: ID del usuario
   * @param  int $categoryID: ID de la categoría
   * @return bool: true si el usuario está suscrito, false en caso contrario
   **/
  public function checkUserCategorySubscription($userID, $categoryID) {
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM UsersCategories
      WHERE UserID = ? AND CategoryID = ?");
    $stmt->execute([$userID, $categoryID]);
    return $stmt->fetchColumn() > 0;
  }

  /**
   * Actualiza las FAQs asociadas a una publicación
   *
   * Elimina todas las FAQs existentes e inserta las nuevas.
   * Se ejecuta en contexto de una transacción.
   *
   * @param  int $offeringID: ID de la publicación
   * @param  array $faqs: array de FAQs con structure { Position, Question, Answer }
   * @throws DatabaseException
   **/
  private function _updateOfferingFaqs($offeringID, $faqs) {
    if ($faqs !== null && is_array($faqs)) {
      $stmt = $this->db->prepare("DELETE FROM OfferingsFaqs WHERE OfferingID = ?");
      $stmt->execute([$offeringID]);

      $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, Position, Question, Answer)
      VALUES (?, ?, ?, ?)");
      foreach ($faqs as $faq) {
        $stmt->execute([$offeringID, $faq['Position'], $faq['Question'], $faq['Answer']]);
      }
    }
  }
}
