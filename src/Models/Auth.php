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
      $stmt = $this->db->prepare("SELECT u.* FROM Users AS u WHERE u.UserName = ?");
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
      return array();
    }
    curl_close($ch);
    return json_decode($response);
  }

  public function loginFacebook($user_id, $token){
    // URL del endpoint
    $url = "https://graph.facebook.com/$user_id?fields=id,name,email,picture.width(640)&access_token=$token";
    $ch = curl_init();

    // Configuración de cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false); // Para obtener la cabecera HTTP completa
    
    $response = curl_exec($ch);
    
    // Verifica si hubo un error en la solicitud
    if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200){
      return array();
    }
    curl_close($ch);
    return json_decode($response);
  }
}  


