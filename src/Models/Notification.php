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
    // 1. Obtener EventTypeID y prioridad
    $sql = "SELECT ID, DefaultPriority FROM NotificationsEventType WHERE Code = ?";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$eventCode]);
    $event = $stmt->fetch();

    if (!$event) {
      throw new Exception("EventCode inválido: $eventCode");
    }

    // 2. Insertar en Notifications
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

    // 3. Obtener preferencias del usuario
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

    // 4. Canales configurados para el evento
    $sql = "SELECT Channel FROM NotificationsEventChannel 
            WHERE EventTypeID = ? AND Enabled = 1 ORDER BY SendOrder ASC";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$event['ID']]);
    $channels = $stmt->fetchAll();

    foreach ($channels as $ch) {
      $channel = $ch['Channel'];

      // 5. Verificar preferencias del usuario
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

      // 6. Renderizar plantilla (si existe)
      $template = $this->getTemplate($event['ID'], $channel);
      $subject = null;
      $body = null;

      if ($template) {
        $subject = $this->renderTemplate($template['Subject'], $payload);
        $body = $this->renderTemplate($template['Body'], $payload);
      }

      // 7. Insertar delivery
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

      // 8. Si es IN_APP → además insertamos en InAppNotification
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
      }
    }

    return $notificationID;
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

  // -------------------------
  // Helpers internos
  // -------------------------

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
    foreach ($payload as $key => $value) {
      $rendered = str_replace('{{' . $key . '}}', $value, $rendered);
    }
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