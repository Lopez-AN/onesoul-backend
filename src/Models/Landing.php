<?php

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Landing {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene los datos de contacto con filtro de rango de fecha opcional
   *
   * Las datos se devuelven ordenadas por fecha de creación ascendente.
   *
   * @param array $paginador (limit, offset)
   * @param date $fromDate: fecha minima a buscar  (opcional)
   * @param date $toDate: fecha maxima a buscar  (opcional)
   * @return array Array con 'data' (contactos) y 'rows' (total y cantidad obtenida)
   */
  public function getContactInfo($paginator, $fromDate, $toDate) {
    $w = "TRUE"; # Condiciones extra
    $params = [];
    if(!is_null($fromDate)){
      $w .= " AND Date(lc.Date) >= ? ";
      $params[] = $fromDate;
    }
    if(!is_null($toDate)){
      $w .= " AND Date(lc.Date) <= ? ";
      $params[] = $toDate;
    }

    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS *
      FROM LandingContacts as lc
      WHERE $w
      ORDER BY lc.Date ASC
      LIMIT ? OFFSET ?");
    $params[] = $paginator->limit;
    $params[] = $paginator->offset;

    $stmt->execute($params);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
      "data" => $contacts,
      "rows" => [
        "total" => $total,
        "fetched" => count($contacts)
      ]
    ];
  }

  /**
   * Inserta un nuevo contacto desde el landing page
   * Graba tambien los datos del browser, dispositivo e ip de la solicitud
   * @param int $userID: ID del guía propietario
   * @param int $data: Datos del contacto a guardar
   * @param int $browser: Informacion del browser, dispositivo...
   **/
  public function saveContactInfo($data, $browser){
    $stmt = $this->db->prepare("INSERT INTO LandingContacts
      (Name, Email, SocialNetwork, CountryCode, City, Specialization,
      Browser, BrowserVersion, Os, Device, IP)
      VALUES (:name, :email, :socialNetwork, :countryCode, :city, :specialization,
      :browser, :browserVersion, :os, :device, :ip)");

    $stmt->execute([
      ':name' => $data['Name'],
      ':email' => $data['Email'],
      ':socialNetwork' => $data['SocialNetwork'],
      ':countryCode' => $data['CountryCode'],
      ':city' => $data['City'],
      ':specialization' => $data['Specialization'],
      ':browser' => $browser['browser'],
      ':browserVersion' => $browser['version'],
      ':os' => $browser['os'],
      ':device' => $browser['device'],
      ':ip' => $browser['ip']
    ]);
  }
}