<?php
session_start();
include 'email_helper.php'; // טעינת פונקציית המייל שבנינו בשלב 3

// הגדרות חיבור למסד הנתונים
$servername = "localhost";
$username = "root";
$password_db = "";
$dbname = "charging_station_db";

$conn = new mysqli($servername, $username, $password_db, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$message = "";
$success_message = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);

    if (empty($email)) {
        $message = "יש להזין כתובת אימייל.";
    } else {
        // בדיקה אם המשתמש קיים
        $stmt = $conn->prepare("SELECT uid, name FROM user WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // 1. יצירת סיסמה זמנית אקראית (8 תווים)
            $temp_password = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);
            
            // 2. עדכון הסיסמה בבסיס הנתונים (טקסט רגיל כפי שביקשת במערכת כרגע)
            $update_stmt = $conn->prepare("UPDATE user SET password = ? WHERE email = ?");
            $update_stmt->bind_param("ss", $temp_password, $email);
            
            if ($update_stmt->execute()) {
                // 3. שליחת המייל עם הסיסמה החדשה
                $subject = "איפוס סיסמה - מערכת טעינה";
                $body = "
                    <h2>שלום " . htmlspecialchars($user['name']) . ",</h2>
                    <p>קיבלנו בקשה לאיפוס הסיסמה שלך.</p>
                    <p>הסיסמה החדשה והזמנית שלך היא: <strong style='font-size: 18px; color: #2e7d32;'>" . $temp_password . "</strong></p>
                    <p>אנו ממליצים להתחבר ולשנות את הסיסמה בהקדם בהגדרות החשבון.</p>
                    <p><a href='http://localhost/login.php' style='color: #2e7d32;'>לחץ כאן להתחברות</a></p>
                ";

                if (sendSystemEmail($email, $subject, $body)) {
                    $message = "סיסמה חדשה נשלחה לתיבת המייל שלך.";
                    $success_message = true;
                } else {
                    $message = "הסיסמה שונתה, אך הייתה תקלה בשליחת המייל. פנה לתמיכה.";
                }
            }
            $update_stmt->close();
        } else {
            // למען האבטחה, לפעמים כדאי להציג הודעה כללית, אבל כאן נהיה ישירים
            $message = "כתובת האימייל לא נמצאה במערכת.";
        }
        $stmt->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
     <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <!-- התיקון הקריטי לתצוגה בנייד -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>איפוס סיסמה</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(to bottom, #e8f5e9, #ffffff);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 15px; /* מרווח מהקצוות בנייד */
        }
        
        .container {
            max-width: 420px;
            width: 100%; /* התאמה אוטומטית לרוחב המסך */
            background: #ffffff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        h1 { color: #2e7d32; margin-bottom: 15px; font-size: 26px; }
        
        p { color: #666; margin-bottom: 25px; font-size: 15px; line-height: 1.5; }
        
        .input-group { position: relative; width: 100%; margin-bottom: 20px; }
        
        .input-group i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(0, 0, 0, 0.25);
            font-size: 18px;
        }
        
        input {
            width: 100%;
            padding: 14px 45px 14px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            background-color: #fafafa;
            outline: none;
            transition: all 0.3s ease;
        }

        input:focus {
            border-color: #2e7d32;
            background-color: #fff;
            box-shadow: 0 0 8px rgba(46,125,50,0.15);
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: #2e7d32;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        
        .btn:hover { background: #1b5e20; }
        
        .message {
            margin-bottom: 20px;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            background-color: <?php echo $success_message ? '#e8f5e9' : '#ffebee'; ?>;
            color: <?php echo $success_message ? '#2e7d32' : '#d32f2f'; ?>;
            border: 1px solid <?php echo $success_message ? '#c8e6c9' : '#ffcdd2'; ?>;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 25px;
            color: #2e7d32;
            text-decoration: none;
            font-size: 15px;
            font-weight: bold;
        }
        
        .back-link:hover { text-decoration: underline; }

        /* התאמות נוספות למסכים קטנים מאוד */
        @media (max-width: 480px) {
            .container { padding: 20px; }
            h1 { font-size: 22px; }
            p { font-size: 14px; }
            input { font-size: 14px; }
        }
    </style>
</head>
<body>

<div class="container">
    <h1>שכחת סיסמה?</h1>
    <p>הזן את כתובת האימייל איתה נרשמת, ונשלח לך סיסמה חדשה להתחברות.</p>

    <?php if ($message): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <i class="fa-solid fa-envelope"></i>
            <input type="email" name="email" placeholder="הכנס אימייל" required>
        </div>
        <button class="btn" type="submit">שלח לי סיסמה חדשה</button>
    </form>

    <a href="login.php" class="back-link">חזרה לדף התחברות</a>
</div>

</body>
</html>