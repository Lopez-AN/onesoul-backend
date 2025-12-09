<?php
namespace App\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailHelper {
  public static function send($toName, $toEmail, $subject, $template) {
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

      $mail->AddEmbeddedImage(ROOT.'/src/templates/logo.png', 'logo');
      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body = $html;

      $mail->send();
      return (object)[
        "success" => true
      ];
    } catch (Exception $e) {
      return (object)[
        "success" => false,
        "error" => $e
      ];
    }
  }
}
