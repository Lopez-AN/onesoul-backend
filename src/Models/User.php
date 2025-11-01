<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Exceptions\DatabaseException;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use App\Enums\UserAccessScope;

class User {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene todos los usuarios con paginación
   * @param  object $paginator: objeto con limit y offset
   * @return object: { data: [], rows: { total: int, fetched: int } }
   **/
  public function getUsers($paginator) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
    u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
    u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
    u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
    u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
    u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
    u.OTPDate, u.OTPCode, u.OTPAttemps,
    u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
    u.LockedUntil,u.ReferralCode,u.IsAdmin,
    sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
    s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
    GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
      ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
    -- Subconsulta para reviews y ratings
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
    GROUP BY u.UserID
    ORDER BY u.UserID
    LIMIT ? OFFSET ?");

    $stmt->execute([$categoryID, $paginator->limit, $paginator->offset]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGenericMulti($users, $total['total'], $scope);
  }

  /**
   * Obtiene usuarios filtrados por tipo con paginación
   * @param  object $paginator: objeto con limit y offset
   * @param  string $userType: tipo de usuario (Guide, Seeker, Admin)
   * @return object: { data: [], rows: { total: int, fetched: int } }
   **/
  public function getUsersByType($paginator, $userType) {
    if ($userType == 'Guide') {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
      u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
      u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
      u.OTPDate, u.OTPCode, u.OTPAttemps,
      u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
      u.LockedUntil,u.ReferralCode,u.IsAdmin,
      sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
      GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
      -- Subconsulta para reviews y ratings
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
      ORDER BY u.UserID
      LIMIT ? OFFSET ?");

      $stmt->execute([$paginator->limit, $paginator->offset]);
    } else {
      $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
      u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
      u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
      u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
      u.OTPDate, u.OTPCode, u.OTPAttemps,
      u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
      u.LockedUntil,u.ReferralCode,u.IsAdmin,
      sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
      s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
      GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
      -- Subconsulta para reviews y ratings
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
      WHERE u.UserType = ?
      GROUP BY u.UserID
      ORDER BY u.UserID
      LIMIT ? OFFSET ?");

      $stmt->execute([$userType, $paginator->limit, $paginator->offset]);
    };

    $stmt->execute([$categoryID, $paginator->limit, $paginator->offset]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGenericMulti($users, $total['total'], $scope);
  }

  /**
   * Obtiene usuarios por categoría con paginación
   * @param  object $paginator: objeto con limit y offset
   * @param  int $categoryID: ID de la categoría
   * @return object: { data: [], rows: { total: int, fetched: int } }
   **/
  public function getUsersByCategory($paginator, $categoryID) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
    u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
    u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
    u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
    u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
    u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
    u.OTPDate, u.OTPCode, u.OTPAttemps,
    u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
    u.LockedUntil,u.ReferralCode,u.IsAdmin,
    sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
    s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
    GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
      ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
    -- Subconsulta para reviews y ratings
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
    WHERE uc.CategoryID = ? AND u.DeactivationDate is null
    GROUP BY u.UserID
    ORDER BY u.UserID
    LIMIT ? OFFSET ?");

    $stmt->execute([$categoryID, $paginator->limit, $paginator->offset]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGenericMulti($users, $total['total'], $scope);
  }

  /**
   * Obtiene un usuario por su ID
   * @param  int $userID: ID del usuario
   * @return array|false: datos del usuario o false si no existe
   **/
  public function getUserById($userID, $scope = UserAccessScope::PUBLIC) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
    u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
    u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
    u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
    u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
    u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
    u.OTPDate, u.OTPCode, u.OTPAttemps,
    u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
    u.LockedUntil,u.ReferralCode,u.IsAdmin,
    sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
    s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
    GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
      ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
    -- Subconsulta para reviews y ratings
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
    WHERE u.UserID = ?
    GROUP BY u.UserID
    ORDER BY u.UserID");

    $stmt->execute([$userID]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user, $scope);
  }

