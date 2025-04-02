<?php

namespace App\Models;

use PDO;
use App\Exceptions\DatabaseException;

class Subscription
{
  protected $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  public function getSubscriptionPlans() {
    try {
      $query = "SELECT sp.PlanID, sp.Name, sp.Description, sp.Beneficts, sp.Price, sp.CurrencyCode, sp.Duration,
                    sf.FeatureCode, sf.Description AS FeatureDescription
                FROM SubscriptionPlans AS sp
                LEFT JOIN SubscriptionItems AS si ON sp.PlanID = si.PlanID
                LEFT JOIN SubscriptionFeatures AS sf ON si.FeatureCode = sf.FeatureCode
                ORDER BY sp.PlanID, sf.FeatureCode";

      $stmt = $this->db->prepare($query);
      $stmt->execute();
      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Organizar los planes en un array estructurado
      $plans = [];
      foreach ($results as $row) {
        $planId = $row['PlanID'];

        // Si el plan no está en el array, inicializarlo
        if (!isset($plans[$planId])) {
          $plans[$planId] = [
            "PlanId"       => $row['PlanID'],
            "Name"         => $row['Name'],
            "Description"  => $row['Description'],
            "Beneficts"    => $row['Beneficts'],
            "Price"        => $row['Price'],
            "CurrencyCode" => $row['CurrencyCode'],
            "Duration"     => $row['Duration'],
            "features"     => []
          ];
        }

        // Agregar las características solo si existen
        if (!empty($row['FeatureCode'])) {
          $plans[$planId]['features'][] = [
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

}  