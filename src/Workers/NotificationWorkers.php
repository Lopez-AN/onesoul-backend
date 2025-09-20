<?php

require_once __DIR__ . '/../models/NotificationsModel.php';
require_once __DIR__ . '/../helpers/NotificationChannels.php';

class NotificationsWorker
{
    private $notificationsModel;

    public function __construct()
    {
        $this->notificationsModel = new NotificationsModel();
    }

    public function run()
    {
        echo "📢 NotificationsWorker iniciado...\n";

        while (true) {
            // Traer el próximo job pendiente
            $job = $this->notificationsModel->getNextJob();

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

            $ok = sendNotificationByChannel($channel, $message, $recipient, $attempt);

            if ($ok) {
                echo "✅ Enviado con éxito por $channel\n";
                $this->notificationsModel->markJobAsSuccess($jobId);
            } else {
                if ($attempt < 3) {
                    $nextAttempt = $attempt + 1;
                    $delay = pow(2, $attempt); // backoff: 2, 4, 8 segundos

                    echo "⚠️ Falló, reintentando en $delay segundos (intento $nextAttempt)\n";

                    $this->notificationsModel->requeueJob($jobId, $nextAttempt, $delay);
                } else {
                    echo "❌ Falló definitivamente tras 3 intentos\n";
                    $this->notificationsModel->markJobAsFailed($jobId);
                }
            }
        }
    }
}

// Ejecutar el worker
$worker = new NotificationsWorker();
$worker->run();
