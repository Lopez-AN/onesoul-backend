<?php

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class Currency {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene las cotizaciones de una maneda base contra las demas
   * Si no se especifica una fecha trae la ultima disponible
   *
   * @param  array $params {
   *   Code: codigo de la moneda base,
   *   currency: código de la moneda a comparar (opcional)
   *   date: fecha a buscar, si no se especifica busca la última (opcional)
   * }
   *   @return object: cotizaciones encontradas
   *
   **/
  public function getCurrencyExchangeRates($params) {
    $w = " BaseCurrency = ? ";
    $values = [$params['Code']];

    if($params['currency']){
      $w .= " AND QuoteCurrency = ? ";
      $values[] = $params['currency'];
    }

    if($params['date']){
      $w .= " AND Date = ? ";
      $values[] = $params['date'];
    }else{
      $w .= " AND Date = (SELECT MAX(Date) FROM CurrencyExchangeRates WHERE BaseCurrency = ?) ";
      $values[] = $params['Code'];
    }

    $stmt = $this->db->prepare("SELECT Date, QuoteCurrency as Currency, ExchangeRate
      FROM CurrencyExchangeRates WHERE $w");
    $stmt->execute($values);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * Obtiene la moneda default del pais (defaultea en ARS)
   *
   * @param  string $countryCode: código de pais a buscar
   * @return string: moneda encontrada
   *
   **/
  public function getCountryDefaultCurrency($countryCode) {
    $stmt = $this->db->prepare("SELECT CurrencyCode FROM Countries WHERE CountryCode = ?");
    $stmt->execute([$countryCode]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: 'ARS';
  }
}