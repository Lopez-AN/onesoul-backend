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
        WHERE u.UserName = ?");
        $stmt->execute([$username]);
      }else{
        $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
        LEFT JOIN Media as m ON u.UserID = m.UserID
        WHERE u.Email = ?");
        $stmt->execute([$email]);
      }
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateFailedLogin($userId, $failedAttempts, $lockedUntil = null) {
    try {
      $stmt = $this->db->prepare("UPDATE Users SET failed_login_attempts = ?,
      locked_until = ? WHERE UserID = ?");
      $stmt->execute([$failedAttempts, $lockedUntil, $userId]);
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
          "first_name" => !empty($response -> given_name) ? $response -> given_name : null,
          "last_name" => !empty($response -> family_name) ? $response -> family_name : null,
          "email" => !empty($response -> email) ? $response -> email : null,
          "picture" => !empty($response -> picture) ? $response -> picture : null
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
          "first_name" => !empty($response -> first_name) ? $response -> first_name : null,
          "last_name" => !empty($response -> last_name) ? $response -> last_name : null,
          "email" => !empty($response -> email) ? $response -> email : null,
          "picture" => !empty($response -> picture -> data -> url) ? $response -> picture -> data -> url : null
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
      "email" => $email,
      "username" => $username,
      "password_hash" => $password_hash,
      "otpCode" => $otpCode
    ]);

    # Envio el mail al usuario
    $this -> _sendOtpMail($email, $username, $otpCode);

    $user_data = $this -> getUserByUserName($username);
    return (object)["http_code" => 200, "data" => $user_data];
  }

  private function passwordComplexity($newPassword): bool {
    $password = trim($newPassword);

    # Requerimiento 1: mínimo 8 caracteres
    if (strlen($newPassword) < 8) {
        return false;
    }

    $points = 0;

    # Requerimiento 2: Validar las reglas con expresiones regulares
    if (preg_match('/[A-Z]/', $newPassword)) {
        $points++;
    }
    if (preg_match('/[a-z]/', $newPassword)) {
        $points++;
    }
    if (preg_match('/[0-9]/', $newPassword)) {
        $points++;
    }
    if (preg_match('/\W/', $newPassword)) {
        $points++;
    }

    # Debe tener al menos 3 puntos para ser considerada segura
    return $points >= 3;
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
      "first_name" => $first_name,
      "last_name" => $last_name,
      "email" => $email,
      "user_name" => $username,
      "picture" => $picture,
      "oauth2_id" => $userId,
      "oauth2_service" => "google"
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
      "first_name" => $first_name,
      "last_name" => $last_name,
      "email" => $email,
      "user_name" => $username,
      "picture" => $picture,
      "oauth2_id" => $userId,
      "oauth2_service" => "facebook"
    ]);

    $user_data = $this -> getUserByOAuthID($userId, "facebook");
    return (object)["http_code" => 200, "data" => $user_data];
  }

  /* Validacion OTP, el parametro resetOTP se envia en false para el metodo de resetear
    contraseña, debido a que este ultimo metodo es el que blanquea el OTP si es exitoso */
  public function validateOTP($userId, $otpCode, $resetOTP = true){
    try{
      $otp_exptime = $GLOBALS['config']['otp_exptime'];
      $stmt = $this->db->prepare("SELECT u.OTP_Code, u.OTP_Date, u.OTP_attemps, u.Email
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

      if(is_null($user['OTP_Code'])){
        return (object)[
          "http_code" => 401,
          "error" => [
            "code" => "OTP_CODE_NOT_FOUND",
            "desc" => "OTP code is not set. Please request a new OTP."
          ]
        ];
      }

      # Comparar el codigo OTP recibido con el codigo generado
      if($otpCode != $user['OTP_Code']){
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
      $otpDate = new DateTime($user['OTP_Date']);
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
  public function sendOtpMail($userId){
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
      $stmt = $this->db->prepare("UPDATE Users SET OTP_Code = ?, OTP_Date = ? WHERE UserID = ?");
      $stmt->execute([$otpCode, date("YmdHis"), $userId]);
      $this -> _sendOtpMail($resp[0]['Email'],$resp[0]['UserName'],$otpCode);

      return (object)["http_code" => 200, "data" => []];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  private function _sendOtpMail($rec, $username, $otpCode){
    $template = file_get_contents(ROOT."/src/templates/email_otp.html");
    $template = str_replace("{CODIGO}", $otpCode, $template);
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
      $mail->addAddress($rec, $username);

      # Contenido del correo
      $mail->isHTML(true);
      $mail->Subject = 'Complete su registro en OneSoul';
      $mail->Body    = $template;
      $mail->AltBody = "Hola $username, bienvenido a OneSoul\nSu código de verificaci&oacute;n es $otpCode";
      $mail->addEmbeddedImage(ROOT."/src/templates/logo.png", 'logo');

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
    $stmt = $this->db->prepare("UPDATE Users SET OTP_attemps = IFNULL(OTP_attemps, 0) + 1 WHERE UserID = ?");
    $stmt->execute([$userId]);
  }

  private function getOtpAttempts($userId) {
    # Obtener el número de intentos fallidos
    $stmt = $this->db->prepare("SELECT OTP_attemps FROM Users WHERE UserID = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user['OTP_attemps'] ?? 0;
  }

  private function resetOtp($userId) {
    # Resetear el OTP y el contador de intentos fallidos
    $stmt = $this->db->prepare("UPDATE Users SET OTP_Code = NULL, OTP_Date = NULL, OTP_attemps = NULL
    WHERE UserID = ?");
    $stmt->execute([$userId]);
  }

  # Registra los datos basicos de un usuario en modo por email
  private function registerUser($userData){
    try {
      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("INSERT INTO Users (Email, UserName, PasswordHash,
      OTP_Code, OTP_Date, RegistrationDate, ValidatedEmail)
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
      UserName, oauth2_id, oauth2_service, RegistrationDate)
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

  # Busca un usuario por username
  public function getUserByUserName($username){
    try{
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName,
      u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
      u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
      u.LastLogin, u.DeactivationDate, u.UserLevel, u.TermsAndConditions, u.SignedContract,
      GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name)) ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
      u.LegalDocuments, u.shortDescription, round(avg(r.Rating),2) as rating, m.URL as imgURL
      FROM Users as u
      LEFT JOIN UsersCategories as uc ON uc.userID = u.userID
      LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media as m ON u.UserID = m.UserID
      LEFT JOIN Reviews as r ON u.UserID = r.SUserID
      WHERE u.UserName = ?");
      $stmt->execute([$username]);
      $rs = $stmt->fetch(PDO::FETCH_ASSOC);

      if($rs && is_null($rs['UserID'])){
        $rs = [];
      }

      if(!empty($rs)){
        $rs['Categories'] = is_null($rs['Categories']) ? [] : array_map(
          function($a){
              $a = explode(":", $a);
              return ["id" => intval($a[0]), "name" => $a[1]];
          },explode(",",$rs['Categories'])
        );
      }

      return $rs;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Busca un usuario por email
  public function getUserByEmail($email){
    try{
      $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS u.UserID, u.FirstName, u.LastName, u.UserName,
      u.Email, u.Phone, u.AddressName, u.AddressNumber, u.Floor,
      u.Department, u.Cp, u.City, u.State, u.CountryCode, u.DateOfBirth,
      u.Gender, u.Biography, u.ValidatedEmail, u.TwoFactorAuth, u.UserType, u.RegistrationDate,
      u.LastLogin, u.DeactivationDate, u.UserLevel, u.TermsAndConditions, u.SignedContract,
      GROUP_CONCAT(DISTINCT CONCAT(c.CategoryID,':',trim(c.Name)) ORDER BY c.CategoryID ASC SEPARATOR ', ') AS Categories,
      u.LegalDocuments, u.shortDescription, round(avg(r.Rating),2) as rating, m.URL as imgURL
      FROM Users as u
      LEFT JOIN UsersCategories as uc ON uc.userID = u.userID
      LEFT JOIN Categories as c ON uc.CategoryID = c.CategoryID
      LEFT JOIN Media as m ON u.UserID = m.UserID
      LEFT JOIN Reviews as r ON u.UserID = r.SUserID
      WHERE u.Email = ? GROUP BY u.UserID ORDER BY u.UserID");
      $stmt->execute([$email]);
      $rs = $stmt->fetch(PDO::FETCH_ASSOC);

      if($rs && is_null($rs['UserID'])){
        $rs = [];
      }

      if(!empty($rs)){
        $rs['Categories'] = is_null($rs['Categories']) ? [] : array_map(
          function($a){
              $a = explode(":", $a);
              return ["id" => intval($a[0]), "name" => $a[1]];
          },explode(",",$rs['Categories'])
        );
      }

      return $rs;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Trae los datos del usuario luego de loguearse por SSO
  private function getUserByOAuthID($userId, $service){
    try{
      $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.oauth2_id = ? AND u.oauth2_service = ?");
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
        SET mfaSecret = ?, TwoFactorAuth = 1 WHERE UserID = ?");
      $stmt->execute([$secret, $userID]);
      $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function mfaCheck($userID){
    try {
      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("SELECT mfaSecret
        FROM Users WHERE UserID = ? AND mfaSecret IS NOT NULL");
      $stmt->execute([$userID]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function mfaDel($userID){
    try {
      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("UPDATE Users
        SET mfaSecret = null, TwoFactorAuth = 0 WHERE UserID = ?");
      $stmt->execute([$userID]);
      $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function validateMfaId($userId, $mfaId) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM UserBrowser WHERE UserID = ? AND mfa_id = ?");
      $stmt->execute([$userId, $mfaId]);
      return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function storeBrowserData($userId, $request) {
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
        INSERT INTO UserBrowser (UserID, mfa_id, browser, version, os, device, ip, expiry)
        VALUES (?, UUID(), ?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([$userId, $browser, $version, $os, $device, $ip, $expiry]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}


