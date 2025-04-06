<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Subscription
{
  protected $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function getSubscriptionPlans() {
    try {
      $stmt = $this->db->prepare("SELECT sp.PlanID, sp.Name, sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
                    sf.FeatureCode, sf.Description AS FeatureDescription
                FROM SubscriptionPlans AS sp
                LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
                LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
                ORDER BY sp.PlanID, sf.FeatureCode");

      $stmt->execute();
      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Organizar los planes en un array estructurado
      $plans = [];
      foreach ($results as $row) {
        $planId = $row['PlanID'];

        // Si el plan no está en el array, inicializarlo
        if (!isset($plans[$planId])) {
          $plans[$planId] = [
            "PlanID"       => $row['PlanID'],
            "Name"         => $row['Name'],
            "Description"  => $row['Description'],
            "Beneficts"    => $row['Beneficts'],
            "Price"        => $row['Price'],
            "CurrencyCode" => $row['CurrencyCode'],
            "Duration"     => $row['Duration'],
            "Features"     => []
          ];
        }

        // Agregar las características solo si existen
        if (!empty($row['FeatureCode'])) {
          $plans[$planId]['Features'][] = [
            "FeatureCode"  => $row['FeatureCode'],
            "Description"  => $row['FeatureDescription']
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
      $stmt = $this->db->prepare("SELECT sp.PlanID, sp.Name, sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
                                sf.FeatureCode, sf.Description AS FeatureDescription
                                FROM SubscriptionPlans AS sp
                                LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
                                LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
                                WHERE sp.PlanID = :id
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
        "Name"         => $rows[0]['Name'],
        "Description"  => $rows[0]['Description'],
        "Beneficts"    => $rows[0]['Beneficts'],
        "Price"        => $rows[0]['Price'],
        "CurrencyCode" => $rows[0]['CurrencyCode'],
        "Duration"     => $rows[0]['Duration'],
        "Features"     => []
      ];

      // Agregar las features
      foreach ($rows as $row) {
        if (!empty($row['FeatureCode'])) {
          $subscription['Features'][] = [
            "FeatureCode"  => $row['FeatureCode'],
            "Description"  => $row['FeatureDescription']
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
      $stmt = $this->db->prepare("SELECT SubscriptionID, UserID, PlanID, StartDate, EndDate, TrialPeriod
                                  FROM Subscriptions
                                  WHERE UserID = :userID");
    
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
            Duration = :Duration
          WHERE PlanID = :planID
        ");
      } else {
        // 2. Si no existe, insertar el plan
        $stmt = $this->db->prepare("
          INSERT INTO SubscriptionPlans (PlanID, Name, Description, Beneficts, Price, CurrencyCode, Duration)
          VALUES (:planID, :Name, :Description, :Beneficts, :Price, :CurrencyCode, :Duration)
        ");
      }

      $stmt->bindParam(':planID', $planID, PDO::PARAM_INT);
      $stmt->bindParam(':Name', $data['Name'], PDO::PARAM_STR);
      $stmt->bindParam(':Description', $data['Description'], PDO::PARAM_STR);
      $stmt->bindParam(':Beneficts', $data['Beneficts'], PDO::PARAM_STR);
      $stmt->bindParam(':Price', $data['Price']);
      $stmt->bindParam(':CurrencyCode', $data['CurrencyCode'], PDO::PARAM_STR);
      $stmt->bindParam(':Duration', $data['Duration'], PDO::PARAM_INT);
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
        $insertItemStmt = $this->db->prepare("INSERT INTO SubscriptionItems (PlanID, FeatureCode)
                                              VALUES (:planID, :FeatureCode)");

        foreach ($data['Features'] as $feature) {
          // Insertar en SubscriptionFeatures si no existe
          $featureCode = $feature['FeatureCode'];
          $description = $feature['Description'];

          $insertFeatureStmt->bindParam(':FeatureCode', $featureCode, PDO::PARAM_STR);
          $insertFeatureStmt->bindParam(':Description', $description, PDO::PARAM_STR);
          $insertFeatureStmt->execute();

          // Insertar la relación en SubscriptionItems
          $insertItemStmt->bindParam(':planID', $planID, PDO::PARAM_INT);
          $insertItemStmt->bindParam(':FeatureCode', $featureCode, PDO::PARAM_STR);
          $insertItemStmt->execute();
        }
      }

      return [
        "success" => true,
        "message" => $exists ? "Subscription plan updated successfully." : "Subscription plan created successfully."
      ];
    
    } catch (\PDOException $e) {
      throw new DatabaseException($e->getMessage());
    }
  }
  
  public function updateSubscriptionByUser($userID, $newPlanID = null)
  {
    try {
      // Buscar la suscripción activa del usuario
      $stmt = $this->db->prepare("SELECT SubscriptionID, PlanID
                                  FROM Subscriptions
                                  WHERE UserID = :userID
                                  AND EndDate IS NULL");

      $stmt->execute(['userID' => $userID]);
      $currentSubscription = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$currentSubscription) {
        throw new DatabaseException("No active subscription found for UserID {$userID}.");
      }

      // Cerrar la suscripción actual
      $stmt = $this->db->prepare("UPDATE Subscriptions
                                  SET EndDate = CURDATE()
                                  WHERE SubscriptionID = :subscriptionID");

      $stmt->execute(['subscriptionID' => $currentSubscription['SubscriptionID']]);

      $currentPlanID = (int)$currentSubscription['PlanID'];

      // Si el nuevo PlanID es diferente al actual, crear nueva subscripción
      if ($newPlanID !== null && $newPlanID !== $currentPlanID) {
        $stmt = $this->db->prepare("INSERT INTO Subscriptions (UserID, PlanID, StartDate)
                                    VALUES (:userID, :planID, CURDATE())");

        $stmt->execute([
          ':userID' => $userID,
          ':planID' => $newPlanID,
        ]);
      }

      return 'Subscription created successfully.';
  
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
}  