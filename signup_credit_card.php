<?php
session_start();

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // קליטת פרטי כרטיס אשראי
    $cardholder_name = trim($_POST["cardholder_name"]);
    $card_number      = str_replace(' ', '', trim($_POST["card_number"]));
    $expiry_date      = trim($_POST["expiry_date"]);
    $cvv               = trim($_POST["cvv"]);

    // בדיקה האם המשתמש השאיר הכל ריק (דילוג)
    $is_all_empty = empty($cardholder_name) && empty($card_number) && empty($expiry_date) && empty($cvv);

    if ($is_all_empty) {
        // המשתמש בחר לדלג - שומרים ערך ריק/null ועוברים לשלב הבא
        $_SESSION['signup_step2'] = null; 
        header("Location: signup_preferences.php");
        exit();
    } else {
        // המשתמש מילא לפחות שדה אחד - מבצעים וולידציה מלאה
        if (empty($cardholder_name) || empty($card_number) || empty($expiry_date) || empty($cvv)) {
            $message = "במידה והתחלת למלא פרטי אשראי, יש למלא את כל השדות. כדי לדלג השאר את הכל ריק.";
        } elseif (preg_match('~[0-9]~', $cardholder_name)) {
            $message = "שם בעל הכרטיס לא יכול לכלול מספרים.";
        } elseif (!preg_match('/^\d{16}$/', $card_number)) {
            $message = "מספר הכרטיס חייב להכיל בדיוק 16 ספרות.";
        } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry_date)) {
            $message = "פורמט תוקף אינו תקין (MM/YY).";
        } elseif ((int)substr($expiry_date, -2) < 27 || (int)substr($expiry_date, -2) > 38) {
            $message = "שנת התוקף חייבת להיות בין 27 ל-38.";
        } elseif (!preg_match('/^\d{3}$/', $cvv)) {
            $message = "ה-CVV חייב להכיל 3 ספרות.";
        } else {
            // הפרטים תקינים - שומרים וממשיכים
            $_SESSION['signup_step2'] = [
                'cardholder_name' => $cardholder_name,
                'card_number'      => $card_number,
                'expiry_date'      => $expiry_date,
                'cvv'               => $cvv
            ];
            header("Location: signup_preferences.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
     <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>הרשמה - שלב 2 (אשראי)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <style>
        * { box-sizing: border-box; }
        body { 
            margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; 
            background: linear-gradient(to bottom, #e8f5e9, #ffffff); 
            padding: 15px; text-align: center; min-height: 100vh;
            display: flex; flex-direction: column; align-items: center;
        }

        .stepper {
            display: flex; justify-content: space-between; align-items: center;
            width: 100%; max-width: 400px; margin: 10px auto 30px; position: relative;
        }
        .stepper::before {
            content: ""; position: absolute; top: 20px; left: 0; right: 0;
            height: 2px; background: #ddd; z-index: 1;
        }
        .step { z-index: 2; position: relative; text-align: center; flex: 1; }
        .step-circle {
            width: 40px; height: 40px; border-radius: 50%; background: white;
            border: 2px solid #ddd; display: flex; align-items: center;
            justify-content: center; margin: 0 auto 8px; font-weight: bold;
            color: #aaa; transition: all 0.3s;
        }
        .step.completed .step-circle { background: #e8f5e9; border-color: #2e7d32; color: #2e7d32; }
        .step.active .step-circle { background: #2e7d32; border-color: #2e7d32; color: white; box-shadow: 0 4px 10px rgba(46,125,50,0.3); }
        .step-label { font-size: 11px; color: #888; font-weight: 600; }
        .step.active .step-label { color: #2e7d32; }

        .container { 
            max-width: 480px; width: 100%; background: #ffffff; 
            padding: 25px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); 
        }
        
        h1 { color: #2e7d32; margin-bottom: 10px; font-size: 24px; }
        p.subtitle { color: #666; margin-bottom: 25px; font-size: 14px; line-height: 1.4; }
        
        .input-group { position: relative; width: 100%; margin-bottom: 15px; }
        .input-group i {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: rgba(0, 0, 0, 0.25); font-size: 17px;
        }
        
        input { 
            width: 100%; padding: 13px 45px 13px 15px; border: 1px solid #ddd; 
            border-radius: 10px; font-size: 16px; background-color: #fafafa;
            transition: border 0.3s;
        }
        input:focus { border-color: #2e7d32; outline: none; background-color: #fff; }

        .row-inputs { display: flex; gap: 10px; }
        .row-inputs .input-group { flex: 1; }

        .btn { 
            width: 100%; padding: 15px; background: #2e7d32; color: white; 
            border: none; border-radius: 10px; font-size: 18px; font-weight: bold; 
            cursor: pointer; transition: 0.3s; margin-top: 10px; 
        }
        .btn:hover { background: #1b5e20; }

        .btn-back {
            background: none; border: 2px solid #ddd; color: #888; margin-top: 10px;
        }
        .btn-back:hover { background: #f9f9f9; color: #666; }

        .message { 
            margin-bottom: 15px; padding: 10px; background-color: #ffebee; 
            color: #d32f2f; border-radius: 8px; font-weight: 600; 
            border: 1px solid #ffcdd2; font-size: 14px;
        }

        .skip-note {
            font-size: 13px; color: #999; margin-top: 10px; display: block;
        }
        
        .footer-links { margin-top: 25px; display: flex; justify-content: center; gap: 15px; width: 100%; }
        .footer-links span { color: #2e7d32; font-size: 13px; cursor: pointer; font-weight: bold; }

        @media (max-width: 400px) {
            .container { padding: 20px; }
            h1 { font-size: 21px; }
            .row-inputs { flex-direction: column; gap: 0; }
        }
    </style>
</head>
<body>

    <div class="stepper">
        <div class="step completed">
            <div class="step-circle"><i class="fa-solid fa-check"></i></div>
            <div class="step-label">פרטים אישיים</div>
        </div>
        <div class="step active">
            <div class="step-circle"><i class="fa-solid fa-credit-card"></i></div>
            <div class="step-label">פרטי אשראי</div>
        </div>
        <div class="step">
            <div class="step-circle"><i class="fa-solid fa-bolt"></i></div>
            <div class="step-label">העדפות</div>
        </div>
    </div>

    <div class="container">
        <h1>פרטי תשלום </h1>
        <p class="subtitle">ניתן להזין פרטים כעת או לדלג ולמלא אותם מאוחר יותר בהגדרות החשבון.</p>

        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <i class="fa-solid fa-user-shield"></i>
                <input type="text" name="cardholder_name" placeholder="שם בעל הכרטיס"
                       value="<?php echo isset($_POST['cardholder_name']) ? htmlspecialchars($_POST['cardholder_name']) : ''; ?>">
            </div>
            
            <div class="input-group">
                <i class="fa-solid fa-hashtag"></i>
                <input type="tel" name="card_number" placeholder="מספר כרטיס (16 ספרות)" maxlength="16"
                       value="<?php echo isset($_POST['card_number']) ? htmlspecialchars($_POST['card_number']) : ''; ?>">
            </div>
            
            <div class="row-inputs">
                <div class="input-group">
                    <i class="fa-solid fa-calendar-days"></i>
                    <input type="text" name="expiry_date" placeholder="תוקף MM/YY" maxlength="5"
                           value="<?php echo isset($_POST['expiry_date']) ? htmlspecialchars($_POST['expiry_date']) : ''; ?>">
                </div>
                
                <div class="input-group">
                    <i class="fa-solid fa-lock"></i>
                    <input type="tel" name="cvv" placeholder="CVV" maxlength="3"
                           value="<?php echo isset($_POST['cvv']) ? htmlspecialchars($_POST['cvv']) : ''; ?>">
                </div>
            </div>

            <button class="btn" type="submit">המשך לשלב האחרון</button>
            <span class="skip-note">השאר הכל ריק ולחץ על הכפתור כדי לדלג</span>
            <button type="button" class="btn btn-back" onclick="window.location.href='signup.php'">חזרה לשלב הקודם</button>
        </form>
    </div>

    <!-- פוטר ומודאלים נשארים ללא שינוי -->
    <div class="footer-links">
        <span onclick="toggleModal('terms')">תנאי שימוש</span>
        <span onclick="toggleModal('privacy')">מדיניות פרטיות</span>
        <span onclick="toggleModal('contact')">צור קשר</span>
    </div>

    <div id="modalOverlay" class="modal-overlay" onclick="toggleModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="close-modal" onclick="toggleModal()" style="position:absolute; top:10px; left:10px; border:none; background:#eee; border-radius:50%; width:30px; height:30px; cursor:pointer;">&times;</button>
            <div id="modalBody"></div>
        </div>
    </div>

    <script>
        function toggleModal(type) {
            const modal = document.getElementById('modalOverlay');
            const body = document.getElementById('modalBody');
            if (!type) { modal.style.display = 'none'; return; }
            if (type === 'terms') { body.innerHTML = '<h2>תנאי שימוש</h2><p>פרטי האשראי נשמרים במערכת לצורך סליקה אוטומטית בלבד.</p>'; }
            else if (type === 'privacy') { body.innerHTML = '<h2>מדיניות פרטיות</h2><p>אנו משתמשים בתקני אבטחה מחמירים.</p>'; }
            else if (type === 'contact') { body.innerHTML = '<h2>צור קשר</h2><p>תמי גלמן<br>גל רביד</p>'; }
            modal.style.display = 'flex';
        }
    </script>
</body>
</html>