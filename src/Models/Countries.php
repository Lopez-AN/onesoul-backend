<?php

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Countries {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene los paises con sus estados
   *
   * @param countryCode $string | null: pais a filtrar (opcional)
   * @return array paises encontrados
   */
  public function getCountries($countryCode) {
    $w = "TRUE"; # Condiciones extra
    $params = [];
    if(!is_null($countryCode)){
      $w .= " AND CountryCode = ? ";
      $params[] = $countryCode;
    }

    $stmt = $this->db->prepare("SELECT *,
      -- Subconsulta para media_images
      (SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
          'StateName', StateName
        )
      ) FROM CountriesStates
      WHERE c.CountryCode = CountryCode
      ORDER BY StateName) as states
      FROM Countries as c
      WHERE $w
      ORDER BY c.CountryCode");

    $stmt->execute($params);
    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(function($e){
      $e['IsActive'] = (bool)$e['IsActive'];
      $states = @json_decode($e['states'], true);
      $e['States'] = array_column($states, 'StateName');
      unset($e['states']);
      return $e;
    }, $countries);
  }

  /**
   * Obtiene los estados de un pais
   *
   * @param countryCode $string: pais a consultar
   * @return array paises encontrados
   */
  public function getStates($countryCode) {
    $stmt = $this->db->prepare("SELECT *
      FROM CountriesStates
      WHERE CountryCode = ?
      ORDER BY StateName");
    $stmt->execute([$countryCode]);
    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return empty($states) ? [] : array_column($states, 'StateName');
  }
}