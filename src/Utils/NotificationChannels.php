<?php
namespace App\Utils;

use Exception;
use App\Utils\EmailHelper;

class NotificationChannels {
  public function sendNotificationByChannel($channel, $message, $recipient, $attempt = 1) {
    try {
      switch ($channel) {
        case 'IN_APP':
          return $this->sendInApp($message, $recipient);

        case 'PUSH':
          return $this->sendPush($message, $recipient);

        case 'EMAIL':
          return $this->sendEmail($message, $recipient);

        case 'SMS':
          return $this->sendSMS($message, $recipient);
    
        case 'WEB_PUSH':
          return $this->sendWebPush($message, $recipient);

        case 'WHATSAPP':
          return $this->sendWhatsapp($message, $recipient);

        default:
          throw new Exception("Canal no soportado: $channel");
      }
    } catch (Exception $e) {
      error_log("Error en canal $channel intento $attempt: " . $e->getMessage());
      return false;
    }
  }

  // Implementaciones simplificadas

  private function sendEmail($message, $recipient) {
    $data = json_decode($message, true);

    $subject      = 'Notificación';
    $toName       = $recipient;
    $templatePath = __DIR__ . '/../Templates/basic.html';

    // Base replacements + defaults
    $repl = [
      '{{year}}'     => date('Y'),
    ];

    if (is_array($data)) {
      if (isset($data['subject']))      $subject = (string)$data['subject'];
      if (isset($data['toName']))       $toName  = (string)$data['toName'];
      if (!empty($data['templatePath']))$templatePath = $data['templatePath'];

      // body: string o array (incluso anidado) -> HTML
      if (array_key_exists('body', $data)) {
        $repl['{{body}}'] = $this->toHtml($data['body']);
      }

      // replacements extra (aplanar cualquier cosa a string HTML)
      if (!empty($data['replacements']) && is_array($data['replacements'])) {
        foreach ($data['replacements'] as $k => $v) {
          $repl[$k] = $this->toHtml($v);
        }
      }
    } else {
      // Mensaje plano
      $repl['{{body}}'] = (string)$message;
    }

    // inyectar subject al template básico
    $repl['{{subject}}'] = $subject;

    EmailHelper::send($toName, $recipient, $subject, $templatePath, $repl, true);
    return true;
  }

  // // Aplana valores a HTML seguro (arrays anidados, bools, objetos)
  // private function toHtml($value) {
  //   if (is_array($value)) {
  //     $parts = [];
  //     foreach ($value as $v) { $parts[] = $this->toHtml($v); }
  //     return implode('<br>', $parts);
  //   }
  //   if (is_bool($value))  return $value ? 'true' : 'false';
  //   if (is_object($value)) return htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  //   return (string)$value;
  // }


  private function sendInApp($message, $recipient) { return true; }
  private function sendPush($message, $recipient) { return true; }
  private function sendSMS($message, $recipient) { return true; }
  private function sendWebPush($message, $recipient) { return true; }
  private function sendWhatsapp($message, $recipient) { return true; }

}