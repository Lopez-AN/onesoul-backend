<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Search
{
  protected $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function searchCategories($paginator, $query)
  {
    try {
      $searchQuery = "%$query%";
      $stmt = $this->pdo->prepare("SELECT SQL_CALC_FOUND_ROWS c.*,m.URL as imgURL
            FROM Categories AS c
            LEFT JOIN Media as m ON c.CategoryID = m.CategoryID
            WHERE (c.Name LIKE :search1 OR c.Description LIKE :search2) AND c.IsActive = 1
            ORDER BY c.CategoryID
            LIMIT :_limit OFFSET :_offset");

      $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
      $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->pdo->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

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

  public function searchOfferings($paginator, $query)
  {
    try {
      $searchQuery = "%$query%";
      $stmt = $this->pdo->prepare("SELECT o.*,
        u.UserID AS author_UserID,
        u.DisplayName AS author_DisplayName,
        u.FirstName AS author_FirstName,
        u.LastName AS author_LastName,
        u.UserName as author_UserName,
        round(avg(ru.Rating),2) as author_Rating,
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
        ) FROM OfferingsFaqs f WHERE f.OfferingID = o.OfferingID) AS faqs,
        -- Subconsulta para packages
        (SELECT JSON_ARRAYAGG(
          JSON_OBJECT(
            'Package', p.Package,
            'Price', p.Price,
            'Description', p.Description,
            'Conditions', p.Conditions,
            'SessionType', p.SessionType
          )
        ) FROM OfferingsPackages p WHERE p.OfferingID = o.OfferingID) AS packages,
        -- Subconsulta para locations
        (SELECT JSON_ARRAYAGG(
          JSON_OBJECT(
            'CountryCode', l.CountryCode,
            'CountryName', c.CountryName,
            'State', l.State,
            'City', l.City
          )
        ) FROM OfferingLocations l WHERE l.OfferingID = o.OfferingID) AS locations,
        ROUND(AVG(r.Rating),2) as rating
        FROM Offerings AS o
        INNER JOIN Users AS u ON u.UserID = o.UserID
        LEFT JOIN Reviews as r ON o.OfferingID = r.OfferingID
        LEFT JOIN Reviews as ru ON u.UserID = ru.SUserID
        LEFT JOIN OfferingLocations AS ol ON o.OfferingID = ol.OfferingID
        LEFT JOIN Countries AS c ON ol.CountryCode = c.CountryCode        
        WHERE (o.Title LIKE :search1 OR o.Description LIKE :search2
        OR o.ShortDescription LIKE :search3 OR o.Tags LIKE :search4)
        AND o.Status = 'Active'
        GROUP BY o.OfferingID
        ORDER BY o.OfferingID
        LIMIT :_limit OFFSET :_offset"
      );

      $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
      $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
      $stmt->bindParam(':search3', $searchQuery, PDO::PARAM_STR);
      $stmt->bindParam(':search4', $searchQuery, PDO::PARAM_STR);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->pdo->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      // Desagrupo los json traidos por MYSQL para armar el JSON anidado de respuesta
      $rs = array_map(function ($e) {
        $e['media'] = [
          'images' => [],
          'videos' => []
        ];

        $images = @json_decode($e['media_images'], true);
        if($images){
          $e['media']['images'] = $images;
        }
        unset($e['media_images']);

        $videos = @json_decode($e['media_videos'], true);
        if($videos){
          $e['media']['videos'] = $videos;
        }
        unset($e['media_videos']);

        $faqs = @json_decode($e['faqs'], true);
        if($faqs){
          $e['faqs'] = $faqs;
        }

        $packages = @json_decode($e['packages'], true);
        if($packages){
          $e['packages'] = $packages;
        }

        $locations = @json_decode($e['locations'], true);
        if($locations){
          $e['locations'] = $locations;
        }        

        $e['author'] = [
          "UserID" => $e['author_UserID'],
          "DisplayName" => $e['author_DisplayName'],
          "FirstName" => $e['author_FirstName'],
          "LastName" => $e['author_LastName'],
          "Rating" => floatVal($e['author_Rating']),
          "TotalReviews" => intval($e['author_TotalReviews']),
          "ImgURL" => $e['author_ImgURL']
        ];

        $e['AverageRating'] = floatVal($e['rating']);

        unset($e['rating'],
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
      }, $rs);

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

  public function searchUsers($paginator, $query, $type)
  {
    $filterSeeker = in_array($type,['seeker','seekers']) ? " u.UserType = 'Seeker' AND " : "";

    try {
      $query = explode(" ", $query);
      $query = array_map(function ($e) {
        return trim($e);
      }, $query);
      $query = implode(" ", $query);
      $searchQuery = "%$query%";

      if(in_array($type,['guide','guides'])){
        $stmt = $this->pdo->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName, u.DisplayName,
        u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
        u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
        u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
        u.LastLogin, u.UserLevel, u.SignedContract,
        GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name)) ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
        u.LegalDocuments, u.ShortDescription, round(avg(r.Rating),2) as rating,
        COUNT(DISTINCT r.ReviewID) AS TotalReviews,
        sub.avgRate, sub.hasVirtual, sub.hasInPerson, m.URL as imgURL
        FROM Users as u
        LEFT JOIN UsersCategories as uc ON uc.UserID = u.UserID
        LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
        LEFT JOIN Media as m ON u.UserID = m.UserID
        LEFT JOIN Reviews as r ON u.UserID = r.GUserID
        LEFT JOIN Offerings as o ON u.UserID = o.UserID
        LEFT JOIN (
          SELECT ROUND(AVG(p.Price),0) as AvgRate, o.UserID,
          MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
          MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
          FROM Offerings as o
          INNER JOIN OfferingsPackages as p ON o.OfferingID = p.OfferingID
          WHERE o.Status = 'Active'
          GROUP BY o.UserID
        ) as sub ON sub.UserID = u.UserID
        WHERE (
          u.FirstName LIKE :search1 OR
          u.LastName LIKE :search2 OR
          u.Biography LIKE :search3 OR
          CONCAT(u.FirstName,' ',u.LastName) LIKE :search4
          OR (o.Status = 'Active' AND (o.Title LIKE :search5 OR o.ShortDescription LIKE :search6))
        )
        AND u.DeactivationDate IS NULL
        GROUP BY u.UserID
        ORDER BY u.UserID
        LIMIT :_limit OFFSET :_offset");

        $stmt->bindParam(':search5', $searchQuery, PDO::PARAM_STR);
        $stmt->bindParam(':search6', $searchQuery, PDO::PARAM_STR);
      }else{
        $stmt = $this->pdo->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName, u.DisplayName,
        u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
        u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
        u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
        u.LastLogin, u.UserLevel, u.SignedContract,
        GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name)) ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
        u.LegalDocuments, u.ShortDescription, round(avg(r.Rating),2) as rating,
        COUNT(DISTINCT r.ReviewID) AS TotalReviews,
        sub.avgRate, sub.hasVirtual, sub.hasInPerson, m.URL as imgURL
        FROM Users as u
        LEFT JOIN UsersCategories as uc ON uc.UserID = u.UserID
        LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
        LEFT JOIN Media as m ON u.UserID = m.UserID
        LEFT JOIN Reviews as r ON u.UserID = r.GUserID
        LEFT JOIN (
          SELECT ROUND(AVG(p.Price),0) as AvgRate, o.UserID,
          MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
          MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
          FROM Offerings as o
          INNER JOIN OfferingsPackages as p WHERE o.OfferingID = p.OfferingID
          GROUP BY o.UserID
        ) as sub ON sub.UserID = u.UserID
        WHERE $filterSeeker u.UserType NOT IN ('Moderator','Admin')
        AND (
          u.FirstName LIKE :search1 OR
          u.LastName LIKE :search2 OR
          u.Biography LIKE :search3 OR
          CONCAT(u.FirstName,' ',u.LastName) LIKE :search4
        ) AND u.DeactivationDate IS NULL
        GROUP BY u.UserID
        ORDER BY u.UserID
        LIMIT :_limit OFFSET :_offset");
      }

      $stmt->bindParam(':search1', $searchQuery, PDO::PARAM_STR);
      $stmt->bindParam(':search2', $searchQuery, PDO::PARAM_STR);
      $stmt->bindParam(':search3', $searchQuery, PDO::PARAM_STR);
      $stmt->bindParam(':search4', $searchQuery, PDO::PARAM_STR);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->pdo->query("SELECT FOUND_ROWS() as total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      $rs = array_map(function ($e) {
        $e['Categories'] = is_null($e['Categories']) ? [] : array_map(
          function ($a) {
            $a = explode(":", $a);
            return ["id" => intval($a[0]), "name" => $a[1]];
          },
          explode(",", $e['Categories'])
        );

        // Agregar sessionType con valores booleanos
        $e['sessionType'] = [
        "virtual" => $e['hasVirtual'] == 1,
        "in-person" => $e['hasInPerson'] == 1
        ];

        unset($e['hasVirtual'], $e['hasInPerson']);
        return $e;
      }, $rs);

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
}
