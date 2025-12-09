<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;

class Notification  {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  public function createNotification($recipientUserID, $eventCode, $payload, $idempotencyKey) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Traigo los user settings
      $userSettings = $this->_getUserNotificationSettings($recipientUserID) ?:
        throw new NotFoundException("Recipient ($recipientUserID) not found", 404);

      # Traigo los canales de difusion
      $eventChannels = $this->_getEventType($eventCode, $userSettings['Locale']) ?:
        throw new NotFoundException("Event $eventCode not found", 404);
      $eventTypeID = $eventChannels[0]['EventTypeID'];

      # Filtro los que tienen templates validos
      $eventChannels = array_filter($eventChannels, function($e){
        return $e['TemplateSubject'] && $e['TemplateBody'];
      });

      # Insertar en Notifications
      $stmt = $this->db->prepare("INSERT INTO Notifications
        (EventTypeID, RecipientUserID, Payload, IdempotencyKey)
        VALUES (?, ?, ?, ?)");
      $stmt->execute([
        $eventTypeID,
        $recipientUserID,
        json_encode($payload),
        $idempotencyKey
      ]);
      $notificationID = $this->db->lastInsertId();

      # Itero cada canal de difusion y si esta activado envio lo agrego al queue
      foreach($eventChannels as $e){
        $channel = $e['Channel'];
        $templateID = $e['TemplateID'];
        $isCritical = $e['IsCritical'];

        # IN_APP siempre se envía, los otros dependen de preferencias (si no esta como critical)
        if($channel !== 'IN_APP' && !$isCritical){
          $canSend = match($channel) {
            'EMAIL' => (bool)$userSettings['Email'],
            'WHATSAPP' => (bool)$userSettings['WhatsApp'],
            'SMS' => (bool)$userSettings['SMS'],
            default => false
          };

          if(!$canSend){
            continue;
          }
        }

        $subject = $this->renderTemplate($e['TemplateSubject'], $payload);
        $body = $this->renderTemplate($e['TemplateBody'], $payload);

        # Insertar delivery
        $stmt = $this->db->prepare("INSERT INTO NotificationsDelivery
          (NotificationID, Channel, TemplateID, RenderedSubject, RenderedBody, Status)
          VALUES (?, ?, ?, ?, ?, 'Queued')");
        $stmt->execute([
          $notificationID,
          $channel,
          $templateID,
          $subject,
          $body
        ]);
      }

      $deliveries = $this->getDeliveriesByNotificationId($notificationID) ?:
        throw new DatabaseException("Failed to retrieve the created deliveries");

      $this->db->commit(); # Confirmo transacción
      return $deliveries;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  # Obtener la próxima entrega
  public function getNextDelivery($recipientID = null) {
    $w = ""; # Condiciones extra
    $params = [];
    if(!is_null($recipientID)){
      $w .= " AND n.RecipientUserID = ? ";
      $params[] = $recipientID;
    }

    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.ID as DeliveryID,
      n.RecipientUserID, nd.NotificationID, net.Code, n.IdempotencyKey, n.CreatedAt,
      nd.Channel, nd.RenderedSubject, nd.RenderedBody, nd.Status, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.DailyCap
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nd.Status IN ('Queued', 'Requeued') AND nec.Enabled AND nec.Channel <> 'IN_APP'
      AND (nd.NextAttemptAt IS NULL OR  nd.NextAttemptAt < NOW()) $w
		  ORDER BY nec.IsCritical DESC, n.CreatedAt ASC, nec.SendOrder ASC LIMIT 1");

    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public function getDeliveriesByNotificationId($notificationID, $recipientID = null) {
    $w = ""; # Condiciones extra
    $params = [$notificationID];
    if(!is_null($recipientID)){
      $w .= " AND n.RecipientUserID = ? ";
      $params[] = $recipientID;
    }

    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.ID as DeliveryID,
      n.RecipientUserID, nd.NotificationID, net.Code, n.IdempotencyKey, n.CreatedAt,
      nd.Channel, nd.RenderedSubject, nd.RenderedBody, nd.Status, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.DailyCap
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nec.Enabled
      AND (nd.NextAttemptAt IS NULL OR nd.NextAttemptAt < NOW())
      AND nd.NotificationID = ? $w
		  ORDER BY nec.IsCritical DESC, n.CreatedAt ASC, nec.SendOrder ASC");

    $stmt->execute($params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
      "data" => $deliveries,
      "rows" => [
        "total" => $total,
        "fetched" => count($deliveries)
      ]
    ];
  }

  public function getDeliveriesByChannel($paginator, $channel, $recipientID = null) {
    $w = ""; # Condiciones extra
    $params = [$channel];
    if(!is_null($recipientID)){
      $w .= " AND n.RecipientUserID = ? ";
      $params[] = $recipientID;
    }

    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.ID as DeliveryID,
      n.RecipientUserID, nd.NotificationID, net.Code, n.IdempotencyKey, n.CreatedAt,
      nd.Channel, nd.RenderedSubject, nd.RenderedBody, nd.Status, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.DailyCap
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nec.Enabled
      AND (nd.NextAttemptAt IS NULL OR nd.NextAttemptAt < NOW())
      AND nd.Channel = ? $w
		  ORDER BY nec.IsCritical DESC, n.CreatedAt ASC, nec.SendOrder ASC");

    $stmt->execute($params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
      "data" => $deliveries,
      "rows" => [
        "total" => $total,
        "fetched" => count($deliveries)
      ]
    ];
  }

  public function getDeliveriesByRecipient($paginator, $recipientID) {
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.ID as DeliveryID,
      n.RecipientUserID, nd.NotificationID, net.Code, n.IdempotencyKey, n.CreatedAt,
      nd.Channel, nd.RenderedSubject, nd.RenderedBody, nd.Status, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.DailyCap
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nec.Enabled
      AND (nd.NextAttemptAt IS NULL OR nd.NextAttemptAt < NOW())
      AND n.RecipientUserID = ?
		  ORDER BY nec.IsCritical DESC, n.CreatedAt ASC, nec.SendOrder ASC");

    $stmt->execute([$recipientID]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
      "data" => $deliveries,
      "rows" => [
        "total" => $total,
        "fetched" => count($deliveries)
      ]
    ];
  }

  # Obtener In-App no leídas
  public function getInAppNotifications($recipientID, $from, $to, $limit, $list) {
    $w = ""; # Condiciones extra
    $params = [$recipientID];
    if(!is_null($from)){
      $w .= " AND nd.NotificationID >= ? ";
      $params[] = $from;
    }
    if(!is_null($to)){
      $w .= " AND nd.NotificationID <= ? ";
      $params[] = $to;
    }
    if($list === 'unread'){
      $w .= " AND nd.Status = 'Queued' ";
    } else if($list === 'read'){
      $w .= " AND nd.Status = 'Sent' ";
    }
    $params[] = !is_null($limit) ? $limit : 1000;

    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.NotificationID,
      nd.RenderedSubject as Subject, nd.RenderedBody as Body,
      nd.Status
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON n.ID = nd.NotificationID
      WHERE n.RecipientUserID = ? AND nd.Channel = 'IN_APP' $w
      ORDER BY n.CreatedAt DESC
      LIMIT ?");

    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    $notifications = array_map(function($e){
      $e['Body'] = json_decode($e['Body']);
      $e['Read'] = $e['Status'] === 'Sent';
      unset($e['Status']);
      return $e;
    }, $notifications);

    return [
      "data" => $notifications,
      "rows" => [
        "total" => $total,
        "fetched" => count($notifications)
      ]
    ];
  }

  # Marcar como leída
  public function markInAppNotifications($recipientID, $from, $to) {
    $stmt = $this->db->prepare("UPDATE NotificationsDelivery as nd
      INNER JOIN Notifications as n
      ON nd.NotificationID = n.ID
      SET nd.Status = 'Sent'
      WHERE n.RecipientUserID = ? AND nd.Channel = 'IN_APP'
      AND nd.NotificationID >= ? AND nd.NotificationID <= ?");
    $stmt->execute([$recipientID, $from, $to]);
    return $stmt->rowCount();
  }

  private function getTemplate($eventTypeID, $channel, $locale = 'es') {
    $stmt = $this->db->prepare("SELECT * FROM NotificationsTemplate
      WHERE EventTypeID = ? AND Channel = ? AND Locale = ?");
    $stmt->execute([$eventTypeID, $channel, $locale]);
    return $stmt->fetch();
  }

  private function renderTemplate($template, $payload) {
    if (!$template) return null;

    $rendered = $template;

    # Reemplazos de payload: soporta {KEY} y {{KEY}}
    foreach ($payload as $key => $value) {
      $val = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
      $rendered = str_replace(['{{' . $key . '}}', '{' . $key . '}'], $val, $rendered);
    }

    # Extras útiles (YEAR, etc.) si aparecen en la plantilla
    $rendered = str_replace(['{{YEAR}}', '{YEAR}'], date('Y'), $rendered);

    return $rendered;
  }

  # Marcar como enviado
  public function markDeliveryAsSent($deliveryID) {
    $stmt = $this->db->prepare("UPDATE NotificationsDelivery
      SET Status = 'Sent', Attempts = Attempts + 1, LastAttemptAt = NOW()
      WHERE ID = ?");
    $stmt->execute([$deliveryID]);
  }

  # Marcar como fallo definitivo
  public function markJobAsFailed($deliveryID) {
    $stmt = $this->db->prepare("UPDATE NotificationsDelivery
      SET Status = 'Failed', Attempts = Attempts + 1, LastAttemptAt = NOW()
      WHERE ID = ?");
    $stmt->execute([$deliveryID]);
  }

  # Reencolar job con delay y nuevo intento
  public function requeueJob($deliveryID, $delay) {
    $stmt = $this->db->prepare("UPDATE NotificationJobs
      SET Status = 'Requeued', Attempts = Attempts + 1, LastAttemptAt = NOW()
      NextAttemptAt = DATE_ADD(NOW(), INTERVAL ? SECOND)
      WHERE ID = ?");
    $stmt->execute([$delay, $deliveryID]);
  }

  /**
   * Obtiene la dirección de envío de un usuario según el canal
   *
   * @param int $recipientID ID del usuario destinatario
   * @param string $channel Canal de notificación: "EMAIL", "PUSH", "WHATSAPP", etc.
   * @return string Dirección o token de envío
   * @throws Exception si no se encuentra la dirección
   */
  public function getRecipientAddress($recipientID, $channel) {
    switch (strtoupper($channel)) {
      case 'EMAIL':
        $sql = "SELECT Email FROM Users WHERE UserID = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$recipientID]);
        $email = $stmt->fetchColumn();
        if (!$email) {
          throw new Exception("No se encontró email para el usuario {$recipientID}");
        }
        return $email;

      case 'WHATSAPP':
        $sql = "SELECT Phone FROM Users WHERE UserID = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$recipientID]);
        $phone = $stmt->fetchColumn();
        if (!$phone) {
          throw new Exception("No se encontró número de WhatsApp para el usuario {$recipientID}");
        }
        return $phone;

      default:
      throw new Exception("Canal de notificación desconocido: {$channel}");
    }
  }

  private function _getEventType($eventCode, $locale){
    $stmt = $this->db->prepare("SELECT nec.EventTypeID, net.Code,
      net.Description, nec.Channel, nec.Enabled, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.DailyCap,
      nt.ID as TemplateID, nt.Locale,
      nt.Subject as TemplateSubject, nt.Body as TemplateBody
      FROM NotificationsEventChannel AS nec
      INNER JOIN NotificationsEventType AS net
        ON net.ID = nec.EventTypeID
      LEFT JOIN NotificationsTemplate AS nt
        ON nt.EventTypeID = nec.EventTypeID AND nt.Channel = nec.Channel
      AND nt.Locale = ? AND nt.Status = 'Active'
      WHERE net.Code = ? AND nec.Enabled
        AND (nt.ID IS NULL OR nt.Version = (
          SELECT MAX(nt2.Version)
          FROM NotificationsTemplate nt2
          WHERE nt2.EventTypeID = nec.EventTypeID
          AND nt2.Channel = nec.Channel
          AND nt2.Locale = ?
          AND nt2.Status = 'Active'
        ))
      GROUP BY nec.Channel");
    $stmt->execute([$locale, $eventCode, $locale]);
    return $stmt->fetchAll();
  }

  private function _getUserNotificationSettings($recipientUserID){
    # Obtener preferencias de comunicacion del usuario
    $stmt = $this->db->prepare("SELECT un.Email, un.WhatsApp, un.SMS, us.PreferredLanguage
    FROM Users as u
    INNER JOIN UserSettings as us
      ON u.UserID = us.UserID
    LEFT JOIN UsersNotifications as un
      ON u.UserID = un.UserID
    WHERE u.UserID = ? AND u.DeactivationDate IS NULL");
    $stmt->execute([$recipientUserID]);
    $preferences = $stmt->fetch();
    if (!$preferences) {
      return false;
    }

    # Si no tiene preferencias, asumimos todo habilitado
    return [
      'Email' => $preferences['Email'] ?? 1,
      'WhatsApp' => $preferences['WhatsApp'] ?? 1,
      'SMS' => $preferences['SMS'] ?? 1,
      'Locale' => $preferences['PreferredLanguage']
    ];
  }
}