<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Auth{
  protected $db;

  public function __construct(PDO $db){
    $this->db = $db;
  }

  /*
  * Logueo usuario
  */
  public function login($username){
    try {
      $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
      LEFT JOIN Media as m ON u.UserID = m.UserID
      WHERE u.UserName = ?");
      $stmt->execute([$username]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function loginGoogle($token){
    $response = $this -> validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
    if($response === false){
      return (object)array("http_code" => 401, "data" => "Cant validate token");
    }

    $user_id = $response -> sub;
    $user_data = $this -> getUserData($user_id, "google");
    if(empty($user_data)){
      return (object)array("http_code" => 404, "data" => array(
         "first_name" => $response -> given_name,
         "last_name" => $response -> family_name,
         "picture" => $response -> picture,
         "email" => $response -> email
      ));
    }
    return (object)array("http_code" => 200, "data" => $user_data);
  }

  public function loginFacebook($user_id, $token){
    $response = $this -> validateToken("https://graph.facebook.com/$user_id?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
    if($response === false){
      return (object)array("http_code" => 401, "data" => "Cant validate token");
    }

    $user_data = $this -> getUserData($user_id, "facebook");
    if(empty($user_data)){
      return (object)array("http_code" => 404, "data" => array(
        "first_name" => $response -> first_name,
        "last_name" => $response -> last_name,
        "picture" => $response -> picture -> data -> url,
        "email" => $response -> email
     ));
    }
    return (object)array("http_code" => 200, "data" => $user_data);
  }

  /*
  * Registro usuario
  */
  public function registerGoogle($token){
    $response = $this -> validateToken("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
    if($response === false){
      return (object)array("http_code" => 401, "data" => "Cant validate token");
    }

    $user_id = $response -> sub;
    $user_data = $this -> getUserData($user_id, "google");
    if(!empty($user_data)){ # Si el usuario existe lo devuelvo para genera el token
      return (object)array("http_code" => 200, "data" => $user_data);
    }

    # Fix por posibles campos nulos
    $first_name = !empty($response -> given_name) ? $response -> given_name : null;
    $last_name = !empty($response -> family_name) ? $response -> family_name : null;
    $email = !empty($response -> email) ? $response -> email : null;
    $picture = !empty($response -> picture) ? $response -> picture : null;

    # Verifico si hay otro usuario con ese email
    if(!empty($email) && !empty($this -> getUserByEmail($email))){
      return (object)array("http_code" => 409, "data" => "A user with this email address already exists.");
    }

    $this -> registerUserSSO((object)array(
      "first_name" => $first_name,
      "last_name" => $last_name,
      "email" => $email,
      "picture" => $picture,
      "oauth2_id" => $user_id,
      "oauth2_service" => "google"
    ));

    $user_data = $this -> getUserData($user_id, "google");
    return (object)array("http_code" => 200, "data" => $user_data);
  }

  public function registerFacebook($user_id, $token){
    $response = $this -> validateToken("https://graph.facebook.com/$user_id?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token");
    if($response === false){
      return (object)array("http_code" => 401, "data" => "Cant validate token");
    }
    $user_data = $this -> getUserData($user_id, "facebook");
    if(!empty($user_data)){ # Si el usuario existe lo devuelvo para genera el token
      return (object)array("http_code" => 200, "data" => $user_data);
    }

    # Fix por posibles campos nulos
    $first_name = !empty($response -> first_name) ? $response -> first_name : null;
    $last_name = !empty($response -> last_name) ? $response -> last_name : null;
    $email = !empty($response -> email) ? $response -> email : null;
    $picture = !empty($response -> picture -> data -> url) ? $response -> picture -> data -> url : null;

    # Verifico si hay otro usuario con ese email
    if(!empty($email) && !empty($this -> getUserByEmail($email))){
      return (object)array("http_code" => 409, "data" => "A user with this email address already exists.");
    }

    $this -> registerUserSSO((object)array(
      "first_name" => $first_name,
      "last_name" => $last_name,
      "email" => $email,
      "picture" => $picture,
      "oauth2_id" => $user_id,
      "oauth2_service" => "facebook"
    ));

    $user_data = $this -> getUserData($user_id, "facebook");
    return (object)array("http_code" => 200, "data" => $user_data);
  }

  # Valida un token generado por el login SSO
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

  # Registra los datos basicos de un usuario en los logueos por SSO
  private function registerUserSSO($userData){
    # Creo el usuario con los datos basicos
    $stmt = $this->db->prepare("INSERT INTO Users (FirstName, LastName, Email, oauth2_id, oauth2_service)
    VALUES (?,?,?,?,?)");
    $stmt->execute([$userData -> first_name, $userData -> last_name, $userData -> email,
    $userData -> oauth2_id, $userData -> oauth2_service]);

    if(!is_null($userData -> picture)){
      # Obtengo el ID del usuario creado
      $userId = $this->db->lastInsertId();

      # Inserto la foto de perfil en la tabla media
      $stmt = $this->db->prepare("INSERT INTO Media (UserID, `URL`) VALUES (?,?)");
      $stmt->execute([$userId, $userData -> picture]);
    }
  }

  # Busca un usuario por email
  private function getUserByEmail($email){
    $stmt = $this->db->prepare("SELECT u.* FROM Users AS u WHERE u.Email = ?");
    $stmt->execute([$email]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  # Trae los datos del usuario luego de loguearse por SSO
  private function getUserData($user_id, $service){
    $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
    LEFT JOIN Media as m ON u.UserID = m.UserID
    WHERE u.oauth2_id = ? AND u.oauth2_service = ?");
    $stmt->execute([$user_id, $service]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}
