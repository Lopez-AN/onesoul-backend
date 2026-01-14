<?php

namespace App\Services;

use Twilio\Rest\Client;
use Exception;

class TwilioService{
  private $client;
  private string $verifyServiceSid;

  public function __construct() {
    # Inicializo cliente de twilio
    $this->client = new Client($GLOBALS['config']['twilio']['sid'], $GLOBALS['config']['twilio']['token']);
  }

  /**
   * Enviar WhatsApp usando Content Template (PRODUCCIÓN)
   *
   * @param string $to Número destino con código de país (+549...)
   * @param string $templateSid SID del template aprobado (ej: HXxxxx...)
   * @param array $variables Variables del template ['nombre', 'valor', ...]
   * @param string $mediaUrl URL de imagen (opcional)
   */
  public function sendWhatsAppTemplate($to, $templateSid, $variables = [], $mediaUrl = null) {
    try {
      if (strpos($to, 'whatsapp:') !== 0) {
        $to = "whatsapp:$to";
      }

      $params = [
        'from' => "whatsapp:".$GLOBALS['config']['twilio']['number'],
        'contentSid' => $templateSid,
        'contentVariables' => json_encode($variables)
      ];

      if ($mediaUrl) {
        $params['mediaUrl'] = [$mediaUrl];
      }

      $result = $this->client->messages->create($to, $params);

      return (object)[
        'sent' => true,
        'message_sid' => $result->sid,
        'status' => $result->status
      ];
    } catch (Exception $e) {
      return (object)[
        'sent' => false,
        'error' => $e
      ];
    }
  }

  public function sendWhatsappOTP(string $phone): void {
    $this->client->verify->v2
      ->services("VAfb4ea2603d28b40a8426628f5b99d6c7")
      ->verifications
      ->create($phone, 'whatsapp');
  }

  public function checkWhatsappOTP(string $phone, string $code): bool {
    $check = $this->client->verify->v2
      ->services("VAfb4ea2603d28b40a8426628f5b99d6c7")
      ->verificationChecks
      ->create([
        'to' => $phone,
        'code' => $code
      ]);

    return $check->status === 'approved';
  }
}