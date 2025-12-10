<?php

use App\Utils\EmailHelper;
use App\Models\Notification;

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT', dirname(__FILE__)."/../..");

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die('Este script solo puede ejecutarse desde la línea de comandos');
}

require ROOT.'/vendor/autoload.php';

define("PENDING_ONLY", true);

# Leo la config
$GLOBALS['config'] = @json_decode(file_get_contents(ROOT.'/config/config.json'),true);
if(!$GLOBALS['config']){
  http_response_code(500);
  exit("Error reading ~/config/config.json");
}

# Conexión a la base de datos usando la config
try {
  require ROOT.'/src/Core/Database.php';
  $pdo = Database::getInstance()->getConnection();
} catch (PDOException $e) {
  die("❌ Cannot connect to MySQL: " . $e->getMessage() . "\n");
}

# Instanciar y ejectuar el worker
$worker = new NotificationWorker($pdo, new Notification($pdo));
$worker->run($argv[1] ?? null);


class NotificationWorker {
  protected $notification;
  protected $db;

  public function __construct(PDO $db, Notification $notification) {
    $this->notification = $notification;
    $this->db = $db;
  }

  public function run($notificationID = null) {
    print("📢 NotificationWorker iniciado...\n");

    if(!is_null($notificationID)){
      $deliveries = $this->notification->getDeliveriesByNotificationId(intval($notificationID), PENDING_ONLY);
      foreach($deliveries as $delivery){
        $this->_sendChannel($delivery);
      }
    }else{
      while(1){
        $delivery = $this->notification->getNextDelivery();
        # Si ya no hay mas envios o entro en un loop salgo
        if(!$delivery || ($id ?? -1) === $delivery['DeliveryID']){
          break;
        }
        $id = $delivery['DeliveryID'];

        $this->_sendChannel($delivery);
      }
    }
  }

  private function _sendChannel($delivery){
    $channel = $delivery['Channel'] ?? null;
    if(is_null($channel)){
      return;
    }
    switch($channel){
      case 'EMAIL':
        $this->_email($delivery);
      break;
      case 'WHATSAPP':
        $this->_whatsapp($delivery);
      break;
    }
  }

  private function _email($delivery){
    try{
      $emailAddress = $this->notification->getRecipientAddress($delivery['RecipientUserID'], "EMAIL");
      $result = EmailHelper::send(
        $emailAddress,
        $delivery['RenderedSubject'],
        $delivery['RenderedBody']
      );
      if($result->sent){
        $this->notification->markJobAsSent($delivery['DeliveryID']);
        print("[EMAIL] Delivery {$delivery['DeliveryID']} SUCCESS\n");
      }else{
        if($delivery['Attempts'] + 1 >= ($delivery['MaxAttemps'] ?? 3)){
          $this->notification->markJobAsFailed($delivery['DeliveryID']);
          print("[EMAIL] Delivery {$delivery['DeliveryID']} FAILED: {$result->error->getMessage()}\n");
        }else{
          $this->notification->requeueJob($delivery['DeliveryID'], $delivery['FallbackAfterSeconds'] ?? 300);
          print("[EMAIL] Delivery {$delivery['DeliveryID']} REQUEUED: {$result->error->getMessage()}\n");
        }
      }
    } catch (\Throwable $e) {
      # Lo marco como fallido
      $this->notification->markJobAsFailed($delivery['DeliveryID']);
      print("[EMAIL] Delivery {$delivery['DeliveryID']} FAILED: {$e->getMessage()}\n");
    }
  }

  private function _whatsapp($delivery){
    print("WHATSAPP NO IMPLEMENTADO\n");
    $this->notification->markDeliveryAsSent($delivery['DeliveryID']);
  }
}

