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

  public function sendOtpSms(string $phoneNumber) {
    $phoneNumber = $this -> _fixArgPhone($phoneNumber);
    $this->client->verify->v2
      ->services($GLOBALS['config']['twilio']['sender_id'])
      ->verifications
      ->create($phoneNumber, 'sms');
  }

  public function validateOtpSms(string $phoneNumber, string $code) {
    $phoneNumber = $this -> _fixArgPhone($phoneNumber);
    $check = $this->client->verify->v2
      ->services($GLOBALS['config']['twilio']['sender_id'])
      ->verificationChecks
      ->create([
        'to' => $phoneNumber,
        'code' => $code
      ]);

    return $check->status === 'approved';
  }

  /**
   * Maneja excepciones específicas de Twilio
   */
  public function handleTwilioException(int $errorCode) {
    switch($errorCode){
      case 60200:
        # Codigo invalido o expirado
        return (object)[
          'status' => 401,
          "code" => "OTP_CODE_INVALID",
          "desc" => "Invalid OTP code"
        ];
      case 60202:
      case 60203:
        # Demasiados intentos
        return (object)[
          'status' => 429,
          "code" => "OTP_MAX_ATTEMPTS",
          "desc" => "Maximum OTP attempts reached."
        ];
      case 20404:
        # No hay verificación pendiente
        return (object)[
          'status' => 404,
          "code" => "OTP_CODE_NOT_FOUND",
          "desc" => "OTP code is not set. Please request a new OTP."
        ];
      case 21211:
      case 21614:
        # Telefonos invalidos
        return (object)[
          'status' => 400,
          'code' => 'PHONE_NUMBER_INVALID',
          'desc' => 'The phone number is invalid'
        ];
      case 21408:
        # Telefono no puede recibir sms
        return (object)[
          'status' => 422,
          'code' => 'PHONE_CANNOT_RECEIVE_SMS',
          'desc' => 'This phone number cannot receive SMS messages'
        ];
      default:
        return (object)[
          'status' => 500,
          "code" => "TWILIO_ERROR",
          "desc" => "Failed to verify code. Please try again"
        ];
    }
  }

  # Fix para los numeros argentinos, agregando el 9
  private function _fixArgPhone(string $phoneNumber){
    # Si el número empieza con +54 pero NO tiene +549, agregar el 9
    if (preg_match('/^\+54(?!9)/', $phoneNumber)) {
      $phoneNumber = preg_replace('/^\+54/', '+549', $phoneNumber);
    }

    return $phoneNumber;
  }
}