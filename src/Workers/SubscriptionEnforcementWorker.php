<?php

use App\Models\User;
use App\Models\Offering;
use App\Models\Subscription;
use App\Services\SubscriptionEnforcementService;

define('ROOT', dirname(__FILE__)."/../..");

# Inicializa config, database (pdo), etc
require ROOT . '/src/Workers/initWorker.php';

# Instanciar y ejectuar el worker
$worker = new SubscriptionEnforcementWorker(
  new User($pdo),
  new SubscriptionEnforcementService(new Subscription($pdo), new Offering($pdo))
);
$worker->run();

class SubscriptionEnforcementWorker {
  protected $user;
  protected $subscriptionEnforcementService;

  public function __construct(
    User $user, SubscriptionEnforcementService $subscriptionEnforcementService
  ) {
    $this->user = $user;
    $this->subscriptionEnforcementService = $subscriptionEnforcementService;
  }

  public function run() {
    print("📢 SubscriptionEnforcement iniciado...\n");

    try {
      $guides = $this->user->getGuidesWithActiveOfferings();
      if(!$guides){
        exit("⚠️ No guides with active offerings are found\n");
      }

      print("⌛ Checking ".count($guides)." guides\n");
      # Itero los guias y compruebo sus suscripciones
      foreach($guides as $g){
        print("✅ ".$g['UserID']." - ".$g['UserName']."\n");
        $this->subscriptionEnforcementService->enforceOfferings($g['UserID']);
      }
    } catch (\Throwable $e) {
      exit("❌ FAILED: {$e->getMessage()}\n");
    }
  }
}

