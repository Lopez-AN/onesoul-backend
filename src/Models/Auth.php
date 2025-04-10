<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use \DateTime;

class Auth{
  protected $db;

  public function __construct(PDO $db){
    $this->db = $db;
  }

  /*
  * Logueo usuario
  */
  public function login($username, $email){
    try {
      if($username){
        $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
        LEFT JOIN Media as m ON u.UserID = m.UserID
        WHERE u.UserName = ? AND u.PasswordHash IS NOT NULL");
        $stmt->execute([$username]);
      }else{
        $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
        LEFT JOIN Media as m ON u.UserID = m.UserID
        WHERE u.Email = ? AND u.PasswordHash IS NOT NULL");
        $stmt->execute([$email]);
      }
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function loginGoogle($token){
    $response = $this -> validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
    if($response === false){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "SSO_INVALID_TOKEN",
          "desc" => "Invalid Google token"
        ]
      ];
    }

    $userId = $response -> sub;
    $user_data = $this -> getUserByOAuthID($userId, "google");
    if(empty($user_data)){
      return (object)["http_code" => 404,
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified Google account was found"
        ],
        "data" => [
          "FirstName" => !empty($response -> given_name) ? $response -> given_name : null,
          "LastName" => !empty($response -> family_name) ? $response -> family_name : null,
          "Email" => !empty($response -> email) ? $response -> email : null,
          "Picture" => !empty($response -> picture) ? $response -> picture : null
        ]
      ];
    }
    return (object)["http_code" => 200, "data" => $user_data];
  }

  public function loginFacebook($userId, $token){
    $response = $this -> validateToken("https://graph.facebook.com/$userId?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
    if($response === false){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "SSO_INVALID_TOKEN",
          "desc" => "Invalid Facebook token"
        ]
      ];
    }

    $user_data = $this -> getUserByOAuthID($userId, "facebook");
    if(empty($user_data)){
      return (object)["http_code" => 404,
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified Facebook account was found"
        ],
        "data" => [
          "FirstName" => !empty($response -> first_name) ? $response -> first_name : null,
          "LastName" => !empty($response -> last_name) ? $response -> last_name : null,
          "Email" => !empty($response -> email) ? $response -> email : null,
          "Picture" => !empty($response -> picture -> data -> url) ? $response -> picture -> data -> url : null
        ]
      ];
    }
    return (object)["http_code" => 200, "data" => $user_data];
  }

  /*
  * Registro usuario
  */
  public function register($email, $username, $newPassword){
    # Validación de fortaleza de contraseña
    if(!$this->passwordComplexity($newPassword)) {
      return (object)[
        "http_code" => 400,
        "error" => [
          "code" => "WEAK_PASSWORD",
          "desc" => "Password doesn't meet complexity requirements"
        ]
      ];
    }

    if(!empty($this -> getUserByEmail($email))){
      return (object)["http_code" => 409,
        "error" => [
          "code" => "DUPLICATED_EMAIL",
          "desc" => "A user with the specified email address already exists"
        ]
      ];
    }

    if(!empty($this -> getUserByUserName($username))){
      return (object)["http_code" => 409,
        "error" => [
          "code" => "DUPLICATED_USERNAME",
          "desc" => "A user with the specified username already exists"
        ]
      ];
    }

    $password_hash = password_hash($newPassword,PASSWORD_BCRYPT); #El password se guarda hasheado (obvio!)
    $otpCode = rand(100000, 999999); # Codigo que se enviara por mail

    $this -> registerUser((object)[
      "Email" => $email,
      "UserName" => $username,
      "PasswordHash" => $password_hash,
      "OTPCode" => $otpCode
    ]);

    $user_data = $this -> getUserByUserName($username);
    return (object)["http_code" => 200, "data" => $user_data];
  }

  private function passwordComplexity($newPassword): bool {
    $password = trim($newPassword);

    return strlen($password) >= 8 &&
        preg_match('/[A-Z]/', $password) &&   // Debe tener al menos una mayúscula
        preg_match('/[a-z]/', $password) &&   // Debe tener al menos una minúscula
        (preg_match('/[0-9]/', $password) || preg_match('/\W/', $password));  // Debe tener un número O un símbolo
    }

  public function registerGoogle($token, $username){
    $response = $this -> validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
    if($response === false){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "SSO_INVALID_TOKEN",
          "desc" => "Invalid Google token"
        ]
      ];
    }

    $userId = $response -> sub;
    $user_data = $this -> getUserByOAuthID($userId, "google");
    if(!empty($user_data)){ # Si el usuario existe lo devuelvo para genera el token
      return (object)["http_code" => 200, "data" => $user_data];
    }

    # Fix por posibles campos nulos
    $first_name = !empty($response -> given_name) ? $response -> given_name : null;
    $last_name = !empty($response -> family_name) ? $response -> family_name : null;
    $email = !empty($response -> email) ? $response -> email : null;
    $picture = !empty($response -> picture) ? $response -> picture : null;

    # Verifico si hay otro usuario con ese email
    if(!empty($email) && !empty($this -> getUserByEmail($email))){
      return (object)["http_code" => 409,
        "error" => [
          "code" => "DUPLICATED_EMAIL",
          "desc" => "A user with the specified email address already exists"
        ]
      ];
    }
   # Verifico si hay otro usuario con ese username
    if(!empty($this -> getUserByUserName($username))){
      return (object)["http_code" => 409,
        "error" => [
          "code" => "DUPLICATED_USERNAME",
          "desc" => "A user with the specified username already exists"
        ]
      ];
    }

    $this -> registerUserSSO((object)[
      "FirstName" => $first_name,
      "LastName" => $last_name,
      "Email" => $email,
      "UserName" => $username,
      "Picture" => $picture,
      "Oauth2ID" => $userId,
      "Oauth2Service" => "google"
    ]);

    $user_data = $this -> getUserByOAuthID($userId, "google");
    return (object)["http_code" => 200, "data" => $user_data];
  }

  public function registerFacebook($userId, $token, $username){
    $response = $this -> validateToken("https://graph.facebook.com/$userId?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
    if($response === false){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "SSO_INVALID_TOKEN",
          "desc" => "Invalid Facebook token"
        ]
      ];
    }

    $user_data = $this -> getUserByOAuthID($userId, "facebook");
    if(!empty($user_data)){ # Si el usuario existe lo devuelvo para genera el token
      return (object)["http_code" => 200, "data" => $user_data];
    }

    # Fix por posibles campos nulos
    $first_name = !empty($response -> first_name) ? $response -> first_name : null;
    $last_name = !empty($response -> last_name) ? $response -> last_name : null;
    $email = !empty($response -> email) ? $response -> email : null;
    $picture = !empty($response -> picture -> data -> url) ? $response -> picture -> data -> url : null;

    # Verifico si hay otro usuario con ese email
    if(!empty($email) && !empty($this -> getUserByEmail($email))){
      return (object)["http_code" => 409,
        "error" => [
          "code" => "DUPLICATED_EMAIL",
          "desc" => "A user with the specified email address already exists"
        ]
      ];
    }
   # Verifico si hay otro usuario con ese username
    if(!empty($username) && !empty($this -> getUserByUserName($username))){
      return (object)["http_code" => 409,
        "error" => [
          "code" => "DUPLICATED_USERNAME",
          "desc" => "A user with the specified username already exists"
        ]
      ];
    }

    $this -> registerUserSSO((object)[
      "FirstName" => $first_name,
      "LastName" => $last_name,
      "Email" => $email,
      "UserName" => $username,
      "Picture" => $picture,
      "Oauth2ID" => $userId,
      "Oauth2Service" => "facebook"
    ]);

    $user_data = $this -> getUserByOAuthID($userId, "facebook");
    return (object)["http_code" => 200, "data" => $user_data];
  }

  /* Validacion OTP, el parametro resetOTP se envia en false para el metodo de resetear
    contraseña, debido a que este ultimo metodo es el que blanquea el OTP si es exitoso */
  public function validateOTP($userId, $otpCode, $resetOTP = true){
    try{
      $otp_exptime = $GLOBALS['config']['otp_exptime'];
      $stmt = $this->db->prepare("SELECT u.OTPCode, u.OTPDate, u.OTPAttemps, u.Email
      FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.UserID = ?");
      $stmt->execute([$userId]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      # Si no se encontro el usuario devolver el error
      if(empty($user)){
        return (object)["http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified data."
          ]
        ];
      }

      if(is_null($user['OTPCode'])){
        return (object)[
          "http_code" => 401,
          "error" => [
            "code" => "OTP_CODE_NOT_FOUND",
            "desc" => "OTP code is not set. Please request a new OTP."
          ]
        ];
      }

      # Comparar el codigo OTP recibido con el codigo generado
      if($otpCode != $user['OTPCode']){
        # Incrementar los intentos fallidos si el código no era correcto
        $this->incrementOtpAttempts($userId);

        # Verificar si ya ha alcanzado el límite de intentos fallidos
        if ($this->getOtpAttempts($userId) > 3) {
          # Resetear OTP y contador de intentos
          $this->resetOtp($userId);
          return (object)[
            "http_code" => 401,
            "error" => [
              "code" => "OTP_MAX_ATTEMPTS",
              "desc" => "Maximum OTP attempts reached. Please request a new OTP."
            ]
          ];
        }

        return (object)[
          "http_code" => 401,
          "error" => [
            "code" => "OTP_CODE_INVALID",
            "desc" => "Invalid OTP code"
          ]
        ];
      }

      # Verificar si el OTP ha expirado
      $otpDate = new DateTime($user['OTPDate']);
      $now = new DateTime();
      $interval_in_seconds = $now->getTimestamp() - $otpDate->getTimestamp();

      # Comparar el intervalo con otp_exptime
      if ($interval_in_seconds > $otp_exptime) {
        # Resetear OTP y contador de intentos
        $this->resetOtp($userId);
        return (object)[
          "http_code" => 400,
          "error" => [
            "code" => "EXPIRED_OTP",
            "desc" => "OTP has expired. Please request a new OTP."
          ]
        ];
      }

      if($resetOTP){
        # Si el OTP es válido, resetear el OTP y los intentos
        $this->resetOtp($userId);
      }

      $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 1 WHERE UserID = ?");
      $stmt->execute([$userId]);

      # OTP válido
      return (object)["http_code" => 200,"data" => []];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Envio de codigo OTP por email desde el JWT
  public function sendOtpMail($userId,$recovery = false){
    try{
      $stmt = $this->db->prepare("SELECT u.Email, u.UserName FROM Users AS u
      WHERE u.UserID = ?");
      $stmt->execute([$userId]);
      $resp = $stmt->fetchAll(PDO::FETCH_ASSOC);
      if(empty($resp)){
        return (object)["http_code" => 404,
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user was found with the specified data."
        ]
      ];
      }

      # Genero un nuevo codigo OTP y lo grabo en el usuario
      $otpCode = rand(100000, 999999); # Codigo que se enviara por mail
      $stmt = $this->db->prepare("UPDATE Users SET OTPCode = ?, OTPDate = ? WHERE UserID = ?");
      $stmt->execute([$otpCode, date("YmdHis"), $userId]);
      $this -> _sendOtpMail($resp[0]['Email'],$resp[0]['UserName'],$otpCode,$recovery);

      return (object)["http_code" => 200, "data" => []];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  private function _sendOtpMail($rec, $username, $otpCode, $recovery){
    $template = file_get_contents(ROOT."/src/templates/email_otp.html");
    $template = str_replace("{CODIGO}", $otpCode, $template);
    $template = str_replace("{USERNAME}", $username, $template);
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
      $mail->addAddress($rec, $username);

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
    } catch (Exception $e) {
      # echo "No se pudo enviar el correo. Error: {$mail->ErrorInfo}";
    }
  }

  public function resetPassword($userID, $newPassword) {
    # Validar la fortaleza de la nueva contraseña
    if (!$this->passwordComplexity($newPassword)) {
      return (object)[
        "http_code" => 400,
        "error" => [
          "code" => "WEAK_PASSWORD",
          "desc" => "Password doesn't meet complexity requirements"
        ]
      ];
    }

    # Encripta la nueva contraseña
    $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);

    # Actualiza la contraseña en la base de datos
    try {
      $stmt = $this->db->prepare("UPDATE Users SET PasswordHash = ? WHERE UserID = ?");
      $stmt->execute([$newPasswordHash, $userID]);

      # Blanqueo el OTP
      $this -> resetOtp($userID);

      return (object)["http_code" => 200,"data" =>[]];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Valida un token generado por el login SSO o reCaptcha
  private function validateToken($url){
    $ch = curl_init();

    # Configuración de cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    $response = curl_exec($ch);

    # Verifica si hubo un error en la solicitud
    if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200){
      return false;
    }
    curl_close($ch);
    return json_decode($response);
  }


  private function incrementOtpAttempts($userId) {
    # Incrementar el contador de intentos fallidos
    $stmt = $this->db->prepare("UPDATE Users SET OTPAttemps = IFNULL(OTPAttemps, 0) + 1 WHERE UserID = ?");
    $stmt->execute([$userId]);
  }

  private function getOtpAttempts($userId) {
    # Obtener el número de intentos fallidos
    $stmt = $this->db->prepare("SELECT OTPAttemps FROM Users WHERE UserID = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user['OTPAttemps'] ?? 0;
  }

  private function resetOtp($userId) {
    # Resetear el OTP y el contador de intentos fallidos
    $stmt = $this->db->prepare("UPDATE Users SET OTPCode = NULL, OTPDate = NULL, OTPAttemps = NULL
    WHERE UserID = ?");
    $stmt->execute([$userId]);
  }

  # Registra los datos basicos de un usuario en modo por email
  private function registerUser($userData){
    try {
      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("INSERT INTO Users (Email, UserName, PasswordHash,
      OTPCode, OTPDate, RegistrationDate, ValidatedEmail)
      VALUES (?,?,?,?,?,?,0)");
      $stmt->execute([$userData -> email, $userData -> username, $userData -> password_hash,
        $userData -> otpCode, date('YmdHis'), date('YmdHis')]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Registra los datos basicos de un usuario en los logueos por SSO
  private function registerUserSSO($userData){
    # Creo el usuario con los datos basicos
    try {
      $stmt = $this->db->prepare("INSERT INTO Users (FirstName, LastName, Email,
      UserName, Oauth2ID, Oauth2Service, RegistrationDate)
      VALUES (?,?,?,?,?,?,?)");
      $stmt->execute([$userData -> first_name, $userData -> last_name, $userData -> email,
      $userData -> user_name, $userData -> oauth2_id, $userData -> oauth2_service, date('YmdHis')]);

      # Obtengo el ID del usuario creado
      $userId = $this->db->lastInsertId();

      if(!is_null($userData -> email)){
        $stmt = $this->db->prepare("UPDATE Users SET ValidatedEmail = 1 WHERE UserID = ?");
        $stmt->execute([$userId]);
      }

      if(!is_null($userData -> picture)){
        # Inserto la foto de perfil en la tabla media
        $stmt = $this->db->prepare("INSERT INTO Media (UserID, `URL`) VALUES (?,?)");
        $stmt->execute([$userId, $userData -> picture]);
      }
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Trae los datos del usuario luego de loguearse por SSO
  private function getUserByOAuthID($userId, $service){
    try{
      $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.Oauth2ID = ? AND u.Oauth2Service = ?");
      $stmt->execute([$userId, $service]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function validateReCaptcha($recaptchaToken, $clientIp) {
    $secret = $GLOBALS['config']['recaptcha']['secret'];
    $minScore = $GLOBALS['config']['recaptcha']['min_score'];
    $url = "https://www.google.com/recaptcha/api/siteverify?secret=$secret&response=$recaptchaToken&remoteip=$clientIp";

    # Hacer la petición a la API de reCAPTCHA
    $response = $this -> validateToken($url);
    if($response === false || empty($response -> success)){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "INVALID_RECAPTCHA_TOKEN",
          "desc" => "Invalid reCaptcha token"
        ]
      ];
    }

    # Si el score es muy bajo
    if ($response -> score < $minScore) {
      return (object)["http_code" => 401,
        "error" => [
          "code" => "RECAPTCHA_LOW_SCORE",
          "desc" => "reCaptcha score is too low"
        ]
      ];
    }

    # Validación exitosa
    return (object)["http_code" => 200, "data" => []];
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
        "http_code" => 200,
        "message" => "MFA Verified"
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

  public function validateMfaId($userId, $mfaId) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM UserBrowser WHERE UserID = ? AND MfaID = ?");
      $stmt->execute([$userId, $mfaId]);
      return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function storeBrowserData($userId, $request, $newMfaId) {
    // Obtener información del navegador desde el encabezado User-Agent
    $userAgent = $request->getHeader('User-Agent')[0];
    $parser = new \WhichBrowser\Parser($userAgent);

    // Detalles del navegador y del dispositivo
    $browser = $parser->browser->getName();
    $version = $parser->browser->getVersion();
    $os = $parser->os->getName();
    $device = $parser->device->type;
    $ip = $request->getAttribute('ip_address');
    $expiry = date('Y-m-d H:i:s', strtotime('+90 days'));

    try {
      // Insertar los datos del navegador en la tabla UserBrowser
      $stmt = $this->db->prepare("
        INSERT INTO UserBrowser (UserID, MfaID, Browser, Version, Os, Device, IP, Expiry)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([$userId, $newMfaId, $browser, $version, $os, $device, $ip, $expiry]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateFailedLogin($userId, $failedAttempts, $lockedUntil = null) {
    try {
      $stmt = $this->db->prepare("UPDATE Users SET FailedLoginAttempts = ?,
      LockedUntil = ? WHERE UserID = ?");
      $stmt->execute([$failedAttempts, $lockedUntil, $userId]);
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
}
