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
      -- Subconsulta para packages
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Package', p.Package,
          'Price', p.Price,
          'Description', p.Description,
          'Conditions', p.Conditions,
          'SessionType', p.SessionType
        )
      ) FROM OfferingsPackages AS p WHERE p.OfferingID = o.OfferingID) AS Packages,
      -- Subconsulta para locations
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'LocationID', l.LocationID,
          'CountryCode', l.CountryCode,
          'CountryName', c.CountryName,
          'State', l.State,
          'City', l.City
        )
      ) FROM OfferingLocations AS l WHERE l.OfferingID = o.OfferingID) AS Locations,
      ROUND(AVG(r.Rating),2) AS Rating,
      COUNT(DISTINCT r.ReviewID) AS TotalReviews,
      COUNT(DISTINCT b.BookingID) as Bookings
      FROM Offerings AS o
      INNER JOIN Users AS u ON u.UserID = o.UserID
      LEFT JOIN Reviews AS r ON o.OfferingID = r.OfferingID
      LEFT JOIN Reviews AS ru ON u.UserID = ru.SeekerID
      LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
      LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
      LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
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
   * @param  object $paginator: objeto con limit y offset
   * @param  string $query: término(s) de búsqueda
   * @return object: { data: [], rows: { total: int, fetched: int } }
   *
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
      -- Subconsulta para packages
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Package', p.Package,
          'Price', p.Price,
          'Description', p.Description,
          'Conditions', p.Conditions,
          'SessionType', p.SessionType
        )
      ) FROM OfferingsPackages p WHERE p.OfferingID = o.OfferingID) AS Packages,
      -- Subconsulta para locations
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'LocationID', l.LocationID,
          'CountryCode', l.CountryCode,
          'CountryName', c.CountryName,
          'State', l.State,
          'City', l.City
        )
      ) FROM OfferingLocations l WHERE l.OfferingID = o.OfferingID) AS Locations,
      ROUND(AVG(r.Rating),2) as Rating
      FROM Offerings AS o
      INNER JOIN Users AS u ON u.UserID = o.UserID
      LEFT JOIN Reviews as r ON o.OfferingID = r.OfferingID
      LEFT JOIN Reviews as ru ON u.UserID = ru.SeekerID
      LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
      LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
      WHERE (o.Title LIKE ? OR o.Description LIKE ?
      OR o.ShortDescription LIKE ? OR o.Tags LIKE ?)
      AND o.Status = 'Active'
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

  public function getOfferingById($id) {
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
    -- Subconsulta para packages
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'Package', p.Package,
        'Price', p.Price,
        'Description', p.Description,
        'Conditions', p.Conditions,
        'SessionType', p.SessionType
      )
    ) FROM OfferingsPackages AS p WHERE p.OfferingID = o.OfferingID) AS Packages,
    -- Subconsulta para locations
    (SELECT JSON_ARRAYAGG(
      JSON_OBJECT(
        'LocationID', l.LocationID,
        'CountryCode', l.CountryCode,
        'CountryName', c.CountryName,
        'State', l.State,
        'City', l.City
      )
    ) FROM OfferingLocations AS l WHERE l.OfferingID = o.OfferingID) AS Locations,
    ROUND(AVG(r.Rating),2) AS Rating,
    COUNT(DISTINCT r.ReviewID) AS TotalReviews,
    COUNT(DISTINCT b.BookingID) as Bookings
    FROM Offerings AS o
    INNER JOIN Users AS u ON u.UserID = o.UserID
    LEFT JOIN Reviews AS r ON o.OfferingID = r.OfferingID
    LEFT JOIN Reviews AS ru ON u.UserID = ru.SeekerID
    LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
    LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
    LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
    WHERE o.OfferingID = ?
    GROUP BY o.OfferingID");

    $stmt->execute([$id]);
    $offering = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingGeneric($offering);
  }


  /**
   * Busca publicaciones por categoria
   *
   * @param  object $paginator: objeto con limit y offset
   * @param  string $category: categoria a buscar
   * @return object: { data: [], rows: { total: int, fetched: int } }
   *
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
      -- packages
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Package', p.Package,
          'Price', p.Price,
          'Description', p.Description,
          'Conditions', p.Conditions,
          'SessionType', p.SessionType
        )
      ) FROM OfferingsPackages p
      WHERE p.OfferingID = o.OfferingID) AS Packages,
      -- locations
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'LocationID', l.LocationID,
          'CountryCode', l.CountryCode,
          'CountryName', c.CountryName,
          'State', l.State,
          'City', l.City
        )
      ) FROM OfferingLocations l
      WHERE l.OfferingID = o.OfferingID) AS Locations,
      ROUND(AVG(r.Rating),2) as Rating
    FROM Offerings AS o
    INNER JOIN category_tree ct ON ct.CategoryID = o.CategoryID
    INNER JOIN Users AS u ON u.UserID = o.UserID
    LEFT JOIN Reviews as r ON o.OfferingID = r.OfferingID
    LEFT JOIN Reviews as ru ON u.UserID = ru.SeekerID
    LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
    LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
    GROUP BY o.OfferingID
    ORDER BY o.OfferingID
    LIMIT ? OFFSET ?");

    $stmt->execute([$category, $paginator->limit, $paginator->offset]);

    $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getOfferingsGenericMulti($offerings, $total['total']);
  }

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
      -- Subconsulta para packages
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'Package', p.Package,
          'Price', p.Price,
          'Description', p.Description,
          'Conditions', p.Conditions,
          'SessionType', p.SessionType
        )
      ) FROM OfferingsPackages AS p WHERE p.OfferingID = o.OfferingID) AS Packages,
      -- Subconsulta para locations
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'LocationID', l.LocationID,
          'CountryCode', l.CountryCode,
          'CountryName', c.CountryName,
          'State', l.State,
          'City', l.City
        )
      ) FROM OfferingLocations AS l WHERE l.OfferingID = o.OfferingID) AS Locations,
      ROUND(AVG(r.Rating),2) AS Rating,
      COUNT(DISTINCT r.ReviewID) AS TotalReviews,
      COUNT(DISTINCT b.BookingID) as Bookings
      FROM Offerings AS o
      INNER JOIN Users AS u ON u.UserID = o.UserID
      LEFT JOIN Reviews AS r ON o.OfferingID = r.OfferingID
      LEFT JOIN Reviews AS ru ON u.UserID = ru.SeekerID
      LEFT JOIN Bookings AS b ON b.OfferingID = o.OfferingID AND b.LastBookingEvent IN ('completed', 'rated')
      LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
      LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
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
   * Procesa y normaliza datos de una publicacion individual
   *
   * Realiza conversiones de tipos de datos y agrupa información relacionada
   *
   * @param  array|null $offering: datos del publicacion obtenidos de la base de datos o null
   * @return array|false: datos del publicacion normalizados o false si no existe
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

    $packages = @json_decode($offering['Packages'], true);
    if($packages){
      $offering['Packages'] = $packages;
    }

    $locations = @json_decode($offering['Locations'], true);
    if($locations){
      $offering['Locations'] = $locations;
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
   * Realiza conversiones de tipos de datos y agrupa información relacionada
   * para un conjunto de publicaciones.
   *
   * @param  array $offerings: array de publicaciones obtenidos de la base de datos
   * @param  int $total: cantidad total de registros disponibles en la base de datos
   * @return object: objeto con propiedades 'data' (array de publicaciones normalizados) y 'rows' (información de paginación)
   **/
  private function _getOfferingsGenericMulti($offerings, $total){
    // Desagrupo los json traidos por MYSQL para armar el JSON anidado de respuesta
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

      $packages = @json_decode($e['Packages'], true);
      if($packages){
        $e['Packages'] = $packages;
      }

      $locations = @json_decode($e['Locations'], true);
      if($locations){
        $e['Locations'] = $locations;
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

  public function createOffering($data) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("INSERT INTO Offerings (Title, ShortDescription, Description, CategoryID, UserID,
        Status, CreationDate, IsActive, Currency, Tags, SKU, Stock, ServiceType)
        VALUES (:Title, :ShortDescription, :Description, :CategoryID, :UserID, :Status,
        :CreationDate, 0, :Currency, :Tags, :SKU, :Stock, :ServiceType)");

      $stmt->execute([
        ':Title' => $data['Title'],
        ':ShortDescription' => $data['ShortDescription'],
        ':Description' => $data['Description'],
        ':CategoryID' => $data['CategoryID'],
        ':UserID' => $data['UserID'],
        ':Status' => 'Pending',
        ':CreationDate' => date('YmdHis'),
        ':Currency' => $data['Currency'],
        ':Tags' => is_array($data['Tags']) ? implode(",", $data['Tags']) : $data['Tags'],
        ':SKU' => $data['SKU'] ?? null,
        ':Stock' => $data['Stock'] ?? null,
        ':ServiceType' => $data['ServiceType']]
      );
      $id = $this->db->lastInsertId();

      // Insertar ubicaciones si existen
      if (!empty($data['Locations']) && is_array($data['Locations'])) {
        $stmt = $this->db->prepare("INSERT INTO OfferingLocations (OfferingID, CountryCode, State, City)
          VALUES (?, ?, ?, ?)");

        foreach ($data['Locations'] as $location) {
          $stmt->execute([$id, $location['CountryCode'], $location['State'], $location['City']]);
        }
      }

      if (isset($data['Faqs'])) {
        foreach ($data['Faqs'] as $faq) {
          $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, Position, Question, Answer)
            VALUES (?, ?, ?, ?)");
          $stmt->execute([$id, $faq['Position'], $faq['Question'], $faq['Answer']]);
        }
      }

      // Gestionar los paquetes, si están presentes en los datos
      if (isset($data['Packages']) && is_array($data['Packages'])) {
        // Eliminar los paquetes existentes para esta oferta
        $stmt = $this->db->prepare("DELETE FROM OfferingsPackages WHERE OfferingID = ?");
        $stmt->execute([$id]);

        // Insertar los nuevos paquetes
        $stmt = $this->db->prepare("INSERT INTO OfferingsPackages (OfferingID, Package, Price, Description, Conditions, SessionType)
                VALUES (:id, :package, :price, :description, :conditions, :sessionType)"
        );

        foreach ($data['Packages'] as $package) {
          $stmt->execute([
            ':id' => $id,
            ':package' => $package['Package'],
            ':price' => $package['Price'],
            ':description' => $package['Description'],
            ':conditions' => $package['Conditions'],
            ':sessionType' => $package['SessionType']
          ]);
        }
      }

      $offering = $this->getOfferingById($id);

      $this->db->commit(); # Confirmo transacción
      return $offering;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  public function approveOfferingById($id) {
    $stmt = $this->db->prepare("UPDATE Offerings
      SET Status = 'Active', IsActive = 1, Approved = 1
      WHERE OfferingID = ? AND Status != 'Deleted'");
    $stmt->execute([$id]);
  }

  public function updateOffering($id, $data)
  {
    if (empty($data)) {
      return (object) [
        "http_code" => 400,
        "error" => [
          "code" => "INVALID_PARAMETERS",
          "desc" => "Parameters are missing or invalid"
        ]
      ];
    }

    try {
      // Verificar si la oferta existe
      $stmt = $this->db->prepare("SELECT * FROM Offerings WHERE OfferingID = :id AND Status != 'Deleted'");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      $offering = $stmt->fetch();
      if (!$offering) {
        throw new NotFoundException("The specified offering does not exist");
      }

      // Lista de campos permitidos para actualizar
      $allowedFields = [
        'Title',
        'ShortDescription',
        'Description',
        'CategoryID',
        'Status',
        'Currency',
        'Tags',
        'SKU',
        'Stock',
        'ServiceType'
      ];

      // Filtrar faqs y packages antes del ciclo de validación
      $faqs = $data['Faqs'] ?? null;
      $packages = $data['Packages'] ?? null;
      $locations = $data['Locations'] ?? null;
      unset($data['Faqs'], $data['Packages'], $data['Locations']);

      // Construcción dinámica de la consulta
      $fields = [];
      foreach ($data as $key => $value) {
        if (in_array($key, $allowedFields)) {
          $fields[] = "$key = :$key";
        } else {
          return (object) [
            "http_code" => 400,
            "error" => [
              "code" => "INVALID_UPDATE_KEY",
              "desc" => "Key '$key' is not allowed to be updated"
            ]
          ];
        }
      }

      if (empty($fields) && !$faqs && !$packages && !$locations) {
        return (object) [
          "http_code" => 400,
          "error" => [
            "code" => "NO_FIELDS_TO_UPDATE",
            "desc" => "No valid fields to update"
          ]
        ];
      }

      // Verificar si se han modificado campos que requieren cambiar el estado
      $updateStatusRequired = false;
      if (
        isset($data['Title']) || isset($data['ShortDescription']) || isset($data['Description']) ||
        isset($faqs['Title']) || isset($faqs['Description']) || isset($packages['Question']) || isset($faqs['Answer'])
      ) {
        $updateStatusRequired = true;
      }

      // Modificación de la fecha de modificación
      $modificationDate = date("YmdHis");
      $fields[] = "ModificationDate = :ModificationDate";  // Siempre agregar ModificationDate

      // Construir la consulta SQL de actualización
      $statusQuery = $updateStatusRequired ? ", Status = 'Pending', IsActive = 0, Approved = 0" : "";
      $sql = "UPDATE Offerings SET " . implode(", ", $fields) . $statusQuery . " WHERE OfferingID = :id";
      $stmt = $this->db->prepare($sql);

      // Vincular los parámetros
      foreach ($data as $key => $value) {
        if (in_array($key, $allowedFields)) {
          $stmt->bindValue(":$key", $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
      }

      $stmt->bindValue(':id', $id, PDO::PARAM_INT);
      $stmt->bindValue(':ModificationDate', $modificationDate, PDO::PARAM_STR);
      $stmt->execute();

      if ($faqs !== null) {
        $this->updateOfferingFaqs($id, $faqs);
      }
      if ($packages !== null) {
        $this->updateOfferingPackages($id, $packages);
      }
      if ($locations !== null) {
        $this->updateOfferingLocations($id, $locations);
      }

      return $this->getOfferingById($id);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateOfferingLocations($id, $locations)
  {
    // Si se recibe `locations`, eliminar las existentes y agregar las nuevas
    if ($locations !== null &&  is_array($locations)) {
      // Eliminar ubicaciones actuales
      $stmt = $this->db->prepare("DELETE FROM OfferingLocations WHERE OfferingID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      // Insertar nuevas ubicaciones
      $stmt = $this->db->prepare("INSERT INTO OfferingLocations (OfferingID, CountryCode, State, City)
      VALUES (:id, :CountryCode, :State, :City)");

      foreach ($locations as $location) {
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':CountryCode', $location['CountryCode'], PDO::PARAM_STR);
        $stmt->bindParam(':State', $location['State'], PDO::PARAM_STR);
        $stmt->bindParam(':City', $location['City'], PDO::PARAM_STR);
        $stmt->execute();
      }
    }
  }

  public function updateOfferingFaqs($id, $faqs)
  {
    if ($faqs !== null && is_array($faqs)) {
      $stmt = $this->db->prepare("DELETE FROM OfferingsFaqs WHERE OfferingID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, Position, Question, Answer)
      VALUES (:id, :position, :question, :answer)");
      foreach ($faqs as $faq) {
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':position', $faq['Position'], PDO::PARAM_INT);
        $stmt->bindParam(':question', $faq['Question'], PDO::PARAM_STR);
        $stmt->bindParam(':answer', $faq['Answer'], PDO::PARAM_STR);
        $stmt->execute();
      }
    }
  }

  public function updateOfferingPackages($id, $packages)
  {
    if ($packages !== null && is_array($packages)) {
      $stmt = $this->db->prepare("DELETE FROM OfferingsPackages WHERE OfferingID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      $stmt = $this->db->prepare("INSERT INTO OfferingsPackages (OfferingID, Package, Price, Description, Conditions, SessionType)
      VALUES (:id, :package, :price, :description, :conditions, :sessionType)");
      foreach ($packages as $package) {
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':package', $package['Package'], PDO::PARAM_STR);
        $stmt->bindParam(':price', $package['Price'], PDO::PARAM_STR);
        $stmt->bindParam(':description', $package['Description'], PDO::PARAM_STR);
        $stmt->bindParam(':conditions', $package['Conditions'], PDO::PARAM_STR);
        $stmt->bindParam(':sessionType', $package['SessionType'], PDO::PARAM_STR);
        $stmt->execute();
      }
    }
  }

  public function deleteOffering($id)
  {
    try {
      $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Deleted', IsActive = 0
            WHERE OfferingID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getMediaById($id, $mediaID)
  {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Media
            WHERE MediaID = :mediaID AND OfferingID = :id");
      $stmt->bindParam(':mediaID', $mediaID, PDO::PARAM_INT);
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function createOfferingMedia($id, $title, $description, $position, $fileURL, $filePath, $mediaType)
  {
    try {
      // Verificar si ya existe una posición 0 asociada al OfferingID
      $stmt = $this->db->prepare("SELECT COUNT(*) AS count FROM Media WHERE OfferingID = :id AND Position = 0 AND MediaType = 'image'");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      // Asignar posición 0 si no existe ninguna imagen en esa posición
      $position = ($result['count'] === 0 && $mediaType === 'image') ? 0 : null;

      // Calcular la próxima posición si no es posición 0
      if ($position === null) {
        $stmt = $this->db->prepare("SELECT MAX(Position) AS max_position FROM Media WHERE OfferingID = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $maxPosition = $stmt->fetch(PDO::FETCH_ASSOC)['max_position'];
        $position = $maxPosition !== null ? $maxPosition + 1 : 1;
      }

      // Insertar en la tabla Media
      $stmt = $this->db->prepare("INSERT INTO Media (`OfferingID`, `Title`, `Description`, `URL`, `Path`, `MediaType`, `Position`)
            VALUES (:id, :title, :description, :fileURL, :filePath, :mediaType, :position)");

      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->bindParam(':title', $title, PDO::PARAM_STR);
      $stmt->bindParam(':description', $description, PDO::PARAM_STR);
      $stmt->bindParam(':position', $position, PDO::PARAM_INT);
      $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
      $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
      $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateOfferingMedia($id, $title, $description, $position, $mediaID, $fileURL = false, $filePath = false, $mediaType = false)
  {
    try {
      // Diferente update según se adjuntó un archivo o no
      if ($fileURL) {
        // Actualización para Media con archivo
        $stmt = $this->db->prepare("UPDATE Media
                SET Title = :title, Description = :description, URL = :fileURL, Path = :filePath, MediaType = :mediaType,
                Position = :position
                WHERE MediaID = :mediaID AND OfferingID = :id");

        $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
        $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
        $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
      } else {
        // Actualización para Media sin archivo
        $stmt = $this->db->prepare("UPDATE Media SET Title = :title, Description = :description, Position = :position
                WHERE MediaID = :mediaID AND OfferingID = :id");
      }

      // Vínculo de los parámetros para Media
      $stmt->bindParam(':title', $title, PDO::PARAM_STR);
      $stmt->bindParam(':description', $description, PDO::PARAM_STR);
      $stmt->bindParam(':position', $position, PDO::PARAM_INT);
      $stmt->bindParam(':mediaID', $mediaID, PDO::PARAM_INT);
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);

      // Ejecutar la consulta
      $stmt->execute();

      // Verificar si es necesario actualizar el Offering
      $updateStatusRequired = false;
      if (isset($title) || isset($description) || isset($fileURL) || isset($filePath)) {
        $updateStatusRequired = true;
      }

      // Modificación de la fecha de modificación
      $modificationDate = date("YmdHis");
      $fields = ["ModificationDate = :ModificationDate"]; // Siempre agregar ModificationDate

      // Construir la consulta SQL de actualización para Offering
      $statusQuery = $updateStatusRequired ? ", Status = 'Pending', IsActive = 0, Approved = 0" : "";
      $sql = "UPDATE Offerings SET " . implode(", ", $fields) . $statusQuery . " WHERE OfferingID = :id";

      // Preparar la consulta de Offering
      $stmt = $this->db->prepare($sql);
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->bindValue(':ModificationDate', $modificationDate, PDO::PARAM_STR);

      // Ejecutar la consulta de Offering
      $stmt->execute();

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function deleteOfferingMedia($mediaID)
  {
    try {
      $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = :mediaID");
      $stmt->bindParam(':mediaID', $mediaID, PDO::PARAM_INT);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }


  public function getMediaByOfferingId($id)
  {
    try {
      $stmt = $this->db->prepare("SELECT MediaID, Path FROM Media
            WHERE OfferingID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getMediaCountByType($id)
  {
    try {
      $stmt = $this->db->prepare("SELECT MediaType, COUNT(*) AS count FROM Media
            WHERE OfferingID = :id GROUP BY MediaType");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      $mediaCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Organizar resultados en un arreglo asociativo
      $counts = ['image' => 0, 'video' => 0];
      foreach ($mediaCounts as $mediaCount) {
        if ($mediaCount['MediaType'] === 'image') {
          $counts['image'] = $mediaCount['count'];
        } elseif ($mediaCount['MediaType'] === 'video') {
          $counts['video'] = $mediaCount['count'];
        }
      }
      return $counts;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  // Función para verificar suscripción de usuario a la categoría
  public function checkUserCategorySubscription($userID, $categoryID)
  {
    try {
      $stmt = $this->db->prepare("SELECT COUNT(*) FROM UsersCategories
            WHERE UserID = :userID AND CategoryID = :categoryID");
      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
      $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
      $stmt->execute();
      return $stmt->fetchColumn() > 0;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}