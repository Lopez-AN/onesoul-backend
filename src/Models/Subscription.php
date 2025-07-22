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

  public function getSubscriptionByUser($userID) {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Subscriptions 
                                  WHERE UserID = :userID
                                  AND (Status = 'ACTIVE' OR (Status = 'CANCELED' AND RemainingDays > 0))
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

      $stmt->bindParam(':priceID', $priceID, PDO::PARAM_INT);
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

  // SUSCRIPCION DE UN USUARIO A UN PLAN 
  public function updateSubscriptionByUser($userID, $subDomain, $newPlanID = null)
  {
    try {
      // Buscar la suscripción activa
      $stmt = $this->db->prepare("SELECT SubscriptionID, PlanID, RemainingDays
                                FROM Subscriptions
                                WHERE UserID = :userID
                                AND Status = 'ACTIVE'
                                ORDER BY StartDate DESC
                                LIMIT 1");
      $stmt->execute(['userID' => $userID]);
      $currentSubscription = $stmt->fetch(PDO::FETCH_ASSOC);

      $currentPlanID = null;
      $remainingDaysNew = 30; // Default para nueva suscripción sin historial
      $nextBillingDateNew = date('Y-m-d', strtotime("+30 days"));
      $proportionalAmount = 0;
      $isUpgrade = false;

      if ($currentSubscription) {
        $currentPlanID = (int)$currentSubscription['PlanID'];
        $remainingDaysActual = (int)$currentSubscription['RemainingDays'];
        $subscriptionID  = (int)$currentSubscription['SubscriptionID'];


        // Obtener precios de los planes actual y nuevo
        $stmt = $this->db->prepare("SELECT PlanID, Price
                                  FROM SubscriptionPlans
                                  WHERE PlanID IN (:currentPlanID, :newPlanID)");
        $stmt->execute([
          'currentPlanID' => $currentPlanID,
          'newPlanID'     => $newPlanID
        ]);

        $prices = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $oldPrice = (float)($prices[$currentPlanID] ?? 0);
        $newPrice = (float)($prices[$newPlanID] ?? 0);

        // Si cambia de plan
        if ($newPlanID !== null && $newPlanID !== $currentPlanID) {
          if($newPrice > $oldPrice) {
            // Upgrade: cobrar proporcional y reiniciar
            $proportionalAmount = round((($newPrice - $oldPrice) / 30) * $remainingDaysActual, 2);
            $isUpgrade = true;

            $stmt = $this->db->prepare("UPDATE Subscriptions
                                        SET EndDate = CURDATE(),
                                        Status = 'CANCELED',
                                        RemainingDays = 0,
                                        NextBillingDate = CURDATE()
                                        WHERE SubscriptionID = :subscriptionID");
            $stmt->execute(['subscriptionID' => $subscriptionID]);

            // Nueva suscripción normal por 30 días
            $remainingDaysNew = 30;
            $nextBillingDateNew = date('Y-m-d', strtotime("+30 days"));

          } else {
            // Downgrade: extender días
            $remainingDaysNew = $remainingDaysActual + 30;
            $nextBillingDateNew = date('Y-m-d', strtotime("+$remainingDaysNew days"));

            // Cancelar anterior
            $stmt = $this->db->prepare("UPDATE Subscriptions
                                      SET EndDate = CURDATE(), Status = 'CANCELED'
                                      WHERE SubscriptionID = :subscriptionID");
            $stmt->execute(['subscriptionID' => $subscriptionID]);
          }
        }
      }

      $suscription = $this->getSubscriptionByUser($userID);
      return [
        'Suscription' => $suscription,
        'ProportionalCharge' => $proportionalAmount
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function createConfirmedSubscription($userID, $planID, $stripeSubscriptionID, $subDomain = '', $userData = [])
  {
    try {
      $remainingDays = 30;
      $nextBillingDate = date('Y-m-d', strtotime("+30 days"));

      // Cancelar suscripción anterior si existe
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                SET EndDate = CURDATE(), Status = 'CANCELED'
                                WHERE UserID = :userID AND Status = 'ACTIVE'");
      $stmt->execute(['userID' => $userID]);

      // Insertar nueva suscripción
      $stmt = $this->db->prepare("INSERT INTO Subscriptions (UserID, PlanID, StartDate, Status, RemainingDays, NextBillingDate, StripeID)
                                VALUES (:userID, :planID, CURDATE(), 'ACTIVE', :remainingDays, :nextBillingDate, :stripeSubscriptionID)");
      $stmt->execute([
        'userID' => $userID,
        'planID' => $planID,
        'remainingDays' => $remainingDays,
        'nextBillingDate' => $nextBillingDate,
        'stripeSubscriptionID' => $stripeSubscriptionID
      ]);

      // Marcar referido como exitoso si corresponde
      $stmt = $this->db->prepare("UPDATE Referrals 
                                SET ReferralStatus = 'Successful' 
                                WHERE ReferredUserID = :userID AND ReferralStatus = 'Pending'");
      $stmt->execute(['userID' => $userID]);

      $suscription = $this->getSubscriptionByUser($userID);

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
        'Suscription' => $suscription
      ];
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function cancelSubscription($stripeSubscriptionID, $subDomain)
  {
    try {
      $stmt = $this->db->prepare("SELECT * FROM Subscriptions 
                                  WHERE StripeID = :stripeSubscriptionID AND Status = 'ACTIVE'
                                  ORDER BY StartDate DESC LIMIT 1");
      $stmt->execute(['stripeSubscriptionID' => $stripeSubscriptionID]);
      $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$subscription) {
        throw new DatabaseException("Subscription not found with that StripeID.");
      }

      $subscriptionID = $subscription['SubscriptionID'];

      $stmt = $this->db->prepare("UPDATE Subscriptions 
                                  SET Status = 'CANCELED', EndDate = CURDATE()
                                  WHERE SubscriptionID = :subscriptionID");
      $stmt->execute(['subscriptionID' => $subscriptionID]);

      return true;
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }

  public function updateSubscriptionPlan($planID, $data)
  {
    try{
      $stmt = $this->db->prepare("SELECT COUNT(*) FROM SubscriptionPlans WHERE PlanID = :planID");
      $stmt->execute(['planID' => $planID]);
      $exists = $stmt->fetchColumn() > 0;

      if ($exists) {
        // 1. Si existe, actualizar el plan
        $stmt = $this->db->prepare("
          UPDATE SubscriptionPlans
          SET Name = :Name,
            Description = :Description,
            Beneficts = :Beneficts,
            Price = :Price,
            CurrencyCode = :CurrencyCode,
            Duration = :Duration,
            StripeID = :StripeID
          WHERE PlanID = :planID
        ");
      } else {
        // 2. Si no existe, insertar el plan
        $stmt = $this->db->prepare("
          INSERT INTO SubscriptionPlans (PlanID, Name, Description, Beneficts, Price, CurrencyCode, Duration, StripeID)
          VALUES (:planID, :Name, :Description, :Beneficts, :Price, :CurrencyCode, :Duration, :StripeID)
        ");
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

  public function updateFeatureStatus($featureCode, $isActive)
  {
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

  public function getPriceInfo($userID, $targetPlanID)
  {
    try {
      // Buscar suscripción actual activa o cancelada con días restantes
        $stmt = $this->db->prepare("SELECT s.PlanID, s.RemainingDays, sp.Name AS PlanName
                                    FROM Subscriptions s
                                    JOIN SubscriptionPlans sp ON sp.PlanID = s.PlanID
                                    WHERE s.UserID = :userID
                                    AND s.Status = 'ACTIVE'
                                    ORDER BY s.StartDate DESC
                                    LIMIT 1");
      $stmt->execute(['userID' => $userID]);
      $current = $stmt->fetch(PDO::FETCH_ASSOC);
  
      $actualPlanID = $current['PlanID'] ?? null;
      $actualPlanName = $current['PlanName'] ?? null;
      $remainingDays = (int)($current['RemainingDays'] ?? 0);
  
      // Obtener info del nuevo plan
      $stmt = $this->db->prepare("SELECT Name, Price FROM SubscriptionPlans WHERE PlanID = :planID");
      $stmt->execute(['planID' => $targetPlanID]);
      $targetPlan = $stmt->fetch(PDO::FETCH_ASSOC);
  
      if (!$targetPlan) {
        throw new DatabaseException("Target plan not found.");
      }
  
      $targetName = $targetPlan['Name'];
      $targetPrice = (float)$targetPlan['Price'];
      $proratedDiscount = 0;
  
      if ($actualPlanID && $actualPlanID != $targetPlanID) {
        // Obtener precio del plan actual
        $stmt = $this->db->prepare("SELECT Price FROM SubscriptionPlans WHERE PlanID = :planID");
        $stmt->execute(['planID' => $actualPlanID]);
        $actualPrice = (float)($stmt->fetchColumn() ?? 0);
  
        // Solo aplicar descuento si es upgrade (precio mayor)
        if ($targetPrice > $actualPrice) {
          $proratedDiscount = round((($targetPrice - $actualPrice) / 30) * $remainingDays, 2);
        }
      }
  
      return [
        'ActualPlan'        => $actualPlanName,
        'TargetPlan'        => $targetName,
        'FullPrice'         => $targetPrice,
        'ProratedDiscount'  => $proratedDiscount,
        'Total'             => round($targetPrice - $proratedDiscount, 2),
        'NextBillingDate'   => date('Y-m-d', strtotime('+30 days'))
      ];
  
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
}