<?php
session_start();
include 'email_helper.php'; // הוספת הקובץ לשליחת מיילים

// 1. בדיקת אבטחה: האם המשתמש מחובר?
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

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

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = "";

// בדיקה שקיבלנו ID של עמדה
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: map.php");
    exit();
}

$station_id = $_GET['id'];

// --- חלק א: טיפול בביצוע ההזמנה (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    $date = $_POST['booking_date'];
    $start_t = $_POST['start_time'];
    $end_t = $_POST['end_time'];

    // יצירת אובייקטים של זמן לצורך חישובים
    $full_start = $date . " " . $start_t . ":00";
    $full_end = $date . " " . $end_t . ":00";

    if (strtotime($full_end) <= strtotime($full_start)) {
        $message = "שעת הסיום חייבת להיות אחרי שעת ההתחלה.";
        $message_type = "error";
    } else {
        // 1. בדיקת שעות פעילות (opening_hours)
        $day_num = date('N', strtotime($date)) + 1; 
        if ($day_num > 7) $day_num = 1; // התאמה לפורמט שלך (1=ראשון)

        $stmt_h = $conn->prepare("SELECT * FROM opening_hours WHERE station_id = ? AND day_of_week = ?");
        $stmt_h->bind_param("si", $station_id, $day_num);
        $stmt_h->execute();
        $h_res = $stmt_h->get_result()->fetch_assoc();

        $is_within_hours = false;
        if ($h_res && $h_res['is_closed'] == 0) {
            if ($start_t >= $h_res['open_time'] && $end_t <= $h_res['close_time']) {
                $is_within_hours = true;
            }
        }

        if (!$is_within_hours) {
            $message = "העמדה סגורה בשעות שבחרת. אנא בדוק את שעות הפעילות למעלה.";
            $message_type = "error";
        } else {
            // 2. בדיקה שאין הזמנה חופפת (Overlap)
            $overlap_sql = "SELECT order_id FROM orders 
                           WHERE station_id = ? 
                           AND status IN ('approved', 'pending', 'completed', 'charging', 'extra parking')
                           AND NOT (end_time <= ? OR start_time >= ?)";
            $stmt_o = $conn->prepare($overlap_sql);
            $stmt_o->bind_param("sss", $station_id, $full_start, $full_end);
            $stmt_o->execute();
            
            if ($stmt_o->get_result()->num_rows > 0) {
                $message = "העמדה תפוסה בזמן שבחרת. נסה שעות אחרות.";
                $message_type = "error";
            } else {
                // 3. שליפת instant_approval + פרטי בעל העמדה לצורך שליחת מייל
                $stmt_s = $conn->prepare("SELECT s.instant_approval, s.name as station_name, u.email as owner_email, u.name as owner_name 
                                        FROM station s 
                                        JOIN owner_stations os ON s.station_id = os.station_id 
                                        JOIN user u ON os.user_id = u.uid 
                                        WHERE s.station_id = ?");
                $stmt_s->bind_param("s", $station_id);
                $stmt_s->execute();
                $s_data = $stmt_s->get_result()->fetch_assoc();

                // קביעת סטטוס לפי instant_approval (1=approved, 0=pending)
                $status = ($s_data['instant_approval'] == 1) ? 'approved' : 'pending';

                // קביעת המחיר לפי הסטטוס (10 לאושר כדמי רצינות, 0 להמתנה)
                $total_price = ($status == 'approved') ? 10.00 : 0.00;

                // ביצוע ההזמנה
                $insert_sql = "INSERT INTO orders (user_id, station_id, start_time, end_time, total_price, status) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_ins = $conn->prepare($insert_sql);
                $stmt_ins->bind_param("isssds", $user_id, $station_id, $full_start, $full_end, $total_price, $status);
                
                if ($stmt_ins->execute()) {
                    
                    // --- תוספת: שליחת מייל לבעל העמדה ---
                    $user_booking_name = $_SESSION['user_name'];
                    $station_name = $s_data['station_name'];
                    $owner_email = $s_data['owner_email'];
                    $owner_name = $s_data['owner_name'];
                    
                    $mail_subject = "הזמנה חדשה לעמדת הטעינה שלך: " . $station_name;
                    
                    if ($status == 'approved') {
                        $mail_body = "<h2>שלום {$owner_name},</h2>
                                      <p>יש לנו חדשות טובות! הזמנה חדשה בוצעה ואושרה אוטומטית לעמדה שלך <b>{$station_name}</b>.</p>
                                      <p><b>פרטי ההזמנה:</b><br>
                                      מזמין: {$user_booking_name}<br>
                                      תאריך: {$date}<br>
                                      שעות: {$start_t} - {$end_t}</p>
                                      <p>אין צורך בפעולה נוספת מצידך.</p>";
                    } else {
                        $mail_body = "<h2>שלום {$owner_name},</h2>
                                      <p>בקשת הזמנה חדשה ממתינה לאישורך עבור העמדה <b>{$station_name}</b>.</p>
                                      <p><b>פרטי הבקשה:</b><br>
                                      מבקש: {$user_booking_name}<br>
                                      תאריך: {$date}<br>
                                      שעות: {$start_t} - {$end_t}</p>
                                      <p>אנא היכנס לאזור האישי שלך במערכת כדי לאשר או לדחות את הבקשה.</p>";
                    }
                    
                    sendSystemEmail($owner_email, $mail_subject, $mail_body);

                    // הגדרת הודעה מותאמת ל-Session לפני המעבר למפה
                    if ($status == 'approved') {
                        $_SESSION['order_status_msg'] = "ההזמנה בוצעה ואושרה בהצלחה! 🎉";
                    } else {
                        $_SESSION['order_status_msg'] = "ההזמנה בוצעה וממתינה לאישור בעל העמדה. ⏳";
                    }
                    
                    header("Location: map.php");
                    exit();
                }
            }
        }
    }
}

