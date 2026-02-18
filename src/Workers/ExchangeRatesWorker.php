<?php

define('ROOT', dirname(__FILE__)."/../..");

# Inicializa config, database (pdo), etc
require ROOT . '/src/Workers/initWorker.php';

# Instanciar y ejectuar el worker
$worker = new ExchangeRatesWorker($pdo);
$worker->run();

class ExchangeRatesWorker {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  public function run() {
    print("📢 ExchangeRatesWorker iniciado...\n");

    try {
      if($this->_getTodayRates()){
        exit("⚠️ Today exchange rates are already updated\n");
      }

      # Traigo las monedas con las que trabajamos
      $allowedCurrencies = $this->_getCurrencies() ?: [];
      $allowedCurrencies = array_column($allowedCurrencies, 'CurrencyCode');

      $rates = [];

      print("⌛ Updating currencies with exchangerate-api\n");
      # Me conecto a la API de BCRA para traer el valor del dolar
      $ch = curl_init('https://v6.exchangerate-api.com/v6/8a629d429a2f1ee2f36dbbf6/latest/USD');
      curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
      ]);
      $result = curl_exec($ch);
      $curlError = curl_error($ch);
      $curlErrno = curl_errno($ch);
      $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      $json = @json_decode($result);
      if($httpCode !== 200 || empty($json -> conversion_rates)){
        print("❌ Cannot connect to ExchangeAPI: $curlError\n");
      }else{
        foreach($json -> conversion_rates as $currency => $rate){
          if($currency === 'USD' || !in_array($currency, $allowedCurrencies)){
            continue;
          }
          $rates[$currency] = $rate;
        }
      }

      print("⌛ Updating USD->ARS with BCRA\n");
      # Me conecto a la API de BCRA para traer el valor del dolar
      $ch = curl_init('https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones/USD');
      curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
      ]);
      $result = curl_exec($ch);
      $curlError = curl_error($ch);
      $curlErrno = curl_errno($ch);
      $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      $json = @json_decode($result);
      if($httpCode !== 200 || empty($json->results[0]->detalle[0]->tipoCotizacion)){
        exit("❌ Cannot retrieve exchange rates from BCRA: $curlError\n");
      }

      $rates['ARS'] = $json->results[0]->detalle[0]->tipoCotizacion;

      if(empty($rates) || empty($rates['ARS'])){
        exit("❌ FAILED\n");
      }

      # Actualizo las cotizaciones
      foreach($rates as $currency => $rate){
        $stmt = $this->db->prepare("REPLACE INTO CurrencyExchangeRates
          (Date, BaseCurrency, QuoteCurrency, ExchangeRate)
          VALUES (?, 'USD', ?, ?)");
        $stmt->execute([date('Y-m-d'), $currency, $rate]);
        print("✅ $currency\n");
      }

    } catch (\Throwable $e) {
      exit("❌ FAILED: {$e->getMessage()}\n");
    }
  }

  # Obtiene las monedas que estan dadas de alta en la plataforma
  # para solo quedarme esas de las que traiga BCRA
  private function _getCurrencies(){
    $stmt = $this->db->prepare("SELECT CurrencyCode FROM Currencies");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  # Obtiene los rates de hoy, si existen no hace falta actualizarlos
  private function _getTodayRates(){
    $stmt = $this->db->prepare("SELECT * FROM CurrencyExchangeRates
      WHERE Date = ?");
    $stmt->execute([date('Y-m-d')]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }
}

