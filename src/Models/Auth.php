<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use \DateTime;
use Predis\Client as RedisClient;

class Auth{
  protected $db;
  protected $redis;

  public function __construct(PDO $db, RedisClient $redis){
    $this->db = $db;
    $this->redis = $redis;
  }

  /*
  * Logueo usuario
  */
  public function login($username, $email){
    try {
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
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function checkLegalDocuments($tycVersion, $privacyPolicyVersion){
    try{
      // Verificar que las versiones legales existan en la base de datos
      $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM LegalDocuments
        WHERE (DocumentType = 'TermsAndConditions' AND Version = ?)
          OR (DocumentType = 'PrivacyPolicy' AND Version = ?)");
      $stmt->execute([$tycVersion, $privacyPolicyVersion]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /*
  * Registro usuario
  */
  public function register($email, $userName, $passwordHash,
    $tycVersion, $privacyVersion, $receiveNewsletters, $clientIp, $userAgent
  ){
    try {
      // Iniciar transacción
      $this->db->beginTransaction();

      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("INSERT INTO Users (Email, UserName, PasswordHash, RegistrationDate, ValidatedEmail)
      VALUES (?,?,?,?,1)");
      $stmt->execute([$email, $userName, $passwordHash, date('YmdHis')]);

      // Obtener el ID del usuario creado
      $userID = $this->db->lastInsertId();

      // Insertar recibir novedades si eligio esta opcion
      $stmt2 = $this->db->prepare("INSERT INTO UserSettings (UserID, ReceiveNewsletters) VALUES (?, ?)");
      $stmt2->execute([$userID, $receiveNewsletters]);

      // Insertar consentimiento
      $stmt3 = $this->db->prepare("INSERT INTO UserLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'TermsAndConditions', 1)");
      $stmt3->execute([$userID, $clientIp, $userAgent, $tycVersion]);

      $stmt4 = $this->db->prepare("INSERT INTO UserLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'PrivacyPolicy', 1)");
      $stmt4->execute([$userID, $clientIp, $userAgent, $privacyVersion]);

      // Confirmo transacción
      $this->db->commit();

      return $userID;
    } catch (\PDOException $e) {
      $this->db->rollBack(); // Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  public function registerSSO($oAuthID, $oAuthService, $email, $firstName, $lastName, $picture, $userName,
    $tycVersion, $privacyVersion, $receiveNewsletters, $clientIp, $userAgent
  ){
    try {
      // Iniciar transacción
      $this->db->beginTransaction();

      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("INSERT INTO Users (Email, FirstName, LastName, UserName,
        RegistrationDate, Oauth2ID, Oauth2Service, ValidatedEmail)
      VALUES (?,?,?,?,?,?,?,1)");
      $stmt->execute([$email, $firstName, $lastName, $userName, date('YmdHis'), $oAuthID, $oAuthService]);

      // Obtener el ID del usuario creado
      $userID = $this->db->lastInsertId();

      // Insertar recibir novedades si eligio esta opcion
      $stmt2 = $this->db->prepare("INSERT INTO UserSettings (UserID, ReceiveNewsletters) VALUES (?, ?)");
      $stmt2->execute([$userID, $receiveNewsletters]);

      // Insertar consentimiento
      $stmt3 = $this->db->prepare("INSERT INTO UserLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'TermsAndConditions', 1)");
      $stmt3->execute([$userID, $clientIp, $userAgent, $tycVersion]);

      $stmt4 = $this->db->prepare("INSERT INTO UserLegalConsents
        (UserID, UserIP, UserAgent, Version, DocumentType, Accepted)
        VALUES (?, ?, ?, ?, 'PrivacyPolicy', 1)");
      $stmt4->execute([$userID, $clientIp, $userAgent, $privacyVersion]);

      if(!empty($picture)){
        # Inserto la foto de perfil en la tabla media
        $stmt5 = $this->db->prepare("INSERT INTO Media (UserID, `URL`) VALUES (?,?)");
        $stmt5->execute([$userID, $picture]);
      }

      // Confirmo transacción
      $this->db->commit();

      return $userID;
    } catch (\PDOException $e) {
      $this->db->rollBack(); // Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getUserOtp($userID){
    try{
      $stmt = $this->db->prepare("SELECT u.OTPCode, u.OTPDate, u.OTPAttemps, u.Email
      FROM Users AS u WHERE u.UserID = ?");
      $stmt->execute([$userID]);
      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function validateUserEmail($userID){
    try{
      $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 1 WHERE UserID = ?");
      $stmt->execute([$userID]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function handleReferralReward($referrerUserID, $newUserID) {
    try {
      // Insertar el referral como pendiente
      $stmt = $this->db->prepare("INSERT INTO Referrals (UserID, ReferredUserID, ReferralStatus)
        VALUES (?, ?, 'Pending')");
      $stmt->execute([$referrerUserID, $newUserID]);

      // Contar la cantidad de referidos pendientes + usados
      $countStmt = $this->db->prepare("SELECT COUNT(*) as total
        FROM Referrals
        WHERE UserID = ?");
      $countStmt->execute([$referrerUserID]);
      $count = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

      $rewardTriggered = false;

      if ($count >= 5) {
        // Marcar 5 referidos como usados
        $updateStmt = $this->db->prepare("UPDATE Referrals
          SET UpdatedAt = NOW(), ReferralStatus = 'Redeemed'
          WHERE UserID = ? AND ReferralStatus = 'Pending'
          LIMIT 5");
        $updateStmt->execute([$referrerUserID]);

        // Insertar recompensa
        $rewardStmt = $this->db->prepare("INSERT INTO ReferralRewards (UserID, RewardType, RewardAmount)
          VALUES (?, 'SubscriptionMonth', 1)");
        $rewardStmt->execute([$referrerUserID]);

        $rewardTriggered = true;
      }
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function resetPassword($userID, $password) {
    # Encripta la nueva contraseña
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    # Actualiza la contraseña en la base de datos
    try {
      $stmt = $this->db->prepare("UPDATE Users SET PasswordHash = ? WHERE UserID = ?");
      $stmt->execute([$passwordHash, $userID]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function mfaSet($userID, $secret){

    try {
      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("UPDATE Users
        SET MfaSecret = ?, TwoFactorAuth = 1 WHERE UserID = ?");
      $stmt->execute([$secret, $userID]);
      $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function mfaCheck($userID, $code)
  {
    try {
      // Verificar si el usuario está bloqueado
      $stmt = $this->db->prepare("SELECT MfaSecret, FailedLoginAttempts, LockedUntil
        FROM Users WHERE UserID = ? AND MfaSecret IS NOT NULL");
      $stmt->execute([$userID]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      if (empty($result) || $result['MfaSecret'] === null) {
        return (object) [
          "http_code" => 401,
          "error" => [
            "code" => "MFA_NOT_SET",
            "desc" => "The user does not have MFA configured"
          ]
        ];
      }

      if (!is_null($result['LockedUntil']) && strtotime($result['LockedUntil']) > time()) {
        return (object) [
          "http_code" => 403,
          "error" => [
            "code" => "USER_LOCKED",
            "desc" => "Account is temporarily locked until " . $result['LockedUntil']
          ]
        ];
      }

      $secret = $result['MfaSecret'];
      $g2fa = new \PragmaRX\Google2FA\Google2FA();

      if (!$g2fa->verifyKey($secret, $code)) {
        // Incrementar intentos fallidos y actualizar bloqueo si es necesario
        $failedAttempts = $result['FailedLoginAttempts'] + 1;
        $lockTime = $this->calculateLockTime($failedAttempts);

        $this->updateFailedLogin($userID, $failedAttempts, $lockTime);

        if ($lockTime !== null) {
          return (object) [
            "http_code" => 403,
            "error" => [
              "code" => "MFA_MAX_ATTEMPTS",
              "desc" => "Maximum MFA attempts reached. Account is now locked."
            ]
          ];
        }

        return (object) [
          "http_code" => 401,
          "error" => [
            "code" => "INVALID_MFA_CODE",
            "desc" => "Cannot verify provided MFA code"
          ]
        ];
      }

      // MFA verificado, resetear intentos fallidos y bloqueo
      $this->updateFailedLogin($userID, 0, null);

      return (object) [
        "http_code" => 200
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function mfaDel($userID){
    try {
      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("UPDATE Users
        SET MfaSecret = null, TwoFactorAuth = 0 WHERE UserID = ?");
      $stmt->execute([$userID]);
      $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function validateMfaId($userID, $mfaId) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM UserBrowser WHERE UserID = ? AND MfaID = ?");
      $stmt->execute([$userID, $mfaId]);
      return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function storeBrowserData($userID, $request, $newMfaId, $clientIp) {
    // Obtener información del navegador desde el encabezado User-Agent
    $userAgent = $request->getHeader('User-Agent')[0];
    $parser = new \WhichBrowser\Parser($userAgent);

    // Detalles del navegador y del dispositivo
    $browser = $parser->browser->getName();
    $version = $parser->browser->getVersion();
    $os = $parser->os->getName();
    $device = $parser->device->type;
    $ip = $clientIp;
    $expiry = date('Y-m-d H:i:s', strtotime('+90 days'));

    try {
      // Insertar los datos del navegador en la tabla UserBrowser
      $stmt = $this->db->prepare("
        INSERT INTO UserBrowser (UserID, MfaID, Browser, Version, Os, Device, IP, Expiry)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([$userID, $newMfaId, $browser, $version, $os, $device, $ip, $expiry]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateFailedLogin($userID, $failedAttempts, $lockedUntil = null) {
    try {
      $stmt = $this->db->prepare("UPDATE Users SET FailedLoginAttempts = ?,
      LockedUntil = ? WHERE UserID = ?");
      $stmt->execute([$failedAttempts, $lockedUntil, $userID]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Función para calcular los tiempos de bloqueo
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

  // Subir documentacion legal
  public function uploadLegalDocuments($type, $version, $releaseDate, $content){
    try {
      $stmt = $this->db->prepare("INSERT INTO LegalDocuments (DocumentType, Version, ReleaseDate, Content)
        VALUES (?,?,?,?)");
      $stmt->execute([$type, $version, $releaseDate, $content]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function legalDocuments() {
    try {
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
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }


  # Envio de codigo OTP por email trayendo al usuario de la base con un userID
  public function sendOtpMailExistingUser($userID, $email, $userName, $recovery = false){
    try{
      # Genero un nuevo codigo OTP y lo grabo en el usuario
      $otpCode = rand(100000, 999999); # Codigo que se enviara por mail
      $stmt = $this->db->prepare("UPDATE Users SET OTPCode = ?, OTPDate = ? WHERE UserID = ?");
      $stmt->execute([$otpCode, date("YmdHis"), $userID]);

      return $this -> _sendOtpMail($email, $otpCode, $userName, $recovery);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Envio de codigo OTP para usuarios no existentes, almaceno el OTP code en redis
  public function sendOtpMailNoUser($email){
    $otpCode = rand(100000, 999999); # Codigo que se enviara por mail
    $hashedOtp = password_hash((string)$otpCode, PASSWORD_BCRYPT); # Hasheo el OTP code

    // Json que guardo en redis
    $otpData = [
      'otp_hash' => $hashedOtp,
      'attempts' => 0, // Contador de intentos
      'validated' => false, // Indica si ya se valido el email
      'created_at' => time(),
      'expires_at' => time() + $GLOBALS['config']['otp_exptime'] // Expiracion
    ];

    try{
      $this->redis->setex("otp:{$email}", 86400, json_encode($otpData));
      return $this -> _sendOtpMail($email, $otpCode);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  private function _sendOtpMail($email, $otpCode, $username = false, $recovery = false){
    $template = file_get_contents(ROOT."/src/templates/email_otp.html");
    $template = str_replace("{CODIGO}", $otpCode, $template);
    $template = str_replace("{USERNAME}", $username ?: $email, $template);
    $template = str_replace("{T_MODE1}", $recovery ? '' : ', bienvenido a OneSoul', $template);
    $template = str_replace("{T_MODE2}", $recovery ? 'recuperaci&oacute;n' : 'registro', $template);

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
      $mail->Subject = $recovery ? "Recupera tu cuenta de OneSoul" : "Complete su registro en OneSoul";
      $mail->Body    = $template;
      $mail->AltBody = $recovery ?
        "Hola $username, bienvenido a OneSoul\nSu código de verificaci&oacute;n es $otpCode" :
        "Hola $username\nSu código de recuperaci&oacute;n es $otpCode";
      $mail->addEmbeddedImage(ROOT."/src/templates/logo2.png", 'logo');

      # Enviar el correo
      $mail->send();
      return true;
    } catch (Exception $e) {
      return false;
    }
  }

  public function incrementUserOtpAttempts($userID) {
    try{
      # Incrementar el contador de intentos fallidos
      $stmt = $this->db->prepare("UPDATE Users SET OTPAttemps = IFNULL(OTPAttemps, 0) + 1
        WHERE UserID = ?");
      $stmt->execute([$userID]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getUserOtpAttempts($userID) {
    try{
      # Obtener el número de intentos fallidos
      $stmt = $this->db->prepare("SELECT OTPAttemps FROM Users WHERE UserID = ?");
      $stmt->execute([$userID]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      return $user ? ($user['OTPAttemps'] ?? 0) : 0;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function clearUserOtp($userID) {
    try{
      # Resetear el OTP y el contador de intentos fallidos
      $stmt = $this->db->prepare("UPDATE Users SET OTPCode = NULL, OTPDate = NULL, OTPAttemps = NULL
      WHERE UserID = ?");
      $stmt->execute([$userID]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}

