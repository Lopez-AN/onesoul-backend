<?php

define('ROOT', dirname(__FILE__)."/../..");

# Inicializa config, database (pdo), etc
require ROOT . '/src/Workers/initWorker.php';

# Instanciar y ejectuar el worker
$worker = new BCRAWorker($pdo);
$worker->run();

class BCRAWorker {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  public function run() {
    print("📢 BCRAWorker iniciado...\n");

    try {
      if($this->_getTodayRates()){
        exit("⚠️ Today BCRA exchange rates are already updated\n");
      }

      # Me conecto a la API de BCRA
      $ch = curl_init('https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones');
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
      curl_close($ch);

      $json = @json_decode($result);
      if($httpCode !== 200 || empty($json -> results -> detalle)){
        exit("❌ Cannot connect to BCRA: $curlError\n");
      }

      $allowedCurrencies = $this->_getCurrencies() ?: [];
      $allowedCurrencies = array_column($allowedCurrencies, 'CurrencyCode');

      # Filtrar el array (asumiendo que $datos es tu array original)
      $currencies = array_filter($json -> results -> detalle, function($moneda) use ($allowedCurrencies) {
        return in_array($moneda -> codigoMoneda, $allowedCurrencies);
      });

      usort($currencies, function($a, $b) {
        # USD siempre primero
        if ($a -> codigoMoneda == 'USD') return -1;
        if ($b -> codigoMoneda == 'USD') return 1;

        # EUR siempre segundo
        if ($a -> codigoMoneda == 'EUR') return -1;
        if ($b -> codigoMoneda == 'EUR') return 1;

        # El resto ordenado alfabéticamente por código de moneda
        return strcmp($a -> codigoMoneda, $b -> codigoMoneda);
      });

      # Actualizo las cotizaciones
      foreach($currencies as $c){
        if($c->codigoMoneda === 'ARS'){ # ARS -> ARS no tiene sentido
          continue;
        }
        $stmt = $this->db->prepare("REPLACE INTO CurrencyExchangeRates
          (Date, BaseCurrency, QuoteCurrency, ExchangeRate)
          VALUES (?, 'ARS', ?, ?)");
        $stmt->execute([date('Y-m-d'), $c->codigoMoneda, $c->tipoCotizacion]);
        print("✅ ".$c->codigoMoneda."\n");
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

