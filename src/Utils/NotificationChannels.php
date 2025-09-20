<?php

function sendNotificationByChannel($channel, $message, $recipient, $attempt = 1)
{
  try {
    switch ($channel) {
      case 'IN_APP':
        return sendInApp($message, $recipient);

      case 'PUSH':
        return sendPush($message, $recipient);

      case 'EMAIL':
        return sendEmail($message, $recipient);

      case 'SMS':
        return sendSMS($message, $recipient);
  
      case 'WEB_PUSH':
        return sendWebPush($message, $recipient);

      case 'WHATSAPP':
        return sendWhatsapp($message, $recipient);

      default:
        throw new Exception("Canal no soportado: $channel");
    }
  } catch (Exception $e) {
    error_log("Error en canal $channel intento $attempt: " . $e->getMessage());
    return false;
  }
}

// Implementaciones simplificadas
function sendInApp($message, $recipient) { return true; }
function sendPush($message, $recipient) { return true; }
function sendEmail($message, $recipient) { return true; }
function sendSMS($message, $recipient) { return true; }
function sendWebPush($message, $recipient) { return true; }
function sendWhatsapp($message, $recipient) { return true; }