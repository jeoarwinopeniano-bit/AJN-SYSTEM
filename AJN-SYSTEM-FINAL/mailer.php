<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

function sendEmailVerificationCode(string $email, string $username, string $code): void
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'andreyygozman@gmail.com';
        $mail->Password = preg_replace('/\\s+/', '', 'liwe iiml mqmb mkkf');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 20;
        $mail->setFrom('andreyygozman@gmail.com', 'AJN T-Shirts and Prints');
        $mail->addAddress($email, $username);
        $mail->isHTML(true);
        $mail->Subject = 'Your AJN email verification code';
        $mail->Body = '<p>Hello ' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
            . '<p>Your AJN email verification code is:</p>'
            . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Enter this code on the Verify Email page. It expires in 10 minutes.</p>'
            . '<p>If you did not create an AJN account, you can ignore this email.</p>';
        $mail->AltBody = "Hello {$username},\n\nYour AJN email verification code is: {$code}\nIt expires in 10 minutes.\n\nIf you did not create an AJN account, ignore this email.";
        $mail->send();
    } catch (Exception $e) {
        error_log('AJN verification-code email failed: ' . $mail->ErrorInfo);
        throw new RuntimeException('We could not send the verification code. Check the Gmail SMTP settings.');
    }
}
