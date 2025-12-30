<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Enums\DeliveriesMode;

class Notification  {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Crea una nueva notificación y sus entregas asociadas en los canales configurados
   *
   * Inserta la notificación en la tabla Notifications y crea las entregas (deliveries)
   * correspondientes según los canales habilitados para el tipo de evento. Los canales
   * IN_APP siempre se envían, mientras que EMAIL, WHATSAPP y SMS dependen de las
   * preferencias del usuario. Si la notificación es crítica, se envía inmediatamente
   * mediante un worker en background.
   *
   * @param int $recipientUserID ID del usuario destinatario de la notificación
   * @param string $eventCode Código del evento que dispara la notificación
   * @param array $payload Datos variables para renderizar en las plantillas
   * @param string $idempotencyKey Clave única para evitar notificaciones duplicadas
   * @return array Lista de entregas (deliveries) creadas
   * @throws NotFoundException Si el usuario o el tipo de evento no existen
   * @throws DatabaseException Si falla alguna operación de base de datos
   */
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

        # IN_APP siempre se envía
        if($channel !== 'IN_APP'){
          $canSend = match($channel) {
            'EMAIL' => $userSettings['Email'],
            'WHATSAPP' => $userSettings['WhatsApp'],
            'SMS' => $userSettings['Sms'],
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
          (NotificationID, Channel, TemplateID, RenderedSubject, RenderedBody, Status, LastAttemptAt)
          VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
          $notificationID,
          $channel,
          $templateID,
          $subject,
          $body,
          /* Si el envio es critico le pongo estado processing
            para que no lo tome el cron justo cuando hago el envio */
          $isCritical && $channel !== 'IN_APP' ? 'Processing' : 'Queued',
          $isCritical && $channel !== 'IN_APP' ? date('YmdHis') : null
        ]);
      }

      $deliveries = $this->getDeliveriesByNotificationId($notificationID, DeliveriesMode::ALL) ?:
        throw new DatabaseException("Failed to retrieve the created deliveries");
      $this->db->commit(); # Confirmo transacción

      # Si es envio critico lo envio en el momento
      if($isCritical){
        exec("php ".escapeshellarg(ROOT.'/src/Workers/NotificationWorker.php')." {$notificationID} > /dev/null 2>&1 &");
      }

      return $deliveries;
    } catch (PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene la próxima entrega pendiente de envío
   *
   * Recupera la entrega con mayor prioridad que esté en estado 'Queued' o 'Requeued'
   * y que haya cumplido su tiempo de espera (NextAttemptAt). Se ordenan por criticidad,
   * fecha de creación y orden de envío. Excluye entregas IN_APP ya que se manejan
   * de forma diferente. Utilizado por el worker de notificaciones vía CRON.
   *
   * @param int|null $recipientID ID del usuario destinatario (opcional, para filtrar por usuario)
   * @return array|false Datos de la entrega o false si no hay entregas pendientes
   */
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
    nec.FallbackAfterSeconds, nec.MaxAttempts, nd.Attempts, nd.NextAttemptAt, nd.LastAttemptAt
    FROM Notifications as n
    INNER JOIN NotificationsDelivery as nd
      ON nd.NotificationID = n.ID
    INNER JOIN NotificationsEventChannel as nec
      ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
    INNER JOIN NotificationsEventType as net
      ON net.ID = nec.EventTypeID
    WHERE (nd.Status IN ('Queued', 'Requeued')
    OR (nd.Status = 'Processing' AND NOW() > DATE_ADD(nd.LastAttemptAt, INTERVAL 120 SECOND)))
    AND nec.Enabled AND nec.Channel <> 'IN_APP'
    /* Este metodo no trae deliveries pospuestos por requeue hasta que se cumpla el plazo */
    AND (nd.NextAttemptAt IS NULL OR nd.NextAttemptAt < NOW()) $w
    ORDER BY nec.IsCritical DESC, n.CreatedAt ASC, nec.SendOrder ASC LIMIT 1");

    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Obtiene una entrega específica por su ID
   *
   * @param int $deliveryID ID de la entrega a consultar
   * @param int|null $recipientID ID del usuario destinatario (opcional, para validar permisos)
   * @return array Datos completos de la entrega o array vacío si no existe
   */
  public function getDeliveryById($deliveryID, $recipientID = null) {
    $w = ""; # Condiciones extra
    $params = [$deliveryID];
    if(!is_null($recipientID)){
      $w .= " AND n.RecipientUserID = ? ";
      $params[] = $recipientID;
    }

    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.ID as DeliveryID,
      n.RecipientUserID, nd.NotificationID, net.Code, n.IdempotencyKey, n.CreatedAt,
      nd.Channel, nd.RenderedSubject, nd.RenderedBody, nd.Status, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.MaxAttempts, nd.Attempts, nd.NextAttemptAt, nd.LastAttemptAt
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nec.Enabled AND nec.Channel <> 'IN_APP'
      AND nd.ID = ? $w
		  ORDER BY nec.IsCritical DESC, n.CreatedAt ASC, nec.SendOrder ASC");

    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?? [];
  }

  /**
   * Obtiene todas las entregas asociadas a una notificación específica
   *
   * @param int $notificationID ID de la notificación
   * @param DeliveriesMode $deliveriesMode Modo de filtrado (ALL, PENDING, etc.)
   * @param int|null $recipientID ID del usuario destinatario (opcional, para validar permisos)
   * @return array Lista de entregas asociadas a la notificación
   */
  public function getDeliveriesByNotificationId($notificationID, DeliveriesMode $deliveriesMode, $recipientID = null) {
    $w = ""; # Condiciones extra
    $params = [$notificationID];
    if(!is_null($recipientID)){
      $w .= " AND n.RecipientUserID = ? ";
      $params[] = $recipientID;
    }
    if($deliveriesMode::PENDING){
      $w .= " AND nd.Status IN ('Queued', 'Requeued', 'Processing') ";
      $w .= " AND (nd.NextAttemptAt IS NULL OR nd.NextAttemptAt < NOW()) ";
    }

    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.ID as DeliveryID,
      n.RecipientUserID, nd.NotificationID, net.Code, n.IdempotencyKey, n.CreatedAt,
      nd.Channel, nd.RenderedSubject, nd.RenderedBody, nd.Status, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.MaxAttempts, nd.Attempts, nd.NextAttemptAt, nd.LastAttemptAt
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nec.Enabled AND nec.Channel <> 'IN_APP'
      AND nd.NotificationID = ? $w
		  ORDER BY nec.IsCritical DESC, n.CreatedAt ASC, nec.SendOrder ASC");

    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
  }

  /**
   * Obtiene entregas filtradas por canal de comunicación con paginación
   *
   * @param object $paginator Objeto con parámetros de paginación
   * @param string $channel Canal de comunicación (EMAIL, WHATSAPP, SMS)
   * @param int|null $recipientID ID del usuario destinatario (opcional, para filtrar por usuario)
   * @return array Array con 'data' (entregas) y 'rows' (total y cantidad obtenida)
   */
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
      nec.FallbackAfterSeconds, nec.MaxAttempts, nd.Attempts, nd.NextAttemptAt, nd.LastAttemptAt
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nec.Enabled AND nec.Channel <> 'IN_APP'
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

  /**
   * Obtiene todas las entregas de un usuario específico con paginación
   *
   * @param object $paginator Objeto con parámetros de paginación
   * @param int $recipientID ID del usuario destinatario
   * @return array Array con 'data' (entregas) y 'rows' (total y cantidad obtenida)
   */
  public function getDeliveriesByRecipient($paginator, $recipientID) {
    $stmt = $this->db->prepare("SELECT SQL_CALC_FOUND_ROWS nd.ID as DeliveryID,
      n.RecipientUserID, nd.NotificationID, net.Code, n.IdempotencyKey, n.CreatedAt,
      nd.Channel, nd.RenderedSubject, nd.RenderedBody, nd.Status, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.MaxAttempts, nd.Attempts, nd.NextAttemptAt, nd.LastAttemptAt
      FROM Notifications as n
      INNER JOIN NotificationsDelivery as nd
        ON nd.NotificationID = n.ID
      INNER JOIN NotificationsEventChannel as nec
        ON nec.Channel = nd.Channel AND nec.EventTypeID = n.EventTypeID
      INNER JOIN NotificationsEventType as net
        ON net.ID = nec.EventTypeID
      WHERE nec.Enabled AND nec.Channel <> 'IN_APP'
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

  /**
   * Obtiene notificaciones in-app de un usuario con filtros opcionales
   *
   * Permite filtrar por rango de IDs, límite de resultados y estado de lectura.
   * Las notificaciones se devuelven ordenadas por fecha de creación descendente.
   *
   * @param int $recipientID ID del usuario destinatario
   * @param int|null $from ID mínimo de notificación (opcional)
   * @param int|null $to ID máximo de notificación (opcional)
   * @param int|null $limit Cantidad máxima de resultados (opcional, default 1000)
   * @param string $list Filtro por estado: 'all', 'unread', 'read'
   * @return array Array con 'data' (notificaciones) y 'rows' (total y cantidad obtenida)
   */
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
      nd.Status, n.CreatedAt
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

  /**
   * Marca notificaciones in-app como leídas
   *
   * Actualiza el estado de las entregas IN_APP a 'Sent' dentro del rango de IDs especificado
   *
   * @param int $recipientID ID del usuario destinatario
   * @param int $from ID mínimo de notificación a marcar
   * @param int $to ID máximo de notificación a marcar
   * @return int Cantidad de notificaciones actualizadas
   */
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

  /**
   * Obtiene la plantilla de notificación para un evento, canal y locale específicos
   *
   * @param int $eventTypeID ID del tipo de evento
   * @param string $channel Canal de comunicación (EMAIL, WHATSAPP, SMS, IN_APP)
   * @param string $locale Código de idioma (default: 'es')
   * @return array|false Datos de la plantilla o false si no existe
   */
  private function getTemplate($eventTypeID, $channel, $locale = 'es') {
    $stmt = $this->db->prepare("SELECT * FROM NotificationsTemplate
      WHERE EventTypeID = ? AND Channel = ? AND Locale = ?");
    $stmt->execute([$eventTypeID, $channel, $locale]);
    return $stmt->fetch();
  }

  /**
   * Renderiza una plantilla reemplazando variables con valores del payload
   *
   * Soporta sintaxis {{KEY}} y {KEY} para variables. También incluye variables
   * predefinidas como {{YEAR}}. Los arrays se convierten en strings separados por comas.
   *
   * @param string $template Plantilla con variables a reemplazar
   * @param array $payload Datos para reemplazar en la plantilla
   * @return string|null Plantilla renderizada o null si la plantilla es vacía
   */
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

  /**
   * Marca una entrega como enviada exitosamente
   *
   * Actualiza el estado a 'Sent', incrementa el contador de intentos y registra
   * la fecha/hora del último intento
   *
   * @param int $deliveryID ID de la entrega
   * @return void
   */
  public function markDeliveryAsSent($deliveryID) {
    $stmt = $this->db->prepare("UPDATE NotificationsDelivery
      SET Status = 'Sent', Attempts = Attempts + 1, LastAttemptAt = NOW()
      WHERE ID = ?");
    $stmt->execute([$deliveryID]);
  }

  /**
   * Marca una entrega como fallida definitivamente
   *
   * Actualiza el estado a 'Failed', incrementa el contador de intentos y registra
   * la fecha/hora del último intento. Se usa cuando se agotaron todos los reintentos.
   *
   * @param int $deliveryID ID de la entrega
   * @return void
   */
  public function markDeliveryAsFailed($deliveryID) {
    $stmt = $this->db->prepare("UPDATE NotificationsDelivery
      SET Status = 'Failed', Attempts = Attempts + 1, LastAttemptAt = NOW()
      WHERE ID = ?");
    $stmt->execute([$deliveryID]);
  }

  /**
   * Reencola una entrega con un delay para reintento
   *
   * Actualiza el estado a 'Requeued', incrementa el contador de intentos, registra
   * la fecha/hora del último intento y establece NextAttemptAt con el delay especificado
   *
   * @param int $deliveryID ID de la entrega
   * @param int $delay Tiempo de espera en segundos antes del próximo intento
   * @return void
   */
  public function requeueDelivery($deliveryID, $delay) {
    $stmt = $this->db->prepare("UPDATE NotificationsDelivery
      SET Status = 'Requeued', Attempts = Attempts + 1, LastAttemptAt = NOW(),
      NextAttemptAt = DATE_ADD(NOW(), INTERVAL ? SECOND)
      WHERE ID = ?");
    $stmt->execute([$delay, $deliveryID]);
  }

  /**
   * Obtiene la dirección de envío de un usuario según el canal
   *
   * @param int $recipientID ID del usuario destinatario
   * @param string $channel Canal de notificación: "EMAIL", "WHATSAPP", etc.
   * @return string Dirección o token de envío (email, teléfono, etc.)
   * @throws NotFoundException Si no se encuentra la dirección para el canal especificado
   */
  public function getRecipientAddress($recipientID, $channel) {
    switch (strtoupper($channel)) {
      case 'EMAIL':
        $sql = "SELECT Email FROM Users WHERE UserID = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$recipientID]);
        $email = $stmt->fetchColumn();
        if (!$email) {
          throw new NotFoundException("No email found for user {$recipientID}");
        }
        return $email;

      case 'WHATSAPP':
        $sql = "SELECT Phone FROM Users WHERE UserID = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$recipientID]);
        $phone = $stmt->fetchColumn();
        if (!$phone) {
          throw new NotFoundException("No phone found for user {$recipientID}");
        }
        return $phone;

      default:
      throw new NotFoundException("Unknown channel {$channel}");
    }
  }

  /**
   * Obtiene los canales configurados y plantillas activas para un tipo de evento
   *
   * Recupera todos los canales habilitados para el evento especificado junto con
   * sus plantillas activas en el locale indicado. Solo devuelve la versión más
   * reciente de cada plantilla.
   *
   * @param string $eventCode Código del tipo de evento
   * @param string $locale Código de idioma (ej: 'es', 'en')
   * @return array Lista de canales con sus plantillas asociadas
   */
  private function _getEventType($eventCode, $locale){
    $stmt = $this->db->prepare("SELECT nec.EventTypeID, net.Code,
      net.Description, nec.Channel, nec.Enabled, nec.IsCritical,
      nec.FallbackAfterSeconds, nec.MaxAttempts,
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

  /**
   * Obtiene las preferencias de notificación de un usuario
   *
   * Recupera los canales de comunicación habilitados por el usuario y su idioma
   * preferido. Si no tiene preferencias configuradas, asume todos los canales habilitados.
   *
   * @param int $recipientUserID ID del usuario destinatario
   * @return array|false Array con preferencias (Email, WhatsApp, SMS, Locale) o false si el usuario no existe
   */
  private function _getUserNotificationSettings($recipientUserID){
    # Obtener preferencias de comunicacion del usuario
    $stmt = $this->db->prepare("SELECT un.Email, un.WhatsApp, un.Sms,
      un.PushApp, un.PushWeb, us.Locale
    FROM Users as u
    INNER JOIN UsersSettings as us
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
      'Email' => (bool)$preferences['Email'] ?? 1,
      'WhatsApp' => (bool)$preferences['WhatsApp'] ?? 1,
      'Sms' => (bool)$preferences['Sms'] ?? 1,
      'PushApp' => (bool)$preferences['PushApp'] ?? 1,
      'PushWeb' => (bool)$preferences['PushWeb'] ?? 1,
      'Locale' => $preferences['Locale']
    ];
  }
}