<?php

use App\Models\Cal;

define('ROOT', dirname(__FILE__)."/../..");

# Inicializa config, database (pdo), etc
require ROOT . '/src/Workers/initWorker.php';

$worker = new CalWebhookCleanupWorker(new Cal($pdo), $redis ?? null);
$worker->run(
  intval($argv[1] ?? 5),
  intval($argv[2] ?? 100)
);

class CalWebhookCleanupWorker {
  protected $cal;
  protected $redis;

  public function __construct(Cal $cal, $redis = null) {
    $this->cal = $cal;
    $this->redis = $redis;
  }

  public function run($olderThanMinutes = 5, $limit = 100) {
    $olderThanMinutes = max(1, intval($olderThanMinutes));
    $limit = max(1, intval($limit));

    print("📢 CalWebhookCleanupWorker iniciado (olderThan={$olderThanMinutes}m, limit={$limit})...\n");

    $rows = $this->cal->getStaleUnlinkedWebhookBookings($olderThanMinutes, $limit);
    if(empty($rows)){
      print("✅ No hay webhooks huérfanos para limpiar\n");
      return;
    }

    foreach($rows as $row){
      $uid = $row['Uid'];
      $calUserID = $row['CalUserID'];

      try {
        $calUser = $this->cal->getCalUserByCalID($calUserID);
        if(!$calUser){
          $this->cal->deleteWebhookByUid($uid);
          print("🧹 $uid eliminado (sin CalConnection)\n");
          continue;
        }

        $tokenData = $this->_getValidAccessToken($calUser);
        if(!$tokenData['valid']){
          print("⚠️ $uid no se pudo cancelar: {$tokenData['error']}\n");
          continue;
        }

        $cancelResult = $this->_cancelBooking($uid, $tokenData['access_token']);
        if(!$cancelResult['valid']){
          print("⚠️ $uid cancel API error: {$cancelResult['error']}\n");
          continue;
        }

        $this->cal->deleteWebhookByUid($uid);
        print("✅ $uid cancelado en Cal y limpiado local\n");
      } catch (\Throwable $e) {
        print("❌ $uid fallo: {$e->getMessage()}\n");
      }
    }
  }

  private function _getValidAccessToken($calUser){
    $accessToken = $calUser['AccessToken'] ?? null;
    $refreshToken = $calUser['RefreshToken'] ?? null;
    $calUserID = $calUser['CalUserID'] ?? null;

    if(empty($refreshToken) || empty($calUserID)){
      return [
        'valid' => false,
        'error' => 'missing_refresh_or_cal_user_id'
      ];
    }

    if($accessToken && $this->_isJwtValid($accessToken)){
      return [
        'valid' => true,
        'access_token' => $accessToken
      ];
    }

    $clientId = $GLOBALS['config']['cal']['client_id'];
    $secret = $GLOBALS['config']['cal']['secret'];

    $lockKey = "cal:refresh_lock:$calUserID";
    if(!$this->_acquireRefreshLock($lockKey, 20)){
      return [
        'valid' => false,
        'error' => 'refresh_in_progress'
      ];
    }

    try {
      # Releo desde base por posible rotación previa en otra request
      $freshCalUser = $this->cal->getCalUserByCalID($calUserID);
      if($freshCalUser){
        $refreshToken = $freshCalUser['RefreshToken'] ?? $refreshToken;
        $accessToken = $freshCalUser['AccessToken'] ?? $accessToken;

        if($accessToken && $this->_isJwtValid($accessToken)){
          return [
            'valid' => true,
            'access_token' => $accessToken
          ];
        }
      }

      $result = $this->_calRequest(
        'POST',
        '/v2/auth/oauth2/token',
        [
          'Content-Type: application/json'
        ],
        json_encode([
          'client_id' => $clientId,
          'client_secret' => $secret,
          'grant_type' => 'refresh_token',
          'refresh_token' => $refreshToken
        ])
      );

      if(!$result['valid']){
        return $result;
      }

      $newAccessToken = $result['response']->access_token ?? null;
      if(empty($newAccessToken)){
        return [
          'valid' => false,
          'error' => 'refresh_response_without_access_token'
        ];
      }

      $newRefreshToken = $result['response']->refresh_token ?? $refreshToken;
      $this->cal->updateCalUserTokens($calUserID, $newAccessToken, $newRefreshToken);

      return [
        'valid' => true,
        'access_token' => $newAccessToken
      ];
    } finally {
      $this->_releaseRefreshLock($lockKey);
    }
  }

  private function _cancelBooking($uid, $accessToken){
    $payload = [
      'cancellationReason' => 'Auto-cancel: booking not confirmed in OneSoul within 5 minutes'
    ];

    $result = $this->_calRequest(
      'POST',
      "/v2/bookings/{$uid}/cancel",
      [
        "Authorization: Bearer {$accessToken}",
        'Content-Type: application/json',
        'cal-api-version: 2024-08-13'
      ],
      json_encode($payload)
    );

    if($result['valid']){
      return $result;
    }

    $calError = $result['response']->error_description ?? $result['error'] ?? '';
    if(in_array($calError, ['booking_not_found', 'already_cancelled'])){
      return [
        'valid' => true,
        'response' => null
      ];
    }

    return $result;
  }

  private function _isJwtValid($token){
    try {
      $parts = explode('.', $token);
      if(count($parts) < 2){
        return false;
      }
      $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')));
      $exp = intval($payload->exp ?? 0);
      return $exp > (time() + 30);
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function _calRequest($method, $path, $headers, $postData = null){
    $ch = curl_init("https://api.cal.com{$path}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if(in_array($method, ['POST','PATCH','PUT'])){
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw);
    if($errno || ($httpCode >= 400 && $httpCode <= 599)){
      return [
        'valid' => false,
        'error' => $errno ? "curl_error: {$error}" : "http_error: {$httpCode}",
        'response' => $json
      ];
    }

    return [
      'valid' => true,
      'response' => $json
    ];
  }

  private function _acquireRefreshLock($key, $ttlSeconds = 20){
    if(!$this->redis){
      return true;
    }

    try {
      return $this->redis->set($key, '1', 'EX', $ttlSeconds, 'NX') === 'OK';
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function _releaseRefreshLock($key){
    if(!$this->redis){
      return;
    }

    try {
      $this->redis->del([$key]);
    } catch (\Throwable $e) {
      // no-op
    }
  }
}
