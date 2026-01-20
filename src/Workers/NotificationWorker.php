<?php

use App\Utils\EmailHelper;
use App\Models\Notification;
use App\Enums\DeliveriesMode;
use App\Services\TwilioService;

define('ROOT', dirname(__FILE__)."/../..");

# Inicializa config, database (pdo), etc
require ROOT . '/src/Workers/initWorker.php';

# Instanciar y ejectuar el worker
$worker = new NotificationWorker(new Notification($pdo), new TwilioService());
$worker->run($argv[1] ?? null);


class NotificationWorker {
  protected $notification;
  protected $twilio;

  public function __construct(Notification $notification, TwilioService $twilio) {
    $this->notification = $notification;
    $this->twilio = $twilio;
  }

  public function run($notificationID = null) {
    print("📢 NotificationWorker iniciado...\n");

    if(!is_null($notificationID)){
      $deliveries = $this->notification->getDeliveriesByNotificationId(intval($notificationID), DeliveriesMode::PENDING);
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
        $this->notification->markDeliveryAsSent($delivery['DeliveryID']);
        print("[EMAIL] Delivery {$delivery['DeliveryID']} SUCCESS\n");
      }else{
        if($delivery['Attempts'] + 1 >= ($delivery['MaxAttempts'] ?? 3)){
          $this->notification->markDeliveryAsFailed($delivery['DeliveryID']);
          print("[EMAIL] Delivery {$delivery['DeliveryID']} FAILED: {$result->error->getMessage()}\n");
        }else{
          $this->notification->requeueDelivery($delivery['DeliveryID'], $delivery['FallbackAfterSeconds'] ?? 300);
          print("[EMAIL] Delivery {$delivery['DeliveryID']} REQUEUED: {$result->error->getMessage()}\n");
        }
      }
    } catch (\Throwable $e) {
      # Lo marco como fallido
      $this->notification->markDeliveryAsFailed($delivery['DeliveryID']);
      print("[EMAIL] Delivery {$delivery['DeliveryID']} FAILED: {$e->getMessage()}\n");
    }
  }

  private function _whatsapp($delivery){
    try{
      $phoneNumber = $this->notification->getRecipientAddress($delivery['RecipientUserID'], "WHATSAPP");

      # Fix numeros argentinos
      # ----------------------------
      # Si el número empieza con +54 pero NO tiene +549, agregar el 9
      if (preg_match('/^\+54(?!9)/', $phoneNumber)) {
        $phoneNumber = preg_replace('/^\+54/', '+549', $phoneNumber);
      }

      $template = $delivery['RenderedSubject'];
      $params = json_decode($delivery['RenderedBody']);

      $result = $this->twilio->sendWhatsAppTemplate(
        $phoneNumber,
        $template, # SID del template
        $params # Parametros
      );

      if($result->sent){
        $this->notification->markDeliveryAsSent($delivery['DeliveryID']);
        print("[WHATSAPP] Delivery {$delivery['DeliveryID']} SUCCESS\n");
      }else{
        if($delivery['Attempts'] + 1 >= ($delivery['MaxAttempts'] ?? 3)){
          $this->notification->markDeliveryAsFailed($delivery['DeliveryID']);
          print("[WHATSAPP] Delivery {$delivery['DeliveryID']} FAILED: {$result->error->getMessage()}\n");
        }else{
          $this->notification->requeueDelivery($delivery['DeliveryID'], $delivery['FallbackAfterSeconds'] ?? 300);
          print("[WHATSAPP] Delivery {$delivery['DeliveryID']} REQUEUED: {$result->error->getMessage()}\n");
        }
      }
    } catch (\Throwable $e) {
      # Lo marco como fallido
      $this->notification->markDeliveryAsFailed($delivery['DeliveryID']);
      print("[WHATSAPP] Delivery {$delivery['DeliveryID']} FAILED: {$e->getMessage()}\n");
    }
  }
}

