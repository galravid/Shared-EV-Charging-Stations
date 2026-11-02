<?php
session_start(); // הפעלת סשן לשמירת הנתונים בין השלבים

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // קליטת פרטי שלב 1
    $name      = trim($_POST["name"]);
    $email     = trim($_POST["email"]);
    $phone     = trim($_POST["phone"]);
    $password  = trim($_POST["password"]);
    $confirm   = trim($_POST["confirm"]);

    // וולידציה
    if (empty($name) || empty($email) || empty($password) || empty($confirm) || empty($phone)) {
        $message = "אנא מלא את כל השדות.";
    } elseif (preg_match('~[0-9]~', $name)) {
        $message = "השם המלא לא יכול לכלול מספרים.";
    } elseif (!preg_match('/^05\d{8}$/', $phone)) {
        $message = "מספר הטלפון חייב לכלול 10 ספרות ולהתחיל ב-05.";
    } elseif ($password !== $confirm) {
        $message = "הסיסמאות אינן תואמות.";
    } else {
        // בדיקה האם האימייל כבר קיים
        $check_stmt = $conn->prepare("SELECT uid FROM user WHERE email = ?");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $message = "כתובת האימייל הזו כבר רשומה במערכת.";
        } else {
            // שמירת הנתונים בסשן ומעבר לשלב הבא
            $_SESSION['signup_step1'] = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password
            ];
            
            header("Location: signup_credit_card.php");
            exit();
        }
        $check_stmt->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <!-- השורה שביקשת למניעת ג'יבריש -->
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>הרשמה - שלב 1</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <style>
        * { box-sizing: border-box; }
        
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
        }

        /* --- עיצוב ה-Stepper (סרגל התקדמות) רספונסיבי --- */
        .stepper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            max-width: 400px;
            margin: 10px auto 30px;
            position: relative;
        }
        
        .stepper::before {
            content: "";
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #ddd;
            z-index: 1;
        }
        
        .step {
            z-index: 2;
            position: relative;
            text-align: center;
            flex: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 2px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-weight: bold;
            color: #aaa;
            transition: all 0.3s;
            font-size: 16px;
        }
        
        .step.active .step-circle {
            background: #2e7d32;
            border-color: #2e7d32;
            color: white;
            box-shadow: 0 4px 10px rgba(46,125,50,0.3);
        }
        
        .step-label {
            font-size: 11px;
            color: #888;
            font-weight: 600;
        }
        
        .step.active .step-label { color: #2e7d32; }

        .container { 
            max-width: 480px; 
            width: 100%; 
            background: #ffffff; 
            padding: 25px; 
            border-radius: 15px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.1); 
        }
        
        h1 { color: #2e7d32; margin-bottom: 20px; font-size: 24px; }
        
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
            font-size: 17px;
        }
        
        input { 
            width: 100%; 
            padding: 13px 45px 13px 15px; 
            border: 1px solid #ddd; 
            border-radius: 10px; 
            font-size: 16px; 
            background-color: #fafafa;
            transition: border 0.3s;
        }
        
        input:focus { border-color: #2e7d32; outline: none; background-color: #fff; }

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
        
        .btn:hover { background: #1b5e20; }

        .message { 
            margin-bottom: 15px; 
            padding: 10px; 
            background-color: #ffebee; 
            color: #d32f2f; 
            border-radius: 8px; 
            font-weight: 600; 
            border: 1px solid #ffcdd2; 
            font-size: 14px;
        }
        
        .footer-links { margin-top: 25px; display: flex; justify-content: center; gap: 15px; width: 100%; }
        .footer-links span { color: #2e7d32; font-size: 13px; cursor: pointer; font-weight: bold; }

        .modal-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0, 0, 0, 0.5); display: none; align-items: center; 
            justify-content: center; z-index: 3000; padding: 15px;
        }
        .modal-content { 
            background: white; width: 100%; max-width: 450px; padding: 25px; 
            border-radius: 20px; position: relative; text-align: right; 
        }

        @media (max-width: 400px) {
            .step-circle { width: 35px; height: 40px; font-size: 14px; }
            .step-label { font-size: 10px; }
            h1 { font-size: 20px; }
            .container { padding: 20px; }
        }
    </style>
</head>
<body>

    <div class="stepper">
        <div class="step active">
            <div class="step-circle"><i class="fa-solid fa-user"></i></div>
            <div class="step-label">פרטים אישיים</div>
        </div>
        <div class="step">
            <div class="step-circle"><i class="fa-solid fa-credit-card"></i></div>
            <div class="step-label">פרטי אשראי</div>
        </div>
        <div class="step">
            <div class="step-circle"><i class="fa-solid fa-bolt"></i></div>
            <div class="step-label">העדפות</div>
        </div>
    </div>

    <div class="container">
        <h1>יצירת חשבון</h1>

        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <i class="fa-solid fa-user-tag"></i>
                <input type="text" name="name" placeholder="שם מלא" required 
                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
            </div>
            
            <div class="input-group">
                <i class="fa-solid fa-envelope"></i>
                <input type="email" name="email" placeholder="כתובת אימייל" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <div class="input-group">
                <i class="fa-solid fa-phone"></i>
                <input type="tel" name="phone" placeholder="טלפון (05XXXXXXXX)" required maxlength="10"
                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
            </div>
            
            <div class="input-group">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" placeholder="סיסמה" required>
            </div>

            <div class="input-group">
                <i class="fa-solid fa-circle-check"></i>
                <input type="password" name="confirm" placeholder="אישור סיסמה" required>
            </div>

            <button class="btn" type="submit">המשך לשלב הבא</button>
        </form>

        <a href="login.php" style="display:block; margin-top:20px; color:#2e7d32; text-decoration:none; font-size:14px; font-weight:bold;">
		כבר רשום? להתחברות</a>
    </div>

    <div class="footer-links">
        <span onclick="toggleModal('terms')">תנאי שימוש</span>
        <span onclick="toggleModal('privacy')">מדיניות פרטיות</span>
        <span onclick="toggleModal('contact')">צור קשר</span>
    </div>

    <div id="modalOverlay" class="modal-overlay" onclick="toggleModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="close-modal" onclick="toggleModal()" style="position:absolute; top:10px; left:10px; border:none; background:#eee; border-radius:50%; width:30px; height:30px; cursor:pointer;">
			×</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <script>
        function toggleModal(type) {
            const modal = document.getElementById('modalOverlay');
            const body = document.getElementById('modalBody');
            if (!type) { modal.style.display = 'none'; return; }

            if (type === 'terms') {
                body.innerHTML = '<h2>תנאי שימוש</h2><p>ברוכים הבאים. השימוש במערכת כפוף להסכמה לתנאים אלו...</p>';
            } else if (type === 'privacy') {
                body.innerHTML = '<h2>מדיניות פרטיות</h2><p>אנו שומרים על פרטיותך ומאבטחים את המידע שלך...</p>';
            } else if (type === 'contact') {
                body.innerHTML = '<h2>צור קשר</h2><p>תמי גלמן<br>גל רביד</p>';
            }
            modal.style.display = 'flex';
        }
    </script>
</body>
</html>