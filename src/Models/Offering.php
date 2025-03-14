<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Offering
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function getOfferings($paginator)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, u.UserID as author_UserID,
        u.FirstName as author_FirstName, u.LastName as author_LastName,
        u.DisplayName as author_DisplayName, m.URL as author_imgURL,
        GROUP_CONCAT(DISTINCT CONCAT(trim(ol.CountryCode), ':', trim(ol.State), ':', trim(ol.City))
        ORDER BY ol.CountryCode, ol.State, ol.City ASC SEPARATOR ', ') AS locations
        FROM Offerings AS o
        INNER JOIN Users AS u ON u.UserID = o.UserID
        LEFT JOIN Media AS m ON u.UserID = m.UserID
        LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
        LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
        GROUP BY o.OfferingID
        ORDER BY o.OfferingID
        LIMIT :_limit OFFSET :_offset");

      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($offerings as &$offering) {
        // Obtener FAQs
        $faqStmt = $this->db->prepare("SELECT Position, Question, Answer
          FROM OfferingsFaqs
          WHERE OfferingID = :offeringID
          ORDER BY Position ASC");
        $faqStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
        $faqStmt->execute();
        $faqs = $faqStmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener Packages
        $packagesStmt = $this->db->prepare("SELECT Package, Price, Description, Conditions, SessionType
          FROM OfferingsPackages
          WHERE OfferingID = :offeringID");
        $packagesStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
        $packagesStmt->execute();
        $packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Proceso de organización de medios
        $stmtMedia = $this->db->prepare("SELECT MediaID, URL, Title,
          Description, MediaType, Position
          FROM Media
          WHERE OfferingID = :id
          ORDER BY Position ASC
        ");
        $stmtMedia->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtMedia->execute();
        $mediaData = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

        $media = ["images" => [], "videos" => []];
        foreach ($mediaData as $item) {
          $mediaItem = [
            "Id" => $item['MediaID'],
            "Url" => $item['URL'],
            "Title" => $item['Title'],
            "Description" => $item['Description'],
            "Position" => $item["Position"]
          ];
          if ($item['MediaType'] === 'image') {
            $media["images"][] = $mediaItem;
          } elseif ($item['MediaType'] === 'video') {
            $media["videos"][] = $mediaItem;
          }
        }

        // Construcción del objeto principal
        $offering['media'] = $media;
        $offering['faqs'] = $faqs;
        $offering['packages'] = $packages;
        $offering['author'] = [
          "UserID" => $offering['author_UserID'],
          "FirstName" => $offering['author_FirstName'],
          "LastName" => $offering['author_LastName'],
          "DisplayName" => $offering['author_DisplayName'],
          "ImgURL" => $offering['author_imgURL']
        ];

        // Formatear las locaciones
        $offering['locations'] = is_null($offering['locations']) ? [] : array_map(
          function ($a) {
            $a = explode(":", $a);
            return [
              "countryCode" => $a[0],
              "state" => $a[1],
              "city" => $a[2]
            ];
          },
          explode(",", $offering['locations'])
        );

        unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'],
              $offering['author_DisplayName'], $offering['author_imgURL'],
              $offering['CountryCode'], $offering['CountryName'], $offering['State'], $offering['City']
        );
      }

      $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      return [
        "data" => $offerings,
        "rows" => [
          "total" => $total['total'],
          "fetched" => count($offerings)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getOfferingById($id)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, u.UserID as author_UserID,
        u.FirstName as author_FirstName, u.LastName as author_LastName,
        u.DisplayName as author_DisplayName, m.URL as author_imgURL,
        GROUP_CONCAT(DISTINCT CONCAT(trim(ol.CountryCode), ':', trim(ol.State), ':', trim(ol.City))
        ORDER BY ol.CountryCode, ol.State, ol.City ASC SEPARATOR ', ') AS locations
        FROM Offerings AS o
        INNER JOIN Users AS u ON u.UserID = o.UserID
        LEFT JOIN Media AS m ON u.UserID = m.UserID
        LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
        LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
        WHERE o.OfferingID = :id
        GROUP BY o.OfferingID");

      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($offerings)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "OFFERING_NOT_FOUND",
            "desc" => "No offering was found with the specified ID"
          ]
        ];
      }

      // Consulta para FAQs
      $stmtFaqs = $this->db->prepare("
            SELECT Position, Question, Answer
            FROM OfferingsFaqs
            WHERE OfferingID = :id
            ORDER BY Position ASC
        ");
      $stmtFaqs->bindParam(':id', $id, PDO::PARAM_INT);
      $stmtFaqs->execute();
      $faqs = $stmtFaqs->fetchAll(PDO::FETCH_ASSOC);

      // Consulta para Packages
      $stmtPackages = $this->db->prepare("
            SELECT Package, Price, Description, Conditions, SessionType
            FROM OfferingsPackages
            WHERE OfferingID = :id
        ");
      $stmtPackages->bindParam(':id', $id, PDO::PARAM_INT);
      $stmtPackages->execute();
      $packages = $stmtPackages->fetchAll(PDO::FETCH_ASSOC);

      // Proceso de organización de medios
      $stmtMedia = $this->db->prepare("SELECT MediaID, URL, Title,
        Description, MediaType, Position
        FROM Media
        WHERE OfferingID = :id
        ORDER BY Position ASC
      ");
      $stmtMedia->bindParam(':id', $id, PDO::PARAM_INT);
      $stmtMedia->execute();
      $mediaData = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

      $media = ["images" => [], "videos" => []];
      foreach ($mediaData as $item) {
        $mediaItem = [
          "Id" => $item['MediaID'],
          "Url" => $item['URL'],
          "Title" => $item['Title'],
          "Description" => $item['Description'],
          "Position" => $item["Position"]
        ];
        if ($item['MediaType'] === 'image') {
          $media["images"][] = $mediaItem;
        } elseif ($item['MediaType'] === 'video') {
          $media["videos"][] = $mediaItem;
        }
      }

      // Construcción del objeto principal
      $offering = $offerings[0];
      $offering['media'] = $media;
      $offering['faqs'] = $faqs;
      $offering['packages'] = $packages;
      $offering['author'] = [
        "UserID" => $offering['author_UserID'],
        "FirstName" => $offering['author_FirstName'],
        "LastName" => $offering['author_LastName'],
        "DisplayName" => $offering['author_DisplayName'],
        "ImgURL" => $offering['author_imgURL']
      ];

      // Formatear las locaciones
      $offering['locations'] = is_null($offering['locations']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return [
            "countryCode" => $a[0],
            "state" => $a[1],
            "city" => $a[2]
          ];
        },
        explode(",", $offering['locations'])
      );

      unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'],
            $offering['author_DisplayName'], $offering['author_imgURL'],
            $offering['CountryCode'], $offering['CountryName'], $offering['State'], $offering['City']
      );

      return (object) [
        "http_code" => 200,
        "data" => $offering
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getOfferingsByCategoryId($paginator, $categoryId)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, u.UserID as author_UserID,
        u.FirstName as author_FirstName, u.LastName as author_LastName,
        u.DisplayName as author_DisplayName, m.URL as author_imgURL,
        GROUP_CONCAT(DISTINCT CONCAT(trim(ol.CountryCode), ':', trim(ol.State), ':', trim(ol.City))
        ORDER BY ol.CountryCode, ol.State, ol.City ASC SEPARATOR ', ') AS locations
        FROM Offerings AS o
        INNER JOIN Users AS u ON u.UserID = o.UserID
        LEFT JOIN Media AS m ON u.UserID = m.UserID
        LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
        LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
        WHERE o.CategoryID = :categoryId
        GROUP BY o.OfferingID
        ORDER BY o.OfferingID
        LIMIT :_limit OFFSET :_offset");

      $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($offerings as &$offering) {
        // Obtener FAQs
        $faqStmt = $this->db->prepare("SELECT Position, Question, Answer
          FROM OfferingsFaqs
          WHERE OfferingID = :offeringID
          ORDER BY Position ASC");
        $faqStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
        $faqStmt->execute();
        $faqs = $faqStmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener Packages
        $packagesStmt = $this->db->prepare("SELECT Package, Price, Description, Conditions, SessionType
          FROM OfferingsPackages
          WHERE OfferingID = :offeringID");
        $packagesStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
        $packagesStmt->execute();
        $packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Proceso de organización de medios
        $stmtMedia = $this->db->prepare("SELECT MediaID, URL, Title,
          Description, MediaType, Position
          FROM Media
          WHERE OfferingID = :id
          ORDER BY Position ASC
        ");
        $stmtMedia->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtMedia->execute();
        $mediaData = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

        $media = ["images" => [], "videos" => []];
        foreach ($mediaData as $item) {
          $mediaItem = [
            "Id" => $item['MediaID'],
            "Url" => $item['URL'],
            "Title" => $item['Title'],
            "Description" => $item['Description'],
            "Position" => $item["Position"]
          ];
          if ($item['MediaType'] === 'image') {
            $media["images"][] = $mediaItem;
          } elseif ($item['MediaType'] === 'video') {
            $media["videos"][] = $mediaItem;
          }
        }

        // Construcción del objeto principal
        $offering['media'] = $media;
        $offering['faqs'] = $faqs;
        $offering['packages'] = $packages;
        $offering['author'] = [
          "UserID" => $offering['author_UserID'],
          "FirstName" => $offering['author_FirstName'],
          "LastName" => $offering['author_LastName'],
          "DisplayName" => $offering['author_DisplayName'],
          "ImgURL" => $offering['author_imgURL']
        ];

        // Formatear las locaciones
        $offering['locations'] = is_null($offering['locations']) ? [] : array_map(
          function ($a) {
            $a = explode(":", $a);
            return [
              "countryCode" => $a[0],
              "state" => $a[1],
              "city" => $a[2]
            ];
          },
          explode(",", $offering['locations'])
        );

        unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'],
              $offering['author_DisplayName'], $offering['author_imgURL'],
              $offering['CountryCode'], $offering['CountryName'], $offering['State'], $offering['City']
        );
      }

      $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      return [
        "data" => $offerings,
        "rows" => [
          "total" => $total['total'],
          "fetched" => count($offerings)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }


  public function getOfferingsByUserId($paginator, $userId)
  {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS o.*, u.UserID as author_UserID,
        u.FirstName as author_FirstName, u.LastName as author_LastName,
        u.DisplayName as author_DisplayName, m.URL as author_imgURL,
        GROUP_CONCAT(DISTINCT CONCAT(trim(ol.CountryCode), ':', trim(ol.State), ':', trim(ol.City))
        ORDER BY ol.CountryCode, ol.State, ol.City ASC SEPARATOR ', ') AS locations
        FROM Offerings AS o
        INNER JOIN Users AS u ON u.UserID = o.UserID
        LEFT JOIN Media AS m ON u.UserID = m.UserID
        LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
        LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode
        WHERE o.UserID = :userId
        GROUP BY o.OfferingID
        LIMIT :_limit OFFSET :_offset");

      $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);

      foreach ($offerings as &$offering) {
        // Obtener FAQs
        $faqStmt = $this->db->prepare("SELECT Position, Question, Answer
          FROM OfferingsFaqs
          WHERE OfferingID = :offeringID
          ORDER BY Position ASC");
        $faqStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
        $faqStmt->execute();
        $faqs = $faqStmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener Packages
        $packagesStmt = $this->db->prepare("SELECT Package, Price, Description, Conditions, SessionType
          FROM OfferingsPackages
          WHERE OfferingID = :offeringID");
        $packagesStmt->bindValue(':offeringID', $offering['OfferingID'], PDO::PARAM_INT);
        $packagesStmt->execute();
        $packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Proceso de organización de medios
        $stmtMedia = $this->db->prepare("SELECT MediaID, URL, Title,
          Description, MediaType, Position
          FROM Media
          WHERE OfferingID = :id
          ORDER BY Position ASC
        ");
        $stmtMedia->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtMedia->execute();
        $mediaData = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

        $media = ["images" => [], "videos" => []];
        foreach ($mediaData as $item) {
          $mediaItem = [
            "Id" => $item['MediaID'],
            "Url" => $item['URL'],
            "Title" => $item['Title'],
            "Description" => $item['Description'],
            "Position" => $item["Position"]
          ];
          if ($item['MediaType'] === 'image') {
            $media["images"][] = $mediaItem;
          } elseif ($item['MediaType'] === 'video') {
            $media["videos"][] = $mediaItem;
          }
        }

        // Construcción del objeto principal
        $offering['media'] = $media;
        $offering['faqs'] = $faqs;
        $offering['packages'] = $packages;
        $offering['author'] = [
          "UserID" => $offering['author_UserID'],
          "FirstName" => $offering['author_FirstName'],
          "LastName" => $offering['author_LastName'],
          "DisplayName" => $offering['author_DisplayName'],
          "ImgURL" => $offering['author_imgURL']
        ];

        // Formatear las locaciones
        $offering['locations'] = is_null($offering['locations']) ? [] : array_map(
          function ($a) {
            $a = explode(":", $a);
            return [
              "countryCode" => $a[0],
              "state" => $a[1],
              "city" => $a[2]
            ];
          },
          explode(",", $offering['locations'])
        );

        unset($offering['author_UserID'], $offering['author_FirstName'], $offering['author_LastName'],
              $offering['author_DisplayName'], $offering['author_imgURL'],
              $offering['CountryCode'], $offering['CountryName'], $offering['State'], $offering['City']
        );
      }

      $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      return [
        "data" => $offerings,
        "rows" => [
          "total" => $total['total'],
          "fetched" => count($offerings)
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function createOffering($data)
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
      // Validar datos obligatorios
      if (!isset($data['Title'], $data['ShortDescription'], $data['Description'], $data['CategoryID'], $data['UserID'])) {
        return (object) [
          "http_code" => 400,
          "error" => [
            "code" => "MISSING_REQUIRED_FIELDS",
            "desc" => "Missing required fields: Title, ShortDescription, Description, CategoryID, or UserID."
          ]
        ];
      }

      $stmt = $this->db->prepare("INSERT INTO Offerings (Title, ShortDescription, Description, CategoryID, UserID,
            Status, CreationDate, IsActive, Currency, Tags, SKU, Stock, ServiceType)
            VALUES (:Title, :ShortDescription, :Description, :CategoryID, :UserID, :Status, :CreationDate, 0, :Currency, :Tags, :SKU, :Stock, :ServiceType)");

      $stmt->bindParam(':Title', $data['Title'], PDO::PARAM_STR);
      $stmt->bindParam(':ShortDescription', $data['ShortDescription'], PDO::PARAM_STR);
      $stmt->bindParam(':Description', $data['Description'], PDO::PARAM_STR);
      $stmt->bindParam(':CategoryID', $data['CategoryID'], PDO::PARAM_INT);
      $stmt->bindParam(':UserID', $data['UserID'], PDO::PARAM_INT);
      $stmt->bindValue(':Status', 'Pending', PDO::PARAM_STR);
      $stmt->bindValue(':CreationDate', date('YmdHis'), PDO::PARAM_STR);
      $stmt->bindValue(':Currency', $data['Currency'], PDO::PARAM_STR);
      $stmt->bindValue(':Tags', is_array($data['Tags']) ? implode(",", $data['Tags']) : $data['Tags'], PDO::PARAM_STR);
      $stmt->bindValue(':SKU', $data['SKU'] ?? null, ($data['SKU'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':Stock', $data['Stock'] ?? null, ($data['Stock'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $stmt->bindParam(':ServiceType', $data['ServiceType'], PDO::PARAM_STR);

      $stmt->execute();
      $id = $this->db->lastInsertId();

      // Insertar ubicaciones si existen
      if (!empty($data['locations']) && is_array($data['locations'])) {
        $stmt = $this->db->prepare("INSERT INTO OfferingLocations (OfferingID, CountryCode, State, City)
                VALUES (:OfferingID, :CountryCode, :State, :City)");

        foreach ($data['locations'] as $location) {
          $stmt->bindParam(':OfferingID', $id, PDO::PARAM_INT);
          $stmt->bindParam(':CountryCode', $location['countrycode'], PDO::PARAM_STR);
          $stmt->bindParam(':State', $location['state'], PDO::PARAM_STR);
          $stmt->bindParam(':City', $location['city'], PDO::PARAM_STR);
          $stmt->execute();
        }
      }

      if (isset($data['faqs'])) {
        foreach ($data['faqs'] as $faq) {
          $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, Position, Question, Answer)
                  VALUES (:id, :position, :question, :answer)");

          $stmt->bindParam(':id', $id, PDO::PARAM_INT);
          $stmt->bindParam(':position', $faq['Position'], PDO::PARAM_INT);
          $stmt->bindParam(':question', $faq['Question'], PDO::PARAM_STR);
          $stmt->bindParam(':answer', $faq['Answer'], PDO::PARAM_STR);
          $stmt->execute();
        }
      }

      // Gestionar los paquetes, si están presentes en los datos
      if (isset($data['packages']) && is_array($data['packages'])) {

        // Eliminar los paquetes existentes para esta oferta
        $stmt = $this->db->prepare("DELETE FROM OfferingsPackages WHERE OfferingID = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Insertar los nuevos paquetes
        $stmt = $this->db->prepare("INSERT INTO OfferingsPackages (OfferingID, Package, Price, Description, Conditions, SessionType)
                VALUES (:id, :package, :price, :description, :conditions, :sessionType)"
        );

        foreach ($data['packages'] as $package) {
          $stmt->bindParam(':id', $id, PDO::PARAM_INT);
          $stmt->bindParam(':package', $package['Package'], PDO::PARAM_STR);
          $stmt->bindParam(':price', $package['Price'], PDO::PARAM_STR);
          $stmt->bindParam(':description', $package['Description'], PDO::PARAM_STR);
          $stmt->bindParam(':conditions', $package['Conditions'], PDO::PARAM_STR);
          $stmt->bindParam(':sessionType', $package['SessionType'], PDO::PARAM_STR);
          $stmt->execute();
        }
      }

      return $this->getOfferingById($id);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function approveOfferingById($id)
  {
    try {
      $stmt = $this->db->prepare("UPDATE Offerings SET Status = 'Active', IsActive = 1, Approved = 1
            WHERE OfferingID = :id AND Status != 'Deleted'");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
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
      $faqs = $data['faqs'] ?? null;
      $packages = $data['packages'] ?? null;
      unset($data['faqs'], $data['packages']);

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

      if (empty($fields) && !$faqs && !$packages && !$data['media']) {
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

      // Si se recibe `locations`, eliminar las existentes y agregar las nuevas
      if (isset($data['locations']) && is_array($data['locations'])) {
        // Eliminar ubicaciones actuales
        $stmt = $this->db->prepare("DELETE FROM OfferingLocations WHERE OfferingID = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Insertar nuevas ubicaciones
        $stmt = $this->db->prepare("INSERT INTO OfferingLocations (OfferingID, CountryCode, State, City)
                VALUES (:OfferingID, :CountryCode, :State, :City)");

        foreach ($data['locations'] as $location) {
          $stmt->bindParam(':OfferingID', $id, PDO::PARAM_INT);
          $stmt->bindParam(':CountryCode', $location['countrycode'], PDO::PARAM_STR);
          $stmt->bindParam(':State', $location['state'], PDO::PARAM_STR);
          $stmt->bindParam(':City', $location['city'], PDO::PARAM_STR);
          $stmt->execute();
        }
      }

      return $this->getOfferingById($id);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateOfferingFaqs($id, $faqs)
  {
    if ($faqs !== null && is_array($faqs)) {
      $stmt = $this->db->prepare("DELETE FROM OfferingsFaqs WHERE OfferingID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      $stmt = $this->db->prepare("INSERT INTO OfferingsFaqs (OfferingID, Position, Question, Answer) VALUES (:id, :position, :question, :answer)");
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

      $stmt = $this->db->prepare("INSERT INTO OfferingsPackages (OfferingID, Package, Price, Description, Conditions, SessionType) VALUES (:id, :package, :price, :description, :conditions, :sessionType)");
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

  public function getMediaById($id, $media_id)
  {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Media
            WHERE MediaID = :media_id AND OfferingID = :id");
      $stmt->bindParam(':media_id', $media_id, PDO::PARAM_INT);
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
      $position = ($result['count'] == 0 && $mediaType === 'image') ? 0 : null;

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

  public function updateOfferingMedia($id, $title, $description, $position, $media_id, $fileURL = false, $filePath = false, $mediaType = false)
  {
    try {
      // Diferente update según se adjuntó un archivo o no
      if ($fileURL) {
        // Actualización para Media con archivo
        $stmt = $this->db->prepare("UPDATE Media
                SET Title = :title, Description = :description, URL = :fileURL, Path = :filePath, MediaType = :mediaType, Position = :position
                WHERE MediaID = :media_id AND OfferingID = :id");

        $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
        $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
        $stmt->bindParam(':mediaType', $mediaType, PDO::PARAM_STR);
      } else {
        // Actualización para Media sin archivo
        $stmt = $this->db->prepare("UPDATE Media SET Title = :title, Description = :description, Position = :position
                WHERE MediaID = :media_id AND OfferingID = :id");
      }

      // Vínculo de los parámetros para Media
      $stmt->bindParam(':title', $title, PDO::PARAM_STR);
      $stmt->bindParam(':description', $description, PDO::PARAM_STR);
      $stmt->bindParam(':position', $position, PDO::PARAM_INT);
      $stmt->bindParam(':media_id', $media_id, PDO::PARAM_INT);
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

  public function deleteOfferingMedia($media_id)
  {
    try {
      $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = :media_id");
      $stmt->bindParam(':media_id', $media_id, PDO::PARAM_INT);
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
      $stmt = $this->db->prepare("SELECT MediaType, COUNT(*) as count FROM Media
            WHERE OfferingID = :id GROUP BY MediaType");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      $mediaCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Organizar resultados en un arreglo asociativo
      $counts = ['image' => 0, 'video' => 0];
      foreach ($mediaCounts as $mediaCount) {
        if ($mediaCount['MediaType'] == 'image') {
          $counts['image'] = $mediaCount['count'];
        } elseif ($mediaCount['MediaType'] == 'video') {
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