<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

    $user_id = $response -> sub;
    $user_data = $this -> getUserByOAuthID($user_id, "google");
    if(empty($user_data)){
      return (object)["http_code" => 404,
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified Google account was found"
        ],
        "data" => [
          "first_name" => $response -> given_name,
          "last_name" => $response -> family_name,
          "picture" => $response -> picture,
          "email" => $response -> email
        ]
      ];
    }
    return (object)["http_code" => 200, "data" => $user_data];
  }

  public function loginFacebook($user_id, $token){
    $response = $this -> validateToken("https://graph.facebook.com/$user_id?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
    if($response === false){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "SSO_INVALID_TOKEN",
          "desc" => "Invalid Facebook token"
        ]
      ];
    }

    $user_data = $this -> getUserByOAuthID($user_id, "facebook");
    if(empty($user_data)){
      return (object)["http_code" => 404,
        "error" => [
          "code" => "USER_NOT_FOUND",
          "desc" => "No user associated with the specified Facebook account was found"
        ],
        "data" => [
          "first_name" => $response -> first_name,
          "last_name" => $response -> last_name,
          "picture" => $response -> picture -> data -> url,
          "email" => $response -> email
        ]
      ];
    }
    return (object)["http_code" => 200, "data" => $user_data];
  }

  /*
  * Registro usuario
  */
  public function register($email, $username, $password){
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

    $password_hash = password_hash($password,PASSWORD_BCRYPT); #El password se guarda hasheado (obvio!)
    $otpCode = rand(100000, 999999); # Codigo que se enviara por mail

    $this -> registerUser((object)[
      "email" => $email,
      "username" => $username,
      "password_hash" => $password_hash,
      "otp_code" => $otpCode
    ]);

    # Envio el mail al usuario
    $this -> _sendOtpMail($email, $username, $otpCode);

    $user_data = $this -> getUserByUserName($username);
    return (object)["http_code" => 200, "data" => $user_data];
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

    $user_id = $response -> sub;
    $user_data = $this -> getUserByOAuthID($user_id, "google");
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
      "oauth2_id" => $user_id,
      "oauth2_service" => "google"
    ]);

    $user_data = $this -> getUserByOAuthID($user_id, "google");
    return (object)["http_code" => 200, "data" => $user_data];
  }

  public function registerFacebook($user_id, $token, $username){
    $response = $this -> validateToken("https://graph.facebook.com/$user_id?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
    if($response === false){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "SSO_INVALID_TOKEN",
          "desc" => "Invalid Facebook token"
        ]
      ];
    }

    $user_data = $this -> getUserByOAuthID($user_id, "facebook");
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
      "oauth2_id" => $user_id,
      "oauth2_service" => "facebook"
    ]);

    $user_data = $this -> getUserByOAuthID($user_id, "facebook");
    return (object)["http_code" => 200, "data" => $user_data];
  }

  # Validacion OTP
  public function validateOTP($user_id, $otpCode){
    try{
      $stmt = $this->db->prepare("SELECT u.OTP_Date FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.UserID = ? AND u.OTP_Code = ?");
      $stmt->execute([$user_id, $otpCode]);
      $resp = $stmt->fetchAll(PDO::FETCH_ASSOC);

      # El OTP es de un solo uso
      if(!empty($resp)){
        $stmt = $this->db->prepare("UPDATE Users
        SET OTP_code = null,OTP_date = null
        WHERE UserID = ?");
        $stmt->execute([$user_id]);
      }
      return $resp;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Envio de codigo OTP por email
  public function sendOtpMail($user_id){
    try{
      $stmt = $this->db->prepare("SELECT u.Email, u.UserName FROM Users AS u
      WHERE u.UserID = ?");
      $stmt->execute([$user_id]);
      $resp = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if(empty($resp)){
        return (object)["http_code" => 404,
          "error" => [
            "code" => "USER_NOT_FOUND",
            "desc" => "No user was found with the specified ID"
          ]
        ];
      }

      # Genero un nuevo codigo OTP y lo grabo en el usuario
      $otpCode = rand(100000, 999999); # Codigo que se enviara por mail
      $stmt = $this->db->prepare("UPDATE Users SET OTP_Code = ?, OTP_Date = ? WHERE UserID = ?");
      $stmt->execute([$otpCode, date("YmdHis"), $user_id]);

      $this -> _sendOtpMail($resp[0]['Email'],$resp[0]['UserName'],$otpCode);
      return (object)["http_code" => 200, "data" => []];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
  private function _sendOtpMail($rec, $username, $otp_cod){
    $template = file_get_contents(ROOT."/src/templates/email_otp.html");
    $template = str_replace("{CODIGO}", $otp_cod, $template);
    $template = str_replace("{USERNAME}", $username, $template);

    $smtpAccount = $GLOBALS['config']['mailer']['account'];
    $smtpPassword = $GLOBALS['config']['mailer']['password'];

    // Configuración de PHPMailer
    $mail = new PHPMailer(true);
    try {
      // Configuración del servidor SMTP
      $mail->isSMTP();
      $mail->Host = 'smtp.gmail.com';
      $mail->SMTPAuth = true;
      $mail->Username = $smtpAccount;
      $mail->Password = $smtpPassword;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port = 587;

      // Configuración del remitente y destinatario
      $mail->setFrom($smtpAccount,'Contacto OneSoul');
      $mail->addAddress($rec, $username);

      // Contenido del correo
      $mail->isHTML(true);
      $mail->Subject = 'Complete su registro en OneSoul';
      $mail->Body    = $template;
      $mail->AltBody = "Hola $username, bienvenido a OneSoul\nSu código de verificaci&oacute;n es $otp_cod";
      $mail->addEmbeddedImage(ROOT."/src/templates/logo.png", 'logo');

      // Enviar el correo
      $mail->send();
    } catch (Exception $e) {
      # echo "No se pudo enviar el correo. Error: {$mail->ErrorInfo}";
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

  # Registra los datos basicos de un usuario en modo por email
  private function registerUser($userData){
    try {
      # Creo el usuario con los datos basicos
      $stmt = $this->db->prepare("INSERT INTO Users (Email, UserName, PasswordHash, OTP_Code, OTP_Date, ValidatedEmail)
      VALUES (?,?,?,?,?,0)");
      $stmt->execute([$userData -> email, $userData -> username, $userData -> password_hash,
      $userData -> otp_code, date('YmdHis')]);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Registra los datos basicos de un usuario en los logueos por SSO
  private function registerUserSSO($userData){
    # Creo el usuario con los datos basicos
    try {
      $stmt = $this->db->prepare("INSERT INTO Users (FirstName, LastName, Email, UserName, oauth2_id, oauth2_service)
      VALUES (?,?,?,?,?,?)");
      $stmt->execute([$userData -> first_name, $userData -> last_name, $userData -> email,
      $userData -> user_name, $userData -> oauth2_id, $userData -> oauth2_service]);

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
      $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.UserName = ?");
      $stmt->execute([$username]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Busca un usuario por email
  public function getUserByEmail($email){
    try{
      $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.Email = ?");
      $stmt->execute([$email]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  # Trae los datos del usuario luego de loguearse por SSO
  private function getUserByOAuthID($user_id, $service){
    try{
      $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.oauth2_id = ? AND u.oauth2_service = ?");
      $stmt->execute([$user_id, $service]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function validateReCaptcha($recaptchaToken, $clientIp) {
    $secret = $GLOBALS['config']['recaptcha']['secret'];
    $minScore = $GLOBALS['config']['recaptcha']['min_score'];
    $url = "https://www.google.com/recaptcha/api/siteverify?secret=$secret&response=$recaptchaToken&remoteip=$clientIp";

    // Hacer la petición a la API de reCAPTCHA
    $response = $this -> validateToken($url);
    if($response === false || empty($response -> success)){
      return (object)["http_code" => 401,
        "error" => [
          "code" => "RECAPTCHA_INVALID_TOKEN",
          "desc" => "Invalid reCaptcha token"
        ]
      ];
    }

    // Si el score es muy bajo
    if ($response -> score < $minScore) {
      return (object)["http_code" => 401,
        "error" => [
          "code" => "RECAPTCHA_LOW_SCORE",
          "desc" => "reCaptcha score is too low"
        ]
      ];
    }

    // Validación exitosa
    return (object)["http_code" => 200, "data" => []];
  }
}

