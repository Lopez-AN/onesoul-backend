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
   * @param  string $userName: nombre de usuario
   * @param  array $params: filtros para la consulta
   * @return array: cotizaciones encontradas
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
}