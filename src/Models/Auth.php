<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Auth{
  protected $db;

  public function __construct(PDO $db){
    $this->db = $db;
  }

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

  # Valida un token generado por el login SSO
  private function validateToken($url){
    $ch = curl_init();

    // Configuración de cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    $response = curl_exec($ch);

    // Verifica si hubo un error en la solicitud
    if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200){
      return false;
    }
    curl_close($ch);
    return json_decode($response);
  }

  # Trae los datos del usuario luego de loguearse por SSO
  private function getUserData($user_id, $service){
    //query para buscar el usuario
    $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u
    LEFT JOIN Media as m ON u.UserID = m.UserID
    WHERE u.oauth2_id = ? AND u.oauth2_service = ?");
    $stmt->execute([$user_id, $service]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}