// --- חלק ב: שליפת פרטים לתצוגה (כולל פרטי בעלים) ---
// עדכון השאילתה לשליפת שם, טלפון ואימייל של בעל העמדה
$stmt = $conn->prepare("SELECT s.name, s.price_per_hour, s.parking_fee, u.name AS owner_name, u.phone_number, u.email AS owner_email 
                       FROM station s 
                       JOIN owner_stations os ON s.station_id = os.station_id 
                       JOIN user u ON os.user_id = u.uid 
                       WHERE s.station_id = ?");
$stmt->bind_param("s", $station_id);
$stmt->execute();
$station = $stmt->get_result()->fetch_assoc();

if (!$station) { die("העמדה לא נמצאה."); }

$hours_stmt = $conn->prepare("SELECT * FROM opening_hours WHERE station_id = ? ORDER BY day_of_week ASC");
$hours_stmt->bind_param("s", $station_id);
$hours_stmt->execute();
$hours_result = $hours_stmt->get_result();
$opening_hours = [];
while($h_row = $hours_result->fetch_assoc()) {
    $opening_hours[$h_row['day_of_week']] = $h_row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ביצוע הזמנה - <?php echo htmlspecialchars($station['name']); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: linear-gradient(to bottom, #e8f5e9, #ffffff); padding: 20px; min-height: 100vh; }
        .container { max-width: 600px; width: 100%; margin: 20px auto; background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #e8f5e9; padding-bottom: 15px; }
        h1 { color: #2e7d32; margin: 0; font-size: 22px; }
        .back-btn { text-decoration: none; color: #666; font-size: 14px; border: 1px solid #ccc; padding: 5px 12px; border-radius: 4px; transition: 0.2s; }

        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .message.error { background-color: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }

        .section-title { font-weight: bold; color: #2e7d32; margin-bottom: 15px; font-size: 18px; border-right: 4px solid #2e7d32; padding-right: 10px; }

        .owner-card { background: #f9f9f9; border: 1px solid #eee; border-radius: 8px; padding: 15px; margin-bottom: 25px; display: flex; flex-direction: column; gap: 5px; }
        .owner-card div { font-size: 14px; color: #444; }

        .price-container { display: flex; gap: 10px; margin-bottom: 25px; }
        .price-tag { background: #e3f2fd; color: #1565c0; padding: 10px; border-radius: 8px; font-weight: bold; border: 1px solid #bbdefb; font-size: 14px; flex: 1; text-align: center; }
        .price-tag span { display: block; font-size: 11px; color: #546e7a; font-weight: normal; margin-bottom: 4px; }

        .schedule-container { background: #f9f9f9; border-radius: 8px; padding: 15px; margin-bottom: 25px; border: 1px solid #eee; }
        .schedule-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; border-bottom: 1px dotted #ddd; }
        .schedule-row:last-child { border-bottom: none; }
        .schedule-row.today { font-weight: bold; color: #2e7d32; background: #e8f5e9; border-radius: 4px; padding: 4px 8px; }

        .booking-form { background: #f1f8e9; padding: 20px; border-radius: 12px; border: 1px solid #c8e6c9; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; font-size: 14px; }
        input[type=date], input[type=time] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; font-size: 16px; }

        .submit-btn { width: 100%; padding: 15px; background-color: #2e7d32; color: white; border: none; border-radius: 8px; font-size: 18px; font-weight: bold; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .submit-btn:hover { background-color: #1b5e20; }
        
        .cancel-note { font-size: 12px; color: #666; text-align: center; margin-top: 10px; display: block; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>הזמנת עמדה: <?php echo htmlspecialchars($station['name']); ?></h1>
        <a href="station.php?id=<?php echo $station_id; ?>" class="back-btn">
		ביטול</a>
    </header>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="section-title">פרטי בעל העמדה</div>
    <div class="owner-card">
        <div>👤 <b>שם:</b> <?php echo htmlspecialchars($station['owner_name']); ?></div>
        <div>📞 <b>טלפון:</b> <?php echo htmlspecialchars($station['phone_number']); ?></div>
        <div>📧 <b>אימייל:</b> <?php echo htmlspecialchars($station['owner_email']); ?></div>
    </div>

    <div class="section-title">עלויות טעינה וחניה</div>
    <div class="price-container">
        <div class="price-tag">
            <span>עלות טעינה</span>
            ₪<?php echo htmlspecialchars($station['price_per_hour']); ?> / שעה
        </div>
        <div class="price-tag" style="background: #fff3e0; color: #e65100; border-color: #ffe0b2;">
            <span>עלות חניה (לאחר סיום הטעינה)</span>
            ₪<?php echo htmlspecialchars($station['parking_fee']); ?> / שעה
        </div>
    </div>

    <div class="section-title">שעות פעילות העמדה</div>
    <div class="schedule-container">
        <?php 
        $days_names = [1 => "ראשון", 2 => "שני", 3 => "שלישי", 4 => "רביעי", 5 => "חמישי", 6 => "שישי", 7 => "שבת"];
        $current_day_num = date('N') + 1;
        if ($current_day_num > 7) $current_day_num = 1;

        foreach ($days_names as $num => $name): 
            $h = isset($opening_hours[$num]) ? $opening_hours[$num] : null;
            $is_today = ($num == $current_day_num);
            $time_str = "לא צוין";
            if ($h) {
                $time_str = ($h['is_closed']) ? "סגור" : substr($h['open_time'], 0, 5) . " - " . substr($h['close_time'], 0, 5);
            }
        ?>
            <div class="schedule-row <?php echo $is_today ? 'today' : ''; ?>">
                <span>יום <?php echo $name; ?>:</span>
                <span><?php echo $time_str; ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="section-title">בחירת זמן טעינה</div>
    <form class="booking-form" method="POST">
        <div class="form-group">
            <label>תאריך:</label>
            <input type="date" id="booking_date" name="booking_date" required>
        </div>

        <div style="display: flex; gap: 15px;">
            <div class="form-group" style="flex: 1;">
                <label>שעת התחלה:</label>
                <input type="time" id="start_time" name="start_time" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label>שעת סיום:</label>
                <input type="time" id="end_time" name="end_time" required>
            </div>
        </div>

        <button type="submit" name="place_order" class="submit-btn">ביצוע הזמנה</button>
        <span class="cancel-note">* דמי ביטול הזמנה הם 10 ש"ח</span>
    </form>
</div>

<script>
    window.onload = function() {
        const now = new Date();
        const today = now.toISOString().split('T')[0];
        document.getElementById('booking_date').value = today;
        document.getElementById('booking_date').min = today;

        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        document.getElementById('start_time').value = hours + ":" + minutes;

        const nextHour = String((now.getHours() + 1) % 24).padStart(2, '0');
        document.getElementById('end_time').value = nextHour + ":" + minutes;
    };
</script>

</body>
</html>