  /**
   * Obtiene un usuario por username
   * @param  string $username: nombre de usuario
   * @return array|false: datos del usuario o false si no existe
   **/
  public function getUserByUserName($userName, $scope = UserAccessScope::PUBLIC) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
    u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
    u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
    u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
    u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
    u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
    u.OTPDate, u.OTPCode, u.OTPAttemps,
    u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
    u.LockedUntil,u.ReferralCode,u.IsAdmin,
    sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
    s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
    GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
      ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
    -- Subconsulta para reviews y ratings
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
    WHERE u.UserName = ?
    GROUP BY u.UserID
    ORDER BY u.UserID");

    $stmt->execute([$userName]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user, $scope);
  }

  /**
   * Obtiene un usuario por email
   * @param  string $email: email del usuario
   * @return array|false: datos del usuario o false si no existe
   **/
  public function getUserByEmail($email, $scope = UserAccessScope::PUBLIC) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
    u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
    u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
    u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
    u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
    u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
    u.OTPDate, u.OTPCode, u.OTPAttemps,
    u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
    u.LockedUntil,u.ReferralCode,u.IsAdmin,
    sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
    s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
    GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
      ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
    -- Subconsulta para reviews y ratings
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
    WHERE u.Email = ?
    GROUP BY u.UserID
    ORDER BY u.UserID");

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user, $scope);
  }

  /**
   * Obtiene un usuario por OAuth ID
   * @param  string $oAuthID: ID del OAuth
   * @param  string $oAuthService: servicio OAuth (Google, Facebook, etc)
   * @return array|false: datos del usuario o false si no existe
   **/
  public function getUserByOAuthID($oAuthID, $oAuthService, $scope = UserAccessScope::PUBLIC) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
    u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
    u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
    u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
    u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
    u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
    u.OTPDate, u.OTPCode, u.OTPAttemps,
    u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
    u.LockedUntil,u.ReferralCode,u.IsAdmin,
    sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
    s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
    GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
      ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
    -- Subconsulta para reviews y ratings
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
    WHERE u.Oauth2ID = ? AND u.Oauth2Service = ?
    GROUP BY u.UserID
    ORDER BY u.UserID");

    $stmt->execute([$oAuthID, $oAuthService]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user, $scope);
  }

  /**
   * Obtiene un usuario por su código de referencia
   * @param  string $referralCode: código de referencia
   * @return array|false: datos del usuario o false si no existe
   **/
  public function getUserByRefCode($referralCode, $scope = UserAccessScope::PUBLIC) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.FirstName, u.LastName,
    u.UserName, u.DisplayName, u.Email, u.Phone, u.AddressName, u.AddressNumber,
    u.Floor, u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
    u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
    u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
    u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
    u.OTPDate, u.OTPCode, u.OTPAttemps,
    u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
    u.LockedUntil,u.ReferralCode,u.IsAdmin,
    sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL,
    s.PlanID, s.StartDate, s.EndDate, s.Status, u.IsAdmin,
    GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
      ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
    -- Subconsulta para reviews y ratings
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
    WHERE u.ReferralCode = ?
    GROUP BY u.UserID
    ORDER BY u.UserID");

    $stmt->execute([$referralCode]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user, $scope);
  }

  /**
   * Procesa y filtra datos de un usuario según el nivel de acceso especificado
   *
   * Realiza conversiones de tipos de datos, agrupación de información relacionada,
   * y elimina campos sensibles según el scope de acceso del usuario.
   *
   * @param  array|null $user: datos del usuario obtenidos de la base de datos o null
   * @param  UserAccessScope $scope: nivel de acceso para filtrar campos (PUBLIC, USER, ADMIN)
   * @return array|false: datos del usuario filtrados según scope o false si no existe
   *
   * @example
   * $userData = $this->_getUserGeneric($rawUserData, UserAccessScope::PUBLIC);
   * # Retorna solo datos públicos (DisplayName, Categories, Rating, etc)
   *
   * $adminData = $this->_getUserGeneric($rawUserData, UserAccessScope::ADMIN);
   * # Retorna todos los datos incluyendo MFA, OTP, intentos fallidos
   *
   * Campos removidos según scope:
   * - ADMIN: acceso completo a todos los campos
   * - USER: se ocultan MfaSecret, OTPCode, OTPDate, OTPAttemps, FailedLoginAttempts
   * - PUBLIC: se ocultan todos los campos privados (FirstName, LastName, Email, Phone,
   *   AddressName, AddressNumber, Floor, Department, Cp, DateOfBirth, Gender, etc)
   **/
  private function _getUserGeneric($user, $scope){
    if (empty($user)) {
      return false;
    }

    $stmt->execute([$userID]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $user['Floor'] = is_null($user['Floor']) ? null : (int)$user['Floor'];
    $user['UserLevel'] = !$user['UserLevel'] ? 1 : (int)$user['UserLevel'];
    $user['ValidatedEmail'] = (bool)$user['ValidatedEmail'];
    $user['ValidatedPhone'] = (bool)$user['ValidatedPhone'];
    $user['TwoFactorAuth'] = (bool)$user['TwoFactorAuth'];
    $user['IsAdmin'] = (bool)$user['IsAdmin'];
    $user['Categories'] = is_null($user['Categories']) ? [] : array_map(
      function ($a) {
        $a = explode(":", $a);
        return ["Id" => intval($a[0]), "Name" => $a[1]];
      },
      explode(",", $user['Categories'])
    );

    # Agregar sessionType con valores booleanos
    $user['SessionType'] = [
      "Virtual" => $user['hasVirtual'] == 1,
      "InPerson" => $user['hasInPerson'] == 1
    ];
    unset($user['hasVirtual'],
    $user['hasInPerson']);

    # Agregar información de suscripción
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

    # Si no es admin estos campos internos no los muestro
    if($scope !== UserAccessScope::ADMIN){
      unset($user['MfaSecret'],
      $user['OTPDate'],
      $user['OTPDate'],
      $user['OTPCode'],
      $user['OTPAttemps'],
      $user['FailedLoginAttempts']);
    }

    # Si no es admin ni user no muestro campos privados
    if(!in_array($scope, [UserAccessScope::ADMIN, UserAccessScope::USER])){
      unset($user['FirstName'],
      $user['LastName'],
      $user['Email'],
      $user['Phone'],
      $user['AddressName'],
      $user['AddressNumber'],
      $user['Floor'],
      $user['Department'],
      $user['Cp'],
      $user['DateOfBirth'],
      $user['Gender'],
      $user['ValidatedEmail'],
      $user['ValidatedPhone'],
      $user['TwoFactorAuth'],
      $user['RegistrationDate'],
      $user['LastLogin'],
      $user['DeactivationDate'],
      $user['SignedContract'],
      $user['LegalDocuments'],
      $user['LockedUntil'],
      $user['ReferralCode'],
      $user['HistorySubscription'],
      $user['Oauth2ID'],
      $user['Oauth2Service'],
      $user['IsAdmin']);
    }
    return $user;
  }

  private function _getUserGenericMulti($users, $scope){
    $users = array_map(function ($e) {
      $e['Floor'] = is_null($e['Floor']) ? null : (int)$e['Floor'];
      $e['UserLevel'] = !$e['UserLevel'] ? 1 : (int)$e['UserLevel'];
      $e['ValidatedEmail'] = (bool)$e['ValidatedEmail'];
      $e['ValidatedPhone'] = (bool)$e['ValidatedPhone'];
      $e['TwoFactorAuth'] = (bool)$e['TwoFactorAuth'];
      $e['IsAdmin'] = (bool)$e['IsAdmin'];
      $e['Categories'] = is_null($e['Categories']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return ["Id" => intval($a[0]), "Name" => $a[1]];
        },
        explode(",", $e['Categories'])
      );

      # Agregar sessionType con valores booleanos
      $e['SessionType'] = [
        "Virtual" => $e['hasVirtual'] == 1,
        "InPerson" => $e['hasInPerson'] == 1
      ];

      unset($e['hasVirtual'], $e['hasInPerson']);

      # Agregar información de suscripción
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

      # Si no es admin no muestro campos privados
      if($scope !== UserAccessScope::ADMIN){
        unset($e['MfaSecret'],
        $e['OTPDate'],
        $e['OTPDate'],
        $e['OTPCode'],
        $e['OTPAttemps'],
        $e['FailedLoginAttempts'],
        $e['FirstName'],
        $e['LastName'],
        $e['Email'],
        $e['Phone'],
        $e['AddressName'],
        $e['AddressNumber'],
        $e['Floor'],
        $e['Department'],
        $e['Cp'],
        $e['DateOfBirth'],
        $e['Gender'],
        $e['ValidatedEmail'],
        $e['ValidatedPhone'],
        $e['TwoFactorAuth'],
        $e['RegistrationDate'],
        $e['LastLogin'],
        $e['DeactivationDate'],
        $e['SignedContract'],
        $e['LegalDocuments'],
        $e['LockedUntil'],
        $e['ReferralCode'],
        $e['HistorySubscription'],
        $e['Oauth2ID'],
        $e['Oauth2Service'],
        $e['IsAdmin']);
      }

      return $e;
    }, $users);

    return (object) [
      "data" => $users,
      "rows" => [
        "total" => $total,
        "fetched" => count($users)
      ]
    ];
  }


  /**
   * Obtiene el consentimiento legal más reciente del usuario
   * @param  int $userID: ID del usuario
   * @return array|false: datos del consentimiento
   **/
  public function latestConsentByUser($userID) {
    $stmt = $this->db->prepare("SELECT * FROM UserLegalConsents
      WHERE UserID = ? ORDER BY ConsentDate DESC LIMIT 1");
    return $stmt->execute([$userID]);
  }

  /**
   * Obtiene los referidos de un usuario
   * @param  int $userID: ID del usuario
   * @return array: lista de usuarios referidos
   **/
  public function referralsByUser ($userID) {
    $stmt = $this->db->prepare("SELECT u.UserID, u.DisplayName, u.FirstName,
      u.LastName, u.RegistrationDate, m.URL AS ProfilePhoto, r.ReferralStatus
      FROM Referrals AS r
      LEFT JOIN Users AS u ON u.UserID = r.ReferredUserID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      WHERE r.UserID = ?
      ORDER BY u.RegistrationDate DESC");
    $stmt->execute([$userID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Obtiene las recompensas de referencia de un usuario
   * @param  int $userID: ID del usuario
   * @return array: lista de recompensas
   **/
  public function rewardsByUser($userID) {
    $stmt = $this->db->prepare("SELECT * FROM ReferralRewards WHERE UserID = ?");
    $stmt->execute([$userID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Envía un email de invitación con código de referencia
   * @param  string $userName: nombre de usuario que invita
   * @param  string $referralCode: código de referencia
   * @param  string $email: email a invitar
   * @param  string $subDomain: subdominio opcional de onesoul.app
   * @return bool: true si se envió exitosamente, false en caso de excepcion
   **/
  public function inviteByEmail ($userName, $referralCode, $subDomain) {
    # Construir enlace de referido
    $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";
    $referralUrl = $origin ."/onboard/register?refid=" . urlencode($referralCode);

    # Cargar plantilla HTML
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
      return $mail->send();
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Actualiza los datos de un usuario
   * @param  int $userID: ID del usuario a actualizar
   * @param  array $fields: campos a actualizar en formato ["campo = ?", ...]
   * @param  array $data: valores correspondientes a los campos
   * @return array: array con datos del usuario actualizado
   * @throws DatabaseException
   **/
  public function updateUser($userID, $currentEmail, $fields, $data) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Construir la consulta SQL para la actualización
      $sql = "UPDATE Users SET " . implode(", ", $fields) . " WHERE UserID = :UserID";
      $stmt = $this->db->prepare($sql);

      # Vincular parámetros y manejar valores NULL
      foreach ($data AS $key => $value) {
        $stmt->bindValue(":$key", $value === null ? null : $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      }

      # Vincular el ID del usuario
      $stmt->bindValue(':UserID', $userID, PDO::PARAM_INT);
      $stmt->execute(); # Ejecutar la consulta

      # Si cambio el mail se marca el email como no validado
      if (isset($data['Email'])) {
        if ($data['Email'] !== $currentEmail) {
          $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 0 WHERE UserID = ?");
          # Vincular el ID del usuario
          $stmt->execute([$userID]); # Ejecutar la consulta
        }
      }
      $user = $this->getUserById($userID, UserAccessScope::USER);

      $this->db->commit(); # Confirmo transacción
      return $user;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Desactiva un usuario estableciendo fecha de desactivación
   * @param  int $userID: ID del usuario a desactivar
   **/
  public function disableUser($userID) {
    $stmt = $this->db->prepare("UPDATE Users SET DeactivationDate = ? WHERE UserID = ?");
    $stmt->execute([date('Y-m-d H:i:s'), $userID]);
  }

  /**
   * Actualiza la foto de perfil de un usuario
   * @param  int $userID: ID del usuario
   * @param  string $fileURL: URL de la imagen optimizada
   * @param  string $filePath: ruta local del archivo
   * @return array: array con datos del usuario actualizado
   * @throws Exception
   **/
  public function updateProfilePhoto($userID, $fileURL, $filePath) {
    try {
      # Buscar si ya existe foto de perfil
      $stmt = $this->db->prepare("SELECT MediaID, Path FROM Media WHERE UserID = ? LIMIT 1");

      $stmt->execute([$userID]);
      $oldMedia = $stmt->fetch(PDO::FETCH_ASSOC);
      $stmt->closeCursor();

      $this->db->beginTransaction(); # Iniciar transacción

      if ($oldMedia) {
        # ACTUALIZAR media existente
        $stmt = $this->db->prepare("UPDATE Media SET `URL` = ?, `Path` = ? WHERE UserID = ?");
        $stmt->execute([$fileURL, $filePath, $userID]);

        # Borrar archivo anterior si existe
        $oldPath = $oldMedia['Path'];
        if (!empty($oldPath) && file_exists($oldPath)) {
          unlink($oldPath);
        }
      } else {
        # INSERTAR nuevo media
        $stmt = $this->db->prepare("INSERT INTO Media (`URL`, `Path`, `UserID`) VALUES (?, ?, ?)");
        $stmt->execute([$fileURL, $filePath, $userID]);
      }
      $user = $this->getUserById($userID, UserAccessScope::USER);

      $this->db->commit(); # Confirmo transacción
      return $user;
    } catch (Exception $e) {
      $this->db->rollBack(); # Revierto en caso de error
      if (!empty($filePath) && file_exists($filePath)) {
        unlink($filePath);
      }
      throw new Exception($e->getMessage());
    }
  }

  /**
   * Elimina la foto de perfil de un usuario
   * @param  int $userID: ID del usuario
   * @return array: array con datos del usuario actualizado
   * @return bool: false si no existe foto
   * @throws Exception
   **/
  public function deleteProfilePhoto($userID) {
    try {
      # Seleccionar el MediaID para eliminar la entrada
      $stmt = $this->db->prepare("SELECT m.MediaID, m.URL, m.Path
        FROM Media AS m WHERE m.UserID = ?");

      $stmt->execute([$userID]);
      $profilePhoto = $stmt->fetch(PDO::FETCH_ASSOC);
      $mediaID = $profilePhoto['MediaID'];

      if (empty($mediaID)) {
        return false;
      }

      $this->db->beginTransaction(); # Iniciar transacción

      # Eliminar la entrada en la tabla Media
      $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = ?");
      $stmt->execute([$mediaID]);

      # Si existe el archivo local lo borro
      if (!is_null($profilePhoto['Path']) && file_exists($profilePhoto['Path'])) {
        unlink($profilePhoto['Path']); # Eliminar el archivo del sistema
      }
      $user = $this->getUserById($userID, UserAccessScope::USER);

      $this->db->commit(); # Confirmo transacción
      return $user;
    } catch (Exception $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new Exception($e->getMessage());
    }
  }

  /**
   * Obtiene las categorías asociadas a un usuario
   * @param  int $userID: ID del usuario
   * @return array: lista de CategoryIDs
   **/
  public function getUserCategories($userID) {
    $stmt = $this->db->prepare("SELECT CategoryID FROM UsersCategories WHERE UserID = ?");
    $stmt->execute([$userID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Agrega una categoría a un usuario
   * @param  int $userID: ID del usuario
   * @param  int $categoryID: ID de la categoría
   **/
  public function addUserCategory($userID, $categoryID) {
    $stmt = $this->db->prepare("INSERT INTO UsersCategories (UserID, CategoryID) VALUES (?, ?)");
    $stmt->execute([$userID, $categoryID]);
  }

  /**
   * Elimina una categoría de un usuario
   * @param  int $userID: ID del usuario
   * @param  int $categoryID: ID de la categoría
   **/
  public function deleteUserCategory($userID, $categoryID) {
    $stmt = $this->db->prepare("DELETE FROM UsersCategories WHERE UserID = ? AND CategoryID = ?");
    $stmt->execute([$userID, $categoryID]);
  }

  /**
   * Obtiene los tipos de redes sociales activos
   * @return array: mapa de tipos sociales activos { nombre => [SocialAccountTypeID, Name] }
   **/
  public function getActiveSocialAccountsTypes() {
    $stmt = $this->db->prepare("SELECT SocialAccountTypeID, Name FROM SocialAccountsTypes WHERE IsActive = 1");

    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $stmt = $this->db->prepare("SELECT sma.SocialAccountID, sma.SocialAccountTypeID,
      LOWER(TRIM(smt.Name)) as Name, sma.AccountName
      FROM SocialAccounts AS sma
      INNER JOIN SocialAccountsTypes AS smt
      ON sma.SocialAccountTypeID = smt.SocialAccountTypeID
      WHERE sma.UserID = ? AND sma.IsActive = 1");

    $stmt->execute([$userID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Agrega una cuenta social a un usuario
   * @param  int $userID: ID del usuario
   * @param  int $typeID: ID del tipo de red social
   * @param  string $accountName: URL o nombre de cuenta de la red social
   **/
  public function addUserSocialAccount($userID, $typeID, $accountName) {
    $stmt = $this->db->prepare("INSERT INTO SocialAccounts (UserID, SocialAccountTypeID, AccountName, IsActive)
      VALUES (?, ?, ?, 1)");
    $stmt->execute([$userID, $typeID, $accountName]);
  }

  /**
   * Actualiza una cuenta social de un usuario
   * @param  int $userID: ID del usuario
   * @param  int $typeID: ID del tipo de red social
   * @param  string $accountName: nuevo URL o nombre de cuenta
   **/
  public function updateUserSocialAccount($userID, $typeID, $accountName) {
    $stmt = $this->db->prepare("UPDATE SocialAccounts SET AccountName = ?
      WHERE UserID = ? AND SocialAccountTypeID = ?");
    $stmt->execute([$accountName, $userID, $typeID]);
  }

  /**
   * Elimina una cuenta social de un usuario
   * @param  int $userID: ID del usuario
   * @param  int $typeID: ID del tipo de red social
   **/
  public function deleteUserSocialAccount($userID, $typeID) {
    $stmt = $this->db->prepare("DELETE FROM SocialAccounts
      WHERE UserID = ? AND SocialAccountTypeID = ?");
    $stmt->execute([$userID, $typeID]);
  }

  /**
   * Formatea y normaliza una URL de red social
   * @param  string $name: nombre de la red social (twitter, instagram, etc)
   * @param  string $url: URL o username a formatear
   * @return string: URL formateada correctamente
   **/
  public function formatSocialUrl($name, $url) {
    $stmt = $this->db->prepare("SELECT * FROM SocialAccountsTypes WHERE Name = ?");

    $stmt->execute([$name]);
    $socialurl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$socialurl) {
      throw new Exception("Tipo de red social inválido: {$name}");
    }

    $formatName = $socialurl['FormatName'];

    # Si solo envían username
    if (!preg_match('~^https?://~i', $url) && strpos($url, '.') === false) {
      return rtrim($formatName, '/') . '/' . ltrim($url, '/');
    }

    # Parsear lo que venga (aunque sea incorrecto)
    $parts = parse_url($url);
    $path  = $parts['path'] ?? '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';

    # Siempre usamos el host de FormatName (no el que mandaron)
    $baseParts = parse_url($formatName);
    $scheme = $baseParts['scheme'] ?? 'https';
    $host   = $baseParts['host'];

    # Reconstruir con el host correcto y el path recibido
    return $scheme . '://' . $host . $path . $query;
  }
}
