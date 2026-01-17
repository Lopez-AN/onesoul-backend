<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Offering;
use PDO;

class SubscriptionEnforcementService {
  protected $db;
  protected $subscription;
  protected $offering;

  public function __construct(Subscription $subscription, Offering $offering) {
    $this->subscription = $subscription;
    $this->offering = $offering;
  }

  public function enforceOfferings($userID, $d = 0){
    # Controlo si no se excedio de los offerings maximos

    # Maximas publicaciones que puede tener
    $pubMax = $this->subscription->getUserSubscriptionFeature($userID, 'PUB_MAX')?->Value ?? 0;
    # Publicaciones activas actuales
    $activeOfferings = $this->offering->countUserActiveOfferings($userID);

    if($activeOfferings > $pubMax){
      $this->offering->disableUserOfferings($userID);
    }

    # Videos en publicaciones?
    $videos = $this->subscription->getUserSubscriptionFeature($userID, 'VIDEOS')?->Value ?? false;
    if(!$videos){
      $this->offering->disableUserOfferingsWithVideos($userID);
    }
  }
}