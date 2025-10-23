<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once(ROOT . '/src/Utils/AWSRekognition.php');

clASs User
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

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
      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ];
      }
      $user = $rs[0];
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

      // Verificar si la cuenta está desactivada
      if (!is_null($user['DeactivationDate']) && strtotime($user['DeactivationDate']) <= time()) {
        return (object) [
          "http_code" => 401,
          "error" => [
            "code" => "USER_DISABLED",
            "desc" => "The specified user is disabled"
          ]
        ];
      }

      return (object) [
        "http_code" => 200,
        "data" => $user
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Busca un usuario por username
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
    $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rs)) {
      return (object) [
        "http_code" => 404,
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user was found with the specified ID"
        ]
      ];
    }
    $user = $rs[0];
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

    // Verificar si la cuenta está desactivada
    if (!is_null($user['DeactivationDate']) && strtotime($user['DeactivationDate']) <= time()) {
      return (object) [
        "http_code" => 401,
        "error" => [
          "code" => "USER_DISABLED",
          "desc" => "The specified user is disabled"
        ]
      ];
    }

    return (object) [
      "http_code" => 200,
      "data" => $user
    ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Busca un usuario por email
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
      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ];
      }
      $user = $rs[0];
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

      // Verificar si la cuenta está desactivada
      if (!is_null($user['DeactivationDate']) && strtotime($user['DeactivationDate']) <= time()) {
        return (object) [
          "http_code" => 401,
          "error" => [
            "code" => "USER_DISABLED",
            "desc" => "The specified user is disabled"
          ]
        ];
      }

      return (object) [
        "http_code" => 200,
        "data" => $user
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Busca un usuario por oAuthID
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
      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ];
      }
      $user = $rs[0];
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

      // Verificar si la cuenta está desactivada
      if (!is_null($user['DeactivationDate']) && strtotime($user['DeactivationDate']) <= time()) {
        return (object) [
          "http_code" => 401,
          "error" => [
            "code" => "USER_DISABLED",
            "desc" => "The specified user is disabled"
          ]
        ];
      }

      return (object) [
        "http_code" => 200,
        "data" => $user
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

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

  public function getUserByCategory($categoryID) {
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
      ORDER BY u.UserID");

      $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

      if (empty($rs)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified CategoryID"
          ]
        ];
      }

      return (object) [
        "http_code" => 200,
        "data" => $rs
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

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
      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified Referral Code"
          ]
        ];
      }
      $user = $rs[0];
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

      // Verificar si la cuenta está desactivada
      if (!is_null($user['DeactivationDate']) && strtotime($user['DeactivationDate']) <= time()) {
        return (object) [
          "http_code" => 401,
          "error" => [
            "code" => "USER_DISABLED",
            "desc" => "The specified user is disabled"
          ]
        ];
      }

      return (object) [
        "http_code" => 200,
        "data" => $user
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function latestConsentByUser($id) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM UserLegalConsents
                            WHERE UserID = :id
                            ORDER BY ConsentDate DESC
                            LIMIT 1");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

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

  public function rewardsByUser ($id) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM ReferralRewards
                                  WHERE UserID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      $rs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

      return (object) [
        "data" => $rs,
        "rows" => count($rs)
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function inviteByEmail ($userID, $email, $subDomain) {
    $userResult = $this->getUserById($userID);
    if ($userResult->http_code !== 200 || empty($userResult->data['ReferralCode'])) {
      return [
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "Could not retrieve referral code for the user"
        ]
      ];
    }

    $referralCode = $userResult->data['ReferralCode'];
    $username = $userResult->data['UserName'];

    // Construir enlace de referido
    $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";
    $referralUrl = $origin ."/onboard/register?refid=" . urlencode($referralCode);

    // Cargar plantilla HTML
    $template = file_get_contents(ROOT."/src/templates/email_refCode.html");
    $template = str_replace("{LINK}", $referralUrl, $template);
    $template = str_replace("{USERNAME}", $username, $template);

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
      $mail->addAddress($email, $username);

      # Contenido del correo
      $mail->isHTML(true);
      $mail->Subject = "Te invitan a OneSoul.app";
      $mail->Body    = $template;
      $mail->addEmbeddedImage(ROOT."/src/templates/logo2.png", 'logo');

      # Enviar el correo
      $mail->send();
    } catch (Exception $e) {
      # echo "No se pudo enviar el correo. Error: {$mail->ErrorInfo}";
    }
  }

  public function updateUser($userId, $data) {
    try {
      // Verificar si el usuario existe
      $resp = $this->getUserById($userId);
      if ($resp->http_code != 200) {
        return $resp;
      }

      // Verificar si hay campos para actualizar
      if (empty($data)) {
        return (object) [
          "http_code" => 400,
          "error" => [
            "code" => "INVALID_PARAMETERS",
            "desc" => "Parameters are missing or invalid"
          ]
        ];
      }

      // Lista de campos permitidos para actualizar
      $allowedFields = [
        'FirstName',
        'LastName',
        'DisplayName',
        'Email',
        'Phone',
        'AddressName',
        'AddressNumber',
        'Floor',
        'Department',
        'Cp',
        'City',
        'State',
        'CountryCode',
        'DateOfBirth',
        'Gender',
        'Biography',
        'UserType',
        'SignedContract',
        'LegalDocuments',
        'ShortDescription'
      ];

      // Filtrar y preparar los campos a actualizar
      $fields = [];
      foreach ($data AS $key => $value) {
        if (!in_array($key, $allowedFields)) {
          return (object) [
            "http_code" => 400,
            "error" => [
              "code" => "INVALID_UPDATE_KEY",
              "desc" => "Key '$key' present in the JSON is not supported"
            ]
          ];
        }
        $fields[] = "$key = :$key";
      }

      // Construir la consulta SQL para la actualización
      $sql = "UPDATE Users SET " . implode(", ", $fields) . " WHERE UserID = :UserID";
      $stmt = $this->db->prepare($sql);

      // Vincular parámetros y manejar valores NULL
      foreach ($data AS $key => $value) {
        $stmt->bindValue(":$key", $value === null ? null : $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      }

      // Vincular el ID del usuario
      $stmt->bindValue(':UserID', $userId, PDO::PARAM_INT);
      $stmt->execute(); // Ejecutar la consulta

      // Si cambio el mail se marca el email como no validado
      if (isset($data['Email'])) {
        if ($data['Email'] != $resp->data['Email']) {
          $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 0 WHERE UserID = :UserID");
          // Vincular el ID del usuario
          $stmt->bindValue(':UserID', $userId, PDO::PARAM_INT);
          $stmt->execute(); // Ejecutar la consulta
        }
      }

      // Devolver los datos actualizados del usuario
      return $this->getUserById($userId);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function deleteUser($id) {
    try {
      $stmt = $this->db->prepare("SELECT UserID FROM Users WHERE UserID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();
      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ];
      }
      $stmt = $this->db->prepare("DELETE FROM Users WHERE UserID = :id");
      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      return (object) [
        "http_code" => 200,
        "data" => [
          "Message" => "User deleted"
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateProfilePhoto($userId, $uploadedFile) {
    $fileWritten = false; # Indica que se grabo el archivo en el FS
    try {
      # Busco al usuario y si tenia imagen antes
      $stmt = $this->db->prepare("SELECT u.UserID,m.MediaID,m.Path FROM Users AS u
            LEFT JOIN Media AS m ON u.UserID = m.UserID WHERE u.UserID = :id");
      $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
      $stmt->execute();
      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);
      if (empty($rs)) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ];
      }

      # Extraigo el nombre del archivo y su extension
      $fileName = $uploadedFile->getClientFilename();
      $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
      $imgID = uniqid(); #Le doy un ID unico a la imagen

      # Directorio destino
      $uploadDirectory = $GLOBALS['config']['media_folder']['path'];

      # El archivo destino se guarda con ID unico
      $filePath = $uploadDirectory . "/user/" . $imgID . "." . $fileExtension;

      #Grabo el archivo en el FS
      $uploadedFile->moveTo($filePath);
      $fileWritten = true;

      // Validar con Amazon Rekognition
      if(empty($GLOBALS['config']['debug_mode']) || !$GLOBALS['config']['debug_mode']){
        $rekognitionResult = analyzeImageWithRekognition($filePath);
        if ($rekognitionResult['error']) {
          unlink($filePath); // Borrar la imagen si es inapropiada
          return (object) [
            "http_code" => 400,
            "error" => [
              "code" => "INAPPROPRIATE_CONTENT",
              "desc" => $rekognitionResult['reASon']
            ]
          ];
        }
      }

      // Aquí optimizamos la imagen usando la función optimizeImage
      $optimizedPath = optimizeImage($filePath);
      unlink($filePath);
      $filePath = $optimizedPath;

      # Genero la URL del archivo
      $fileURL = $GLOBALS['config']['media_folder']['url'] . "/user/" . $imgID . ".webp";

      # Borro lAS imagenes que tuviera antes (si son locales)
      foreach ($rs AS $r) {
        if (!is_null($r['Path']) && is_file($r['Path'])) {
          unlink($r['Path']);
        }
      }

      if (!is_null($rs[0]['MediaID'])) {
        $stmt = $this->db->prepare("UPDATE Media SET `URL` = :fileURL, `Path` = :filePath
                WHERE `UserID` = :userID");
        $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
        $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
        $stmt->bindParam(':userID', $userId, PDO::PARAM_INT);
        $stmt->execute();

        # Borro la imagen anterior si existe en el sistema de archivos
        if (!empty($rs[0]['Path']) && file_exists($rs[0]['Path'])) {
          unlink($rs[0]['Path']);
        }
      } else {
        $stmt = $this->db->prepare("INSERT INTO Media (`URL`,`UserID`,`Path`)
                VALUES (:fileURL,:userID,:filePath)");
        $stmt->bindParam(':fileURL', $fileURL, PDO::PARAM_STR);
        $stmt->bindParam(':filePath', $filePath, PDO::PARAM_STR);
        $stmt->bindParam(':userID', $userId, PDO::PARAM_INT);
        $stmt->execute();
      }

      // Devolver los datos actualizados del usuario
      return $this->getUserById($userId);
    } catch (\PDOException $e) {
      # Si hubo algun error de DB y se llego a grabar el archivo en el FS borrarlo
      if ($fileWritten && file_exists($rs[0]['Path'])) {
        unlink($filePath);
      }
      throw new DatabaseException($e->getMessage());
    } catch (Exception $e) {
      throw new Exception($e->getMessage());
    }
  }

  public function deleteProfilePhoto($userId) {
    try {
      # Seleccionar el MediaID para eliminar la entrada
      $stmt = $this->db->prepare("SELECT m.MediaID, m.URL, m.Path FROM Media AS m WHERE m.UserID = :id");
      $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
      $stmt->execute();
      $rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rs[0]['MediaID'])) {
        return (object) [
          "http_code" => 404,
          "error" => [
            "code" => "PHOTO_NOT_FOUND",
            "desc" => "No profile photo found for this user"
          ]
        ];
      }
      # Eliminar la entrada en la tabla Media
      $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = :mediaID");
      $stmt->bindParam(':mediaID', $rs[0]['MediaID'], PDO::PARAM_INT);
      $stmt->execute();

      # Si existe el archivo local lo borro
      if (!is_null($rs[0]['Path']) && file_exists($rs[0]['Path'])) {
        unlink($rs[0]['Path']); // Eliminar el archivo del sistema
      }

      return (object) [
        "http_code" => 200,
        "data" => [
          "Message" => "Profile photo deleted"
        ]
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    } catch (Exception $e) {
      throw new Exception($e->getMessage());
    }
  }

  public function getUserCategories($userId) {
    $query = "SELECT CategoryID FROM UsersCategories WHERE UserID = :userId";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function addUserCategory($userId, $categoryId) {
    $query = "INSERT INTO UsersCategories (UserID, CategoryID) VALUES (:userId, :categoryId)";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
    $stmt->execute();
  }

  public function deleteUserCategory($userId, $categoryId) {
    $query = "DELETE FROM UsersCategories WHERE UserID = :userId AND CategoryID = :categoryId";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
    $stmt->execute();
  }

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

  public function getUserSocialAccounts($userID) {
    $query = "SELECT sma.SocialAccountID, sma.SocialAccountTypeID, LOWER(TRIM(smt.Name)) as Name, sma.AccountName
      FROM SocialAccounts AS sma
      INNER JOIN SocialAccountsTypes AS smt ON sma.SocialAccountTypeID = smt.SocialAccountTypeID
      WHERE sma.UserID = :userID AND sma.IsActive = 1";

    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function addUserSocialAccount($userID, $typeID, $accountName) {
  $query = "INSERT INTO SocialAccounts (UserID, SocialAccountTypeID, AccountName, IsActive)
            VALUES (:userID, :typeID, :accountName, 1)";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':typeID', $typeID, PDO::PARAM_INT);
    $stmt->bindParam(':accountName', $accountName, PDO::PARAM_STR);
    $stmt->execute();
  }

  public function updateUserSocialAccount($userID, $typeID, $accountName) {
    $query = "UPDATE SocialAccounts SET AccountName = :accountName
              WHERE UserID = :userID AND SocialAccountTypeID = :typeID";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':typeID', $typeID, PDO::PARAM_INT);
    $stmt->bindParam(':accountName', $accountName, PDO::PARAM_STR);
    $stmt->execute();
  }

  public function deleteUserSocialAccount($userID, $typeID) {
    $query = "DELETE FROM SocialAccounts WHERE UserID = :userID AND SocialAccountTypeID = :typeID";
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':typeID', $typeID, PDO::PARAM_INT);
    $stmt->execute();
  }

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
