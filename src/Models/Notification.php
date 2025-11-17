<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\ValidationException;

class Notification
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function createNotification($recipientUserID, $eventCode, $payload, $idempotencyKey = null) {
    // Obtener EventTypeID y prioridad
    $sql = "SELECT ID, DefaultPriority FROM NotificationsEventType WHERE Code = ?";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$eventCode]);
    $event = $stmt->fetch();

    if (!$event) {
      throw new \Exception("EventCode inválido: $eventCode");
    }

    // Insertar en Notifications
    $sql = "INSERT INTO Notifications (EventTypeID, RecipientUserID, Payload, IdempotencyKey)
            VALUES (?, ?, ?, ?)";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([
      $event['ID'],
      $recipientUserID,
      json_encode($payload),
      $idempotencyKey
    ]);
    $notificationID = $this->db->lastInsertId();

    // Obtener preferencias del usuario
    $sql = "SELECT * FROM UsersNotifications WHERE UserID = ?";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$recipientUserID]);
    $preferences = $stmt->fetch();

    if (!$preferences) {
      // Si no tiene preferencias, asumimos todo habilitado
      $preferences = [
        'PushApp' => 1,
        'Email' => 1,
        'WhatsApp' => 1,
        'SMS' => 1,
        'WebPush' => 1,
        'InApp' => 1
      ];
    }

    // Canales configurados para el evento
    $sql = "SELECT Channel FROM NotificationsEventChannel
            WHERE EventTypeID = ? AND Enabled = 1 ORDER BY SendOrder ASC";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$event['ID']]);
    $channels = $stmt->fetchAll();

    foreach ($channels as $ch) {
      $channel = $ch['Channel'];

      // Verificar preferencias del usuario
      $canSend = false;
      switch ($channel) {
        case 'IN_APP':
          $canSend = (bool)$preferences['InApp'];
          break;
        case 'PUSH':
          $canSend = (bool)$preferences['PushApp'];
          break;
        case 'EMAIL':
          $canSend = (bool)$preferences['Email'];
          break;
        case 'SMS':
          $canSend = (bool)$preferences['SMS'];
          break;
        case 'WEB_PUSH':
          $canSend = (bool)$preferences['WebPush'];
          break;
        case 'WHATSAPP':
          $canSend = (bool)$preferences['WhatsApp'];
          break;
      }

      if (!$canSend) {
        // No crear delivery si el usuario lo deshabilitó
        continue;
      }

      // Renderizar plantilla (si existe)
      $template = $this->getTemplate($event['ID'], $channel);
      $subject = null;
      $body = null;

      if ($template) {
        $subject = $this->renderTemplate($template['Subject'], $payload);
        $body = $this->renderTemplate($template['Body'], $payload);
      }

      // Insertar delivery
      $sql = "INSERT INTO NotificationsDelivery
              (NotificationID, Channel, TemplateID, RenderedSubject, RenderedBody, Status)
              VALUES (?, ?, ?, ?, ?, 'Queued')";
      $stmt = $this->db->prepare($sql);
      $stmt->execute([
        $notificationID,
        $channel,
        $template['ID'] ?? null,
        $subject,
        $body
      ]);

      // Si es IN_APP → además insertamos en InAppNotification
      if ($channel === 'IN_APP') {
        $sql = "INSERT INTO InAppNotification (UserID, Title, Body, Priority, IsRead)
                VALUES (?, ?, ?, ?, 0)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
          $recipientUserID,
          $payload['title'] ?? ($subject ?? 'Notificación'),
          $payload['body'] ?? ($body ?? ''),
          $event['DefaultPriority'] ?? 0
        ]);
      } else {
        // Canales asíncronos → crear job con JSON que entiende el sender
        $messageForJob = [
          'subject' => $subject ?: ($payload['subject'] ?? 'Notificación'),
          'toName'  => $payload['toName'] ?? ($payload['USERNAME'] ?? null),
          // si hay $body renderizado úsalo; si no, mandá el payload (sendEmail lo aplanará)
          'body'    => $body ?? ($payload['body'] ?? $payload),
        ];

        $sql = "INSERT INTO NotificationJobs (Channel, Recipient, Message, Attempt, Status)
                VALUES (?, ?, ?, 1, 'PENDING')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
          $channel,
          $this->getRecipientAddress($recipientUserID, $channel),
          json_encode($messageForJob, JSON_UNESCAPED_UNICODE)
        ]);
      }
    }

    return $notificationID;
  }

    /**
   * Obtiene la dirección de envío de un usuario según el canal
   *
   * @param int $userID ID del usuario destinatario
   * @param string $channel Canal de notificación: "EMAIL", "PUSH", "WHATSAPP", etc.
   * @return string Dirección o token de envío
   * @throws Exception si no se encuentra la dirección
   */
  public function getRecipientAddress($userID, $channel) {
    switch (strtoupper($channel)) {
      case 'EMAIL':
        $sql = "SELECT Email FROM Users WHERE UserID = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userID]);
        $email = $stmt->fetchColumn();
        if (!$email) {
          throw new Exception("No se encontró email para el usuario {$userID}");
        }
        return $email;

      // case 'PUSH':
      //   $sql = "SELECT PushToken FROM WebPushSubscription WHERE UserID = ? ORDER BY CreatedAt DESC LIMIT 1";
      //   $stmt = $this->db->prepare($sql);
      //   $stmt->execute([$userID]);
      //   $token = $stmt->fetchColumn();
      //   if (!$token) {
      //     throw new Exception("No se encontró token push para el usuario {$userID}");
      //   }
      //   return $token;

      case 'WHATSAPP':
        $sql = "SELECT Phone FROM Users WHERE UserID = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userID]);
        $phone = $stmt->fetchColumn();
        if (!$phone) {
          throw new Exception("No se encontró número de WhatsApp para el usuario {$userID}");
        }
        return $phone;

      default:
      throw new Exception("Canal de notificación desconocido: {$channel}");
    }
  }

  // Obtener In-App no leídas
  public function getUnreadInAppByUser($userID) {
    $sql = "SELECT ID, Title, Body, DeepLink, Priority, CreatedAt
            FROM InAppNotification
            WHERE UserID = ? AND IsRead = 0
            ORDER BY CreatedAt DESC";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$userID]);
    return $stmt->fetchAll();
  }

  // Marcar como leída
  public function markInAppAsRead($userID, $notifID) {
    $sql = "UPDATE InAppNotification
            SET IsRead = 1, ReadAt = NOW()
            WHERE ID = ? AND UserID = ? AND IsRead = 0";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$notifID, $userID]);
    return $stmt->rowCount() > 0;
  }

  private function getTemplate($eventTypeID, $channel, $locale = 'es') {
    $sql = "SELECT * FROM NotificationsTemplate
            WHERE EventTypeID = ? AND Channel = ? AND Locale = ? AND Status = 'Active'
            ORDER BY Version DESC LIMIT 1";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$eventTypeID, $channel, $locale]);
    return $stmt->fetch();
  }

  private function renderTemplate($template, $payload) {
    if (!$template) return null;

    $rendered = $template;

    // Reemplazos de payload: soporta {KEY} y {{KEY}}
    foreach ($payload as $key => $value) {
      $val = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
      $rendered = str_replace(['{{' . $key . '}}', '{' . $key . '}'], $val, $rendered);
    }

    // Extras útiles (YEAR, etc.) si aparecen en la plantilla
    $rendered = str_replace(['{{YEAR}}', '{YEAR}'], date('Y'), $rendered);

    return $rendered;
  }

  // Obtener el próximo job pendiente
  public function getNextJob() {
    $stmt = $this->db->prepare("SELECT * FROM NotificationJobs WHERE Status = 'PENDING' ORDER BY CreatedAt ASC LIMIT 1");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  // Marcar como éxito
  public function markJobAsSuccess($jobId) {
    $stmt = $this->db->prepare("UPDATE NotificationJobs SET Status='SUCCESS', UpdatedAt=NOW() WHERE JobID=?");
    $stmt->execute([$jobId]);
  }

  // Marcar como fallo definitivo
  public function markJobAsFailed($jobId) {
    $stmt = $this->db->prepare("UPDATE NotificationJobs SET Status='FAILED', UpdatedAt=NOW() WHERE JobID=?");
    $stmt->execute([$jobId]);
  }

  // Reencolar job con delay y nuevo intento
  public function requeueJob($jobId, $attempt, $delay) {
    $stmt = $this->db->prepare("UPDATE NotificationJobs
                              SET Attempt=?, NextRunAt=DATE_ADD(NOW(), INTERVAL ? SECOND), UpdatedAt=NOW()
                              WHERE JobID=?");
    $stmt->execute([$attempt, $delay, $jobId]);
  }
}