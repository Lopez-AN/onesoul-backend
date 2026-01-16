<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Enums\MediaType;

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
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*,
      u.UserID AS author_UserID,
      u.DisplayName AS author_DisplayName,
      u.FirstName AS author_FirstName,
      u.LastName AS author_LastName,
      u.UserName AS author_UserName,
      round(avg(ru.Rating),2) AS author_Rating,
      COUNT(DISTINCT ru.ReviewID) AS author_TotalReviews,
      (SELECT URL FROM Media WHERE UserID = u.UserID LIMIT 1) AS author_ImgURL,
      -- Subconsulta para media_images
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Id', m.MediaID,
          'Url', m.URL,
          'Title', m.Title,
          'Description', m.Description,
          'Position', m.Position
        )
      ) FROM Media AS m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'image') AS media_images,
      -- Subconsulta para media_videos
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Id', m.MediaID,
          'Url', m.URL,
          'Title', m.Title,
          'Description', m.Description,
          'Position', m.Position
        )
      ) FROM Media AS m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'video') AS media_videos,
      -- Subconsulta para faqs
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Position', f.Position,
          'Question', f.Question,
          'Answer', f.Answer
        )
      ) FROM OfferingsFaqs AS f WHERE f.OfferingID = o.OfferingID) AS Faqs,
      ROUND(AVG(r.Rating),2) AS Rating,
      COUNT(DISTINCT r.ReviewID) AS TotalReviews,
      COUNT(DISTINCT b.BookingID) as Bookings
      FROM Offerings AS o
      INNER JOIN Users AS u ON u.UserID = o.UserID
      LEFT JOIN Reviews AS r ON o.OfferingID = r.OfferingID
      LEFT JOIN Reviews AS ru ON u.UserID = ru.SeekerID
      LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
      WHERE o.Status = 'Active' AND o.IsActive = 1
      GROUP BY o.OfferingID
      ORDER BY o.OfferingID
      LIMIT ? OFFSET ?");

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
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function searchOfferings($paginator, $query) {
    $searchQuery = "%$query%";
    $stmt = $this->db->prepare("SELECT o.*,
      u.UserID AS author_UserID,
      u.DisplayName AS author_DisplayName,
      u.FirstName AS author_FirstName,
      u.LastName AS author_LastName,
      u.UserName AS author_UserName,
      round(avg(ru.Rating),2) AS author_Rating,
      COUNT(DISTINCT ru.ReviewID) AS author_TotalReviews,
      (SELECT URL FROM Media WHERE UserID = u.UserID LIMIT 1) AS author_ImgURL,
      -- Subconsulta para media_images
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Id', m.MediaID,
          'Url', m.URL,
          'Title', m.Title,
          'Description', m.Description,
          'Position', m.Position
        )
      ) FROM Media m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'image') AS media_images,
      -- Subconsulta para media_videos
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Id', m.MediaID,
          'Url', m.URL,
          'Title', m.Title,
          'Description', m.Description,
          'Position', m.Position
        )
      ) FROM Media m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'video') AS media_videos,
      -- Subconsulta para faqs
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Position', f.Position,
          'Question', f.Question,
          'Answer', f.Answer
        )
      ) FROM OfferingsFaqs f WHERE f.OfferingID = o.OfferingID) AS Faqs,
      ROUND(AVG(r.Rating),2) as Rating,
      COUNT(DISTINCT r.ReviewID) AS TotalReviews,
      COUNT(DISTINCT b.BookingID) as Bookings
      FROM Offerings AS o
      INNER JOIN Users AS u ON u.UserID = o.UserID
      LEFT JOIN Reviews as r ON o.OfferingID = r.OfferingID
      LEFT JOIN Reviews as ru ON u.UserID = ru.SeekerID
      LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
      WHERE (o.Title LIKE ? OR o.Description LIKE ?
      OR o.ShortDescription LIKE ? OR o.Tags LIKE ?)
      AND o.Status = 'Active' AND o.IsActive = 1
      GROUP BY o.OfferingID
      ORDER BY o.OfferingID
      LIMIT ? OFFSET ?"
    );

    $stmt->execute([$searchQuery, $searchQuery, $searchQuery, $searchQuery, $paginator->limit, $paginator->offset]);
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
    $stmt = $this->db->prepare("SELECT o.*,
    u.UserID AS author_UserID,
    u.DisplayName AS author_DisplayName,
    u.FirstName AS author_FirstName,
    u.LastName AS author_LastName,
    u.UserName AS author_UserName,
    round(avg(ru.Rating),2) AS author_Rating,
    COUNT(DISTINCT ru.ReviewID) AS author_TotalReviews,
    (SELECT URL FROM Media WHERE UserID = u.UserID LIMIT 1) AS author_ImgURL,
    -- Subconsulta para media_images
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'Id', m.MediaID,
        'Url', m.URL,
        'Title', m.Title,
        'Description', m.Description,
        'Position', m.Position
      )
    ) FROM Media AS m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'image') AS media_images,
    -- Subconsulta para media_videos
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'Id', m.MediaID,
        'Url', m.URL,
        'Title', m.Title,
        'Description', m.Description,
        'Position', m.Position
      )
    ) FROM Media AS m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'video') AS media_videos,
    -- Subconsulta para faqs
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'Position', f.Position,
        'Question', f.Question,
        'Answer', f.Answer
      )
    ) FROM OfferingsFaqs AS f WHERE f.OfferingID = o.OfferingID) AS Faqs,
    ROUND(AVG(r.Rating),2) AS Rating,
    COUNT(DISTINCT r.ReviewID) AS TotalReviews,
    COUNT(DISTINCT b.BookingID) as Bookings
    FROM Offerings AS o
    INNER JOIN Users AS u ON u.UserID = o.UserID
    LEFT JOIN Reviews AS r ON o.OfferingID = r.OfferingID
    LEFT JOIN Reviews AS ru ON u.UserID = ru.SeekerID
    LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
    WHERE o.OfferingID = ?
    GROUP BY o.OfferingID");

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
    $stmt = $this->db->prepare("WITH RECURSIVE category_tree AS (
      SELECT CategoryID
      FROM Categories
      WHERE CategoryID = ?

      UNION ALL

      SELECT c.CategoryID
      FROM Categories c
      INNER JOIN category_tree ct ON c.ParentCategoryID = ct.CategoryID
    )
    SELECT SQL_CALC_FOUND_ROWS
    o.*,
    u.UserID AS author_UserID,
    u.DisplayName AS author_DisplayName,
    u.FirstName AS author_FirstName,
    u.LastName AS author_LastName,
    u.UserName AS author_UserName,
    ROUND(AVG(ru.Rating),2) AS author_Rating,
    COUNT(DISTINCT ru.ReviewID) AS author_TotalReviews,
    (SELECT URL FROM Media WHERE UserID = u.UserID LIMIT 1) AS author_ImgURL,
    -- media_images
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'Id', m.MediaID,
        'Url', m.URL,
        'Title', m.Title,
        'Description', m.Description,
        'Position', m.Position
      )
    ) FROM Media m
    WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'image') AS media_images,
    -- media_videos
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'Id', m.MediaID,
        'Url', m.URL,
        'Title', m.Title,
        'Description', m.Description,
        'Position', m.Position
      )
    ) FROM Media m
    WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'video') AS media_videos,
    -- faqs
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'Position', f.Position,
        'Question', f.Question,
        'Answer', f.Answer
      )
    ) FROM OfferingsFaqs f
    WHERE f.OfferingID = o.OfferingID) AS Faqs,
    ROUND(AVG(r.Rating),2) as Rating,
    COUNT(DISTINCT r.ReviewID) AS TotalReviews,
    COUNT(DISTINCT b.BookingID) as Bookings
    FROM Offerings AS o
    INNER JOIN category_tree ct ON ct.CategoryID = o.CategoryID
    INNER JOIN Users AS u ON u.UserID = o.UserID
    LEFT JOIN Reviews as r ON o.OfferingID = r.OfferingID
    LEFT JOIN Reviews as ru ON u.UserID = ru.SeekerID
    LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
    WHERE o.Status = 'Active' AND o.IsActive = 1
    GROUP BY o.OfferingID
    ORDER BY o.OfferingID
    LIMIT ? OFFSET ?");

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
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*,
      u.UserID AS author_UserID,
      u.DisplayName AS author_DisplayName,
      u.FirstName AS author_FirstName,
      u.LastName AS author_LastName,
      u.UserName AS author_UserName,
      round(avg(ru.Rating),2) AS author_Rating,
      COUNT(DISTINCT ru.ReviewID) AS author_TotalReviews,
      (SELECT URL FROM Media WHERE UserID = u.UserID LIMIT 1) AS author_ImgURL,
      -- Subconsulta para media_images
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Id', m.MediaID,
          'Url', m.URL,
          'Title', m.Title,
          'Description', m.Description,
          'Position', m.Position
        )
      ) FROM Media AS m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'image') AS media_images,
      -- Subconsulta para media_videos
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Id', m.MediaID,
          'Url', m.URL,
          'Title', m.Title,
          'Description', m.Description,
          'Position', m.Position
        )
      ) FROM Media AS m WHERE m.OfferingID = o.OfferingID AND m.MediaType = 'video') AS media_videos,
      -- Subconsulta para faqs
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Position', f.Position,
          'Question', f.Question,
          'Answer', f.Answer
        )
      ) FROM OfferingsFaqs AS f WHERE f.OfferingID = o.OfferingID) AS Faqs,
      ROUND(AVG(r.Rating),2) AS Rating,
      COUNT(DISTINCT r.ReviewID) AS TotalReviews,
      COUNT(DISTINCT b.BookingID) as Bookings
      FROM Offerings AS o
      INNER JOIN Users AS u ON u.UserID = o.UserID
      LEFT JOIN Reviews AS r ON o.OfferingID = r.OfferingID
      LEFT JOIN Reviews AS ru ON u.UserID = ru.SeekerID
      LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
      WHERE o.UserID = ?
      GROUP BY o.OfferingID
      ORDER BY o.OfferingID
      LIMIT ? OFFSET ?");

    $stmt->execute([$userID, $paginator->limit, $paginator->offset]);
    $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingsGenericMulti($offerings, $total['total']);
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

    $faqs = @json_decode($offering['Faqs'], true);
    if($faqs){
      $offering['Faqs'] = $faqs;
    }

    $offering['Author'] = [
      "UserID" => $offering['author_UserID'],
      "DisplayName" => $offering['author_DisplayName'],
      "FirstName" => $offering['author_FirstName'],
      "LastName" => $offering['author_LastName'],
      "Rating" => floatVal($offering['author_Rating']),
      "TotalReviews" => intval($offering['author_TotalReviews']),
      "ImgURL" => $offering['author_ImgURL']
    ];

    $offering['AverageRating'] = floatVal($offering['Rating']);

    unset($offering['Rating'],
      $offering['author_UserID'],
      $offering['author_DisplayName'],
      $offering['author_FirstName'],
      $offering['author_LastName'],
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

      $faqs = @json_decode($e['Faqs'], true);
      if($faqs){
        $e['Faqs'] = $faqs;
      }

      $e['Author'] = [
        "UserID" => $e['author_UserID'],
        "DisplayName" => $e['author_DisplayName'],
        "FirstName" => $e['author_FirstName'],
        "LastName" => $e['author_LastName'],
        "Rating" => floatVal($e['author_Rating']),
        "TotalReviews" => intval($e['author_TotalReviews']),
        "ImgURL" => $e['author_ImgURL']
      ];

      $e['AverageRating'] = floatVal($e['Rating']);

      unset($e['Rating'],
        $e['author_UserID'],
        $e['author_DisplayName'],
        $e['author_FirstName'],
        $e['author_LastName'],
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
        Tags, SKU, Stock, ServiceType, Price, SessionType, Conditions, Duration)
        VALUES (:Title, :ShortDescription, :Description, :CategoryID, :UserID, :Status,
        :CreationDate, :Currency, 0, :Tags, :SKU, :Stock, :ServiceType,
        :Price, :SessionType, :Conditions, :Duration)");

      $stmt->execute([
        ':Title' => $data['Title'],
        ':ShortDescription' => $data['ShortDescription'],
        ':Description' => $data['Description'],
        ':CategoryID' => $data['CategoryID'],
        ':UserID' => $data['UserID'],
        ':Status' => 'Active',
        ':CreationDate' => date('YmdHis'),
        ':Currency' => 'USD',
        ':Tags' => is_array($data['Tags']) ? implode(",", $data['Tags']) : $data['Tags'],
        ':SKU' => $data['SKU'] ?? null,
        ':Stock' => $data['Stock'] ?? null,
        ':ServiceType' => $data['ServiceType'],
        ':Price' => $data['Price'],
        ':SessionType' => $data['SessionType'],
        ':Conditions' => $data['Conditions'],
        ':Duration' => $data['Duration']
      ]);
      $offeringID = $this->db->lastInsertId();

      # Inserto los FAQs
      if (isset($data['Faqs'])) {
        foreach ($data['Faqs'] as $faq) {
          $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, Position, Question, Answer)
            VALUES (?, ?, ?, ?)");
          $stmt->execute([$offeringID, $faq['Position'], $faq['Question'], $faq['Answer']]);
        }
      }

      # Inserto el location
      $stmt = $this->db->prepare("INSERT INTO OfferingsLocations
        (UserID, LocationID, OfferingID)
        VALUES (:UserID, :LocationID, :OfferingID)");
      $stmt->execute([$data['UserID'], $data['LocationID'], $offeringID]);

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