<?php
namespace App\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body = $template;

      if ($embedLogo) {
        $logoPath = ROOT . "/src/templates/logo2.png";
        if (file_exists($logoPath)) {
          $mail->addEmbeddedImage($logoPath, 'logo');
        }
      }

      $mail->send();
    } catch (Exception $e) {
      error_log("Error al enviar email: " . $mail->ErrorInfo);
    }
  }
}
