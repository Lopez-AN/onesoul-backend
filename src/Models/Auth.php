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
    // URL del endpoint
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=$token";
    $ch = curl_init();

    // Configuración de cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false); // Para obtener la cabecera HTTP completa
    
    $response = curl_exec($ch);
    
    // Verifica si hubo un error en la solicitud
    if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200){
      return (object)array("http_code" => 401, "data" => "Cant validate token");
    }
    curl_close($ch);

    $response = json_decode($response);

    $user_id = $response -> sub;

    $data = $this -> getUserData($user_id, "google");
    if(empty($data)){
      return (object)array("http_code" => 404, "data" => array(
         "first_name" => $data[0]["given_name"],
         "last_name" => $data[0]["family_name"],
         "picture" => $data[0]["picture"],
         "email" => $data[0]["email"]
      ));  
    }

    return (object)array("http_code" => 200, "data" => $data);
  }

  public function loginFacebook($user_id, $token){
    // URL del endpoint
    $url = "https://graph.facebook.com/$user_id?fields=id,first_name,last_name,email,picture.width(640)&access_token=$token";
    $ch = curl_init();

    // Configuración de cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false); // Para obtener la cabecera HTTP completa
    
    $response = curl_exec($ch);
    
    // Verifica si hubo un error en la solicitud
    if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200){
      return (object)array("http_code" => 401, "data" => "Cant validate token");
    }
    curl_close($ch);

    $response = json_decode($response);
    $data = $this -> getUserData($user_id, "facebook");
    if(empty($data)){
      return (object)array("http_code" => 404, "data" => array(
        "first_name" => $data[0]["first_name"],
        "last_name" => $data[0]["last_name"],
        "picture" => $data[0]["picture"]['data']['url'],
        "email" => $data[0]["email"]
     ));  
    }
    return (object)array("http_code" => 200, "data" => $data);
  }

  private function getUserData($user_id, $service){
    //query para buscar el usuario
    $stmt = $this->db->prepare("SELECT u.*,m.URL FROM Users AS u 
    LEFT JOIN Media as m ON u.UserID = m.UserID
    WHERE u.oauth2_id = ? AND u.oauth2_service = ?");
    $stmt->execute([$user_id, $service]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}  


