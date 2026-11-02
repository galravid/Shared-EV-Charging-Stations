<?php
// הגדרת כותרת PHP למניעת ג'יבריש מהצד של השרת
header('Content-Type: text/html; charset=utf-8');

session_start();
include 'email_helper.php'; // פונקציית המייל שבנינו


// הגדרות חיבור
$servername = "localhost";
$username = "root";
$password_db = ""; 
$dbname = "charging_station_db";

$conn = new mysqli($servername, $username, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$message = "";
$success_message = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // קבלת ההעדפות מהסליידרים
    $max_distance = (int)$_POST['max_distance'];
    $min_rating   = (float)$_POST['min_rating'];
    $max_price    = (float)$_POST['max_price'];

    // שליפת הנתונים שנשמרו בסשן
    $s1 = $_SESSION['signup_step1'];
    $s2 = $_SESSION['signup_step2']; // יכול להיות [] אם המשתמש דילג

    // התחלת טרנזקציה
    $conn->begin_transaction();

    try {
        // 1. הכנסת משתמש (תמיד קורה)
        $stmt = $conn->prepare("INSERT INTO user (name, email, phone_number, password) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $s1['name'], $s1['email'], $s1['phone'], $s1['password']);
        $stmt->execute();
        $new_uid = $stmt->insert_id;
        $stmt->close();

        // 2. הכנסת פרטי אשראי - קורה רק אם הנתונים קיימים!
        if (!empty($s2)) {
            $is_default = 1;
            $stmt_pay = $conn->prepare("INSERT INTO payment_method (user_id, card_number, cardholder_name, expiry_date, cvv, is_default) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_pay->bind_param("issssi", $new_uid, $s2['card_number'], $s2['cardholder_name'], $s2['expiry_date'], $s2['cvv'], $is_default);
            $stmt_pay->execute();
            $stmt_pay->close();
        }

        // 3. הכנסת העדפות (תמיד קורה)
        $stmt_pref = $conn->prepare("INSERT INTO preferences (user_id, max_distance, min_rating, max_price) VALUES (?, ?, ?, ?)");
        $stmt_pref->bind_param("iidd", $new_uid, $max_distance, $min_rating, $max_price);
        $stmt_pref->execute();
        $stmt_pref->close();

        $conn->commit();

        // ניקוי סשן וכניסה אוטומטית
        unset($_SESSION['signup_step1']);
        unset($_SESSION['signup_step2']);
        $_SESSION['user_id'] = $new_uid;
        $_SESSION['user_name'] = $s1['name'];

        // שליחת מייל ברוכים הבאים
        $welcome_body = "<h2>שלום " . htmlspecialchars($s1['name']) . ",</h2><p>ברוך הבא לקהילת הטעינה שלנו! החשבון שלך הוגדר בהצלחה.</p>";
        sendSystemEmail($s1['email'], "ברוך הבא למערכת!", $welcome_body);

        $success_message = true;
        header("refresh:2;url=map.php");

    } catch (Exception $e) {
        $conn->rollback();
        $message = "שגיאה ברישום: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>הרשמה - העדפות טעינה</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { 
            margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; 
            background: linear-gradient(to bottom, #e8f5e9, #ffffff); 
            padding: 20px 10px; text-align: center; 
            min-height: 100vh; color: #333;
            display: flex; flex-direction: column; align-items: center;
            overflow-x: hidden;
        }

        /* --- Stepper --- */
        .stepper { display: flex; justify-content: space-between; align-items: center; width: 100%; max-width: 380px; margin: 20px auto 30px; position: relative; }
        .stepper::before { 
            content: ""; position: absolute; top: 22px; left: 10%; right: 10%; 
            height: 3px; background: #cbd5e0; z-index: 1; 
        }
        .step { z-index: 2; position: relative; flex: 1; }
        .step-circle { 
            width: 45px; height: 45px; border-radius: 50%; background: #f0f0f0; 
            border: 2px solid #cbd5e0; display: flex; align-items: center; justify-content: center; 
            margin: 0 auto 8px; color: #718096; font-weight: bold; transition: 0.3s; font-size: 16px;
        }
        .step.completed .step-circle { background: #e8f5e9; border-color: #2e7d32; color: #2e7d32; }
        .step.active .step-circle { background: #2e7d32; border-color: #2e7d32; color: white; box-shadow: 0 4px 10px rgba(46,125,50,0.2); }
        .step-label { font-size: 11px; color: #718096; font-weight: bold; }
        .step.active .step-label { color: #2e7d32; }

        /* --- כותרות --- */
        .page-header { margin-bottom: 35px; padding: 0 10px; width: 100%; max-width: 550px; }
        .page-header h1 { font-size: 26px; color: #1b1b1b; margin-bottom: 8px; }
        .page-header p { font-size: 14px; color: #666; line-height: 1.5; margin: 0; }

        /* --- כרטיסיות העדפה --- */
        .pref-card {
            background: white; border-radius: 30px; padding: 22px 28px; margin-bottom: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04); text-align: right; width: 100%; max-width: 440px;
        }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-title { display: flex; align-items: center; gap: 10px; font-weight: bold; font-size: 18px; color: #1b1b1b; }
        .card-title i { color: #2e7d32; font-size: 20px; }
        
        /* אזור הקלט הידני והבאדג' */
        .value-wrapper { display: flex; align-items: center; gap: 8px; }
        .manual-input {
            width: 55px; padding: 4px; border: 1px solid #ddd; border-radius: 8px;
            text-align: center; font-size: 14px; font-weight: bold; color: #2e7d32;
            outline: none;
        }
        .manual-input:focus { border-color: #2e7d32; box-shadow: 0 0 4px rgba(46,125,50,0.2); }

        .badge { background: #e3f2fd; color: #2196f3; padding: 5px 14px; border-radius: 12px; font-size: 14px; font-weight: bold; }
        .badge-green { background: #e8f5e9; color: #2e7d32; }
        .badge-red { background: #ffebee; color: #d32f2f; }

        /* --- סליידרים --- */
        .slider-container { width: 100%; position: relative; padding: 8px 0; }
        input[type=range] {
            -webkit-appearance: none; width: 100%; height: 6px; background: #edf2f7;
            border-radius: 5px; outline: none; margin: 12px 0;
        }
        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none; appearance: none; width: 24px; height: 24px;
            background: #2e7d32; border: 4px solid white; border-radius: 50%;
            cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }

        .slider-labels { display: flex; justify-content: space-between; font-size: 12px; color: #a0aec0; margin-top: 4px; }

        /* --- כפתורים --- */
        .btn-finish {
            background: #2e7d32; color: white; border: none; border-radius: 25px;
            padding: 18px; width: 100%; max-width: 440px; font-size: 18px; font-weight: bold;
            cursor: pointer; transition: 0.2s; margin-top: 15px; display: flex;
            align-items: center; justify-content: center; gap: 10px;
        }
        .btn-finish:hover { background: #1b5e20; transform: translateY(-1px); }

        .btn-back {
            background: none; border: 2px solid #cbd5e0; color: #718096; margin-top: 12px;
            padding: 12px; width: 100%; max-width: 440px; border-radius: 25px;
            font-size: 15px; font-weight: bold; cursor: pointer; transition: 0.2s;
        }
        .btn-back:hover { background: #f7fafc; border-color: #a0aec0; color: #4a5568; }

        .footer-links { margin-top: 30px; display: flex; justify-content: center; gap: 20px; padding-bottom: 25px; }
        .footer-links span { color: #2e7d32; font-size: 13px; cursor: pointer; font-weight: bold; }

        .message { 
            max-width: 440px; margin: 10px auto; padding: 12px; border-radius: 15px; 
            background: #e8f5e9; color: #2e7d32; font-weight: bold; font-size: 15px;
        }

        /* מודאלים */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 3000; }
        .modal-content { background: white; width: 90%; max-width: 450px; padding: 30px; border-radius: 25px; position: relative; text-align: right; }
        .close-modal { position: absolute; top: 15px; left: 15px; background: #f0f0f0; border: none; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; }
    </style>
</head>
<body>

    <div class="stepper">
        <div class="step completed">
            <div class="step-circle"><i class="fa-solid fa-check"></i></div>
            <div class="step-label">פרטים</div>
        </div>
        <div class="step completed">
            <div class="step-circle"><i class="fa-solid fa-check"></i></div>
            <div class="step-label">אשראי</div>
        </div>
        <div class="step active">
            <div class="step-circle"><i class="fa-solid fa-bolt"></i></div>
            <div class="step-label">העדפות</div>
        </div>
    </div>

    <div class="page-header">
        <h1>מה הכי חשוב לך בטעינה?</h1>
        <p>סמן את העדפותיך והאלגוריתם שלנו יסנן את כל העמדות באזור, כדי להמליץ לך על ההתאמה המושלמת עבורך.</p>
    </div>

    <?php if ($message): ?> <div class="message" style="background:#ffebee; color:#d32f2f;"><?php echo $message; ?></div> <?php endif; ?>
    <?php if ($success_message): ?> <div class="message">נרשמת בהצלחה! מעביר אותך למפה...</div> <?php endif; ?>

    <form method="POST" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <div class="pref-card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-location-dot"></i> מרחק</div>
                <div class="value-wrapper">
                    <input type="number" id="dist-num" class="manual-input" min="1" max="50" value="20" oninput="syncInputs(this.value, 'dist-range', 'dist-val', 'עד ', ' ק\'מ')">
                    <div class="badge" id="dist-val">עד 20 ק"מ</div>
                </div>
            </div>
            <div class="slider-container">
                <input type="range" id="dist-range" name="max_distance" min="1" max="50" value="20" oninput="syncInputs(this.value, 'dist-num', 'dist-val', 'עד ', ' ק\'מ')">
                <div class="slider-labels">
                    <span>קרוב</span>
                    <span>בכל מקום</span>
                </div>
            </div>
        </div>

        <div class="pref-card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-star"></i> דירוג</div>
                <div class="value-wrapper">
                    <input type="number" id="rate-num" class="manual-input" min="0" max="5" step="0.1" value="3" oninput="syncInputs(this.value, 'rate-range', 'rate-val', '+', '')">
                    <div class="badge badge-green" id="rate-val">+3.0</div>
                </div>
            </div>
            <div class="slider-container">
                <input type="range" id="rate-range" name="min_rating" min="0" max="5" step="0.5" value="3" oninput="syncInputs(this.value, 'rate-num', 'rate-val', '+', '')">
                <div class="slider-labels">
                    <span>סטנדרטי</span>
                    <span>פרימיום</span>
                </div>
            </div>
        </div>

        <div class="pref-card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-money-bill-wave"></i> מחיר לשעה</div>
                <div class="value-wrapper">
                    <input type="number" id="price-num" class="manual-input" min="5" max="100" value="20" oninput="syncInputs(this.value, 'price-range', 'price-val', 'עד ₪', '')">
                    <div class="badge badge-red" id="price-val">עד ₪20</div>
                </div>
            </div>
            <div class="slider-container">
                <input type="range" id="price-range" name="max_price" min="5" max="100" value="20" oninput="syncInputs(this.value, 'price-num', 'price-val', 'עד ₪', '')">
                <div class="slider-labels">
                    <span>חיסכון</span>
                    <span>יוקרה</span>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-finish">
            סיום <i class="fa-solid fa-arrow-left"></i>
        </button>
        
        <button type="button" class="btn-back" onclick="window.location.href='signup_credit_card.php'">
            חזרה לשלב הקודם
        </button>
    </form>

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
        // פונקציית סנכרון חדשה לעדכון הדדי של סליידר ותיבת טקסט
        function syncInputs(val, otherId, labelId, prefix, suffix) {
            // עדכון האלמנט השני (אם זה סליידר או תיבה)
            document.getElementById(otherId).value = val;
            
            // עיצוב מיוחד לדירוג (הוספת .0 במידת הצורך)
            let displayVal = val;
            if (labelId === 'rate-val') {
                displayVal = parseFloat(val).toFixed(1);
            }
            
            // עדכון הבאדג' הוויזואלי
            document.getElementById(labelId).innerText = prefix + displayVal + suffix;
        }

        function updateLabel(id, text) {
            document.getElementById(id).innerText = text;
        }

        function toggleModal(type) {
            const modal = document.getElementById('modalOverlay');
            const body = document.getElementById('modalBody');
            if (!type) { modal.style.display = 'none'; return; }

            if (type === 'terms') {
                body.innerHTML = '<h3 style="color:#2e7d32;">תנאי שימוש</h3><p style="font-size:14px; color:#444;">השימוש במערכת כפוף להסכמה לתנאי השירות והשימוש בעמדות...</p>';
            } else if (type === 'privacy') {
                body.innerHTML = '<h3 style="color:#2e7d32;">מדיניות פרטיות</h3><p style="font-size:14px; color:#444;">אנחנו שומרים על המידע שלך ומאבטחים אותו לפי התקנים המחמירים ביותר...</p>';
            } else if (type === 'contact') {
                body.innerHTML = '<h3 style="color:#2e7d32;">צור קשר</h3><p style="font-size:15px;"><b>תמי גלמן</b><br><b>גל רביד</b></p>';
            }
            modal.style.display = 'flex';
        }
    </script>
</body>
</html>