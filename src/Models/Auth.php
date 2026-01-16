<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Exceptions\DatabaseException;
use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use App\Models\User;

class Auth{
  protected $db;

  public function __construct(PDO $db){
    $this->db = $db;
  }

  /**
   * Autentica un usuario por nombre de usuario o email
   * @param  string|null $username: nombre de usuario
   * @param  string|null $email: correo electrónico
   * @return array|false: datos del usuario (UserID, PasswordHash, FailedLoginAttempts) o false si no existe
   **/
  public function login($username, $email){
    if($username){
      $stmt = $this->db->prepare("SELECT u.UserID,
      u.PasswordHash,u.FailedLoginAttempts FROM Users AS u
      WHERE u.UserName = ? AND u.PasswordHash IS NOT NULL");
      $stmt->execute([$username]);
    }else{
      $stmt = $this->db->prepare("SELECT u.UserID,
      u.PasswordHash,u.FailedLoginAttempts FROM Users AS u
      WHERE u.Email = ? AND u.PasswordHash IS NOT NULL");
      $stmt->execute([$email]);
    }
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Verifica que las versiones de documentos legales existan en la base de datos
   * @param  string $tycVersion: versión de términos y condiciones
   * @param  string $privacyVersion: versión de política de privacidad
   * @return array: ['total' => cantidad de documentos encontrados]
   **/
  public function checkLegalDocuments($tycVersion, $privacyVersion){
    # Verificar que las versiones legales existan en la base de datos
    $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM LegalDocuments
      WHERE (DocumentType = 'TermsAndConditions' AND Version = ?)
        OR (DocumentType = 'PrivacyPolicy' AND Version = ?)");
    $stmt->execute([$tycVersion, $privacyVersion]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Registra un nuevo usuario con transacción
   * @param  User $userModel: model de usuario para traer el usuario creado
   * @param  string $email: correo electrónico del usuario
   * @param  string $userName: nombre de usuario
   * @param  string $passwordHash: contraseña hasheada
   * @param  string $tycVersion: versión de términos aceptada
   * @param  string $privacyVersion: versión de privacidad aceptada
   * @param  bool $receiveNewsletters: si desea recibir newsletters
   * @param  string $clientIp: IP del cliente
   * @param  string $userAgent: user agent del cliente
   * @return int: ID del usuario creado
   * @throws DatabaseException: si hay error en la transacción
   **/
  public function register(User $userModel, $email, $userName, $passwordHash,
    $tycVersion, $privacyVersion, $receiveNewsletters, $clientIp, $userAgent
  ){
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("INSERT INTO Users (Email, UserName, PasswordHash, RegistrationDate, ValidatedEmail)
      VALUES (?,?,?,?,1)");
      $stmt->execute([$email, $userName, $passwordHash, date('YmdHis')]);

      # Obtener el ID del usuario creado
      $userID = $this->db->lastInsertId();

      # Insertar recibir novedades si eligio esta opcion
      $stmt = $this->db->prepare("INSERT INTO UsersSettings
        (UserID, Locale, ViewMode, ReceiveNewsletters, TimeZone)
        VALUES (?, 'es', 'Light', ?, 'America/Argentina/Buenos_Aires')");
      $stmt->execute([$userID, $receiveNewsletters]);

      # Por defecto canales notificacion activados
      $stmt = $this->db->prepare("INSERT INTO UsersNotifications
        (UserID, Email, WhatsApp, Sms, PushWeb, InApp)
        VALUES (?, 1, 1, 1, 1, 1)");
      $stmt->execute([$userID]);

      # Insertar consentimiento
      $stmt = $this->db->prepare("INSERT INTO UsersLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'TermsAndConditions', 1)");
      $stmt->execute([$userID, $clientIp, $userAgent, $tycVersion]);

      $stmt = $this->db->prepare("INSERT INTO UsersLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'PrivacyPolicy', 1)");
      $stmt->execute([$userID, $clientIp, $userAgent, $privacyVersion]);

      $user = $userModel->getUserById($userID) ?:
        throw new DatabaseException("Failed to retrieve the registered user");

      $this->db->commit(); # Confirmo transacción
      return $user;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Registra un nuevo usuario con autenticación OAuth (SSO) con transacción
   * @param  User $userModel: model de usuario para traer el usuario creado
   * @param  string $oAuthID: ID del usuario en el servicio OAuth
   * @param  string $oAuthService: nombre del servicio OAuth (google, facebook, apple)
   * @param  string $email: correo electrónico del usuario
   * @param  string|null $firstName: nombre del usuario
   * @param  string|null $lastName: apellido del usuario
   * @param  string|null $picture: URL de foto de perfil
   * @param  string $userName: nombre de usuario
   * @param  string $tycVersion: versión de términos aceptada
   * @param  string $privacyVersion: versión de privacidad aceptada
   * @param  bool $receiveNewsletters: si desea recibir newsletters
   * @param  string $clientIp: IP del cliente
   * @param  string $userAgent: user agent del cliente
   * @return int: ID del usuario creado
   * @throws DatabaseException: si hay error en la transacción
   **/
  public function registerSSO(User $userModel, $oAuthID, $oAuthService, $email, $firstName, $lastName, $picture, $userName,
    $tycVersion, $privacyVersion, $receiveNewsletters, $clientIp, $userAgent
  ){
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("INSERT INTO Users (Email, FirstName, LastName, UserName,
        RegistrationDate, Oauth2ID, Oauth2Service, ValidatedEmail)
      VALUES (?,?,?,?,?,?,?,1)");
      $stmt->execute([$email, $firstName, $lastName, $userName, date('YmdHis'), $oAuthID, $oAuthService]);

      $userID = $this->db->lastInsertId(); # Obtener el ID del usuario creado

      # Insertar recibir novedades si eligio esta opcion
      $stmt = $this->db->prepare("INSERT INTO UsersSettings
        (UserID, Locale, ViewMode, ReceiveNewsletters, TimeZone)
        VALUES (?, 'es', 'Light', ?, 'America/Argentina/Buenos_Aires')");
      $stmt->execute([$userID, $receiveNewsletters]);

      # Por defecto canales notificacion activados
      $stmt = $this->db->prepare("INSERT INTO UsersNotifications
        (UserID, Email, WhatsApp, Sms, PushWeb, InApp)
        VALUES (?, 1, 1, 1, 1, 1)");
      $stmt->execute([$userID]);

      # Insertar consentimiento
      $stmt = $this->db->prepare("INSERT INTO UsersLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'TermsAndConditions', 1)");
      $stmt->execute([$userID, $clientIp, $userAgent, $tycVersion]);

      $stmt = $this->db->prepare("INSERT INTO UsersLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'PrivacyPolicy', 1)");
      $stmt->execute([$userID, $clientIp, $userAgent, $privacyVersion]);

      if(!empty($picture)){
        # Inserto la foto de perfil en la tabla media
        $stmt = $this->db->prepare("INSERT INTO Media (UserID, `URL`) VALUES (?,?)");
        $stmt->execute([$userID, $picture]);
      }

      $user = $userModel->getUserById($userID) ?:
        throw new DatabaseException("Failed to retrieve the registered user");

      $this->db->commit(); # Confirmo transacción

      return $user;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene datos OTP de un usuario
   * @param  int $userID: ID del usuario
   * @return array|false: datos OTP (OTPCode, OTPDate, OTPAttemps, Email) o false si no existe
   **/
  public function getUserOtp($userID){
    $stmt = $this->db->prepare("SELECT u.OTPCode, u.OTPDate, u.OTPAttemps, u.Email
    FROM Users AS u WHERE u.UserID = ?");
    $stmt->execute([$userID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Marca el email de un usuario como validado
   * @param  int $userID: ID del usuario
   **/
  public function validateUserEmail($userID){
    $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 1 WHERE UserID = ?");
    $stmt->execute([$userID]);
  }

  /**
   * Marca el telefono de un usuario como validado
   * @param  int $userID: ID del usuario
   **/
  public function validateUserPhone($userID){
    $stmt = $this->db->prepare("UPDATE Users SET ValidatedPhone = 1 WHERE UserID = ?");
    $stmt->execute([$userID]);
  }

  /**
   * Maneja la recompensa de referral cuando se alcanza el límite con transacción
   * @param  int $referrerUserID: ID del usuario que refirió
   * @param  int $newUserID: ID del nuevo usuario referido
   * @return bool: true si se activó la recompensa, false si no
   * @throws DatabaseException: si hay error en la transacción
   **/
  public function handleReferralReward($referrerUserID, $newUserID) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Insertar el referral como pendiente
      $stmt = $this->db->prepare("INSERT INTO Referrals (UserID, ReferredUserID, ReferralStatus)
        VALUES (?, ?, 'Pending')");
      $stmt->execute([$referrerUserID, $newUserID]);

      # Contar la cantidad de referidos pendientes + usados
      $countStmt = $this->db->prepare("SELECT COUNT(*) as total
        FROM Referrals
        WHERE UserID = ?");
      $countStmt->execute([$referrerUserID]);
      $count = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

      $rewardTriggered = false;

      if ($count >= 5) {
        # Marcar 5 referidos como usados
        $updateStmt = $this->db->prepare("UPDATE Referrals
          SET UpdatedAt = NOW(), ReferralStatus = 'Redeemed'
          WHERE UserID = ? AND ReferralStatus = 'Pending'
          LIMIT 5");
        $updateStmt->execute([$referrerUserID]);

        # Insertar recompensa
        $rewardStmt = $this->db->prepare("INSERT INTO ReferralRewards (UserID, RewardType, RewardAmount)
          VALUES (?, 'SubscriptionMonth', 1)");
        $rewardStmt->execute([$referrerUserID]);

        $rewardTriggered = true;
      }

      $this->db->commit(); # Confirmo transacción
      return $rewardTriggered;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Resetea la contraseña de un usuario
   * @param  int $userID: ID del usuario
   * @param  string $password: nueva contraseña en texto plano
   **/
  public function resetPassword($userID, $password) {
    # Encripta la nueva contraseña
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    # Actualiza la contraseña en la base de datos
    $stmt = $this->db->prepare("UPDATE Users SET PasswordHash = ? WHERE UserID = ?");
    $stmt->execute([$passwordHash, $userID]);
  }

  /**
   * Habilita autenticación de dos factores para un usuario
   * @param  int $userID: ID del usuario
   * @param  string $secret: secreto MFA generado
   **/
  public function mfaSet($userID, $secret) {
    $stmt = $this->db->prepare("UPDATE Users
      SET MfaSecret = ?, TwoFactorAuth = 1 WHERE UserID = ?");
    $stmt->execute([$secret, $userID]);
  }

  /**
   * Obtiene datos MFA de un usuario
   * @param  int $userID: ID del usuario
   * @return array|false: datos MFA (MfaSecret, FailedLoginAttempts, LockedUntil) o false si no existe
   **/
  public function getMfa($userID){
    $stmt = $this->db->prepare("SELECT MfaSecret, FailedLoginAttempts, LockedUntil
      FROM Users WHERE UserID = ? AND MfaSecret IS NOT NULL AND TwoFactorAuth = 1");
    $stmt->execute([$userID]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Deshabilita autenticación de dos factores para un usuario
   * @param  int $userID: ID del usuario
   **/
  public function mfaDel($userID){
    $stmt = $this->db->prepare("UPDATE Users
      SET MfaSecret = null, TwoFactorAuth = 0 WHERE UserID = ?");
    $stmt->execute([$userID]);
  }

  /**
   * Valida si un ID MFA registrado es válido para un usuario
   * @param  int $userID: ID del usuario
   * @param  string $mfaId: ID del MFA a validar
   * @return bool: true si es válido, false si no
   **/
  public function validateMfaId($userID, $mfaId) {
    $stmt = $this->db->prepare("SELECT * FROM UsersBrowser WHERE UserID = ? AND MfaID = ?");
    $stmt->execute([$userID, $mfaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
  }

  /**
   * Almacena datos del navegador para validación MFA
   * @param  int $userID: ID del usuario
   * @param  Request $request: objeto request para obtener User-Agent
   * @param  string $newMfaId: ID MFA a asociar
   * @param  string $clientIp: IP del cliente
   **/
  public function storeBrowserData($userID, $request, $newMfaId, $clientIp) {
    # Obtener información del navegador desde el encabezado User-Agent
    $userAgent = $request->getHeader('User-Agent')[0];
    $parser = new \WhichBrowser\Parser($userAgent);

    # Detalles del navegador y del dispositivo
    $browser = $parser->browser->getName();
    $version = $parser->browser->getVersion();
    $os = $parser->os->getName();
    $device = $parser->device->type;
    $ip = $clientIp;
    $expiry = date('Y-m-d H:i:s', strtotime('+90 days'));

    # Insertar los datos del navegador en la tabla UsersBrowser
    $stmt = $this->db->prepare("INSERT INTO UsersBrowser
      (UserID, MfaID, Browser, Version, Os, Device, IP, Expiry)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userID, $newMfaId, $browser, $version, $os, $device, $ip, $expiry]);
  }

  /**
   * Actualiza intentos fallidos de login y tiempo de bloqueo
   * @param  int $userID: ID del usuario
   * @param  int $failedAttempts: cantidad de intentos fallidos
   * @param  string|null $lockedUntil: timestamp de desbloqueo (NULL si no está bloqueado)
   **/
  public function updateFailedLogin($userID, $failedAttempts, $lockedUntil = null) {
    $stmt = $this->db->prepare("UPDATE Users SET FailedLoginAttempts = ?,
    LockedUntil = ? WHERE UserID = ?");
    $stmt->execute([$failedAttempts, $lockedUntil, $userID]);
  }

  /**
   * Calcula el tiempo de bloqueo basado en intentos fallidos
   * @param  int $failedAttempts: cantidad de intentos fallidos
   * @return string|null: timestamp de desbloqueo o null si no hay bloqueo
   **/
  public function calculateLockTime($failedAttempts) {
    $lockTime = null;
    switch ($failedAttempts) {
      case 5: $lockTime = "+1 minute"; break;
      case 6: $lockTime = "+2 minutes"; break;
      case 7: $lockTime = "+4 minutes"; break;
      case 8: $lockTime = "+8 minutes"; break;
      case 9: $lockTime = "+15 minutes"; break;
      case 10: $lockTime = "+30 minutes"; break;
    }
    return $lockTime ? date("Y-m-d H:i:s", strtotime($lockTime)) : null;
  }

  /**
   * Sube un documento legal a la base de datos
   * @param  string $type: tipo de documento (TermsAndConditions, PrivacyPolicy)
   * @param  string $version: versión del documento
   * @param  string $releaseDate: fecha de lanzamiento
   * @param  string $content: contenido del documento
   **/
  public function uploadLegalDocuments($type, $version, $releaseDate, $content){
    try {
      $this->db->beginTransaction(); # Iniciar transacción
      $stmt = $this->db->prepare("INSERT INTO LegalDocuments (DocumentType, Version, ReleaseDate, Content)
        VALUES (?,?,?,?)");
      $stmt->execute([$type, $version, $releaseDate, $content]);

      # Obtener el ID del documento creado
      $documentID = $this->db->lastInsertId();

      # SELECT dentro de la transacción
      $stmt = $this->db->prepare("SELECT * FROM LegalDocuments WHERE DocumentID = ?");
      $stmt->execute([$documentID]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      $this->db->commit(); # Confirmar DESPUÉS del SELECT

      return $result;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene los documentos legales más recientes de cada tipo
   * @return array: array con DocumentType como clave y datos del documento como valor
   **/
  public function legalDocuments() {
    $stmt = $this->db->prepare("SELECT t.* FROM LegalDocuments t
      INNER JOIN (
        SELECT DocumentType, MAX(ReleaseDate) AS MaxDate
        FROM LegalDocuments
        GROUP BY DocumentType
      ) latest
      ON t.DocumentType = latest.DocumentType AND t.ReleaseDate = latest.MaxDate");
    $stmt->execute();
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($documents as $doc) {
      $result[$doc['DocumentType']] = $doc;
    }
    return $result;
  }

  /**
   * Genera y graba en base de datos un código OTP para validar una cuenta
   * @param  int $userID: ID del usuario
   * @param  string $email: correo del usuario
   * @param  string $userName: nombre de usuario
   * @param  bool $recovery: true si es para recuperación, false si es para registro
   * @return bool: true si se envió correctamente, false si falló
   **/
  public function setOtpCodeDB($userID){
    # Genero un nuevo codigo OTP y lo grabo en el usuario
    $otpCode = rand(100000, 999999); # Codigo que se enviara por mail
    $stmt = $this->db->prepare("UPDATE Users SET OTPCode = ?, OTPDate = ? WHERE UserID = ?");
    $stmt->execute([$otpCode, date("YmdHis"), $userID]);

    return $otpCode;
  }

  /**
   * Incrementa el contador de intentos fallidos de validación OTP
   * @param  int $userID: ID del usuario
   **/
  public function incrementUserOtpAttempts($userID) {
    # Incrementar el contador de intentos fallidos
    $stmt = $this->db->prepare("UPDATE Users SET OTPAttemps = IFNULL(OTPAttemps, 0) + 1
      WHERE UserID = ?");
    $stmt->execute([$userID]);
  }

  /**
   * Obtiene el número de intentos fallidos de validación OTP
   * @param  int $userID: ID del usuario
   * @return int: cantidad de intentos fallidos (0 si no hay)
   **/
  public function getUserOtpAttempts($userID) {
    # Obtener el número de intentos fallidos
    $stmt = $this->db->prepare("SELECT OTPAttemps FROM Users WHERE UserID = ?");
    $stmt->execute([$userID]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ? ($user['OTPAttemps'] ?? 0) : 0;
  }

  /**
   * Limpia datos OTP de un usuario
   * @param  int $userID: ID del usuario
   **/
  public function clearUserOtp($userID) {
    # Resetear el OTP y el contador de intentos fallidos
    $stmt = $this->db->prepare("UPDATE Users SET OTPCode = NULL, OTPDate = NULL, OTPAttemps = NULL
    WHERE UserID = ?");
    $stmt->execute([$userID]);
  }
}

