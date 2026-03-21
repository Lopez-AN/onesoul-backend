<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Helpers\EmailHelper;
use App\Services\SubscriptionEnforcementService;

class Subscription {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
   * Obtiene todos los planes de suscripción con características activas
   * @return array: lista de planes normalizados con características
   */
  public function getSubscriptionPlans() {
    $stmt = $this->db->prepare("SELECT sp.PlanID, sp.StripeID, sp.Name,
      sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
      sf.FeatureCode, sf.Description AS FeatureDescription,
      si.Value, si.Type, si.Description AS ItemDescription
      FROM SubscriptionPlans AS sp
      LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
      LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
      WHERE sf.IsActive = 1
      ORDER BY sp.PlanID, sf.FeatureCode");

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    # Organizar los planes en un array estructurado
    $plans = [];
    foreach ($results as $row) {
      $planId = $row['PlanID'];

      # Castear el valor según el tipo
      $value = $row['Value'];
      if (isset($value) && isset($row['Type'])) {
        switch ($row['Type']) {
          case 'INTEGER':
            $value = is_numeric($value) ? (int)$value : 0;
            break;
          case 'FLOAT':
            $value = is_numeric($value) ? (float)$value : 0.0;
            break;
          case 'BOOLEAN':
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            break;
          case 'STRING':
          default:
            $value = (string)$value;
            break;
        }
      }

      # Si el plan no está en el array, inicializarlo
      if (!isset($plans[$planId])) {
        $plans[$planId] = [
          "PlanID"       => $row['PlanID'],
          "StripeID"     => $row['StripeID'],
          "Name"         => $row['Name'],
          "Description"  => $row['Description'],
          "Beneficts"    => $row['Beneficts'],
          "Price"        => (float)$row['Price'],
          "CurrencyCode" => $row['CurrencyCode'],
          "Duration"     => $row['Duration'],
          "Features"     => []
        ];
      }

      # Agregar las características solo si existen
      if (!empty($row['FeatureCode'])) {
        $plans[$planId]['Features'][] = [
          "FeatureCode"  => $row['FeatureCode'],
          "Description"  => $row['FeatureDescription'],
          "Value"  => $value,
          "ItemDescription"  => $row['ItemDescription']
        ];
      }
    }

    return array_values($plans);
  }

  /**
   * Obtiene un plan de suscripción por su ID
   * @param  int $planID: ID del plan de suscripción
   * @return array|false: datos del plan o false si no existe
   */
  public function getSubscriptionPlanByID($planID) {
    $stmt = $this->db->prepare("SELECT sp.PlanID, sp.StripeID, sp.Name,
      sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
      sf.FeatureCode, sf.Description AS FeatureDescription,
      si.Value, si.Type, si.Description AS ItemDescription,
      sf.IsActive
      FROM SubscriptionPlans AS sp
      LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
      LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
      WHERE sp.PlanID = ?
      ORDER BY sf.FeatureCode");

    $stmt->execute([$planID]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
      return false; # No existe el plan
    }

    # Inicializar el plan
    $subscription = [
      "PlanID"       => $rows[0]['PlanID'],
      "StripeID"     => $rows[0]['StripeID'],
      "Name"         => $rows[0]['Name'],
      "Description"  => $rows[0]['Description'],
      "Beneficts"    => $rows[0]['Beneficts'],
      "Price"        => (float)$rows[0]['Price'],
      "CurrencyCode" => $rows[0]['CurrencyCode'],
      "Duration"     => $rows[0]['Duration'],
      "Features"     => []
    ];

    # Agregar las features
    foreach ($rows as $row) {
      if (!empty($row['FeatureCode']) && !empty($row['IsActive']) && $row['IsActive']) {
        # Castear el valor según el tipo
        $value = $row['Value'];
        if (isset($value) && isset($row['Type'])) {
          switch ($row['Type']) {
            case 'INTEGER':
              $value = is_numeric($value) ? (int)$value : 0;
              break;
            case 'FLOAT':
              $value = is_numeric($value) ? (float)$value : 0.0;
              break;
            case 'BOOLEAN':
              $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
              break;
            case 'STRING':
            default:
              $value = (string)$value;
              break;
          }
        }

        $subscription['Features'][] = [
          "FeatureCode"  => $row['FeatureCode'],
          "Description"  => $row['FeatureDescription'],
          "Value"  => $value,
          "ItemDescription"  => $row['ItemDescription']
        ];
      }
    }

    return $subscription;
  }

  /**
   * Obtiene un plan de suscripción por su ID de Stripe
   * @param  string $stripePriceID: ID del precio en Stripe
   * @return array|false: datos del plan o false si no existe
   */
  public function getSubscriptionPlanByStripeID($stripePriceID) {
    $stmt = $this->db->prepare("SELECT sp.PlanID, sp.StripeID, sp.Name,
      sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
      sf.FeatureCode, sf.Description AS FeatureDescription,
      si.Value, si.Type, si.Description AS ItemDescription
      FROM SubscriptionPlans AS sp
      LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
      LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
      WHERE sp.StripeID = ? AND sf.IsActive = 1
      ORDER BY sf.FeatureCode");

    $stmt->execute([$stripePriceID]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
      return false; # No existe el plan
    }

    # Inicializar el plan
    $subscription = [
      "PlanID"       => $rows[0]['PlanID'],
      "StripeID"     => $rows[0]['StripeID'],
      "Name"         => $rows[0]['Name'],
      "Description"  => $rows[0]['Description'],
      "Beneficts"    => $rows[0]['Beneficts'],
      "Price"        => (float)$rows[0]['Price'],
      "CurrencyCode" => $rows[0]['CurrencyCode'],
      "Duration"     => $rows[0]['Duration'],
      "Features"     => []
    ];

    # Agregar las features
    foreach ($rows as $row) {
      if (!empty($row['FeatureCode'])) {

        # Castear el valor según el tipo
        $value = $row['Value'];
        if (isset($value) && isset($row['Type'])) {
          switch ($row['Type']) {
            case 'INTEGER':
              $value = is_numeric($value) ? (int)$value : 0;
              break;
            case 'FLOAT':
              $value = is_numeric($value) ? (float)$value : 0.0;
              break;
            case 'BOOLEAN':
              $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
              break;
            case 'STRING':
            default:
              $value = (string)$value;
              break;
          }
        }

        $subscription['Features'][] = [
          "FeatureCode"  => $row['FeatureCode'],
          "Description"  => $row['FeatureDescription'],
          "Value"  => $value,
          "ItemDescription"  => $row['ItemDescription']
        ];
      }
    }

    return $subscription;
  }

  /**
   * Obtiene la suscripción activa de un usuario
   * @param  int $userID: ID del usuario
   * @return array|false: datos de suscripción con upgrades/downgrades pendientes o false
   */
  public function getSubscriptionByUser($userID) {
    $stmt = $this->db->prepare("SELECT * FROM Subscriptions
      WHERE UserID = :userID
      AND Status IN ('ACTIVE','TRIALING')
      ORDER BY StartDate DESC
      LIMIT 1");

    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->execute();

    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$subscription) {
      return false;
    }

    # Upgrade pendiente (por alguna demora en pago por ejemplo)
    $stmt = $this->db->prepare("SELECT NewPlanID
      FROM SubscriptionChanges
      WHERE UserID = :userID
      AND Status = 'WAITING'
      ORDER BY CreatedAt DESC
      LIMIT 1");

    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->execute();
    $newPlanID = $stmt->fetchColumn();
    $subscription['PendingUpgrade'] = $newPlanID ?: null;

    # Downgrade pendiente (aun no llego la fecha)
    $stmt = $this->db->prepare("SELECT NewPlanID
      FROM SubscriptionChanges
      WHERE UserID = :userID
      AND Status = 'PENDING'
      ORDER BY CreatedAt DESC
      LIMIT 1");

    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->execute();
    $newPlanID = $stmt->fetchColumn();
    $subscription['PendingDowngrade'] = $newPlanID ?: null;

    return $subscription;
  }

  public function getUserSubscriptionFeature($guideID, $feature) {
    $userSubscription = $this->getSubscriptionByUser($guideID);
    if(!$userSubscription){
      return null; # Devuelvo null si no tiene subscripcion
    }
    $plan = $this->getSubscriptionPlanByID($userSubscription['PlanID']);
    $feature = $plan ? array_filter($plan['Features'] ?? null, function($e) use ($feature){
      return $e['FeatureCode'] === $feature;
    }) : [];
    if(empty($feature)){ # Esto es una ecepcion porque , deberia existir el feature siempre
      throw new Exception("Plan or feature not found");
    }
    return (object)array_pop($feature);
  }


  /**
   * Obtiene la suscripción activa por ID de plataforma de pagos
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @return array|false: datos de suscripción con detalles de plan o false
   */
  public function getUserSubscriptionByPlatformSubID($platformSubscriptionID) {
    $stmt = $this->db->prepare("SELECT * FROM Subscriptions
      WHERE PlatformSubscriptionID = ?
      AND Status IN ('ACTIVE','TRIALING')
      ORDER BY StartDate DESC
      LIMIT 1");

    $stmt->execute([$platformSubscriptionID]);

    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$subscription) {
      return false;
    }

    # Se obtiene los detalles del Plan del usuario
    $id = $subscription['PlanID'];
    $planDetails = $this->getSubscriptionPlanByID($id);
    $subscription['PlanDetails'] = $planDetails;

    return $subscription;
  }

  /**
   * Crea una suscripción confirmada reemplazando la anterior si existe
   * @param  int $userID: ID del usuario
   * @param  int $planID: ID del plan a contratar
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  string $platformCustomerID: ID de cliente en plataforma de pagos
   * @param  string $subDomain: subdominio personalizado (opcional)
   * @param  array $userData: datos del usuario con email, nombre y período de prueba
   * @return array: datos de suscripción creada
   * @throws DatabaseException
   */
  public function createConfirmedSubscription($userID, $planID, $platformSubscriptionID, $platformCustomerID, $subDomain = '', $userData = []) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Cancelar suscripción anterior si existe
      $stmt = $this->db->prepare("UPDATE Subscriptions
        SET EndDate = NOW(), Status = 'CANCELED'
        WHERE UserID = ? AND Status = 'ACTIVE'");
      $stmt->execute([$userID]);

      $today = date('Y-m-d');
      $trialStart = $userData['TrialStart'];
      $trialEnd = $userData['TrialEnd'];
      $trialSource = $userData['TrialSource'];
      $paymentPlatform = $userData['PaymentPlatform'];
      $nextBillingDate = $userData['NextBillingDate'];
      $latestInvoice = $userData['LatestInvoiceID'];
      $trialEndDate = $trialEnd ? substr($trialEnd, 0, 10) : null;

      # TRIALING si el trial no terminó aún
      $status = ($trialEndDate && $trialEndDate >= $today) ? 'TRIALING' : 'ACTIVE';

      # Insertar nueva suscripción
      $stmt = $this->db->prepare("INSERT INTO Subscriptions (PlanID, UserID, TrialStart, TrialEnd, TrialSource,
        StartDate, Status, PaymentPlatform, PlatformSubscriptionID, PlatformCustomerID, NextBillingDate, LatestInvoiceID)
        VALUES (:planID, :userID, :trialStart, :trialEnd, :trialSource, NOW(), :status,
        :paymentPlatform, :platformSubscriptionID, :platformCustomerID, :nextBillingDate, :latestInvoice)");

      $stmt->execute([
        ':planID' => $planID,
        ':userID' => $userID,
        ':trialStart' => $trialStart,
        ':trialEnd' => $trialEnd,
        ':trialSource' => $trialSource,
        ':status' => $status,
        ':paymentPlatform' => $paymentPlatform,
        ':platformSubscriptionID' => $platformSubscriptionID,
        ':platformCustomerID' => $platformCustomerID,
        ':nextBillingDate' => $nextBillingDate,
        ':latestInvoice' => $latestInvoice
      ]);

      # Marcar referido como exitoso si corresponde
      $stmt = $this->db->prepare("UPDATE Referrals
        SET ReferralStatus = 'Successful'
        WHERE ReferredUserID = ? AND ReferralStatus = 'Pending'");
      $stmt->execute([$userID]);

      $subscription = $this->getSubscriptionByUser($userID) ?:
        throw new DatabaseException("Failed to retrieve the created subscription");

      # Enviar email si hay datos del usuario
      if (!empty($userData['UserName']) && !empty($userData['Email'])) {
        $username = $userData['UserName'];
        $email = $userData['Email'];

        $planInfo = $this->getSubscriptionPlanByID($planID);
        if (!$planInfo) return;

        $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";
        $dashboardURL = $origin . "/profile";
      }

      $this->db->commit(); # Confirmo transacción
      return $subscription;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Programa un cambio de plan para ejecutarse en fecha futura
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  int $newPlanID: ID del nuevo plan
   * @param  int|null $effectiveDate: timestamp de ejecución (opcional)
   * @return string|int: ID del cambio programado
   * @throws DatabaseException
   */
  public function scheduleSubscriptionChange($platformSubscriptionID, $newPlanID, $effectiveDate = null) {
    $effectiveDate = $effectiveDate ? date("Y-m-d H:i:s", $effectiveDate) : null;

    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $sub = $this->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
      if (!$sub) {
        throw new DatabaseException("Subscription not found: $platformSubscriptionID");
      }

      $stmt = $this->db->prepare(
        "INSERT INTO SubscriptionChanges
        (PlatformSubscriptionID, UserID, OldPlanID, NewPlanID, EffectiveDate, Status, CreatedAt)
        VALUES (:platformSubscriptionID, :userID, :oldPlanID, :newPlanID, :effectiveDate, 'PENDING', NOW())"
      );

      $stmt->execute([
        ':platformSubscriptionID' => $platformSubscriptionID,
        ':userID' => $sub['UserID'],
        ':oldPlanID' => $sub['PlanID'],
        ':newPlanID' => $newPlanID,
        ':effectiveDate' => $effectiveDate
      ]);

      $id = $this->db->lastInsertId();

      $this->db->commit(); # Confirmo transacción
      return $id;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene el cambio pendiente de un plan programado
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  int|null|false $newPlanID: filtra por nuevo plan (null: acepta NULL, false: ignora)
   * @return array|false: datos del cambio pendiente o false
   */
  public function getPendingChange($platformSubscriptionID, $newPlanID = null) {
    $sql = "SELECT * FROM SubscriptionChanges WHERE PlatformSubscriptionID = :platformSubscriptionID AND Status = 'PENDING'";

    if ($newPlanID !== null) {
      $sql .= " AND NewPlanID = :newPlanID ";
    }

    $sql .= " ORDER BY CreatedAt DESC LIMIT 1";

    $stmt = $this->db->prepare($sql);
    $stmt->bindValue(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
    if ($newPlanID !== null) {
      $stmt->bindValue(':newPlanID', $newPlanID, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Programa un cambio de plan en estado "en espera de pago"
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  int $newPlanID: ID del nuevo plan
   * @param  int|null $effectiveDate: timestamp de ejecución (opcional)
   * @return string|int: ID del cambio programado
   * @throws DatabaseException
   */
  public function waitingSubscriptionChange($platformSubscriptionID, $newPlanID, $effectiveDate = null) {
    $effectiveDate = $effectiveDate ? date("Y-m-d H:i:s", $effectiveDate) : null;

    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $sub = $this->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
      if (!$sub) {
        throw new DatabaseException("Subscription not found: $platformSubscriptionID");
      }

      $stmt = $this->db->prepare(
        "INSERT INTO SubscriptionChanges
        (PlatformSubscriptionID, UserID, OldPlanID, NewPlanID, EffectiveDate, Status, CreatedAt)
        VALUES (:platformSubscriptionID, :userID, :oldPlanID, :newPlanID, :effectiveDate, 'WAITING', NOW())"
      );

      $stmt->execute([
        ':platformSubscriptionID' => $platformSubscriptionID,
        ':userID' => $sub['UserID'],
        ':oldPlanID' => $sub['PlanID'],
        ':newPlanID' => $newPlanID,
        ':effectiveDate' => $effectiveDate
      ]);

      $id = $this->db->lastInsertId();

      $this->db->commit(); # Confirmo transacción
      return $id;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene el cambio pendiente en estado "en espera de pago"
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @return array|false: datos del cambio o false
   */
  public function getWaitingChange($platformSubscriptionID) {
    $sql = "SELECT * FROM SubscriptionChanges
      WHERE PlatformSubscriptionID = ? AND Status = 'WAITING'
      ORDER BY CreatedAt DESC LIMIT 1";

    $stmt = $this->db->prepare($sql);
    $stmt->execute([$platformSubscriptionID]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Cancela cambios pendientes de una suscripción
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   */
  public function cancelSubscriptionChange($platformSubscriptionID) {
    $sql = "UPDATE SubscriptionChanges SET Status = 'CANCELLED', AppliedAt = NOW()
      WHERE PlatformSubscriptionID = ? AND Status = 'PENDING'";

    $stmt = $this->db->prepare($sql);
    $stmt->execute([$platformSubscriptionID]);
  }

  /**
   * Obtiene una cancelación pendiente al final del período
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @return array|false: datos de cancelación pendiente o false
   */
  public function getPendingCancel($platformSubscriptionID) {
    $stmt = $this->db->prepare("SELECT * FROM SubscriptionChanges
      WHERE PlatformSubscriptionID = ?
      AND NewPlanID IS NULL AND Status = 'PENDING'"
    );
    $stmt->execute([$platformSubscriptionID]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Programa o desprograma la cancelación al final del período
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  int|false $cancelAt: timestamp de cancelación o false para deshacer
   */
  public function resumeSubscription($platformSubscriptionID, $cancelAt = false) {
    $stmt = $this->db->prepare("UPDATE Subscriptions SET CancelAtPeriodEnd = :cancel, CancelAt = :cancelAt
      WHERE PlatformSubscriptionID = :platformSubscriptionID"
    );
    $stmt->execute([
      ':platformSubscriptionID' => $platformSubscriptionID,
      ':cancel' => $cancelAt ? 1 : 0,
      ':cancelAt' => $cancelAt ? date('Y-m-d H:i:s', $cancelAt) : null
    ]);
  }

  /**
   * Aplica un cambio de plan programado inmediatamente
   * @param  int|string $changeId: ID del cambio a aplicar
   * @param  string|null $effectiveDate: fecha efectiva del cambio (opcional)
   * @return bool: true si se aplicó exitosamente
   * @throws DatabaseException
   */
  public function applyScheduledChange($changeId, $effectiveDate = null) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # bloquear y leer el cambio
      $stmt = $this->db->prepare("SELECT * FROM SubscriptionChanges WHERE id = :id FOR UPDATE");
      $stmt->execute([':id' => $changeId]);
      $change = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$change) throw new DatabaseException("Change not found: $changeId");

      # actualizar Subscriptions
      $stmt = $this->db->prepare("UPDATE Subscriptions
        SET PlanID = :newPlanID, NextBillingDate = :nextBillingDate
        WHERE PlatformSubscriptionID = :platformSubscriptionID
        AND Status IN ('ACTIVE','TRIALING')"
      );

      $stmt->execute([
        ':newPlanID' => $change['NewPlanID'],
        ':nextBillingDate' => $effectiveDate,
        ':platformSubscriptionID' => $change['PlatformSubscriptionID']
      ]);

      # marcar change como aplicado
      $stmt = $this->db->prepare("UPDATE SubscriptionChanges SET Status = 'APPLIED', AppliedAt = NOW() WHERE id = :id");
      $stmt->execute([':id' => $changeId]);

      $subscription = $this->getUserSubscriptionByPlatformSubID($change['PlatformSubscriptionID']) ?:
        throw new DatabaseException("Failed to retrieve the updated subscription");
      $userID = $subscription['UserID'];

      $this->db->commit();
      return $subscription;
    } catch (\PDOException $e) {
      $this->db->rollBack();
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Actualiza el plan de una suscripción (inmediato o programado)
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  int $newPlanID: ID del nuevo plan
   * @param  string|null $nextBillingDate: próxima fecha de facturación (opcional)
   * @param  bool $applyNow: aplicar inmediatamente o programar para después
   * @return array|string|int: resultado o ID de cambio programado
   * @throws DatabaseException
   */
  public function updateSubscriptionByPlatformId($platformSubscriptionID, $newPlanID, $nextBillingDate = null, $applyNow = true) {
    try {
      if (!$applyNow) {
        # crear registro pendiente
        return $this->scheduleSubscriptionChange($platformSubscriptionID, $newPlanID, $nextBillingDate);
      }

      # aplicar ahora (comportamiento previo) + registrar en SubscriptionChanges como APPLIED
      $this->db->beginTransaction(); # Iniciar transacción

      # obtener suscripción actual para oldPlanID
      $current = $this->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
      $oldPlanID = $current ? $current['PlanID'] : null;

      $stmt = $this->db->prepare("UPDATE Subscriptions
        SET PlanID = :planID, NextBillingDate = :nextBillingDate
        WHERE PlatformSubscriptionID = :platformSubscriptionID
        AND Status IN ('ACTIVE','TRIALING')"
      );

      $stmt->execute([
        ':planID' => $newPlanID,
        ':nextBillingDate' => $nextBillingDate,
        ':platformSubscriptionID' => $platformSubscriptionID
      ]);

      $affected = $stmt->rowCount();

      if (!$skipHistory) {
        # registrar en historico como APPLIED
        $stmt = $this->db->prepare("INSERT INTO SubscriptionChanges
              (PlatformSubscriptionID, UserID, OldPlanID, NewPlanID, EffectiveDate, Status, CreatedAt, AppliedAt)
              VALUES (:platformSubscriptionID, :userID, :oldPlanID, :newPlanID, :effectiveDate, 'APPLIED', NOW(), NOW())"
        );

        $stmt->execute([
          ':platformSubscriptionID' => $platformSubscriptionID,
          ':userID' => $current['UserID'] ?? null,
          ':oldPlanID' => $oldPlanID,
          ':newPlanID' => $newPlanID,
          ':effectiveDate' => $nextBillingDate
        ]);
      }

      if ($affected === 0) {
        error_log("Warning: updateSubscriptionByUser no afectó filas para PlatformSubscriptionID={$platformSubscriptionID}.
        Estado actual DB: " . json_encode($current));
      }

      $subscription = $this->getUserSubscriptionByPlatformSubID($platformSubscriptionID) ?:
        throw new DatabaseException("Failed to retrieve the updated subscription");
      $userID = $subscription['UserID'];

      $this->db->commit(); # Confirmo transacción
      return $subcription;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Actualiza campos de pago de una factura
   * @param  string $invoiceID: ID de factura
   * @param  array $data: datos a actualizar (AmountDue, AmountPaid, AmountRemaining, Status, PaidAt)
   * @return array: datos actualizados de pago
   * @throws DatabaseException
   */
  public function updateSubscriptionPayment($invoiceID, $data) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
        SET AmountDue = :AmountDue, AmountPaid = :AmountPaid,
        AmountRemaining = :AmountRemaining, Status = :Status,
        PaidAt = :PaidAt
        WHERE InvoiceID = :InvoiceID");

      $stmt->execute([
        ':AmountDue' => $data['AmountDue'],
        ':AmountPaid' => $data['AmountPaid'],
        ':AmountRemaining'=> $data['AmountRemaining'],
        ':Status' => $data['Status'],
        ':PaidAt' => $data['PaidAt'],
        ':InvoiceID' => $invoiceID
      ]);

      # Devolver el registro recién creado
      $payment = $this->getPaymentByInvoiceID($invoiceID) ?:
        throw new DatabaseException("Failed to retrieve the updated payment");

      $this->db->commit(); # Confirmo transacción

      return $payment;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Obtiene un pago por ID de factura
   * @param  string $invoiceID: ID de factura
   * @return array|false: datos del pago o false
   */
  public function getPaymentByInvoiceID($invoiceID) {
    $stmt = $this->db->prepare("SELECT * FROM SubscriptionsPayments
    WHERE InvoiceID = :invoiceID");
    $stmt->execute(['invoiceID' => $invoiceID]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /**
   * Lista pagos de un cliente con paginación
   * @param  string $customerID: ID de cliente en plataforma de pagos
   * @param  object $paginator: objeto con limit y offset
   * @return object|null: { data: array, rows: { total: int, fetched: int } } o null
   */
  public function getPaymentsByUser($customerID, $paginator) {
    $stmt = $this->db->prepare("SELECT * FROM SubscriptionsPayments
      WHERE PlatformCustomerID = ?
      LIMIT ? OFFSET ?");

    $stmt->execute([$customerID, $paginator->limit, $paginator->offset]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($payments)) {
      return false;
    }

    $stmt = $this->db->query("SELECT FOUND_ROWS() as total");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);

    return (object) [
      "data" => $payments,
      "rows" => [
        "total" => $total['total'],
        "fetched" => count($payments)
      ]
    ];
  }

  /**
   * Registra un rechazo de pago de suscripción
   * @param  array $data: datos del rechazo
   * @return int|string: ID del registro creado
   * @throws DatabaseException
   */
  public function createSubscriptionPaymentRejection($data) {
    try {
      $contextJson = null;
      if (isset($data['Context']) && $data['Context'] !== null) {
        $encoded = json_encode($data['Context'], JSON_UNESCAPED_UNICODE);
        $contextJson = $encoded !== false ? $encoded : null;
      }

      $stmt = $this->db->prepare("INSERT INTO SubscriptionPaymentRejections
        (UserID, PlatformSubscriptionID, PlatformCustomerID, NewPlanID, PaymentPlatform,
        InvoiceID, PaymentIntentID, PaymentIntentStatus, RejectionCode, RejectionReason, ContextJSON)
        VALUES (:UserID, :PlatformSubscriptionID, :PlatformCustomerID, :NewPlanID, :PaymentPlatform,
        :InvoiceID, :PaymentIntentID, :PaymentIntentStatus, :RejectionCode, :RejectionReason, :ContextJSON)");

      $stmt->execute([
        ':UserID' => $data['UserID'] ?? null,
        ':PlatformSubscriptionID' => $data['PlatformSubscriptionID'] ?? null,
        ':PlatformCustomerID' => $data['PlatformCustomerID'] ?? null,
        ':NewPlanID' => $data['NewPlanID'] ?? null,
        ':PaymentPlatform' => $data['PaymentPlatform'] ?? 'STRIPE',
        ':InvoiceID' => $data['InvoiceID'] ?? null,
        ':PaymentIntentID' => $data['PaymentIntentID'] ?? null,
        ':PaymentIntentStatus' => $data['PaymentIntentStatus'] ?? null,
        ':RejectionCode' => $data['RejectionCode'] ?? 'UNKNOWN_REJECTION',
        ':RejectionReason' => $data['RejectionReason'] ?? null,
        ':ContextJSON' => $contextJson,
      ]);

      return $this->db->lastInsertId();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Marca cancelación al final del período con fecha de efectividad
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  string $canceledAt: fecha de cancelación
   * @param  string $nextBillingDate: próxima fecha de facturación
   */
  public function markCancelAtPeriodEnd($platformSubscriptionID, $canceledAt, $nextBillingDate) {
    $stmt = $this->db->prepare("UPDATE Subscriptions
      SET CancelAtPeriodEnd = 1, CancelAt = ?, NextBillingDate = ?
      WHERE PlatformSubscriptionID = ? AND
      Status IN ('ACTIVE','TRIALING')");
    $stmt->execute([$canceledAt, $nextBillingDate, $platformSubscriptionID]);
  }

  /**
   * Cancela definitivamente una suscripción activa
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  string $endDate: fecha de finalización
   * @throws DatabaseException
   */
  public function cancelSubscription($platformSubscriptionID, $endDate) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      # Cancelar la suscripción
      $stmt = $this->db->prepare("UPDATE Subscriptions
        SET Status = 'CANCELED', EndDate = ?, NextBillingDate = NULL
        WHERE PlatformSubscriptionID = ?
        AND Status IN ('ACTIVE','TRIALING')");
      $stmt->execute([$endDate, $platformSubscriptionID]);

      # Marcar cambios pendientes como aplicados
      $stmt = $this->db->prepare("UPDATE SubscriptionChanges
        SET Status = 'APPLIED', AppliedAt = NOW()
        WHERE PlatformSubscriptionID = ?
        AND Status = 'PENDING'");
      $stmt->execute([$platformSubscriptionID]);

      $this->db->commit(); # Confirmo transacción
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Actualiza el fin del período de prueba informado por Stripe
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  string|null $trialEnd: fecha de fin de prueba
   */
  public function handleTrialWillEnd($platformSubscriptionID, $trialEnd) {
    # Refleja el fin de trial informado por Stripe.
    $stmt = $this->db->prepare("UPDATE Subscriptions
      SET TrialEnd = COALESCE(?, TrialEnd),
      TrialSource = 'STRIPE'
      WHERE PlatformSubscriptionID = ?");
    $stmt->execute([$trialEnd, $platformSubscriptionID]);
  }

  /**
   * Marca una suscripción como pausada
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  string|null $behavior: comportamiento al pausar (opcional)
   */
  public function markPaused($platformSubscriptionID, $behavior = null) {
    $stmt = $this->db->prepare("UPDATE Subscriptions
      SET Status = 'PAUSED', NextBillingDate = NULL
      WHERE PlatformSubscriptionID = ?
      AND Status IN ('ACTIVE','PAST_DUE','INCOMPLETE')");
    $stmt->execute([$platformSubscriptionID]);
  }


  /**
   * Reactiva una suscripción pausada
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  string $nextBillingDate: próxima fecha de facturación
   */
  public function markResumed($platformSubscriptionID, $nextBillingDate) {
    $stmt = $this->db->prepare("UPDATE Subscriptions
      SET Status = 'ACTIVE', NextBillingDate = ?,
      CancelAtPeriodEnd = 0, CancelAt = NULL
      WHERE PlatformSubscriptionID = ?
      AND Status IN ('PAUSED','PAST_DUE','INCOMPLETE')");
    $stmt->execute([$nextBillingDate, $platformSubscriptionID]);
  }

  /**
   * Crea un cupón en base local
   * @param  array $data: datos del cupón con código, porcentaje/monto de descuento
   */
  public function createCoupon($data) {
    $stmt = $this->db->prepare("INSERT INTO SubscriptionsCoupons
      (PlatformSubscriptionID, PlatformCouponID, CouponCode, CouponName,
      UserID, PercentOff, AmountOff, Status, ExpiresAt)
      VALUES (:PlatformSubscriptionID, :PlatformCouponID, :CouponCode, :CouponName, :UserID, :PercentOff,
      :AmountOff, 'PENDING', :ExpiresAt)");
    $stmt->execute([
      ':PlatformSubscriptionID' => $data['PlatformSubscriptionID'],
      ':PlatformCouponID' => $data['PlatformCouponID'],
      ':CouponCode' => $data['CouponCode'],
      ':CouponName' => $data['CouponName'],
      ':UserID' => $data['UserID'],
      ':PercentOff' => $data['PercentOff'],
      ':AmountOff' => $data['AmountOff'],
      ':AppliedAt' => $data['AppliedAt'],
      ':ExpiresAt' => $data['ExpiresAt'],
    ]);
  }

  /**
   * Actualiza estado de cupón a aplicado
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   * @param  string $platformCouponID: ID de cupón en plataforma de pagos
   * @throws DatabaseException
   */
  public function updateCouponStatus($platformSubscriptionID, $platformCouponID) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("UPDATE SubscriptionsCoupons
        SET Status = 'APPLIED',
        AppliedAt = NOW(),
        UpdatedAt = CURRENT_TIMESTAMP
        WHERE PlatformSubscriptionID = ? AND PlatformCouponID = ?");
      $stmt->execute([$platformSubscriptionID, $platformCouponID]);

      $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
        SET PlatformCouponID = ?
        WHERE PlatformSubscriptionID = ?
        ORDER BY CreatedAt DESC LIMIT 1");
      $stmt->execute([$platformCouponID, $platformSubscriptionID]);

      $this->db->commit(); # Confirmo transacción
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Inserta o actualiza una factura en base local
   * @param  array $data: datos de factura con InvoiceID, moneda, monto, estado
   * @throws DatabaseException
   */
  public function upsertInvoice($data) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("INSERT INTO SubscriptionsPayments (InvoiceID, Motive, PlatformSubscriptionID, PlatformCustomerID,
        Currency, AmountDue, AmountPaid, AmountRemaining, Status, PlatformPriceID, PlatformProductID, Quantity,
        PeriodStart, PeriodEnd, InvoicePDF, HostedInvoiceURL, CreatedAt, PaidAt)
        VALUES (:InvoiceID, :BillingReason, :SubscriptionID, :CustomerID, :Currency,
        :AmountDue, :AmountPaid, :AmountRemaining, :Status,
        :PriceID, :ProductID, :Quantity, :PeriodStart, :PeriodEnd,
        :InvoicePDF, :HostedInvoiceURL, :CreatedAt, :PaidAt)");

      $stmt->execute($data);

      # Update Subscriptions con el último invoice
      if (!empty($data['SubscriptionID'])) {
        $stmt = $this->db->prepare("UPDATE Subscriptions
          SET LatestInvoiceID = :InvoiceID
          WHERE PlatformSubscriptionID = :SubscriptionID");
        $stmt->execute([
          ':InvoiceID'      => $data['InvoiceID'],
          ':SubscriptionID' => $data['SubscriptionID']
        ]);
      }

      $this->db->commit(); # Confirmo transacción
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
   * Marca una factura como anulada
   * @param  string $invoiceId: ID de factura
   */
  public function markInvoiceVoided($invoiceId) {
    $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
      SET Status='Void' WHERE InvoiceID = ?");
    $stmt->execute([$invoiceId]);
  }

  /**
   * Marca una factura como incobrable
   * @param  string $invoiceId: ID de factura
   */
  public function markInvoiceUncollectible($invoiceId) {
    $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
      SET Status='Uncollectible'
      WHERE InvoiceID = ?");
    $stmt->execute([$invoiceId]);
  }

  /**
   * Marca una suscripción como vencida
   * @param  string $platformSubscriptionID: ID de suscripción en plataforma de pagos
   */
  public function markPastDue($platformSubscriptionID) {
    $stmt = $this->db->prepare("UPDATE Subscriptions
      SET Status='PAST_DUE'
      WHERE PlatformSubscriptionID = ?
      AND Status IN ('ACTIVE','INCOMPLETE')");
    $stmt->execute([$platformSubscriptionID]);
  }

  /**
   * Crea o actualiza un plan de suscripción y sus características
   * @param  int $planID: ID del plan
   * @param  array $data: datos del plan (Name, Description, Price, StripeID, Features)
   * @return array: resultado con código y mensaje
   * @throws DatabaseException
   */
  public function clearPastDueOnPaid($platformSubscriptionID) {
    $stmt = $this->db->prepare("UPDATE Subscriptions
      SET Status='ACTIVE'
      WHERE PlatformSubscriptionID = ?
      AND Status='PAST_DUE'");
    $stmt->execute([$platformSubscriptionID]);
  }

  /**
   * Activa o desactiva una característica de suscripción globalmente
   * @param  string $featureCode: código de característica
   * @param  int $isActive: 1 para activa, 0 para inactiva
   * @return array: datos de característica actualizada
   * @throws DatabaseException
   */
  public function updateSubscriptionPlan($planID, $data) {
    try {
      $this->db->beginTransaction(); # Iniciar transacción

      $stmt = $this->db->prepare("SELECT COUNT(*) FROM SubscriptionPlans
        WHERE PlanID = ?");
      $stmt->execute([$planID]);
      $exists = $stmt->fetchColumn() > 0;

      if ($exists) {
        # 1. Si existe, actualizar el plan
        $stmt = $this->db->prepare("UPDATE SubscriptionPlans
          SET Name = :Name, Description = :Description, Beneficts = :Beneficts, Price = :Price,
          CurrencyCode = :CurrencyCode, Duration = :Duration, StripeID = :StripeID
          WHERE PlanID = :planID");
      } else {
        # 2. Si no existe, insertar el plan
        $stmt = $this->db->prepare("INSERT INTO SubscriptionPlans (PlanID, Name, Description, Beneficts, Price, CurrencyCode, Duration, StripeID)
          VALUES (:planID, :Name, :Description, :Beneficts, :Price, :CurrencyCode, :Duration, :StripeID)");
      }

      $stmt->execute([
        ':planID' => $planID,
        ':Name' => $data['Name'],
        ':Description' => $data['Description'],
        ':Beneficts' => $data['Beneficts'],
        ':Price' => $data['Price'],
        ':CurrencyCode' => $data['CurrencyCode'],
        ':Duration' => $data['Duration'],
        ':StripeID' => $data['StripeID']
      ]);

      # 3. Si hay Features en el body
      if (isset($data['Features']) && is_array($data['Features'])) {

        # Eliminar los SubscriptionItems actuales del Plan
        $deleteStmt = $this->db->prepare("DELETE FROM SubscriptionItems WHERE PlanID = ?");
        $deleteStmt->execute([$planID]);

        # Preparar inserción en SubscriptionFeatures (para asegurarnos que existan)
        $insertFeatureStmt = $this->db->prepare("INSERT IGNORE INTO SubscriptionFeatures (FeatureCode, Description, IsActive)
          VALUES (:FeatureCode, :Description, 1)");
        # Preparar inserción en SubscriptionItems
        $insertItemStmt = $this->db->prepare("INSERT INTO SubscriptionItems (PlanID, FeatureCode, Value, Type, Description)
          VALUES (:planID, :FeatureCode, :Value, :Type, :ItemDescription)");

        foreach ($data['Features'] as $feature) {
          # Insertar en SubscriptionFeatures si no existe
          $featureCode = $feature['FeatureCode'];
          $description = $feature['Description'];
          $value = isset($feature['Value']) ? $feature['Value'] : null;
          $type = isset($feature['Type']) ? $feature['Type'] : null;
          $itemDescription = isset($feature['ItemDescription']) ? $feature['ItemDescription'] : null;

          $insertFeatureStmt->bindParam(':FeatureCode', $featureCode, PDO::PARAM_STR);
          $insertFeatureStmt->bindParam(':Description', $description, PDO::PARAM_STR);
          $insertFeatureStmt->execute();

          # Insertar la relación en SubscriptionItems
          $insertItemStmt->bindParam(':planID', $planID, PDO::PARAM_INT);
          $insertItemStmt->bindParam(':FeatureCode', $featureCode, PDO::PARAM_STR);
          $insertItemStmt->bindParam(':planID', $planID, PDO::PARAM_INT);
          $insertItemStmt->bindParam(':FeatureCode', $featureCode, PDO::PARAM_STR);
          $insertItemStmt->bindParam(':Value', $value);
          $insertItemStmt->bindParam(':Type', $type, PDO::PARAM_STR);
          $insertItemStmt->bindParam(':ItemDescription', $itemDescription, PDO::PARAM_STR);
          $insertItemStmt->execute();
        }
      }

      $subcription = $this->getSubscriptionPlanByID($planID) ?:
        throw new DatabaseException("Failed to retrieve the ".(!$exists ? 'created' : 'updated')." plan");
      $this->db->commit(); # Confirmo transacción

      return $subcription;
    } catch (\PDOException $e) {
      $this->db->rollBack(); # Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }
}
