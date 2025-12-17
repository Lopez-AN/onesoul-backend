<?php

namespace App\Services;

use Twilio\Rest\Client;
use Exception;

class TwilioService{
  private $client;

  public function __construct() {
    # Inicializo cliente de twilio
    $this->client = new Client($GLOBALS['config']['twilio']['sid'], $GLOBALS['config']['twilio']['token']);
  }
  /**
   * Enviar WhatsApp
   */
  public function sendWhatsApp($to, $subject, $body, $mediaUrl = null) {
    try {
      # Agregar prefijo whatsapp: si no lo tiene
      if (strpos($to, 'whatsapp:') !== 0) {
        $to = "whatsapp:$to";
      }

      $templateSid = "HX657d04dbb5d124ca1c43b9b70d363b4c";

      $params = [
        'from' => "whatsapp:".$GLOBALS['config']['twilio']['number'],
        'contentSid' => $templateSid
      ];

      if ($mediaUrl) {
        $params['mediaUrl'] = [$mediaUrl];
      }

      $result = $this->client->messages->create($to, $params);

      return (object)[
        'sent' => true,
        'message_sid' => $result->sid,
        'status' => $result->status,
        'template_sid' => $templateSid
      ];
    } catch (Exception $e) {
      return (object)[
        'sent' => false,
        'error' => $e
      ];
    }
  }
}