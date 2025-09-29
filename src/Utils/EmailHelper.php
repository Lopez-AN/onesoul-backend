<?php
namespace App\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class EmailHelper {
  public static function send($toName, $toEmail, $subject, $templatePath, $replacements = [], $embedLogo = true)
  {
    if (!$toEmail || !file_exists($templatePath)) return;

    $template = file_get_contents($templatePath);
    foreach ($replacements as $key => $value) {
      $template = str_replace($key, htmlspecialchars($value), $template);
    }

    $smtpAccount = $GLOBALS['config']['mailer']['account'];
    $smtpPassword = $GLOBALS['config']['mailer']['password'];

    $mail = new PHPMailer(true);
    try {
      $mail->isSMTP();
      $mail->Host = 'smtp.gmail.com';
      $mail->SMTPAuth = true;
      $mail->Username = $smtpAccount;
      $mail->Password = $smtpPassword;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port = 587;

      $mail->CharSet = 'UTF-8';
      $mail->Encoding = 'base64';

      $mail->setFrom($smtpAccount, 'Contacto OneSoul');
      $mail->addAddress($toEmail, $toName);

      // Embeder logo sólo si existe (sin ROOT)
      $projectRoot = dirname(__DIR__, 2);
      $logoCandidates = [
        $projectRoot . '/src/templates/logo1.png',
        $projectRoot . '/src/templates/logo2.png',
      ];
      foreach ($logoCandidates as $p) {
        if (is_file($p)) {
          $mail->AddEmbeddedImage($p, 'logo');
          break;
        }
      }

      // Cargar template (fallback simple si no existe)
      $html = is_file($templatePath) ? file_get_contents($templatePath) : '<!doctype html><html><body>{{body}}</body></html>';

      // Aplicar replacements
      if (!empty($replacements)) {
        $html = strtr($html, $replacements);
      }

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body = $html;

      $mail->send();
    } catch (Exception $e) {
      error_log("Error al enviar email: " . $mail->ErrorInfo);
    }
  }
}
