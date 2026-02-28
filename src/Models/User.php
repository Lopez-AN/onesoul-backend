<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Exceptions\DatabaseException;
use Exception;
use App\Helpers\CategoryTreeHelper;
use PHPMailer\PHPMailer\PHPMailer;

class User {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene todos los usuarios con paginación
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  object $paginator: objeto con limit y offset
   * @return object: { data: [], rows: { total: int, fetched: int } }
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUsers($paginator) {
    $stmt = $this->db->prepare(
      $this->_sqlMain().
      "GROUP BY u.UserID
      ORDER BY u.UserID
      LIMIT ? OFFSET ?
    ");

    $stmt->execute([$paginator->limit, $paginator->offset]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGenericMulti($users, $total['total']);
  }

  /**
   * Obtiene usuarios filtrados por tipo con paginación
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  object $paginator: objeto con limit y offset
   * @param  string $userType: tipo de usuario (Guide, Seeker, Admin)
   * @return object: { data: [], rows: { total: int, fetched: int } }
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUsersByType($paginator, $userType) {
    $stmt = $this->db->prepare(
      $this->_sqlMain().
      "WHERE u.UserType = ?
      GROUP BY u.UserID
      ORDER BY u.UserID
      LIMIT ? OFFSET ?
    ");

    $stmt->execute([$userType, $paginator->limit, $paginator->offset]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGenericMulti($users, $total['total']);
  }

  /**
   * Obtiene usuarios por categoría con paginación
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  object $paginator: objeto con limit y offset
   * @param  int $categoryID: ID de la categoría
   * @return object: { data: [], rows: { total: int, fetched: int } }
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUsersByCategory($paginator, $categoryID) {
    $stmt = $this->db->prepare("WITH RECURSIVE ".
      CategoryTreeHelper::getFilterCategoryCTE().
      $this->_sqlMain().
      "INNER JOIN category_filter_tree AS cat ON cat.CategoryID = c.CategoryID
      WHERE u.DeactivationDate is null
      GROUP BY u.UserID
      ORDER BY u.UserID
      LIMIT ? OFFSET ?
    ");
    $stmt->execute([$categoryID, $paginator->limit, $paginator->offset]);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGenericMulti($users, $total['total']);
  }

 /**
   * Busca guías por término de búsqueda con paginación
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  object $paginator: objeto con limit y offset
   * @param  string $query: término(s) de búsqueda (busca en DisplayName, Biography, Categories, etc)
   * @return object: { data: [], rows: { total: int, fetched: int } }
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function searchGuides($paginator, $query, $category = null) {
    $searchQuery = "%$query%";

    $stmt = $this->db->prepare("WITH RECURSIVE ".
      ($category !== null ? CategoryTreeHelper::getFilterCategoryCTE() : '').
      $this->_sqlMain().
      ($category !== null ? ' INNER JOIN category_filter_tree AS cat
        ON cat.CategoryID = c.CategoryID ' : '').
      "WHERE u.UserType = 'Guide' AND (
        u.UserName LIKE ? OR
        u.DisplayName LIKE ? OR
        u.Biography LIKE ? OR
        u.ShortDescription LIKE ? OR
        c.Name LIKE ?
      )
      AND u.DeactivationDate IS NULL
      GROUP BY u.UserID
      ORDER BY u.UserID
      LIMIT ? OFFSET ?"
    );

    $params = [$searchQuery, $searchQuery, $searchQuery, $searchQuery, $searchQuery,
      $paginator->limit, $paginator->offset];
    if($category !== null){
      array_unshift($params, $category);
    }

    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() AS total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGenericMulti($users, $total['total']);
  }

  /**
   * Trae los guias con offerings activos y cuantos tienen.
   * @return array: lista de guias con offertings activos o [] si no hay ninguno
   **/
  public function getGuidesWithActiveOfferings() {
    $stmt = $this->db->prepare("SELECT u.UserID, u.UserName, count(*)
      FROM Users AS u
      INNER JOIN Offerings as o ON u.UserID = o.UserID
      WHERE u.DeactivationDate IS NULL AND o.Status = 'Active'
      GROUP BY u.UserID");

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Obtiene un usuario por su ID
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  int $userID: ID del usuario
   * @param  bool $activeOnly: si esta en true solo trae usuarios activos
   * @return array|false: datos del usuario o false si no existe
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUserById($userID, $activeOnly = true) {
    $wactive = $activeOnly ? " AND u.DeactivationDate IS NULL " : "";

    $stmt = $this->db->prepare(
      $this->_sqlMainSingle().
      "WHERE u.UserID = ? $wactive
      GROUP BY u.UserID
      ORDER BY u.UserID"
    );

    $stmt->execute([$userID]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user);
  }

  /**
   * Obtiene un usuario por username
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  string $userName: nombre de usuario
   * @param  bool $activeOnly: si esta en true solo trae usuarios activos
   * @return array|false: datos del usuario o false si no existe
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUserByUserName($userName, $activeOnly = true) {
    $wactive = $activeOnly ? " AND u.DeactivationDate IS NULL " : "";

    $stmt = $this->db->prepare(
      $this->_sqlMainSingle().
      "WHERE u.UserName = ? $wactive
      GROUP BY u.UserID
      ORDER BY u.UserID"
    );

    $stmt->execute([$userName]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user);
  }

  /**
   * Obtiene un usuario por email
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  string $email: email del usuario
   * @param  bool $activeOnly: si esta en true solo trae usuarios activos
   * @return array|false: datos del usuario o false si no existe
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUserByEmail($email, $activeOnly = true) {
    $wactive = $activeOnly ? " AND u.DeactivationDate IS NULL " : "";

    $stmt = $this->db->prepare(
      $this->_sqlMainSingle().
      "WHERE u.Email = ? $wactive
      GROUP BY u.UserID
      ORDER BY u.UserID"
    );

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user);
  }

  /**
   * Obtiene un usuario por su telefono
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  string $phone: Telefono del usuario
   * @param  bool $activeOnly: si esta en true solo trae usuarios activos
   * @return array|false: datos del usuario o false si no existe
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUserByPhone($phone, $activeOnly = true) {
    $wactive = $activeOnly ? " AND u.DeactivationDate IS NULL " : "";

    $stmt = $this->db->prepare(
      $this->_sqlMainSingle().
      "WHERE u.Phone = ? $wactive
      GROUP BY u.UserID
      ORDER BY u.UserID"
    );

    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user);
  }

  /**
   * Obtiene un usuario por OAuth ID
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  string $oAuthID: ID del OAuth
   * @param  string $oAuthService: servicio OAuth (Google, Facebook, etc)
   * @param  bool $activeOnly: si esta en true solo trae usuarios activos
   * @return array|false: datos del usuario o false si no existe
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUserByOAuthID($oAuthID, $oAuthService, $activeOnly = true) {
    $wactive = $activeOnly ? " AND u.DeactivationDate IS NULL " : "";

    $stmt = $this->db->prepare(
      $this->_sqlMainSingle().
      "WHERE u.Oauth2ID = ? AND u.Oauth2Service = ? $wactive
      GROUP BY u.UserID
      ORDER BY u.UserID"
    );

    $stmt->execute([$oAuthID, $oAuthService]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user);
  }

  /**
   * Obtiene un usuario por su código de referencia
   *
   * Retorna datos sin filtrar de campos sensibles. El controller es responsable
   * de aplicar el scope de acceso antes de enviar la respuesta al cliente.
   *
   * @param  string $referralCode: código de referencia
   * @param  bool $activeOnly: si esta en true solo trae usuarios activos
   * @return array|false: datos del usuario o false si no existe
   *
   * @note El filtrado de datos según scope (PUBLIC, USER, ADMIN) debe realizarse en el controller
   **/
  public function getUserByRefCode($referralCode, $activeOnly = true) {
    $wactive = $activeOnly ? " AND u.DeactivationDate IS NULL " : "";

    $stmt = $this->db->prepare(
      $this->_sqlMainSingle().
      "WHERE u.ReferralCode = ? $wactive
      GROUP BY u.UserID
      ORDER BY u.UserID"
    );

    $stmt->execute([$referralCode]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $this -> _getUserGeneric($user);
  }

  /**
   * Generaliza la consulta principal de obtener users
   *
   * @return string: consulta principal sin filtros
   **/
  private function _sqlMainSingle() {
    return str_replace('SQL_CALC_FOUND_ROWS ', '', $this->_sqlMain());
  }
  private function _sqlMain(){
    return "SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName,
      u.UserName, u.DisplayName, u.Email, u.Phone, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.ValidatedPhone, u.TwoFactorAuth,
      u.MfaSecret, u.UserType, u.RegistrationDate, u.LastLogin, u.DeactivationDate,
      u.UserLevel, u.SignedContract, u.LegalDocuments, u.ShortDescription,
      u.OTPDate, u.OTPCode, u.OTPAttemps,
      u.Oauth2ID, u.Oauth2Service, u.FailedLoginAttempts,
      u.LockedUntil,u.ReferralCode,u.IsAdmin,
      sub.AvgRate, sub.hasVirtual, sub.hasInPerson, m.URL AS ImgURL, u.IsAdmin,
      GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name))
        ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
      -- Subconsulta para reviews y ratings
      (SELECT ROUND(CAST(AVG(r.Rating) AS FLOAT),2)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS Rating,
      (SELECT COUNT(DISTINCT r.ReviewID)
        FROM Reviews AS r WHERE r.GuideID = u.UserID) AS TotalReviews,
      -- Subconsulta para locations
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'LocationID', l.LocationID,
          'CountryCode', l.CountryCode,
          'State', l.State,
          'City', l.City,
          'Cp', l.Cp,
          'LocationName', l.LocationName,
          'AddressName', l.AddressName,
          'AddressNumber', l.AddressNumber,
          'Floor', l.Floor,
          'Department', l.Department,
          'IsActive', l.IsActive
        )
      ) FROM UsersLocations as l WHERE l.UserID = u.UserID) as user_locations,
      IF(ct.CurrencyCode IS NULL,'ARS',ct.CurrencyCode) as Currency
      FROM Users AS u
      LEFT JOIN UsersCategories AS uc ON uc.userID = u.userID
      LEFT JOIN Categories AS c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media AS m ON u.UserID = m.UserID
      LEFT JOIN (
        SELECT ROUND(AVG(p.Price),0) AS AvgRate, o.UserID,
        MAX(CASE WHEN p.SessionType IN ('virtual', 'both') THEN 1 ELSE 0 END) AS hasVirtual,
        MAX(CASE WHEN p.SessionType IN ('in-person', 'both') THEN 1 ELSE 0 END) AS hasInPerson
        FROM Offerings AS o
        INNER JOIN OfferingsPackages AS p ON o.OfferingID = p.OfferingID
        WHERE o.Status = 'Active'
        GROUP BY o.UserID
      ) AS sub ON sub.UserID = u.UserID
      LEFT JOIN UsersLocations as l ON u.UserID = l.UserID AND l.LocationID = 0
      LEFT JOIN Countries as ct ON l.CountryCode = ct.CountryCode ";
  }

  /**
   * Procesa y normaliza datos de un usuario individual
   *
   * Realiza conversiones de tipos de datos y agrupa información relacionada
   * sin eliminar campos sensibles. El filtrado de campos según nivel de acceso
   * debe realizarse en el controller.
   *
   * @param  array|null $user: datos del usuario obtenidos de la base de datos o null
   * @return array|false: datos del usuario normalizados o false si no existe
   **/
  private function _getUserGeneric($user){
    if (empty($user)) {
      return false;
    }

    $user['UserLevel'] = !$user['UserLevel'] ? 1 : (int)$user['UserLevel'];
    $user['ValidatedEmail'] = (bool)$user['ValidatedEmail'];
    $user['ValidatedPhone'] = (bool)$user['ValidatedPhone'];
    $user['TwoFactorAuth'] = (bool)$user['TwoFactorAuth'];
    $user['IsAdmin'] = (bool)$user['IsAdmin'];
    $e['Phone'] = $user['Phone'] ? str_replace("+549", "+54", $user['Phone']) : null; # Fix telefonos argentinos
    $user['Categories'] = is_null($user['Categories']) ? [] : array_map(
      function ($a) {
        $a = explode(":", $a);
        return ["Id" => intval($a[0]), "Name" => $a[1]];
      },
      explode(",", $user['Categories'])
    );
    $user['IsActive'] = $user['DeactivationDate'] === null;

    # Agregar sessionType con valores booleanos
    $user['SessionType'] = [
      "Virtual" => $user['hasVirtual'] === 1,
      "InPerson" => $user['hasInPerson'] === 1
    ];
    unset($user['hasVirtual'], $user['hasInPerson']);

    $locations = @json_decode($user['user_locations'], true);
    $user['Locations'] = $locations ? $locations : [];
    $user['Locations'] = array_map(function($a){
      $a['IsActive'] = (bool)$a['IsActive'];
      return $a;
    }, $user['Locations']);
    unset($user['user_locations']);

    return $user;
  }

  /**
   * Procesa y normaliza múltiples registros de usuarios
   *
   * Realiza conversiones de tipos de datos y agrupa información relacionada
   * para un conjunto de usuarios. Retorna datos en formato paginado sin eliminar
   * campos sensibles. El filtrado de campos según nivel de acceso debe realizarse
   * en el controller.
   *
   * @param  array $users: array de usuarios obtenidos de la base de datos
   * @param  int $total: cantidad total de registros disponibles en la base de datos
   * @return object: objeto con propiedades 'data' (array de usuarios normalizados) y 'rows' (información de paginación)
   **/
  private function _getUserGenericMulti($users, $total){
    $users = array_map(function ($e){
      $e['UserLevel'] = !$e['UserLevel'] ? 1 : (int)$e['UserLevel'];
      $e['ValidatedEmail'] = (bool)$e['ValidatedEmail'];
      $e['ValidatedPhone'] = (bool)$e['ValidatedPhone'];
      $e['TwoFactorAuth'] = (bool)$e['TwoFactorAuth'];
      $e['IsAdmin'] = (bool)$e['IsAdmin'];
      $e['Phone'] = $e['Phone'] ? str_replace("+549", "+54", $e['Phone']) : null; # Fix telefonos argentinos
      $e['Categories'] = is_null($e['Categories']) ? [] : array_map(
        function ($a) {
          $a = explode(":", $a);
          return ["Id" => intval($a[0]), "Name" => $a[1]];
        },
        explode(",", $e['Categories'])
      );
      $e['IsActive'] = $e['DeactivationDate'] === null;

      # Agregar sessionType con valores booleanos
      $e['SessionType'] = [
        "Virtual" => $e['hasVirtual'] === 1,
        "InPerson" => $e['hasInPerson'] === 1
      ];

      unset($e['hasVirtual'], $e['hasInPerson']);

      $locations = @json_decode($e['user_locations'], true);
      $e['Locations'] = $locations ? $locations : [];
      $e['Locations'] = array_map(function($a){
        $a['IsActive'] = (bool)$a['IsActive'];
        return $a;
      }, $e['Locations']);
      unset($e['user_locations']);

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
    $stmt = $this->db->prepare("SELECT * FROM UsersLegalConsents
      WHERE UserID = ? GROUP BY DocumentType ORDER BY ConsentDate DESC");
    $stmt->execute([$userID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
      INNER JOIN Users AS u ON u.UserID = r.ReferredUserID
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
  public function inviteByEmail($userName, $referralCode, $email, $subDomain) {
    # Construir enlace de referido
    $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";
    $referralUrl = $origin ."/onboard/register?refid=" . urlencode($referralCode);

    # Cargar plantilla HTML
    $template = file_get_contents(ROOT."/src/Templates/email_refCode.html");
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
      $mail->addEmbeddedImage(ROOT."/src/Templates/logo2.png", 'logo');

      # Enviar el correo
      return $mail->send();
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Actualiza los datos de un usuario
   * @param  array $user: datos actuales del usuario
   * @param  array $values: valores correspondientes a los campos
   * @return array: array con datos del usuario actualizado
   * @throws DatabaseException
   **/
  public function updateUser($user, $values) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Veo si vino con location
      $location = null;
      if(!empty($values['Location'])){
        $location = $values['Location'];
        unset($values['Location']);
      }

      $fields = [];
      foreach ($values AS $key => $value) {
        if($key === 'UserID'){ # La pk no se actualiza
          continue;
        }
        $fields[] = "$key = :$key";
      }

      # Construir la consulta SQL para la actualización
      $stmt = $this->db->prepare("UPDATE Users SET " . implode(", ", $fields) . " WHERE UserID = :UserID");

      # Vincular parámetros y manejar valores NULL
      foreach ($values AS $key => $value) {
        $stmt->bindValue(":$key", $value === null ? null : $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      }

      # Vincular el ID del usuario
      $stmt->bindValue(':UserID', $user['UserID'], PDO::PARAM_INT);
      $stmt->execute(); # Ejecutar la consulta

      # Si cambio el mail se marca el email como no validado
      if (isset($values['Email'])) {
        if ($values['Email'] !== $user['Email']) {
          $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 0 WHERE UserID = ?");
          # Vincular el ID del usuario
          $stmt->execute([$user['UserID']]); # Ejecutar la consulta
        }
      }

      if($location){
        # Chequeo si ya tiene la ubicacion base creada
        $stmt = $this->db->prepare("SELECT * FROM UsersLocations
          WHERE UserID = ? AND LocationID = 0");
        $stmt->execute([$user['UserID']]);

        $checkLocation = $stmt->fetch();

        $locationKeys = array_keys($location) ;
        $locationValues = array_values($location) ;

        # Inserto si no existe
        if(!$checkLocation){
          $placeholder = array_fill(0,count($locationKeys),"?");

          $stmt = $this->db->prepare("INSERT INTO UsersLocations (UserID, LocationID, " . implode(", ", $locationKeys) .
          ") VALUES (?, 0, ". implode(", ", $placeholder) . ")");
          array_unshift($locationValues, $user['UserID']);
          $stmt->execute($locationValues);
        # Updateo
        }else{
          foreach($locationKeys as $k){
            $s[] = "$k = ?";
          }
          $stmt = $this->db->prepare("UPDATE UsersLocations SET ". implode(", ",$s). " WHERE UserID = ? AND LocationID = 0");
          $locationValues[] = $user['UserID'];
          $stmt->execute($locationValues);
        }
      }

      $user = $this->getUserById($user['UserID']) ?:
        throw new DatabaseException("Failed to retrieve the updated user");

      $this->db->commit(); # Confirmo transacción
      return $user;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene las preferencias y preferencias de notificación de un usuario
   *
   * Recupera los canales de comunicación habilitados por el usuario y sus preferencias.
   * Si no tiene preferencias configuradas, asume todos los canales habilitados.
   *
   * @param int $userID ID del usuario
   * @return array|false Array con preferencias (Email, WhatsApp, SMS, Locale) o false si el usuario no existe
   */
  public function getSettings($userID){
    # Obtener preferencias de comunicacion del usuario
    $stmt = $this->db->prepare("SELECT us.ReceiveNewsletters, us.TimeZone,
      us.Locale, us.ViewMode, un.Email,
      un.WhatsApp, un.Sms, un.PushWeb, un.PushApp
    FROM Users as u
    INNER JOIN UsersSettings as us
      ON u.UserID = us.UserID
    LEFT JOIN UsersNotifications as un
      ON u.UserID = un.UserID
    WHERE u.UserID = ? AND u.DeactivationDate IS NULL");
    $stmt->execute([$userID]);
    $preferences = $stmt->fetch();
    if (!$preferences) {
      return false;
    }

    return [
      'ReceiveNewsletters' => (bool)$preferences['ReceiveNewsletters'],
      'Locale' => $preferences['Locale'] ?? 'es',
      'ViewMode' => $preferences['ViewMode'] ?? 'Light',
      'TimeZone' => $preferences['TimeZone'] ?? 'America/Argentina/Buenos_Aires',
      'Notifications' => [
        'Email' => (bool)$preferences['Email'] ?? 1,
        'WhatsApp' => (bool)$preferences['WhatsApp'] ?? 1,
        'Sms' => (bool)$preferences['Sms'] ?? 1,
        'PushApp' => (bool)$preferences['PushApp'] ?? 1,
        'PushWeb' => (bool)$preferences['PushWeb'] ?? 1
      ]
    ];
  }

  /**
   * Actualiza los ajustes del usuario
   * @param  int $userID: ID del usuario a actualizar
   * @param  array $settings: valores correspondientes a los ajustes generales
   * @param  array $notifications: valores correspondientes a las notificaciones
   * @return array: array con datos del usuario actualizado
   * @throws DatabaseException
   **/
  public function updateSettings($userID, $settings, $notifications) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE UsersNotifications
        SET WhatsApp = ?, Sms = ?, PushApp = ?, PushWeb = ?
        WHERE UserID = ?");
      $stmt->execute([
        (int)$notifications['WhatsApp'], (int)$notifications['Sms'],
        (int)$notifications['PushApp'], (int)$notifications['PushWeb'],
        $userID
      ]);

      if($settings){
        $stmt = $this->db->prepare("UPDATE UsersSettings
          SET Locale = ?, ViewMode = ?, ReceiveNewsletters = ?, TimeZone = ?
          WHERE UserID = ?");
        $stmt->execute([
          $settings['Locale'], $settings['ViewMode'],
          (int)$settings['ReceiveNewsletters'], $settings['TimeZone'],
          $userID
        ]);
      }

      $user = $this->getSettings($userID) ?:
        throw new DatabaseException("Failed to retrieve the updated settings");

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
   * Busca una ubicacion de usuario
   *
   * @param  int $userID: ID del usuario
   * @param  int $locationID: ID de la ubicación
   * @return array: datos de la ubicación o false si no existe
   **/
  public function getUserLocation($userID, $locationID) {
    $stmt = $this->db->prepare("SELECT LocationName, AddressName, AddressNumber,
      Floor, Department, Cp, City, State, CountryCode, IsActive
      FROM UsersLocations
      WHERE UserID = ? AND LocationID = ?");
    $stmt->execute([$userID, $locationID]);
    return $stmt->fetch();
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
      $user = $this->getUserById($userID) ?:
        throw new DatabaseException("Failed to retrieve the updated user");

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

      if (empty($profilePhoto)) {
        return false;
      }

      $mediaID = $profilePhoto['MediaID'];

      $this->db->beginTransaction(); # Iniciar transacción

      # Eliminar la entrada en la tabla Media
      $stmt = $this->db->prepare("DELETE FROM Media WHERE MediaID = ?");
      $stmt->execute([$mediaID]);

      # Si existe el archivo local lo borro
      if (!is_null($profilePhoto['Path']) && file_exists($profilePhoto['Path'])) {
        unlink($profilePhoto['Path']); # Eliminar el archivo del sistema
      }
      $user = $this->getUserById($userID) ?:
        throw new DatabaseException("Failed to retrieve the updated user");

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
    $stmt = $this->db->prepare("SELECT SocialAccountTypeID, Name
     FROM SocialAccountsTypes WHERE IsActive = 1");

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
    $stmt = $this->db->prepare("INSERT INTO SocialAccounts
      (UserID, SocialAccountTypeID, AccountName, IsActive)
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
