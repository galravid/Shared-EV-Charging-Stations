<?php
session_start(); // חובה להתחיל session כדי לזכור את המשתמש המחובר

// הגדרות חיבור למסד הנתונים
$servername = "localhost";
$username = "root";
$password_db = "";
$dbname = "charging_station_db";

// יצירת חיבור
$conn = new mysqli($servername, $username, $password_db, $dbname);

// בדיקת חיבור
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$message = "";
$success_message = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email    = trim($_POST["email"]);
    $password = trim($_POST["password"]);

    if (empty($email) || empty($password)) {
        $message = "יש למלא את כל השדות.";
    } else {
        // הכנת השאילתה למניעת SQL Injection
        $stmt = $conn->prepare("SELECT uid, name, password FROM user WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            
            // בדיקת סיסמה
            if ($password === $row['password']) {
                
                // --- התחברות מוצלחת ---
                $_SESSION['user_id'] = $row['uid'];
                $_SESSION['user_name'] = $row['name'];

                $message = "התחברת בהצלחה! מעביר אותך למפה...";
                $success_message = true;

                // הפניה גורפת לכל המשתמשים לדף המפה
                header("refresh:1;url=map.php"); 
                
            } else {
                $message = "אימייל או סיסמה שגויים.";
            }
        } else {
            $message = "אימייל או סיסמה שגויים.";
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
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(to bottom, #e8f5e9, #ffffff);
            padding: 15px;
            text-align: center;
            min-height: 100vh;
            display: flex;
            flex-direction: column; 
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 420px;
            width: 100%; /* מבטיח שהמכולה תהיה רספונסיבית */
            background: #ffffff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .login-image-container {
            width: 100%;
            height: 180px;
            overflow: hidden;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .login-image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        h1 {
            color: #2e7d32;
            margin-bottom: 25px;
            font-size: 26px;
        }

        .input-group {
            position: relative;
            width: 100%;
            margin-bottom: 15px;
        }

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
            transition: all 0.3s ease;
        }

        input:focus {
            border-color: #2e7d32;
            background-color: #fff;
            outline: none;
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
            margin-top: 10px;
        }

        .btn:hover {
            background: #1b5e20;
            transform: translateY(-1px);
        }

        .message {
            margin-bottom: 20px;
            padding: 12px;
            background-color: <?php echo $success_message ? '#e8f5e9' : '#ffebee'; ?>; 
            color: <?php echo $success_message ? '#2e7d32' : '#d32f2f'; ?>;
            border-radius: 8px;
            font-weight: 600;
            border: 1px solid <?php echo $success_message ? '#c8e6c9' : '#ffcdd2'; ?>;
        }

        a {
            display: inline-block;
            margin-top: 20px;
            color: #2e7d32;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
        }
        
        a:hover {
            text-decoration: underline;
        }

        .forgot-link {
            font-size: 14px;
            margin-top: 15px;
            display: block;
        }

        .footer-links {
            margin-top: 30px;
            display: flex;
            justify-content: center;
            gap: 20px;
            width: 100%;
        }

        .footer-links span {
            color: #2e7d32;
            font-size: 13px;
            cursor: pointer;
            font-weight: bold;
        }

        /* מודאלים */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center; justify-content: center;
            z-index: 2000;
            padding: 15px;
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 450px;
            padding: 30px;
            border-radius: 20px;
            position: relative;
            text-align: right;
        }

        .close-modal {
            position: absolute;
            top: 15px; left: 15px;
            background: #f0f0f0; border: none;
            width: 30px; height: 30px;
            border-radius: 50%; cursor: pointer;
        }

        /* התאמה ספציפית לניידים קטנים */
        @media (max-width: 480px) {
            h1 { font-size: 22px; }
            .container { padding: 20px; }
            .login-image-container { height: 150px; }
            input { font-size: 14px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="login-image-container">
        <img src="login_image.jpeg" alt="Login Image">
    </div>
<br>
    <?php if ($message): ?>
        <div class="message"><?php echo $message; ?><br></div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <i class="fa-solid fa-envelope"></i>
            <input type="email" name="email" placeholder="אימייל" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
        </div>

        <div class="input-group">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="password" placeholder="סיסמה" required>
        </div>

        <button class="btn" type="submit">התחברות</button>
    </form>

    <a href="forgot_password.php" class="forgot-link">שכחת סיסמה?</a>
    <a href="signup.php">אין לך משתמש עדיין? לחץ להרשמה</a>
</div>

<div class="footer-links">
    <span onclick="toggleModal('terms')">תנאי שימוש</span>
    <span onclick="toggleModal('privacy')">מדיניות פרטיות</span>
    <span onclick="toggleModal('contact')">צור קשר</span>
</div>

<div id="modalOverlay" class="modal-overlay" onclick="toggleModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <button class="close-modal" onclick="toggleModal()">&times;</button>
        <div id="modalBody"></div>
    </div>
</div>

<script>
    function toggleModal(type) {
        const modal = document.getElementById('modalOverlay');
        const body = document.getElementById('modalBody');
        
        if (!type) {
            modal.style.display = 'none';
            return;
        }

        let content = '';
        if (type === 'terms') {
            content = '<h2>תנאי שימוש</h2><p>השימוש במערכת ניהול הטעינה מותנה בהסכמה לתנאי השירות. האחריות על תקינות העמדה היא על המשתמשים בלבד.</p>';
        } else if (type === 'privacy') {
            content = '<h2>מדיניות פרטיות</h2><p>אנו שומרים על פרטיותך. המידע שנאסף משמש אך ורק לצרכי תפעול המערכת.</p>';
        } else if (type === 'contact') {
            content = '<h2>צור קשר</h2><p>תמי גלמן<br>גל רביד</p>';
        }

        body.innerHTML = content;
        modal.style.display = 'flex';
    }
    window.addEventListener('keydown', (e) => { if (e.key === 'Escape') toggleModal(); });
</script>

</body>
</html>