<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class User
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  /**
   * Obtiene todos los usuarios con paginación
   * @param  object $paginator: objeto con limit y offset
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function getUsers($paginator) {
    try {
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
      u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
      u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
      u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, sp.Name, sp.Description,
      -- Subconsulta para reviews
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
      LEFT JOIN SubscriptionPlans AS sp ON s.PlanID = sp.PlanID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      GROUP BY u.UserID
      ORDER BY u.UserID
      LIMIT :_limit OFFSET :_offset");

      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      $rs = array_map(function ($e) {
        $e['Floor'] = is_null($e['Floor']) ? null : (int)$e['Floor'];
        $e['UserLevel'] = !$e['UserLevel'] ? 1 : (int)$e['UserLevel'];
        $e['ValidatedEmail'] = (bool)$e['ValidatedEmail'];
        $e['ValidatedPhone'] = (bool)$e['ValidatedPhone'];
        $e['TwoFactorAuth'] = (bool)$e['TwoFactorAuth'];
        $e['Categories'] = is_null($e['Categories']) ? [] : array_map(
          function ($a) {
            $a = explode(":", $a);
            return ["Id" => intval($a[0]), "Name" => $a[1]];
          },
          explode(",", $e['Categories'])
        );

        // Agregar sessionType con valores booleanos
        $e['SessionType'] = [
          "Virtual" => $e['hasVirtual'] == 1,
          "InPerson" => $e['hasInPerson'] == 1
        ];

        unset($e['hasVirtual'], $e['hasInPerson']);

        // Agregar información de suscripción
        $e['Subscription'] = is_null($e['PlanID']) ? null : [
          "PlanID" => (int)$e['PlanID'],
          "StartDate" => $e['StartDate'],
          "Name" => $e['Name'],
          "Description" => $e['Description']
        ];
        unset(
          $e['PlanID'],
          $e['StartDate'],
          $e['Name'],
          $e['Description']
        );

        return $e;
      }, $rs);

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

  /**
   * Obtiene un usuario por su ID
   * @param  int $id: ID del usuario
   * @return array|null: datos del usuario o null si no existe
   * @throws DatabaseException
   **/
  public function getUserById($id) {
    try {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
      u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
      u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
      u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status,
      -- Subconsulta para reviews
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      WHERE u.UserID = :id
      GROUP BY u.UserID
      ORDER BY u.UserID");

      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      if (empty($user)) {
        return null;
      }

      $user['Floor'] = is_null($user['Floor']) ? null : (int)$user['Floor'];
      $user['UserLevel'] = !$user['UserLevel'] ? 1 : (int)$user['UserLevel'];
      $user['ValidatedEmail'] = (bool)$user['ValidatedEmail'];
      $user['ValidatedPhone'] = (bool)$user['ValidatedPhone'];
      $user['TwoFactorAuth'] = (bool)$user['TwoFactorAuth'];
      $user['Categories'] = is_null($user['Categories']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return ["Id" => intval($a[0]), "Name" => $a[1]];
        },
        explode(",", $user['Categories'])
      );

      // Agregar sessionType con valores booleanos
      $user['SessionType'] = [
        "Virtual" => $user['hasVirtual'] == 1,
        "InPerson" => $user['hasInPerson'] == 1
      ];
      unset($user['hasVirtual'], $user['hasInPerson']);

      // Agregar información histórica de suscripción
      $user['HistorySubscription'] = is_null($user['PlanID']) ? null : [
        "LatestPlanID" => (int)$user['PlanID'],
        "StartDate" => $user['StartDate'],
        "EndDate" => $user['EndDate'],
        "Status" => $user['Status'],
        "UsedTrial" => 'True'
      ];
      unset(
        $user['PlanID'],
        $user['StartDate'],
        $user['EndDate'],
        $user['Status']
      );

      return $user;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene un usuario por username
   * @param  string $username: nombre de usuario
   * @return array|null: datos del usuario o null si no existe
   * @throws DatabaseException
   **/
  public function getUserByUserName($username) {
    try {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
      u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
      u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
      u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status,
      -- Subconsulta para reviews
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      WHERE u.UserName = :username
      GROUP BY u.UserID
      ORDER BY u.UserID");

      $stmt->bindParam(':username', $username, PDO::PARAM_STR);
      $stmt->execute();

      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      if (empty($user)) {
        return null;
      }

      $user['Floor'] = is_null($user['Floor']) ? null : (int)$user['Floor'];
      $user['UserLevel'] = !$user['UserLevel'] ? 1 : (int)$user['UserLevel'];
      $user['ValidatedEmail'] = (bool)$user['ValidatedEmail'];
      $user['ValidatedPhone'] = (bool)$user['ValidatedPhone'];
      $user['TwoFactorAuth'] = (bool)$user['TwoFactorAuth'];
      $user['Categories'] = is_null($user['Categories']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return ["Id" => intval($a[0]), "Name" => $a[1]];
        },
        explode(",", $user['Categories'])
      );

      // Agregar sessionType con valores booleanos
      $user['SessionType'] = [
        "Virtual" => $user['hasVirtual'] == 1,
        "InPerson" => $user['hasInPerson'] == 1
      ];
      unset($user['hasVirtual'], $user['hasInPerson']);

      // Agregar información histórica de suscripción
      $user['HistorySubscription'] = is_null($user['PlanID']) ? null : [
        "LatestPlanID" => (int)$user['PlanID'],
        "StartDate" => $user['StartDate'],
        "EndDate" => $user['EndDate'],
        "Status" => $user['Status'],
      "UsedTrial" => 'True'
      ];
      unset(
        $user['PlanID'],
        $user['StartDate'],
        $user['EndDate'],
        $user['Status']
      );

      return $user;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene un usuario por email
   * @param  string $email: email del usuario
   * @return array|null: datos del usuario o null si no existe
   * @throws DatabaseException
   **/
  public function getUserByEmail($email) {
    try {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
      u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
      u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
      u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status,
      -- Subconsulta para reviews
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      WHERE u.Email = :email
      GROUP BY u.UserID
      ORDER BY u.UserID");

      $stmt->bindParam(':email', $email, PDO::PARAM_STR);
      $stmt->execute();

      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      if (empty($user)) {
        return null;
      }

      $user['Floor'] = is_null($user['Floor']) ? null : (int)$user['Floor'];
      $user['UserLevel'] = !$user['UserLevel'] ? 1 : (int)$user['UserLevel'];
      $user['ValidatedEmail'] = (bool)$user['ValidatedEmail'];
      $user['ValidatedPhone'] = (bool)$user['ValidatedPhone'];
      $user['TwoFactorAuth'] = (bool)$user['TwoFactorAuth'];
      $user['Categories'] = is_null($user['Categories']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return ["Id" => intval($a[0]), "Name" => $a[1]];
        },
        explode(",", $user['Categories'])
      );

      // Agregar sessionType con valores booleanos
      $user['SessionType'] = [
        "Virtual" => $user['hasVirtual'] == 1,
        "InPerson" => $user['hasInPerson'] == 1
      ];
      unset($user['hasVirtual'], $user['hasInPerson']);

      // Agregar información histórica de suscripción
      $user['HistorySubscription'] = is_null($user['PlanID']) ? null : [
        "LatestPlanID" => (int)$user['PlanID'],
        "StartDate" => $user['StartDate'],
        "EndDate" => $user['EndDate'],
        "Status" => $user['Status'],
      "UsedTrial" => 'True'
      ];
      unset(
        $user['PlanID'],
        $user['StartDate'],
        $user['EndDate'],
        $user['Status']
      );

      return $user;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene un usuario por OAuth ID
   * @param  string $oAuthID: ID del OAuth
   * @param  string $oAuthService: servicio OAuth (Google, Facebook, etc)
   * @return array|null: datos del usuario o null si no existe
   * @throws DatabaseException
   **/
  public function getUserByOAuthID($oAuthID, $oAuthService) {
    try {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
      u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
      u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
      u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status,
      -- Subconsulta para reviews
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      WHERE u.Oauth2ID = :oAuthID AND u.Oauth2Service = :oAuthService
      GROUP BY u.UserID
      ORDER BY u.UserID");

      $stmt->bindParam(':oAuthID', $oAuthID, PDO::PARAM_STR);
      $stmt->bindParam(':oAuthService', $oAuthService, PDO::PARAM_STR);
      $stmt->execute();

      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      if (empty($user)) {
        return null;
      }

      $user['Floor'] = is_null($user['Floor']) ? null : (int)$user['Floor'];
      $user['UserLevel'] = !$user['UserLevel'] ? 1 : (int)$user['UserLevel'];
      $user['ValidatedEmail'] = (bool)$user['ValidatedEmail'];
      $user['ValidatedPhone'] = (bool)$user['ValidatedPhone'];
      $user['TwoFactorAuth'] = (bool)$user['TwoFactorAuth'];
      $user['Categories'] = is_null($user['Categories']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return ["Id" => intval($a[0]), "Name" => $a[1]];
        },
        explode(",", $user['Categories'])
      );

      // Agregar sessionType con valores booleanos
      $user['SessionType'] = [
        "Virtual" => $user['hasVirtual'] == 1,
        "InPerson" => $user['hasInPerson'] == 1
      ];
      unset($user['hasVirtual'], $user['hasInPerson']);

      // Agregar información histórica de suscripción
      $user['HistorySubscription'] = is_null($user['PlanID']) ? null : [
        "LatestPlanID" => (int)$user['PlanID'],
        "StartDate" => $user['StartDate'],
        "EndDate" => $user['EndDate'],
        "Status" => $user['Status'],
        "UsedTrial" => 'True'
      ];
      unset(
        $user['PlanID'],
        $user['StartDate'],
        $user['EndDate'],
        $user['Status']
      );

      return $user;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene usuarios filtrados por tipo con paginación
   * @param  object $paginator: objeto con limit y offset
   * @param  string $userType: tipo de usuario (Guide, Seeker, Admin)
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function getUsersByType($paginator, $userType) {
    try {
      if ($userType == 'Guide') {
        $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName,
        u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
        u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
        u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
        u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
        u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
          ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
        u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
        s.PlanID, s.StartDate, s.EndDate, s.Status,
        -- Subconsulta para reviews
        (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
          FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
        (SELECT COUNT(DISTINCT r.ReviewID)
          FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
        FROM Users AS u
        LEFT JOIN UsersCategories AS uc ON uc.UserID = u.UserID
        LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
        LEFT JOIN Media AS m ON u.UserID = m.UserID
        LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
        LEFT JOIN (
          SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
          MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
          MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
          FROM Offerings AS o
          INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
          WHERE o.Status = 'Active'
          GROUP BY o.UserID
        ) AS sub ON sub.UserID = u.UserID
        ORDER BY u.UserID
        LIMIT :_limit OFFSET :_offset");
      } else {
        $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName,
        u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
        u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
        u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
        u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
        u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
          ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
        u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
        s.PlanID, s.StartDate, s.EndDate, s.Status,
        -- Subconsulta para reviews
        (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
          FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
        (SELECT COUNT(DISTINCT r.ReviewID)
          FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
        FROM Users AS u
        LEFT JOIN UsersCategories AS uc ON uc.UserID = u.UserID
        LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
        LEFT JOIN Media AS m ON u.UserID = m.UserID
        LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
        LEFT JOIN (
          SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
          MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
          MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
          FROM Offerings AS o
          INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
          WHERE o.Status = 'Active'
          GROUP BY o.UserID
        ) AS sub ON sub.UserID = u.UserID
        WHERE u.UserType = :userType
        GROUP BY u.UserID
        ORDER BY u.UserID
        LIMIT :_limit OFFSET :_offset");
        $stmt->bindParam(':userType', $userType, PDO::PARAM_STR);
      }

      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      $rs = array_map(function ($e) {
        $e['Floor'] = is_null($e['Floor']) ? null : (int)$e['Floor'];
        $e['UserLevel'] = !$e['UserLevel'] ? 1 : (int)$e['UserLevel'];
        $e['ValidatedEmail'] = (bool)$e['ValidatedEmail'];
        $e['ValidatedPhone'] = (bool)$e['ValidatedPhone'];
        $e['TwoFactorAuth'] = (bool)$e['TwoFactorAuth'];
        $e['Categories'] = is_null($e['Categories']) ? [] : array_map(
          function ($a) {
            $a = explode(":", $a);
            return ["Id" => intval($a[0]), "Name" => $a[1]];
          },
          explode(",", $e['Categories'])
        );

        // Agregar sessionType con valores booleanos
        $e['SessionType'] = [
          "Virtual" => $e['hasVirtual'] == 1,
          "InPerson" => $e['hasInPerson'] == 1
        ];

        unset($e['hasVirtual'], $e['hasInPerson']);

        // Agregar información histórica de suscripción
        $user['HistorySubscription'] = is_null($e['PlanID']) ? null : [
          "LatestPlanID" => (int)$e['PlanID'],
          "StartDate" => $e['StartDate'],
          "EndDate" => $e['EndDate'],
          "Status" => $e['Status'],
        "UsedTrial" => 'True'
        ];
        unset(
          $e['PlanID'],
          $e['StartDate'],
          $e['EndDate'],
          $e['Status']
        );

        return $e;
      }, $rs);

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

  /**
   * Obtiene usuarios por categoría con paginación
   * @param  object $paginator: objeto con limit y offset
   * @param  int $categoryID: ID de la categoría
   * @return object: { data: [], rows: { total: int, fetched: int } }
   * @throws DatabaseException
   **/
  public function getUsersByCategory($paginator, $categoryID) {
    try {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
      u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
      u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
      u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status,
      -- Subconsulta para reviews
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN Reviews AS r ON u.UserID = r.GuideID OR u.UserID = r.SeekerID
      LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      WHERE uc.CategoryID = :categoryID AND u.DeactivationDate is null
      GROUP BY u.UserID
      ORDER BY u.UserID
      LIMIT :_limit OFFSET :_offset");

      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
      $total = $stmt->fetch(PDO::FETCH_ASSOC);

      $rs = array_map(function ($e) {
        $e['Floor'] = is_null($e['Floor']) ? null : (int)$e['Floor'];
        $e['UserLevel'] = !$e['UserLevel'] ? 1 : (int)$e['UserLevel'];
        $e['ValidatedEmail'] = (bool)$e['ValidatedEmail'];
        $e['ValidatedPhone'] = (bool)$e['ValidatedPhone'];
        $e['TwoFactorAuth'] = (bool)$e['TwoFactorAuth'];
        $e['Categories'] = is_null($e['Categories']) ? [] : array_map(
          function ($a) {
            $a = explode(":", $a);
            return ["Id" => intval($a[0]), "Name" => $a[1]];
          },
          explode(",", $e['Categories'])
        );

        // Agregar sessionType con valores booleanos
        $e['SessionType'] = [
          "Virtual" => $e['hasVirtual'] == 1,
          "InPerson" => $e['hasInPerson'] == 1
        ];

        unset($e['hasVirtual'], $e['hasInPerson']);

        // Agregar información histórica de suscripción
        $user['HistorySubscription'] = is_null($e['PlanID']) ? null : [
          "LatestPlanID" => (int)$e['PlanID'],
          "StartDate" => $e['StartDate'],
          "EndDate" => $e['EndDate'],
          "Status" => $e['Status'],
        "UsedTrial" => 'True'
        ];
        unset(
          $e['PlanID'],
          $e['StartDate'],
          $e['EndDate'],
          $e['Status']
        );

        return $e;
      }, $rs);

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

  /**
   * Obtiene un usuario por su código de referencia
   * @param  string $referralCode: código de referencia
   * @return array|null: datos del usuario o null si no existe
   * @throws DatabaseException
   **/
  public function getUserByRefCode($referralCode) {
    try {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth, u.UserType,
      u.RegistrationDate, u.LastLogin, u.DeactivationDate, u.UserLevel, u.LockedUntil,
      u.SignedContract, u.ReferralCode, GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories, u.LegalDocuments,
      u.ShortDescription, sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status,
      -- Subconsulta para reviews
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN Subscriptions AS s ON u.UserID = s.UserID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      WHERE u.ReferralCode = :referralCode
      GROUP BY u.UserID
      ORDER BY u.UserID");

      $stmt->bindParam(':referralCode', $referralCode, PDO::PARAM_INT);
      $stmt->execute();

      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      if (empty($user)) {
        return null;
      }

      $user['Floor'] = is_null($user['Floor']) ? null : (int)$user['Floor'];
      $user['UserLevel'] = !$user['UserLevel'] ? 1 : (int)$user['UserLevel'];
      $user['ValidatedEmail'] = (bool)$user['ValidatedEmail'];
      $user['ValidatedPhone'] = (bool)$user['ValidatedPhone'];
      $user['TwoFactorAuth'] = (bool)$user['TwoFactorAuth'];
      $user['Categories'] = is_null($user['Categories']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return ["Id" => intval($a[0]), "Name" => $a[1]];
        },
        explode(",", $user['Categories'])
      );

      // Agregar sessionType con valores booleanos
      $user['SessionType'] = [
        "Virtual" => $user['hasVirtual'] == 1,
        "InPerson" => $user['hasInPerson'] == 1
      ];
      unset($user['hasVirtual'], $user['hasInPerson']);

      // Agregar información histórica de suscripción
      $user['HistorySubscription'] = is_null($user['PlanID']) ? null : [
        "LatestPlanID" => (int)$user['PlanID'],
        "StartDate" => $user['StartDate'],
        "EndDate" => $user['EndDate'],
        "Status" => $user['Status'],
      "UsedTrial" => 'True'
      ];
      unset(
        $user['PlanID'],
        $user['StartDate'],
        $user['EndDate'],
        $user['Status']
      );

      return $user;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene el consentimiento legal más reciente del usuario
   * @param  int $userID: ID del usuario
   * @return array|null: datos del consentimiento o null
   * @throws DatabaseException
   **/
  public function latestConsentByUser($userID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM UserLegalConsents
        WHERE UserID = ?
        ORDER BY ConsentDate DESC
        LIMIT 1");
      $stmt->execute([$userID]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene los referidos de un usuario
   * @param  int $id: ID del usuario
   * @return array: lista de usuarios referidos
   * @throws DatabaseException
   **/
  public function referralsByUser ($id) {
    try {
      $stmt = $this->db->prepare("SELECT u.UserID, u.DisplayName, u.FirstName, u.LastName, u.RegistrationDate,
        m.URL AS ProfilePhoto, r.ReferralStatus
        FROM Referrals AS r
        LEFT JOIN Users AS u ON u.UserID = r.ReferredUserID
        LEFT JOIN Media AS m ON u.UserID = m.UserID
        WHERE r.UserID = :id
        ORDER BY u.RegistrationDate DESC");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene las recompensas de referencia de un usuario
   * @param  int $id: ID del usuario
   * @return array: lista de recompensas
   * @throws DatabaseException
   **/
  public function rewardsByUser($id) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM ReferralRewards
        WHERE UserID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      return $stmt->fetchAll(\PDO::FETCH_ASSOC);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Envía un email de invitación con código de referencia
   * @param  string $userName: nombre de usuario que invita
   * @param  string $referralCode: código de referencia
   * @param  string $email: email a invitar
   * @param  string $subDomain: subdominio opcional de onesoul.app
   * @return bool: true si se envió exitosamente, false en caso contrario
   **/
  public function inviteByEmail ($userName, $referralCode, $subDomain) {
    // Construir enlace de referido
    $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";
    $referralUrl = $origin ."/onboard/register?refid=" . urlencode($referralCode);

    // Cargar plantilla HTML
    $template = file_get_contents(ROOT."/src/templates/email_refCode.html");
    $template = str_replace("{LINK}", $referralUrl, $template);
    $template = str_replace("{USERNAME}", $userName, $template);

    $smtpAccount = $GLOBALS['config']['mailer']['account'];
    $smtpPassword = $GLOBALS['config']['mailer']['password'];

    # Configuración de PHPMailer
    $mail = new PHPMailer(true);
    try {
      # Configuración del servidor SMTP
      $mail->isSMTP();
      $mail->Host = 'smtp.gmail.com';
      $mail->SMTPAuth = true;
      $mail->Username = $smtpAccount;
      $mail->Password = $smtpPassword;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port = 587;

      # Configuración del remitente y destinatario
      $mail->setFrom($smtpAccount,'Contacto OneSoul');
      $mail->addAddress($email, $userName);

      # Contenido del correo
      $mail->isHTML(true);
      $mail->Subject = "Te invitan a OneSoul.app";
      $mail->Body    = $template;
      $mail->addEmbeddedImage(ROOT."/src/templates/logo2.png", 'logo');

      # Enviar el correo
      $mail->send();
      return true;
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Actualiza los datos de un usuario
   * @param  int $userID: ID del usuario a actualizar
   * @param  array $fields: campos a actualizar en formato ["campo = ?", ...]
   * @param  array $data: valores correspondientes a los campos
   * @return void
   * @throws DatabaseException
   **/
  public function updateUser($userID, $fields, $data) {
    try {
      // Construir la consulta SQL para la actualización
      $sql = "UPDATE Users SET " . implode(", ", $fields) . " WHERE UserID = :UserID";
      $stmt = $this->db->prepare($sql);

      // Vincular parámetros y manejar valores NULL
      foreach ($data AS $key => $value) {
        $stmt->bindValue(":$key", $value === null ? null : $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      }

      // Vincular el ID del usuario
      $stmt->bindValue(':UserID', $userID, PDO::PARAM_INT);
      $stmt->execute(); // Ejecutar la consulta

      // Si cambio el mail se marca el email como no validado
      if (isset($data['Email'])) {
        if ($data['Email'] != $resp->data['Email']) {
          $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 0 WHERE UserID = :UserID");
          // Vincular el ID del usuario
          $stmt->bindValue(':UserID', $userID, PDO::PARAM_INT);
          $stmt->execute(); // Ejecutar la consulta
        }
      }
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Desactiva un usuario estableciendo fecha de desactivación
   * @param  int $userID: ID del usuario a desactivar
   * @return void
   * @throws DatabaseException
   **/
  public function disableUser($userID) {
    try {
      $stmt = $this->db->prepare("UPDATE Users SET DeactivationDate = ? WHERE UserID = :id");
      $stmt->execute([date('Y-m-d H:i:s'), $userID]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Actualiza la foto de perfil de un usuario
   * @param  int $userID: ID del usuario
   * @param  string $fileURL: URL de la imagen optimizada
   * @param  string $filePath: ruta local del archivo
   * @return bool: true si se actualizó exitosamente
   * @throws DatabaseException
   **/
  public function updateProfilePhoto($userID, $fileURL, $filePath) {
    try {      # Busco al usuario y si tenia imagen antes
      $stmt = $this->db->prepare("SELECT u.UserID,m.MediaID,m.Path FROM Users AS u
        LEFT JOIN Media AS m ON u.UserID = m.UserID WHERE u.UserID = ?");
      $stmt->execute([$userID]);
      $profilePhoto = $stmt->fetch(PDO::FETCH_ASSOC);

      // Iniciar transacción
      $this->db->beginTransaction();

      if (!empty($profilePhoto)) {
        $stmt2 = $this->db->prepare("UPDATE Media SET `URL` = ?, `Path` = ?
          WHERE `UserID` = ?");
        $stmt2->execute([$fileURL, $filePath, $userID]);

        # Borro la imagen anterior si existe en el sistema de archivos
        if (file_exists($filePath)) {
          unlink($filePath);
        }
      } else {
        $stmt2 = $this->db->prepare("INSERT INTO Media (`URL`,`Path`,`UserID`)
          VALUES (?,?,?)");
        $stmt2->execute([$fileURL, $filePath, $userID]);
      }

      // Confirmo transacción
      $this->db->commit();
      return true;
    } catch (\PDOException $e) {
      $this->db->rollBack(); // Revierto en caso de error
      # Si hubo algun error de DB y se llego a grabar el archivo en el FS borrarlo
      if ($fileWritten && file_exists($rs[0]['Path'])) {
        unlink($filePath);
      }
      throw new DatabaseException($e->getMessage());
    } catch (Exception $e) {
      $this->db->rollBack(); // Revierto en caso de error
      throw new Exception($e->getMessage());
    }
  }

  /**
   * Elimina la foto de perfil de un usuario
   * @param  int $userID: ID del usuario
   * @return bool: true si se eliminó exitosamente, false si no existe foto
   * @throws Exception
   **/
  public function deleteProfilePhoto($userID) {
    try {
      # Seleccionar el MediaID para eliminar la entrada
      $stmt = $this->db->prepare("SELECT m.MediaID, m.URL, m.Path FROM Media AS m WHERE m.UserID = ?");
      $stmt->execute([$userID]);
      $profilePhoto = $stmt->fetch(PDO::FETCH_ASSOC);

      if (empty($profilePhoto['MediaID'])) {
        return false;
      }

      // Iniciar transacción
      $this->db->beginTransaction();

      # Eliminar la entrada en la tabla Media
      $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = ?");
      $stmt->execute([$profilePhoto]);

      # Si existe el archivo local lo borro
      if (!is_null($rs[0]['Path']) && file_exists($rs[0]['Path'])) {
        unlink($rs[0]['Path']); // Eliminar el archivo del sistema
      }

      // Confirmo transacción
      $this->db->commit();
      return true;
    } catch (Exception $e) {
      $this->db->rollBack(); // Revierto en caso de error
      throw new Exception($e->getMessage());
    }
  }

  /**
   * Obtiene las categorías asociadas a un usuario
   * @param  int $userID: ID del usuario
   * @return array: lista de CategoryIDs
   **/
  public function getUserCategories($userID) {
    $query = "SELECT CategoryID FROM UsersCategories WHERE UserID = :userId";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userId', $userID, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Agrega una categoría a un usuario
   * @param  int $userID: ID del usuario
   * @param  int $categoryId: ID de la categoría
   * @return void
   **/
  public function addUserCategory($userID, $categoryId) {
    $query = "INSERT INTO UsersCategories (UserID, CategoryID) VALUES (:userId, :categoryId)";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userId', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
    $stmt->execute();
  }

  /**
   * Elimina una categoría de un usuario
   * @param  int $userID: ID del usuario
   * @param  int $categoryId: ID de la categoría
   * @return void
   **/
  public function deleteUserCategory($userID, $categoryId) {
    $query = "DELETE FROM UsersCategories WHERE UserID = :userId AND CategoryID = :categoryId";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userId', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
    $stmt->execute();
  }

  /**
   * Obtiene los tipos de redes sociales activos
   * @return array: mapa de tipos sociales activos { nombre => [SocialAccountTypeID, Name] }
   **/
  public function getActiveSocialAccountsTypes() {
    $stmt = $this->db->prepare("SELECT SocialAccountTypeID, Name FROM SocialAccountsTypes WHERE IsActive = 1");
    $stmt->execute();
    $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $types = [];
    foreach ($result as $row) {
      $types[strtolower(trim($row['Name']))] = [
        'SocialAccountTypeID' => $row['SocialAccountTypeID'],
        'Name' => $row['Name']
      ];
    }

    return $types;
  }

  /**
   * Obtiene las cuentas sociales de un usuario
   * @param  int $userID: ID del usuario
   * @return array: lista de cuentas sociales con SocialAccountID, SocialAccountTypeID, Name, AccountName
   **/
  public function getUserSocialAccounts($userID) {
    $query = "SELECT sma.SocialAccountID, sma.SocialAccountTypeID, LOWER(TRIM(smt.Name)) as Name, sma.AccountName
      FROM SocialAccounts AS sma
      INNER JOIN SocialAccountsTypes AS smt
        ON sma.SocialAccountTypeID = smt.SocialAccountTypeID
      WHERE sma.UserID = ? AND sma.IsActive = 1";

    $stmt = $this->db->prepare($query);
    $stmt->execute([$userID]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Agrega una cuenta social a un usuario
   * @param  int $userID: ID del usuario
   * @param  int $typeID: ID del tipo de red social
   * @param  string $accountName: URL o nombre de cuenta de la red social
   * @return void
   **/
  public function addUserSocialAccount($userID, $typeID, $accountName) {
  $query = "INSERT INTO SocialAccounts (UserID, SocialAccountTypeID, AccountName, IsActive)
            VALUES (:userID, :typeID, :accountName, 1)";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':typeID', $typeID, PDO::PARAM_INT);
    $stmt->bindParam(':accountName', $accountName, PDO::PARAM_STR);
    $stmt->execute();
  }

  /**
   * Actualiza una cuenta social de un usuario
   * @param  int $userID: ID del usuario
   * @param  int $typeID: ID del tipo de red social
   * @param  string $accountName: nuevo URL o nombre de cuenta
   * @return void
   **/
  public function updateUserSocialAccount($userID, $typeID, $accountName) {
    $query = "UPDATE SocialAccounts SET AccountName = ?
      WHERE UserID = ? AND SocialAccountTypeID = ?";
    $stmt = $this->db->prepare($query);
    $stmt->execute([$accountName, $userID, $typeID]);
  }

  /**
   * Elimina una cuenta social de un usuario
   * @param  int $userID: ID del usuario
   * @param  int $typeID: ID del tipo de red social
   * @return void
   **/
  public function deleteUserSocialAccount($userID, $typeID) {
    $query = "DELETE FROM SocialAccounts
      WHERE UserID = ? AND SocialAccountTypeID = ?";
    $stmt = $this->db->prepare($query);
    $stmt->execute([$userID, $typeID]);
  }

  /**
   * Formatea y normaliza una URL de red social
   * @param  string $name: nombre de la red social (twitter, instagram, etc)
   * @param  string $url: URL o username a formatear
   * @return string: URL formateada correctamente
   * @throws Exception si el tipo de red social no es válido
   **/
  public function formatSocialUrl($name, $url) {
    $query = "SELECT * FROM SocialAccountsTypes
              WHERE Name = :name";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $socialurl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$socialurl) {
      throw new \Exception("Tipo de red social inválido: {$name}");
    }

    $formatName = $socialurl['FormatName'];

    // Si solo envían username
    if (!preg_match('~^https?://~i', $url) && strpos($url, '.') === false) {
      return rtrim($formatName, '/') . '/' . ltrim($url, '/');
    }

    // Parsear lo que venga (aunque sea incorrecto)
    $parts = parse_url($url);
    $path  = $parts['path'] ?? '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';

    // Siempre usamos el host de FormatName (no el que mandaron)
    $baseParts = parse_url($formatName);
    $scheme = $baseParts['scheme'] ?? 'https';
    $host   = $baseParts['host'];

    // Reconstruir con el host correcto y el path recibido
    return $scheme . '://' . $host . $path . $query;
  }
}
