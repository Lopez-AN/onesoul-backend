<?php

use App\Utils\EmailHelper;
use App\Models\Notification;
use App\Utils\NotificationChannels;

require_once __DIR__ . '/../Utils/EmailHelper.php';
require_once __DIR__ . '/../Utils/NotificationChannels.php';
require_once __DIR__ . '/../Models/Notification.php';

define('SRC_ROOT', dirname(__DIR__));
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Leer config desde raíz/config/config.json
$configPath = PROJECT_ROOT . '/config/config.json';
$configJson = @file_get_contents($configPath);
$GLOBALS['config'] = json_decode($configJson, true);

if (!is_array($GLOBALS['config'])) {
  // Mensaje útil con la ruta real
  fwrite(STDERR, "Error leyendo config en: {$configPath}\n");
  http_response_code(500);
  exit(1);
}

// Conexión a la base de datos usando la config
try {
  $pdo = require SRC_ROOT . '/core/database.php';
} catch (PDOException $e) {
    die("❌ Error de conexión: " . $e->getMessage() . "\n");
}

class NotificationsWorker {
  protected $notification;
  protected $channels;
  protected $pdo;

  public function __construct(PDO $db, Notification $notification, NotificationChannels $channels) {
    $this->notification = $notification;
    $this->channels = $channels;
    $this->db = $db;
  }

  public function run() {
    echo "📢 NotificationsWorker iniciado...\n";

    while (true) {
      // Traer el próximo job pendiente
      $job = $this->notification->getNextJob();

      if (!$job) {
        sleep(1); // nada pendiente, esperar
        continue;
      }

      $jobId     = $job['JobID'];
      $channel   = $job['Channel'];
      $message   = $job['Message'];
      $recipient = $job['Recipient'];
      $attempt   = (int) $job['Attempt'];

      echo "🔔 Procesando Job #$jobId ($channel → $recipient, intento $attempt)\n";

      $ok = $this->channels->sendNotificationByChannel($channel, $message, $recipient, $attempt);

      if ($ok) {
        echo "✅ Enviado con éxito por $channel\n";
        $this->notification->markJobAsSuccess($jobId);
      } else {
        if ($attempt < 3) {
          $nextAttempt = $attempt + 1;
          $delay = pow(2, $attempt); // backoff: 2, 4, 8 segundos

          echo "⚠️ Falló, reintentando en $delay segundos (intento $nextAttempt)\n";
          $this->notification->requeueJob($jobId, $nextAttempt, $delay);
        } else {
          echo "❌ Falló definitivamente tras 3 intentos\n";
          $this->notification->markJobAsFailed($jobId);
        }
      }
    }
  }
}

// Crear instancias de los modelos/utilidades
$notificationModel = new Notification($pdo);
$notificationChannel = new NotificationChannels();

// Ejecutar el worker
$worker = new NotificationsWorker($pdo, $notificationModel, $notificationChannel);
$worker->run();
