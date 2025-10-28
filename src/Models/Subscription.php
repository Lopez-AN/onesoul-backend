<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Utils\EmailHelper;

class Subscription {
  protected $db;

  public function __construct(PDO $db) {
    $this->db = $db;
  }

  /**
  * Obtiene todos los planes con features activos.
  * @return array Lista de planes.
  * @throws DatabaseException
  */
  public function getSubscriptionPlans() {
    try {
      $stmt = $this->db->prepare("SELECT sp.PlanID, sp.StripeID, sp.Name, sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
                    sf.FeatureCode, sf.Description AS FeatureDescription,
                    si.Value, si.Type, si.Description AS ItemDescription
                FROM SubscriptionPlans AS sp
                LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
                LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
                WHERE sf.IsActive = 1
                ORDER BY sp.PlanID, sf.FeatureCode");

      $stmt->execute();
      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Organizar los planes en un array estructurado
      $plans = [];
      foreach ($results as $row) {
        $planId = $row['PlanID'];

        // Castear el valor según el tipo
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

        // Si el plan no está en el array, inicializarlo
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

        // Agregar las características solo si existen
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

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Obtiene un plan por ID con sus features.
  * @param int $id
  * @return array|null Plan o null si no existe.
  * @throws DatabaseException
  */
  public function getSubscriptionPlanByID($id) {
    try {
      $stmt = $this->db->prepare("SELECT sp.PlanID, sp.StripeID, sp.Name, sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
                                sf.FeatureCode, sf.Description AS FeatureDescription,
                                si.Value, si.Type, si.Description AS ItemDescription
                                FROM SubscriptionPlans AS sp
                                LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
                                LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
                                WHERE sp.PlanID = :id AND sf.IsActive = 1
                                ORDER BY sf.FeatureCode");

      $stmt->bindParam(':id', $id, PDO::PARAM_INT);
      $stmt->execute();

      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rows)) {
        return null; // No existe el plan
      }

      // Inicializar el plan
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

      // Agregar las features
      foreach ($rows as $row) {
        if (!empty($row['FeatureCode'])) {

          // Castear el valor según el tipo
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

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Obtiene un plan por Stripe Price ID.
  * @param string $priceID
  * @return array|null Plan o null si no existe.
  * @throws DatabaseException
  */
  public function getSubscriptionPlanByStripeID($priceID) {
    try {
      $stmt = $this->db->prepare("SELECT sp.PlanID, sp.StripeID, sp.Name, sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
                                sf.FeatureCode, sf.Description AS FeatureDescription,
                                si.Value, si.Type, si.Description AS ItemDescription
                                FROM SubscriptionPlans AS sp
                                LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
                                LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
                                WHERE sp.StripeID = :priceID AND sf.IsActive = 1
                                ORDER BY sf.FeatureCode");

      $stmt->bindParam(':priceID', $priceID, PDO::PARAM_STR);
      $stmt->execute();

      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rows)) {
        return null; // No existe el plan
      }

      // Inicializar el plan
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

      // Agregar las features
      foreach ($rows as $row) {
        if (!empty($row['FeatureCode'])) {

          // Castear el valor según el tipo
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

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Obtiene la suscripción activa de un usuario e incluye el detalle del plan.
  * @param int $userID
  * @return array|null Suscripción o null si no hay activa/trial.
  * @throws DatabaseException
  */
  public function getSubscriptionByUser($userID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Subscriptions
                                  WHERE UserID = :userID
                                  AND Status IN ('ACTIVE','TRIALING')
                                  ORDER BY StartDate DESC
                                  LIMIT 1");

      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
      $stmt->execute();

      $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$subscription) {
        return null;
      }

      // Upgrade pendiente (por alguna demora en pago por ejemplo)
      $stmt1 = $this->db->prepare("SELECT NewPlanID
                                  FROM SubscriptionChanges
                                  WHERE UserID = :userID
                                  AND Status = 'WAITING'
                                  ORDER BY CreatedAt DESC
                                  LIMIT 1");

      $stmt1->bindParam(':userID', $userID, PDO::PARAM_INT);
      $stmt1->execute();
      $newPlanID = $stmt1->fetchColumn();
      $subscription['PendingUpgrade'] = $newPlanID ?: null;

      // Downgrade pendiente (aun no llego la fecha)
      $stmt2 = $this->db->prepare("SELECT NewPlanID
                                  FROM SubscriptionChanges
                                  WHERE UserID = :userID
                                  AND Status = 'PENDING'
                                  ORDER BY CreatedAt DESC
                                  LIMIT 1");

      $stmt2->bindParam(':userID', $userID, PDO::PARAM_INT);
      $stmt2->execute();
      $newPlanID = $stmt2->fetchColumn();
      $subscription['PendingDowngrade'] = $newPlanID ?: null;

      return $subscription;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Obtiene la suscripción activa por ID de suscripción en plataforma.
  * @param string $platformSubscriptionID
  * @return array|null Suscripción o null si no hay activa/trial.
  * @throws DatabaseException
  */
  public function getUserSubscriptionByPlatformSubID($platformSubscriptionID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Subscriptions
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND Status IN ('ACTIVE','TRIALING')
                                  ORDER BY StartDate DESC
                                  LIMIT 1");

      $stmt->bindParam(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
      $stmt->execute();

      $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$subscription) {
        return null;
      }

      // Se obtiene los detalles del Plan del usuario
      $id = $subscription['PlanID'];
      $planDetails = $this->getSubscriptionPlanByID($id);
      $subscription['PlanDetails'] = $planDetails;

      return $subscription;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Crea una suscripción confirmada (reemplaza la activa si la hubiera) y envía email opcional.
  * @param int $userID
  * @param int $planID
  * @param string $platformSubscriptionID
  * @param string $platformCustomerID
  * @param string $subDomain
  * @param array $userData
  * @return array Datos de la suscripción creada.
  * @throws DatabaseException
  */
  public function createConfirmedSubscription($userID, $planID, $platformSubscriptionID, $platformCustomerID, $subDomain = '', $userData = []) {
    try {
      // Cancelar suscripción anterior si existe
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                SET EndDate = NOW(), Status = 'CANCELED'
                                WHERE UserID = :userID AND Status = 'ACTIVE'");
      $stmt->execute(['userID' => $userID]);

      $today = date('Y-m-d');
      $trialStart = $userData['TrialStart'];
      $trialEnd = $userData['TrialEnd'];
      $trialSource = $userData['TrialSource'];
      $paymentPlatform = $userData['PaymentPlatform'];
      $nextBillingDate = $userData['NextBillingDate'];
      $latestInvoice = $userData['LatestInvoiceID'];
      $trialEndDate = $trialEnd ? substr($trialEnd, 0, 10) : null;

      // TRIALING si el trial no terminó aún
      $status = ($trialEndDate && $trialEndDate >= $today) ? 'TRIALING' : 'ACTIVE';

      // Insertar nueva suscripción
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

      // Marcar referido como exitoso si corresponde
      $stmt = $this->db->prepare("UPDATE Referrals
                                SET ReferralStatus = 'Successful'
                                WHERE ReferredUserID = :userID AND ReferralStatus = 'Pending'");
      $stmt->execute(['userID' => $userID]);

      $subscription = $this->getSubscriptionByUser($userID);

      // Enviar email si hay datos del usuario
      if (!empty($userData['UserName']) && !empty($userData['Email'])) {
        $username = $userData['UserName'];
        $email = $userData['Email'];

        $planInfo = $this->getSubscriptionPlanByID($planID);
        if (!$planInfo) return;

        $origin = $subDomain ? "https://{$subDomain}.onesoul.app" : "https://onesoul.app";
        $dashboardURL = $origin . "/profile";

        // Enviar email
        if ($email) {
          EmailHelper::send(
          $username,
          $email,
          "¡Suscripción activada en OneSoul! 🎉",
          ROOT . "/src/templates/email_subscription.html",
            [
              '{USERNAME}' => $username,
              '{PLAN_NAME}' => $planInfo['Name'],
              '{PLAN_PRICE}' => number_format($planInfo['Price'], 2) . ' ' . $planInfo['CurrencyCode'],
              '{PLAN_DURATION}' => $planInfo['Duration'] . " mes",
              '{DASHBOARD_URL}' => $dashboardURL
            ]
          );
        }
      }

      return [
        'Subscription' => $subscription
      ];

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Registra un cambio de plan programado (pendiente).
  * @param string $platformSubscriptionID
  * @param int $newPlanID
  * @param int|string|null $effectiveDate Timestamp o null.
  * @return string|int ID del cambio.
  * @throws DatabaseException
  */
  public function scheduleSubscriptionChange($platformSubscriptionID, $newPlanID, $effectiveDate = null) {
    $effectiveDate = $effectiveDate ? date("Y-m-d H:i:s", $effectiveDate) : null;

    try {
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

      return $this->db->lastInsertId();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Obtiene un cambio pendiente.
  * @param string $platformSubscriptionID
  * @param int|null|false $newPlanID Filtra por nuevo plan (null acepta NULL, false ignora filtro).
  * @return array|false Fila o false si no existe.
  * @throws DatabaseException
  */
  public function getPendingChange($platformSubscriptionID, $newPlanID = null) {
    try {
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
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  // Crea un cambio pendiente de pago
  public function waitingSubscriptionChange($platformSubscriptionID, $newPlanID, $effectiveDate = null) {
    $effectiveDate = $effectiveDate ? date("Y-m-d H:i:s", $effectiveDate) : null;

    try {
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

      return $this->db->lastInsertId();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function getWaitingChange($platformSubscriptionID) {
    try {
      $sql = "SELECT * FROM SubscriptionChanges
              WHERE PlatformSubscriptionID = :platformSubscriptionID AND Status = 'WAITING'
              ORDER BY CreatedAt DESC LIMIT 1";

      $stmt = $this->db->prepare($sql);
      $stmt->bindValue(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
      $stmt->execute();

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Cancela cambios pendientes.
  * @param string $platformSubscriptionID
  * @return void
  * @throws DatabaseException
  */
  public function cancelSubscriptionChange($platformSubscriptionID) {
    try {
      $sql = "UPDATE SubscriptionChanges SET Status = 'CANCELLED', AppliedAt = NOW()
        WHERE PlatformSubscriptionID = :platformSubscriptionID AND Status = 'PENDING'";

      $stmt = $this->db->prepare($sql);
      $stmt->bindValue(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Obtiene una cancelación pendiente (cancel_at_period_end).
  * @param string $platformSubscriptionID
  * @return array|false
  * @throws DatabaseException
  */
  public function getPendingCancel($platformSubscriptionID) {
    try {
      $sql = "SELECT * FROM SubscriptionChanges
        WHERE PlatformSubscriptionID = :platformSubscriptionID
        AND NewPlanID IS NULL AND Status = 'PENDING'";
      $stmt = $this->db->prepare($sql);
      $stmt->bindValue(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
      $stmt->execute();

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Programa o desprograma la cancelación al final de período.
  * @param string $platformSubscriptionID
  * @param int|false $cancelAt Timestamp o false.
  * @return void
  * @throws DatabaseException
  */
  public function resumeSubscription($platformSubscriptionID, $cancelAt = false) {
    try {
      $sql = "UPDATE Subscriptions SET CancelAtPeriodEnd = :cancel, CancelAt = :cancelAt
        WHERE PlatformSubscriptionID = :platformSubscriptionID";
      $stmt = $this->db->prepare($sql);
      $stmt->bindValue(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
      $stmt->bindValue(':cancel', $cancelAt ? 1 : 0, PDO::PARAM_INT);
      $stmt->bindValue(':cancelAt', $cancelAt ? date('Y-m-d H:i:s', $cancelAt) : null, PDO::PARAM_STR);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Aplica un cambio programado y actualiza la suscripción.
  * @param int|string $changeId
  * @param string|null $effectiveDate
  * @return bool
  * @throws DatabaseException
  */
  public function applyScheduledChange($changeId, $effectiveDate = null) {
    try {
      $this->db->beginTransaction(); // Iniciar transacción

      // bloquear y leer el cambio
      $stmt = $this->db->prepare("SELECT * FROM SubscriptionChanges WHERE id = :id FOR UPDATE");
      $stmt->execute([':id' => $changeId]);
      $change = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$change) throw new DatabaseException("Change not found: $changeId");

      // actualizar Subscriptions
      $stmtUp = $this->db->prepare(
        "UPDATE Subscriptions
        SET PlanID = :newPlanID, NextBillingDate = :nextBillingDate
        WHERE PlatformSubscriptionID = :platformSubscriptionID
        AND Status IN ('ACTIVE','TRIALING')"
      );

      $stmtUp->execute([
        ':newPlanID' => $change['NewPlanID'],
        ':nextBillingDate' => $effectiveDate,
        ':platformSubscriptionID' => $change['PlatformSubscriptionID']
      ]);

      // marcar change como aplicado
      $stmt2 = $this->db->prepare("UPDATE SubscriptionChanges SET Status = 'APPLIED', AppliedAt = NOW() WHERE id = :id");
      $stmt2->execute([':id' => $changeId]);

      $this->db->commit();
      return true;
    } catch (\PDOException $e) {
      $this->db->rollBack();
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Actualiza el plan de una suscripción (inmediato o programado).
  * @param string $platformSubscriptionID
  * @param int $newPlanID
  * @param string|null $nextBillingDate
  * @param bool $applyNow
  * @return array|string|int Resultado o ID de cambio si es programado.
  * @throws DatabaseException
  */
  public function updateSubscriptionByUser($platformSubscriptionID, $newPlanID, $nextBillingDate = null, $applyNow = true) {
    try {
      if (!$applyNow) {
        // crear registro pendiente
        return $this->scheduleSubscriptionChange($platformSubscriptionID, $newPlanID, $nextBillingDate);
      }

      // aplicar ahora (comportamiento previo) + registrar en SubscriptionChanges como APPLIED
      $this->db->beginTransaction(); // Iniciar transacción

      // obtener suscripción actual para oldPlanID
      $current = $this->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
      $oldPlanID = $current ? $current['PlanID'] : null;

      $stmt = $this->db->prepare(
        "UPDATE Subscriptions
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
        // registrar en historico como APPLIED
        $stmtHist = $this->db->prepare("INSERT INTO SubscriptionChanges
              (PlatformSubscriptionID, UserID, OldPlanID, NewPlanID, EffectiveDate, Status, CreatedAt, AppliedAt)
              VALUES (:platformSubscriptionID, :userID, :oldPlanID, :newPlanID, :effectiveDate, 'APPLIED', NOW(), NOW())"
        );

        $stmtHist->execute([
          ':platformSubscriptionID' => $platformSubscriptionID,
          ':userID' => $current['UserID'] ?? null,
          ':oldPlanID' => $oldPlanID,
          ':newPlanID' => $newPlanID,
          ':effectiveDate' => $nextBillingDate
        ]);
      }

      $this->db->commit(); // Confirmo transacción

      if ($affected === 0) {
        error_log("Warning: updateSubscriptionByUser no afectó filas para PlatformSubscriptionID={$platformSubscriptionID}.
        Estado actual DB: " . json_encode($current));
      }

      $subscription = $this->getUserSubscriptionByPlatformSubID($platformSubscriptionID);
      return ['Subscription' => $subscription];
    } catch (\PDOException $e) {
      $this->db->rollBack(); // Revierto en caso de error
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Actualiza campos de pago de una factura y devuelve el registro.
  * @param string $invoiceID
  * @param array $data
  * @return array
  * @throws DatabaseException
  */
  public function updateSubscriptionPayment($invoiceID, $data) {
    try {
      $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
            SET AmountDue            = :AmountDue,
                AmountPaid           = :AmountPaid,
                AmountRemaining      = :AmountRemaining,
                Status               = :Status,
                PaidAt               = :PaidAt
            WHERE InvoiceID = :InvoiceID");

        $stmt->execute([
            ':AmountDue'      => $data['AmountDue'],
            ':AmountPaid'     => $data['AmountPaid'],
            ':AmountRemaining'=> $data['AmountRemaining'],
            ':Status'         => $data['Status'],
            ':PaidAt'         => $data['PaidAt'],
            ':InvoiceID'      => $invoiceID
        ]);

      // Devolver el registro recién creado
      return $this->getPaymentByInvoiceID($invoiceID);
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Obtiene un pago por InvoiceID.
  * @param string $invoiceID
  * @return array|false
  * @throws DatabaseException
  */
  public function getPaymentByInvoiceID($invoiceID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM SubscriptionsPayments
      WHERE InvoiceID = :invoiceID");
      $stmt->execute(['invoiceID' => $invoiceID]);

      return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Lista pagos por cliente con paginación.
  * @param string $customerID
  * @param object $paginator Debe exponer ->limit y ->offset.
  * @return array|null
  * @throws DatabaseException
  */
  public function getPaymentsByUser($customerID, $paginator) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM SubscriptionsPayments
                                  WHERE PlatformCustomerID = :customerID
                                  LIMIT :_limit OFFSET :_offset");
      $stmt->bindParam(':customerID', $customerID, PDO::PARAM_STR);
      $stmt->bindValue(':_limit', $paginator->limit, PDO::PARAM_INT);
      $stmt->bindValue(':_offset', $paginator->offset, PDO::PARAM_INT);
      $stmt->execute();
      $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (empty($payments)) {
        return null;
      }

      // Consulta total real
      $stmtTotal = $this->db->prepare("SELECT COUNT(*) as total
                                      FROM SubscriptionsPayments AS sp
                                      WHERE sp.PlatformCustomerID = :customerID");
      $stmtTotal->bindParam(':customerID', $customerID, PDO::PARAM_STR);
      $stmtTotal->execute();
      $total = (int)$stmtTotal->fetchColumn();

      return $payments;

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Marca cancel_at_period_end y fecha de cancelación.
  * @param string $platformSubscriptionID
  * @param string $canceledAt
  * @param string $nextBillingDate
  * @return bool
  * @throws DatabaseException
  */
  public function markCancelAtPeriodEnd($platformSubscriptionID, $canceledAt, $nextBillingDate) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET CancelAtPeriodEnd = 1, CancelAt = :canceledAt, NextBillingDate = :nextBillingDate
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID AND
                                  Status IN ('ACTIVE','TRIALING')");
      $stmt->execute([
        'canceledAt' => $canceledAt,
        'nextBillingDate' => $nextBillingDate,
        'platformSubscriptionID' => $platformSubscriptionID
      ]);
      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Cancela definitivamente una suscripción activa/trial.
  * @param string $platformSubscriptionID
  * @return bool
  * @throws DatabaseException
  */
  public function cancelSubscription($platformSubscriptionID, $endDate) {
    try {
      // Cancelar la suscripción
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET Status = 'CANCELED', EndDate = :endDate, NextBillingDate = NULL
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND Status IN ('ACTIVE','TRIALING')");
      $stmt->bindValue(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
      $stmt->bindValue(':endDate', $endDate, PDO::PARAM_STR);
      $stmt->execute();

      // Marcar cambios pendientes como aplicados
      $sql = "UPDATE SubscriptionChanges
              SET Status = 'APPLIED', AppliedAt = NOW()
              WHERE PlatformSubscriptionID = :platformSubscriptionID
              AND Status = 'PENDING'";
      $stmt2 = $this->db->prepare($sql);
      $stmt2->bindValue(':platformSubscriptionID', $platformSubscriptionID, PDO::PARAM_STR);
      $stmt2->execute();

      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Actualiza el fin de trial informado por Stripe.
  * @param string $platformSubscriptionID
  * @param string|null $trialEnd
  * @return bool
  * @throws DatabaseException
  */
  public function handleTrialWillEnd($platformSubscriptionID, $trialEnd) {
    // Refleja el fin de trial informado por Stripe.
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET TrialEnd = COALESCE(:trialEnd, TrialEnd),
                                  TrialSource = 'STRIPE'
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID");
      $stmt->execute([
        ':trialEnd' => $trialEnd,
        ':platformSubscriptionID' => $platformSubscriptionID
      ]);

      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Marca una suscripción como pausada.
  * @param string $platformSubscriptionID
  * @param string|null $behavior
  * @return bool
  * @throws DatabaseException
  */
  public function markPaused($platformSubscriptionID, $behavior = null) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET Status = 'PAUSED', NextBillingDate = NULL
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND Status IN ('ACTIVE','PAST_DUE','INCOMPLETE')");
      $stmt->execute([':platformSubscriptionID' => $platformSubscriptionID]);
      return $stmt->rowCount() > 0;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }


  /**
  * Reactiva una suscripción pausada/past_due/incomplete.
  * @param string $platformSubscriptionID
  * @param string $nextBillingDate
  * @return bool
  * @throws DatabaseException
  */
  public function markResumed($platformSubscriptionID, $nextBillingDate) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET Status = 'ACTIVE', NextBillingDate = :nextBillingDate,
                                  CancelAtPeriodEnd = 0, CancelAt = NULL
                                  WHERE PlatformSubscriptionID = :PlatformSubscriptionID
                                  AND Status IN ('PAUSED','PAST_DUE','INCOMPLETE')");
      $stmt->execute([
        ':nextBillingDate' => $nextBillingDate,
        ':PlatformSubscriptionID' => $platformSubscriptionID
      ]);
      return $stmt->rowCount() > 0;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Crea un cupón en base local (estado PENDING).
  * @param array $data
  * @return void
  * @throws DatabaseException
  */
  public function createCoupon($data) {
    try {
      $stmt = $this->db->prepare("INSERT INTO SubscriptionsCoupons (PlatformSubscriptionID, PlatformCouponID, CouponCode, CouponName,
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

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Marca un cupón como aplicado y lo asocia al último pago.
  * @param string $platformSubscriptionID
  * @param string $platformCouponID
  * @return void
  * @throws DatabaseException
  */
  public function updateCouponStatus($platformSubscriptionID, $platformCouponID) {
    try {
      $stmt = $this->db->prepare("UPDATE SubscriptionsCoupons
                                  SET Status = 'APPLIED',
                                  AppliedAt = NOW(),
                                  UpdatedAt = CURRENT_TIMESTAMP
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID AND PlatformCouponID = :platformCouponID");
      $stmt->execute([
          ':PlatformSubscriptionID' => $platformSubscriptionID,
          ':PlatformCouponID' => $platformCouponID
      ]);

      $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
                                  SET PlatformCouponID = :platformCouponID
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  ORDER BY CreatedAt DESC LIMIT 1");
      $stmt->execute([
          ':PlatformSubscriptionID' => $platformSubscriptionID,
          ':PlatformCouponID' => $platformCouponID
      ]);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Inserta/actualiza una factura en base local y actualiza última invoice de la suscripción.
  * @param array $data
  * @return bool
  * @throws DatabaseException
  */
  public function upsertInvoice($data) {
    try {
      $stmt = $this->db->prepare("INSERT INTO SubscriptionsPayments (InvoiceID, Motive, PlatformSubscriptionID, PlatformCustomerID,
              Currency, AmountDue, AmountPaid, AmountRemaining, Status, PlatformPriceID, PlatformProductID, Quantity,
              PeriodStart, PeriodEnd, InvoicePDF, HostedInvoiceURL, CreatedAt, PaidAt)
              VALUES (:InvoiceID, :BillingReason, :SubscriptionID, :CustomerID, :Currency,
              :AmountDue, :AmountPaid, :AmountRemaining, :Status,
              :PriceID, :ProductID, :Quantity, :PeriodStart, :PeriodEnd,
              :InvoicePDF, :HostedInvoiceURL, :CreatedAt, :PaidAt)");

      $stmt->execute($data);

      // Update Subscriptions con el último invoice
      if (!empty($data['SubscriptionID'])) {
        $stmt = $this->db->prepare("UPDATE Subscriptions
                                    SET LatestInvoiceID = :InvoiceID
                                    WHERE PlatformSubscriptionID = :SubscriptionID");
        $stmt->execute([
            ':InvoiceID'      => $data['InvoiceID'],
            ':SubscriptionID' => $data['SubscriptionID']
        ]);
      }

      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Marca una invoice como “Void”.
  * @param string $invoiceId
  * @return bool
  * @throws DatabaseException
  */
  public function markInvoiceVoided($invoiceId) {
    try {
      $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
                                  SET Status='Void'
                                  WHERE InvoiceID = :InvoiceID");
      $stmt->execute([':InvoiceID'=>$invoiceId]);
      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Marca una invoice como “Uncollectible”.
  * @param string $invoiceId
  * @return bool
  * @throws DatabaseException
  */
  public function markInvoiceUncollectible($invoiceId) {
    try {
      $stmt = $this->db->prepare("UPDATE SubscriptionsPayments
                                  SET Status='Uncollectible'
                                  WHERE InvoiceID = :InvoiceID");
      $stmt->execute([':InvoiceID'=>$invoiceId]);
      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Marca una suscripción como “PAST_DUE”.
  * @param string $platformSubscriptionID
  * @return bool
  * @throws DatabaseException
  */
  public function markPastDue($platformSubscriptionID) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET Status='PAST_DUE'
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND Status IN ('ACTIVE','INCOMPLETE')");
      $stmt->execute([':platformSubscriptionID'=>$platformSubscriptionID]);
      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Devuelve una suscripción PAST_DUE a ACTIVE al pagarse.
  * @param string $platformSubscriptionID
  * @return bool
  * @throws DatabaseException
  */
  public function clearPastDueOnPaid($platformSubscriptionID) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET Status='ACTIVE'
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND Status='PAST_DUE'");
      $stmt->execute([':platformSubscriptionID'=>$platformSubscriptionID]);
      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Crea o actualiza un plan y sus features.
  * @param int $planID
  * @param array $data
  * @return array Resultado con código/mensaje.
  * @throws DatabaseException
  */
  public function updateSubscriptionPlan($planID, $data) {
    try{
      $stmt = $this->db->prepare("SELECT COUNT(*) FROM SubscriptionPlans WHERE PlanID = :planID");
      $stmt->execute(['planID' => $planID]);
      $exists = $stmt->fetchColumn() > 0;

      if ($exists) {
        // 1. Si existe, actualizar el plan
        $stmt = $this->db->prepare("UPDATE SubscriptionPlans
                                    SET Name = :Name, Description = :Description, Beneficts = :Beneficts, Price = :Price,
                                    CurrencyCode = :CurrencyCode, Duration = :Duration, StripeID = :StripeID
                                    WHERE PlanID = :planID");
      } else {
        // 2. Si no existe, insertar el plan
        $stmt = $this->db->prepare("INSERT INTO SubscriptionPlans (PlanID, Name, Description, Beneficts, Price, CurrencyCode, Duration, StripeID)
                                    VALUES (:planID, :Name, :Description, :Beneficts, :Price, :CurrencyCode, :Duration, :StripeID)");
      }

      $stmt->bindParam(':planID', $planID, PDO::PARAM_INT);
      $stmt->bindParam(':Name', $data['Name'], PDO::PARAM_STR);
      $stmt->bindParam(':Description', $data['Description'], PDO::PARAM_STR);
      $stmt->bindParam(':Beneficts', $data['Beneficts'], PDO::PARAM_STR);
      $stmt->bindParam(':Price', $data['Price']);
      $stmt->bindParam(':CurrencyCode', $data['CurrencyCode'], PDO::PARAM_STR);
      $stmt->bindParam(':Duration', $data['Duration'], PDO::PARAM_INT);
      $stmt->bindParam(':StripeID', $data['StripeID'], PDO::PARAM_STR);
      $stmt->execute();

      // 3. Si hay Features en el body
      if (isset($data['Features']) && is_array($data['Features'])) {

        // Eliminar los SubscriptionItems actuales del Plan
        $deleteStmt = $this->db->prepare("DELETE FROM SubscriptionItems WHERE PlanID = :planID");
        $deleteStmt->bindParam(':planID', $planID, PDO::PARAM_INT);
        $deleteStmt->execute();

        // Preparar inserción en SubscriptionFeatures (para asegurarnos que existan)
        $insertFeatureStmt = $this->db->prepare("INSERT IGNORE INTO SubscriptionFeatures (FeatureCode, Description, IsActive)
                                                 VALUES (:FeatureCode, :Description, 1)");

        // Preparar inserción en SubscriptionItems
        $insertItemStmt = $this->db->prepare("INSERT INTO SubscriptionItems (PlanID, FeatureCode, Value, Type, Description)
                                              VALUES (:planID, :FeatureCode, :Value, :Type, :ItemDescription)");

        foreach ($data['Features'] as $feature) {
          // Insertar en SubscriptionFeatures si no existe
          $featureCode = $feature['FeatureCode'];
          $description = $feature['Description'];
          $value = isset($feature['Value']) ? $feature['Value'] : null;
          $type = isset($feature['Type']) ? $feature['Type'] : null;
          $itemDescription = isset($feature['ItemDescription']) ? $feature['ItemDescription'] : null;

          $insertFeatureStmt->bindParam(':FeatureCode', $featureCode, PDO::PARAM_STR);
          $insertFeatureStmt->bindParam(':Description', $description, PDO::PARAM_STR);
          $insertFeatureStmt->execute();

          // Insertar la relación en SubscriptionItems
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

      return [
        "Code" => $exists ? "PLAN_UPDATED" : "PLAN_CREATED",
        "Message" => $exists ? "Subscription plan updated successfully." : "Subscription plan created successfully."
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  /**
  * Activa/desactiva una feature globalmente.
  * @param string $featureCode
  * @param int $isActive 1 o 0
  * @return string Mensaje de resultado.
  * @throws DatabaseException
  */
  public function updateFeatureStatus($featureCode, $isActive) {
    try {
      $stmt = $this->db->prepare("UPDATE SubscriptionFeatures
                                  SET IsActive = :isActive
                                  WHERE FeatureCode = :featureCode");

      $stmt->bindParam(':isActive', $isActive, PDO::PARAM_INT);
      $stmt->bindParam(':featureCode', $featureCode, PDO::PARAM_STR);
      $stmt->execute();

      if ($stmt->rowCount() === 0) {
        throw new DatabaseException("No feature found with FeatureCode {$featureCode}.");
      }

      return "Feature {$featureCode} updated successfully.";
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}