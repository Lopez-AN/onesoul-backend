<?php
namespace App\Helpers;

use PHPMailer\PHPMailer\PHPMailer;

class EmailHelper {
  public static function send($toEmail, $subject, $template) {
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
      $mail->addAddress($toEmail);

      $mail->AddEmbeddedImage(ROOT.'/src/Templates/logo.png', 'logo');
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body = $template;

      $mail->send();
      return (object)[
        "sent" => true
      ];
    } catch (\Throwable $e) {
      return (object)[
        "sent" => false,
        "error" => $e
      ];
    }
  }
}
