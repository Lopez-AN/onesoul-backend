<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Utils\EmailHelper;

class Subscription
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

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

  public function getSubscriptionByUser($userID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Subscriptions 
                                  WHERE UserID = :userID
                                  AND (Status = 'ACTIVE' OR (Status = 'CANCELED'))
                                  ORDER BY StartDate DESC
                                  LIMIT 1");

      $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
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
  
  public function getUserSubscriptionByPlatformSubID($platformSubscriptionID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Subscriptions 
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND (Status = 'ACTIVE' OR (Status = 'CANCELED'))
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
 
  public function createConfirmedSubscription($userID, $planID, $platformSubscriptionID, $platformCustomerID, $subDomain = '', $userData = []) {
    try {
      // Cancelar suscripción anterior si existe
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                SET EndDate = CURDATE(), Status = 'CANCELED'
                                WHERE UserID = :userID AND Status = 'ACTIVE'");
      $stmt->execute(['userID' => $userID]);

      $today = date('Y-m-d');
      $trialStart = $userData['TrialStart'];
      $trialEnd = $userData['TrialEnd'];
      $trialSource = $userData['TrialSource'];
      $paymentPlatform = $userData['PaymentPlatform'];
      $trialEndDate = $trialEnd ? substr($trialEnd, 0, 10) : null;

      // TRIALING si el trial no terminó aún
      $status = ($trialEndDate && $trialEndDate >= $today) ? 'TRIALING' : 'ACTIVE';

      // Insertar nueva suscripción
      $stmt = $this->db->prepare("INSERT INTO Subscriptions (PlanID, UserID, TrialStart, TrialEnd, TrialSource, 
              StartDate, Status, PaymentPlatform, PlatformSubscriptionID, PlatformCustomerID)
              VALUES (:planID, :userID, :trialStart, :trialEnd, :trialSource, CURDATE(), :status, 
              :paymentPlatform, :platformSubscriptionID, :platformCustomerID)");

      $stmt->execute([
        ':planID' => $planID,
        ':userID' => $userID,
        ':trialStart' => $trialStart,
        ':trialEnd' => $trialEnd,
        ':trialSource' => $trialSource,
        ':status' => $status,
        ':paymentPlatform' => $paymentPlatform,
        ':platformSubscriptionID' => $platformSubscriptionID,
        ':platformCustomerID' => $platformCustomerID
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

  public function updateSubscriptionByUser($platformSubscriptionID, $newPlanID, $nextBillingDate = null) {
    try {
      // Actualizar la suscripción activa
      $stmt = $this->db->prepare("UPDATE Subscriptions 
                                  SET PlanID = :planID, NextBillingDate = :nextBillingDate
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND Status = 'ACTIVE'");
      $stmt->execute([
        'planID'                 => $newPlanID,
        'nextBillingDate'        => $nextBillingDate,
        'platformSubscriptionID' => $platformSubscriptionID
      ]);

      $subscription = $this->getUserSubscriptionByPlatformSubID($platformSubscriptionID);

      return [
        'Subscription' => $subscription
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

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

  public function getPaymentsByUser($userID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM SubscriptionsPayments
      WHERE userID = :userID");
      $stmt->execute(['userID' => $userID]);

      return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function markCancelAtPeriodEnd($platformSubscriptionID, $canceledAt, $nextBillingDate) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions 
                                  SET CancelAtPeriodEnd = 1, CancelAt = :canceledAt, NextBillingDate = :nextBillingDate
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID AND Status = 'ACTIVE'");
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

  public function cancelSubscription($platformSubscriptionID, $cancelAt) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions 
                                  SET Status = 'CANCELED', EndDate = :cancelAt
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID
                                  AND Status = 'ACTIVE'
                                  ORDER BY StartDate DESC LIMIT 1");
      $stmt->execute(['platformSubscriptionID' => $platformSubscriptionID]);
      $stmt->execute(['cancelAt' => $cancelAt]);

      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function handleTrialWillEnd($platformSubscriptionID, $trialEnd, $trialStart) {
    // Refleja el fin de trial informado por Stripe.
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET TrialStart = COALESCE(:trialStart, TrialStart), TrialEnd = COALESCE(:trialEnd, TrialEnd),
                                  TrialSource = 'STRIPE'
                                  WHERE PlatformSubscriptionID = :platformSubscriptionID");
      $stmt->execute([
        ':trialStart' => $trialStart,
        ':trialEnd' => $trialEnd,
        ':platformSubscriptionID' => $platformSubscriptionID
      ]);

      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

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

  public function markResumed($platformSubscriptionID, $nextBillingDate) {
    try {
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET Status = 'ACTIVE', NextBillingDate = :nextBillingDate,
                                  CancelAtPeriodEnd = 0, CancelAt = NULL
                                  WHERE PlatformSubscriptionID = :PlatformSubscriptionID
                                  AND (Status = 'PAUSED' OR Status = 'INCOMPLETE' OR 
                                  Status = 'ACTIVE' OR Status = 'PAST_DUE')");
      $stmt->execute([
        ':nextBillingDate' => $nextBillingDate,
        ':PlatformSubscriptionID' => $platformSubscriptionID
      ]);
      return $stmt->rowCount() > 0;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

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