<?php
// 1. טעינת הקבצים שהעתקת בשלב הקודם לתיקיית PHPMailer
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * פונקציה מרכזית לשליחת מייל דרך המערכת
 */
function sendSystemEmail($toEmail, $subject, $messageBody) {
    // SMTP credentials are read from config.php (not committed) - see config.example.php
    $config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
    $mail = new PHPMailer(true);

    try {
        // --- הגדרות שרת השליחה (Gmail SMTP) ---
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        // פרטי ההתחברות שלך:
        $mail->Username   = $config['smtp_username'] ?? ''; // המייל השולח
        $mail->Password   = $config['smtp_password'] ?? '';    // קוד האפליקציה שקיבלת מגוגל
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // הגדרות שפה וקידוד (קריטי לעברית!)
        $mail->CharSet    = 'UTF-8';

        // --- הגדרות שולח ונמען ---
        $mail->setFrom($config['mail_from'] ?? ($config['smtp_username'] ?? ''), 'מערכת טעינה');
        $mail->addAddress($toEmail); 

        // --- תוכן המייל ---
        $mail->isHTML(true); 
        $mail->Subject = $subject;
        
        // הוספת ה-Meta Tag בתוך ה-Body כדי למנוע ג'יבריש בתיבת הדואר של המקבל
        $mail->Body    = "
            <html>
            <head>
                <meta content='text/html; charset=utf-8' http-equiv='Content-Type'>
            </head>
            <body>
                <div dir='rtl' style='font-family: Segoe UI, Arial, sans-serif; line-height: 1.6; color: #333;'>
                    $messageBody
                    <br><br>
                    <hr style='border: 0; border-top: 1px solid #eee;'>
                    <p style='font-size: 12px; color: #888;'>הודעה זו נשלחה באופן אוטומטי ממערכת הטעינה.</p>
                </div>
            </body>
            </html>";

        // השליחה בפועל
        $mail->send();
        return true;
    } catch (Exception $e) {
        // במקרה של שגיאה, הפונקציה תחזיר false
        return false;
    }
}
?>