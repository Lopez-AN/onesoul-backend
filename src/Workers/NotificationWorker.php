<?php

use App\Utils\EmailHelper;
use App\Models\Notification;
#use App\Utils\NotificationChannels;

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT', dirname(__FILE__)."/../..");

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  die('Este script solo puede ejecutarse desde la línea de comandos');
}

require ROOT.'/vendor/autoload.php';

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
$worker->run();


class NotificationWorker {
  protected $notification;
  protected $db;

  public function __construct(PDO $db, Notification $notification) {
    $this->notification = $notification;
    $this->db = $db;
  }

  public function run() {
    echo "📢 NotificationWorker iniciado...\n";

    if(isset($argv[1]) && is_numeric($argv[1])){
      $this->notify(intval($argv[1]));
    }else{
      $this->notifyAll();
    }
  }

  public function notifyAll(){
    while(1){
      $delivery = $this->notification->getNextDelivery();
      $channel = $delivery['Channel'] ?? null;
      if(is_null($channel)){
        break;
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
  }

  public function notify($notificationID) {
    echo "📢 NotificationWorker iniciado 2...\n";

    $deliveries = $this->notification->getDeliveriesByNotificationId($notificationID);
    foreach($deliveries as $d){
      print_r($d);
    }
  }

  private function _email($delivery){
    print_r($delivery);
  }

  private function _whatsapp($delivery){
    print_r($delivery);
  }
}

//   public function run() {


//     while (true) {
//       # Traer el próximo job pendiente
//       $job = $this->notification->getNextJob();

//       if (!$job) {
//         sleep(1); # nada pendiente, esperar
//         continue;
//       }

//       $jobId     = $job['JobID'];
//       $channel   = $job['Channel'];
//       $message   = $job['Message'];
//       $recipient = $job['Recipient'];
//       $attempt   = (int) $job['Attempt'];

//       echo "🔔 Procesando Job #$jobId ($channel → $recipient, intento $attempt)\n";

//       $ok = $this->channels->sendNotificationByChannel($channel, $message, $recipient, $attempt);

//       if ($ok) {
//         echo "✅ Enviado con éxito por $channel\n";
//         $this->notification->markJobAsSuccess($jobId);
//       } else {
//         if ($attempt < 3) {
//           $nextAttempt = $attempt + 1;
//           $delay = pow(2, $attempt); # backoff: 2, 4, 8 segundos

//           echo "⚠️ Falló, reintentando en $delay segundos (intento $nextAttempt)\n";
//           $this->notification->requeueJob($jobId, $nextAttempt, $delay);
//         } else {
//           echo "❌ Falló definitivamente tras 3 intentos\n";
//           $this->notification->markJobAsFailed($jobId);
//         }
//       }
//     }
//   }
// }

