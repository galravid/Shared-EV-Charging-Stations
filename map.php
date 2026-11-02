<?php
session_start();
include 'email_helper.php'; // הוספת הקובץ לשליחת מיילים

// הגדרת אזור זמן ישראל כדי שהבדיקה של "פתוח עכשיו" תהיה מדויקת
date_default_timezone_set('Asia/Jerusalem');

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

// --- תוספת: משתני זמן נוכחיים לבדיקת זמינות ---
$current_day = (date('N') == 7) ? 1 : date('N') + 1; // התאמה לפורמט 1=ראשון, 7=שבת
$current_time = date('H:i:s');
$current_dt = date('Y-m-d H:i:s');
// --------------------------------------------

// --- תוספת: בדיקה האם המשתמש נמצא כרגע בטעינה פעילה או בחניה בחריגה ---
$charging_order = null;
if (isset($_SESSION['user_id'])) {
    $u_id = $_SESSION['user_id'];
    // עדכון השאילתה שתכלול את שני הסטטוסים
    $check_charging_sql = "SELECT o.*, s.name as station_name 
                           FROM orders o 
                           JOIN station s ON o.station_id = s.station_id
                           WHERE o.user_id = $u_id AND o.status IN ('charging', 'extra parking') 
                           LIMIT 1";
    $charging_res = $conn->query($check_charging_sql);
    if ($charging_res && $charging_res->num_rows > 0) {
        $charging_order = $charging_res->fetch_assoc();
    }
}

// --- טיפול בבקשות עדכון סטטוס (AJAX) - לוגיקת חישוב מעודכנת כולל מחיר מינימום ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $order_id = $_POST['order_id'];
    $new_status = $_POST['new_status']; // 'extra parking' או 'completed'
    
    if ($new_status === 'extra parking') {
        // כשעוברים ל-extra parking, מחשבים את זמן הטעינה (מעכשיו פחות זמן ההתחלה)
        $update_sql = "UPDATE orders SET status = '$new_status', charging_time = TIMEDIFF(NOW(), start_time) WHERE order_id = $order_id";
        $conn->query($update_sql);
        echo json_encode(['success' => true]); // החזרת תשובה פשוטה
    } elseif ($new_status === 'completed') {
        // שליפת מחירים וזמנים לחישוב טוטאל - הוספתי שם עמדה ופרטי משתמשים לשליחת מייל
        $get_info = "SELECT o.start_time, o.charging_time, s.price_per_hour, s.parking_fee, s.name as station_name, s.station_id,
                            u_booker.email as booker_email, u_booker.name as booker_name,
                            u_owner.email as owner_email, u_owner.name as owner_name
                     FROM orders o 
                     JOIN station s ON o.station_id = s.station_id 
                     JOIN user u_booker ON o.user_id = u_booker.uid
                     JOIN owner_stations os ON s.station_id = os.station_id
                     JOIN user u_owner ON os.user_id = u_owner.uid
                     WHERE o.order_id = $order_id";
        $info_res = $conn->query($get_info)->fetch_assoc();
        
        $start_dt = $info_res['start_time'];
        $p_charge = $info_res['price_per_hour'];
        $p_park = $info_res['parking_fee'];
        
        // אם סיימו שהות בלי ללחוץ על סיום טעינה קודם
        if (empty($info_res['charging_time']) || $info_res['charging_time'] == '00:00:00') {
            $charge_seconds = strtotime("now") - strtotime($start_dt);
            $park_seconds = 0;
            $charging_time_val = date('H:i:s', mktime(0, 0, $charge_seconds));
            $conn->query("UPDATE orders SET charging_time = '$charging_time_val' WHERE order_id = $order_id");
        } else {
            $parts = explode(':', $info_res['charging_time']);
            $charge_seconds = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
            $total_seconds = strtotime("now") - strtotime($start_dt);
            $park_seconds = max(0, $total_seconds - $charge_seconds);
        }

        $extra_park_val = date('H:i:s', mktime(0, 0, $park_seconds));
        $calculated_price = (($charge_seconds / 3600) * $p_charge) + (($park_seconds / 3600) * $p_park);
        
        // --- בדיקת מחיר מינימום של 10 ש"ח ---
        $final_price = ($calculated_price < 10) ? 10.00 : $calculated_price;
        
        // עדכון סטטוס, זמן חניה, מחיר סופי (כולל מינימום) וגם עדכון ה-end_time לזמן הנוכחי
        $update_sql = "UPDATE orders SET status = 'completed', extra_parking_time = '$extra_park_val', total_price = '$final_price', end_time = NOW() WHERE order_id = $order_id";
        $conn->query($update_sql);

        // --- תוספת: שליחת חשבוניות במייל לשני הצדדים ---
        $formatted_price = number_format($final_price, 2);
        $subject = "חשבונית טעינה - עמדת " . $info_res['station_name'];
        
        $invoice_body = "
            <div dir='rtl' style='font-family: Arial, sans-serif;'>
                <h2 style='color: #2e7d32;'>סיכום טעינה וחשבונית</h2>
                <p>עמדה: <b>{$info_res['station_name']}</b></p>
                <p>זמן טעינה: " . (empty($info_res['charging_time']) ? $charging_time_val : $info_res['charging_time']) . "</p>
                <p>זמן חניה נוספת: {$extra_park_val}</p>
                <hr>
                <p style='font-size: 18px;'><b>סה\"כ לתשלום: ₪{$formatted_price}</b></p>
            </div>";

        // שלח למזמין
        sendSystemEmail($info_res['booker_email'], $subject, "<h3>שלום {$info_res['booker_name']},</h3>" . $invoice_body);
        // שלח לבעל העמדה
        sendSystemEmail($info_res['owner_email'], "התקבל תשלום חדש - עמדת " . $info_res['station_name'], "<h3>שלום {$info_res['owner_name']},</h3><p>התקבל תשלום מהמשתמש {$info_res['booker_name']} עבור שימוש בעמדה שלך.</p>" . $invoice_body);

        // --- בדיקה האם המשתמש כבר דירג את העמדה הזו בעבר ---
        $user_id_check = $_SESSION['user_id'];
        $station_id_check = $info_res['station_id'];
        $check_rate_sql = "SELECT id FROM rating WHERE user_id = $user_id_check AND station_id = '$station_id_check' LIMIT 1";
        $rate_result = $conn->query($check_rate_sql);
        $already_rated = ($rate_result && $rate_result->num_rows > 0) ? true : false;

        // החזרת הנתונים ל-JavaScript לצורך הצגת הפופ-אפ
        echo json_encode([
            'success' => true, 
            'final_price' => $formatted_price,
            'station_name' => $info_res['station_name'],
            'station_id' => $info_res['station_id'],
            'already_rated' => $already_rated // שדה חדש שקובע אם להציג פופ-אפ
        ]);
    }
    exit;
}

// --- חדש: טיפול בסימון התראות כ"נקראו" כדי שהמספר לא יחזור בריפרש ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifs_seen'])) {
    $_SESSION['last_notif_count_seen'] = (int)$_POST['total_count'];
    echo json_encode(['success' => true]);
    exit;
}

// --- תוספת: טיפול בשמירת משוב (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_feedback'])) {
    $u_id = $_SESSION['user_id'];
    $s_id = $_POST['station_id'];
    $grade = (int)$_POST['grade'];
    $comment = $conn->real_escape_string($_POST['comment']);

    $query = "INSERT INTO rating (user_id, station_id, grade, comment) VALUES ($u_id, '$s_id', $grade, '$comment')";
    if ($conn->query($query)) {
        // עדכון הממוצע של העמדה בטבלת station
        $conn->query("UPDATE station SET grade = (SELECT AVG(grade) FROM rating WHERE station_id = '$s_id') WHERE station_id = '$s_id'");
        echo json_encode(['success' => true]);
    }
    exit;
}

// --- תוספת: עדכון אוטומטי להזמנות שפג תוקפן ---
$auto_reject_sql = "UPDATE orders SET status = 'rejected' WHERE status = 'pending' AND start_time < NOW()";
$conn->query($auto_reject_sql);
// ---------------------------------------------------

// --- תוספת: בדיקה כמה הזמנות ממתינות לאישור המשתמש (כבעל עמדה) ---
$pending_approvals_count = 0;
$u_id_for_sql = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0; // הגדרת משתנה למשתמש מחובר או 0 לאורח

if (isset($_SESSION['user_id'])) {
    $u_id = $_SESSION['user_id'];
    $pending_sql = "SELECT COUNT(*) as total FROM orders o 
                    JOIN owner_stations os ON o.station_id = os.station_id 
                    WHERE os.user_id = $u_id AND o.status = 'pending'";
    $pending_res = $conn->query($pending_sql);
    if ($pending_res) {
        $p_row = $pending_res->fetch_assoc();
        $pending_approvals_count = $p_row['total'];
    }
}
// ---------------------------------------------------

// --- תוספת: בדיקה האם קיימת הודעת סטטוס הזמנה מהסשן ---
$order_message = "";
if (isset($_SESSION['order_status_msg'])) {
    $order_message = $_SESSION['order_status_msg'];
    unset($_SESSION['order_status_msg']); // מחיקה כדי שלא יופיע שוב בריפרש
}
// ---------------------------------------------------

// --- חדש: מערכת התראות משופרת (מבוססת ספירה כוללת) ---
$notifications = [];
$total_event_count = 0; // ספירה אבסולוטית של כל הנתונים ב-DB

if (isset($_SESSION['user_id'])) {
    $u_id = $_SESSION['user_id'];
    
    // 1. ספירת התראות בעלים (Pending)
    $owner_sql = "SELECT o.order_id, s.name as s_name, u.name as u_name FROM orders o 
                  JOIN station s ON o.station_id = s.station_id 
                  JOIN owner_stations os ON s.station_id = os.station_id 
                  JOIN user u ON o.user_id = u.uid
                  WHERE os.user_id = $u_id AND o.status = 'pending'";
    $res_owner = $conn->query($owner_sql);
    $total_event_count += $res_owner->num_rows;
    while($n = $res_owner->fetch_assoc()) {
        $notifications[] = [
            'text' => "הזמנה חדשה מ-{$n['u_name']} לעמדת {$n['s_name']}", 
            'icon' => 'fa-calendar-plus', 'color' => '#ff9800', 'url' => 'my_stations_orders.php'
        ];
    }

    // 2. ספירת התראות מזמין (Approved, Rejected, Completed)
    $booker_sql = "SELECT o.status, s.name as s_name, o.total_price, o.station_id FROM orders o 
                   JOIN station s ON o.station_id = s.station_id 
                   WHERE o.user_id = $u_id AND o.status IN ('approved', 'rejected', 'completed')";
    $res_booker = $conn->query($booker_sql);
    $total_event_count += $res_booker->num_rows;
    
    // שליפת רק ה-5 האחרונות לתצוגה (אבל הספירה למעלה היא על הכל)
    $res_booker_limited = $conn->query($booker_sql . " ORDER BY o.order_id DESC LIMIT 5");
    while($n = $res_booker_limited->fetch_assoc()) {
        if($n['status'] == 'approved') {
            $notifications[] = ['text' => "הזמנתך לעמדת {$n['s_name']} אושרה!", 'icon' => 'fa-check-circle', 'color' => '#2e7d32', 'url' => "station.php?id={$n['station_id']}"];
        } elseif($n['status'] == 'rejected') {
            $notifications[] = ['text' => "הזמנתך לעמדת {$n['s_name']} נדחתה.", 'icon' => 'fa-times-circle', 'color' => '#d32f2f', 'url' => 'order_history.php'];
        } elseif($n['status'] == 'completed') {
            $notifications[] = ['text' => "חשבונית: טעינתך ב{$n['s_name']} הסתיימה. סה\"כ: ₪{$n['total_price']}", 'icon' => 'fa-file-invoice-dollar', 'color' => '#1976d2', 'url' => 'order_history.php'];
        }
    }
}

// חישוב ההפרש עבור ה-Badge
$last_seen_total = $_SESSION['last_notif_count_seen'] ?? 0;
$unseen_accumulation = max(0, $total_event_count - $last_seen_total);
$show_badge = ($unseen_accumulation > 0);
// ---------------------------------------------------

// שליפת כל העמדות עם בדיקת זמינות מובנית ובדיקת בעלות
$sql = "SELECT s.*, 
        (SELECT COUNT(*) FROM opening_hours oh 
         WHERE oh.station_id = s.station_id 
         AND oh.day_of_week = $current_day 
         AND oh.is_closed = 0 
         AND '$current_time' BETWEEN oh.open_time AND oh.close_time) as is_open_now,
        (SELECT COUNT(*) FROM orders o 
         WHERE o.station_id = s.station_id 
         AND o.status IN ('approved', 'charging', 'extra parking') 
         AND '$current_dt' BETWEEN o.start_time AND o.end_time) as is_busy_now,
        (SELECT COUNT(*) FROM owner_stations os 
         WHERE os.station_id = s.station_id AND os.user_id = $u_id_for_sql) as is_my_station
        FROM station s";
        
$result = $conn->query($sql);

$stations = array();
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $stations[] = $row;
    }
}

// --- תוספת: שליפת מועדפים למשתמש מחובר ---
$favorites = array();
if (isset($_SESSION['user_id'])) {
    $u_id = $_SESSION['user_id'];
    $fav_sql = "SELECT s.* FROM station s JOIN favorites f ON s.station_id = f.station_id WHERE f.user_id = $u_id";
    $fav_res = $conn->query($fav_sql);
    if ($fav_res) {
        while($frow = $fav_res->fetch_assoc()) { $favorites[] = $frow; }
    }
}
// ----------------------------------------

$conn->close();

// המרת המערך ל-JSON כדי להשתמש בו ב-JavaScript
$json_stations = json_encode($stations);
$json_favorites = json_encode($favorites); // תוספת
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <title>מפת עמדות טעינה</title>
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
     integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
     crossorigin=""/>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden; /* מונע גלילה מיותרת כשהמפה על מסך מלא */
        }

        header {
            background-color: #2e7d32;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 3000;
            position: relative;
        }

        h1 {
            margin: 0;
            font-size: 22px;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            margin-left: 15px;
            font-weight: bold;
            font-size: 14px;
            transition: opacity 0.2s;
        }
        
        .nav-links a:hover {
            opacity: 0.8;
            text-decoration: underline;
        }

        /* --- עיצוב הפעמון והתפריט הנפתח --- */
        .notif-wrapper { position: relative; cursor: pointer; margin-left: 10px; }
        .notif-bell { font-size: 22px; color: white; }
        .notif-badge { 
            position: absolute; top: -5px; right: -8px; background: #f44336; color: white; 
            font-size: 11px; font-weight: bold; width: 18px; height: 18px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; border: 2px solid #2e7d32;
        }
        .notif-dropdown {
            position: absolute; top: 45px; left: 0; background: white; width: 280px; 
            border-radius: 12px; box-shadow: 0 5px 25px rgba(0,0,0,0.2); display: none; 
            flex-direction: column; overflow: hidden; z-index: 5000;
        }
        .notif-item {
            padding: 12px 15px; border-bottom: 1px solid #eee; display: flex; 
            align-items: center; gap: 12px; font-size: 13px; color: #333; text-align: right;
            text-decoration: none; transition: background 0.2s;
        }
        .notif-item:hover { background: #f5f5f5; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item i { font-size: 16px; }
        .notif-header { background: #f9f9f9; padding: 10px 15px; font-weight: bold; font-size: 14px; color: #2e7d32; border-bottom: 1px solid #ddd; }
        /* --------------------------------- */

        .map-wrapper {
            position: relative;
            flex-grow: 1;
            width: 100%;
        }

        #map {
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        .search-container {
            position: absolute;
            top: 15px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 2000;
            width: 90%;
            max-width: 400px;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            padding: 5px 15px;
            border: 2px solid transparent;
            transition: border-color 0.3s;
        }

        .search-box:focus-within {
            border-color: #2e7d32;
        }

        .search-icon {
            font-size: 18px;
            color: #2e7d32;
        }

        .search-box input {
            border: none;
            outline: none;
            width: 100%;
            padding: 12px 10px;
            font-size: 16px;
            background: transparent;
            font-family: inherit;
        }

        .search-results {
            list-style: none;
            padding: 0;
            margin: 8px 0 0 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            max-height: 250px;
            overflow-y: auto;
            display: none;
        }

        .search-results li {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            font-size: 15px;
            transition: background 0.2s;
            color: #444;
        }

        .search-results li:hover {
            background: #e8f5e9;
        }

        .highlight-city {
            font-weight: bold;
            color: #2e7d32;
        }

        .station-subtitle {
            display: block;
            font-size: 12px;
            color: #888;
            margin-top: 3px;
        }

        .search-section-title {
            background: #f1f1f1;
            padding: 8px 15px;
            font-size: 13px;
            font-weight: bold;
            color: #2e7d32;
            border-bottom: 1px solid #ddd;
        }

        .add-station-btn {
            position: fixed;
            bottom: 110px; /* הועלה כדי לפנות מקום לתפריט הניווט */
            left: 50%;
            transform: translateX(-50%);
            background-color: #2e7d32;
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            z-index: 2000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-station-btn:hover {
            background-color: #1b5e20;
            transform: translateX(-50%) scale(1.05);
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        }

        .pending-orders-badge {
            position: fixed;
            bottom: 200px; /* הועלה מעט */
            right: 20px;
            width: 110px;
            height: 110px;
            background-color: #ff9800;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-weight: bold;
            font-size: 13px;
            line-height: 1.2;
            padding: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            z-index: 4000;
            text-decoration: none;
            border: 3px solid white;
            animation: pulse-orange 2s infinite;
            cursor: pointer;
        }

        @keyframes pulse-orange {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 152, 0, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 15px rgba(255, 152, 0, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(255, 152, 0, 0); }
        }

        .user-location-marker {
            background-color: #2196F3;
            border: 2px solid white;
            border-radius: 50%;
            box-shadow: 0 0 8px rgba(33, 150, 243, 0.6);
        }

        .status-popup {
            position: fixed;
            top: 90px;
            left: 50%;
            transform: translateX(-50%);
            background: #2e7d32;
            color: white;
            padding: 12px 25px;
            border-radius: 50px;
            z-index: 6000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: bold;
            animation: slideDown 0.5s ease-out;
        }

        .status-popup button {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }

        @keyframes slideDown {
            from { top: -50px; opacity: 0; }
            to { top: 90px; opacity: 1; }
        }

        .home-emoji-marker {
            background: none;
            border: none;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 32px;
            text-shadow: 0 0 5px rgba(0,0,0,0.5); 
            cursor: pointer;
        }

        /* --- עיצוב טיימר טעינה פעיל (מעודכן ל-SVG) --- */
        .charging-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.96);
            z-index: 5000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .finished-header {
            font-size: 30px;
            font-weight: bold;
            color: #000;
            margin-bottom: 35px;
            text-align: center;
            display: none; 
        }

        .timer-container {
            position: relative;
            width: 280px;
            height: 280px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 40px;
        }

        .progress-ring {
            transform: rotate(-90deg);
        }

        .progress-ring__circle {
            transition: stroke-dashoffset 0.35s, stroke 0.3s;
            transform-origin: 50% 50%;
        }

        .timer-text-box {
            position: absolute;
            text-align: center;
            display: flex;
            flex-direction: column;
        }

        .timer-time {
            font-size: 56px;
            font-weight: bold;
            color: #333;
        }

        .timer-label {
            font-size: 16px;
            color: #666;
            font-weight: bold;
            margin-top: 5px;
        }

        .charging-actions {
            display: flex;
            flex-direction: column;
            gap: 15px;
            width: 80%;
            max-width: 320px;
        }

        .btn-charging {
            padding: 18px;
            border: none;
            border-radius: 15px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: all 0.2s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn-end-charge { 
            background-color: transparent; 
            color: #2196F3; 
            border: 2px solid #2196F3;
            padding: 12px;
            font-size: 16px;
            box-shadow: none;
        }
        
        .btn-end-stay { background-color: #f44336; color: white; }
        .btn-charging:active { transform: scale(0.97); }

        .help-icon-small {
            display: inline-block;
            width: 20px;
            height: 20px;
            background: rgba(33, 150, 243, 0.1);
            color: #2196F3;
            border-radius: 50%;
            text-align: center;
            font-size: 13px;
            line-height: 20px;
            cursor: help;
            position: relative;
            font-weight: bold;
            border: 1px solid #2196F3;
        }

        .help-icon-small:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: #fff;
            padding: 8px 12px;
            border-radius: 8px;
            width: 180px;
            font-size: 12px;
            line-height: 1.4;
            z-index: 6000;
            text-align: center;
            font-weight: normal;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        /* --- עיצוב פופ-אפ משוב (סיכום טעינה) --- */
        .feedback-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.75);
            z-index: 8000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .feedback-content {
            background: white;
            width: 100%;
            max-width: 420px;
            border-radius: 35px;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 15px 50px rgba(0,0,0,0.4);
            animation: popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative; /* למיקום כפתור ה-X */
        }

        @keyframes popIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        /* כפתור X לסגירה */
        .fb-close-x {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 28px;
            color: #ccc;
            cursor: pointer;
            background: none;
            border: none;
            line-height: 1;
            padding: 5px;
            transition: color 0.2s;
        }
        .fb-close-x:hover { color: #888; }

        .success-circle {
            width: 85px;
            height: 85px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 45px;
            font-weight: bold;
        }

        .fb-title { font-size: 28px; font-weight: bold; color: #2e7d32; margin-bottom: 8px; }
        .fb-subtitle { font-size: 16px; color: #777; margin-bottom: 30px; line-height: 1.4; }
        
        .fb-cost-box { 
            background: #f8f9fa; 
            padding: 18px; 
            border-radius: 20px; 
            margin-bottom: 30px; 
            border: 1px solid #eee;
        }
        .fb-cost-label { display: block; font-size: 14px; color: #888; margin-bottom: 5px; }
        .fb-cost-value { font-size: 26px; font-weight: 800; color: #333; }

        .fb-stars { 
            display: flex; 
            flex-direction: row-reverse; 
            justify-content: center; 
            gap: 12px; 
            margin-bottom: 25px; 
        }
        .fb-stars input { display: none; }
        .fb-stars label { font-size: 42px; color: #e0e0e0; cursor: pointer; transition: 0.2s; }
        .fb-stars input:checked ~ label,
        .fb-stars label:hover,
        .fb-stars label:hover ~ label { color: #2e7d32; }

        .fb-textarea {
            width: 100%;
            box-sizing: border-box;
            height: 110px;
            border: 1px solid #ddd;
            border-radius: 18px;
            padding: 15px;
            font-family: inherit;
            font-size: 15px;
            resize: none;
            margin-bottom: 25px;
            background: #fafafa;
            outline: none;
            transition: border-color 0.2s;
        }
        .fb-textarea:focus { border-color: #2e7d32; background: #fff; }

        .fb-btn-close {
            width: 100%;
            padding: 18px;
            background: #2e7d32;
            color: white;
            border: none;
            border-radius: 20px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .fb-btn-close:active { transform: scale(0.97); }

        /* --- עיצוב חדש לסרגל ניווט תחתון --- */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: white;
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 10px 0 25px 0; /* פאדינג תחתון קצת גדול יותר למראה מודרני */
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 7000;
        }

        .nav-item {
            text-decoration: none;
            color: #757575;
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 14px;
            font-weight: 500;
            flex: 1;
            transition: all 0.2s ease;
        }

        .nav-item i {
            font-size: 22px;
            margin-bottom: 5px;
        }

        .nav-item.active {
            color: #2e7d32;
        }

        /* עיצוב ה"גלולה" (Pill) לטאב הפעיל כפי שמופיע בתמונה */
        .nav-item.active .active-pill {
            background-color: #e8f5e9;
            padding: 8px 25px;
            border-radius: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* --- עיצוב הצ'אט AI --- */
        #aiChatBubble { position: fixed; bottom: 200px; left: 20px; width: 60px; height: 60px; background: #2e7d32; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 28px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.3); z-index: 8000; border: 3px solid white; transition: 0.3s; }
        #aiChatBubble:hover { transform: scale(1.1); }
        #aiChatWindow { position: fixed; bottom: 180px; left: 20px; width: 320px; height: 450px; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); z-index: 8001; display: none; flex-direction: column; overflow: hidden; border: 1px solid #ddd; animation: slideUp 0.3s ease-out; }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .chat-header { background: #2e7d32; color: white; padding: 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        #aiChatContent { flex-grow: 1; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; background: #f9f9f9; }
        .ai-msg { padding: 10px; border-radius: 12px; font-size: 14px; max-width: 85%; line-height: 1.4; }
        .bot-msg { background: #e8f5e9; color: #333; align-self: flex-start; border-bottom-left-radius: 2px; }
        .user-msg { background: #2e7d32; color: white; align-self: flex-end; border-bottom-right-radius: 2px; }
        .chat-input-area { padding: 10px; border-top: 1px solid #eee; display: flex; gap: 8px; background: white; }
        .chat-input-area input { flex-grow: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; outline: none; }

        @media (max-width: 480px) {
            h1 { font-size: 18px; }
            .nav-links a { margin-left: 10px; font-size: 13px; }
            .timer-time { font-size: 48px; }
            .finished-header { font-size: 30px; }
        }
    </style>
</head>
<body>

<header>
    <h1>מפת עמדות טעינה</h1>
    <div class="nav-links">
        <?php if(isset($_SESSION['user_name'])): ?>
            <span>שלום, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
            <a href="index.php" style="color: #ffcccb; margin-right: 15px;">יציאה</a>
        <?php else: ?>
            <a href="login.php">התחברות</a>
        <?php endif; ?>
    </div>

    <div class="notif-wrapper" onclick="toggleNotifications()">
        <i class="fa-solid fa-bell notif-bell"></i>
        <?php if($show_badge): ?>
            <span id="notifBadge" class="notif-badge"><?php echo $unseen_accumulation; ?></span>
        <?php endif; ?>
        
        <div id="notifDropdown" class="notif-dropdown">
            <div class="notif-header">התראות אחרונות</div>
            <?php if(empty($notifications)): ?>
                <div class="notif-item">אין התראות חדשות</div>
            <?php else: ?>
                <?php foreach($notifications as $notif): ?>
                    <a href="<?php echo $notif['url']; ?>" class="notif-item">
                        <i class="fa-solid <?php echo $notif['icon']; ?>" style="color: <?php echo $notif['color']; ?>;"></i>
                        <span><?php echo $notif['text']; ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if ($charging_order): ?>
<div id="chargingOverlay" class="charging-overlay" onclick="closeChargingOverlay(event)">
    
    <h2 id="finishedHeader" class="finished-header">הטעינה הסתיימה</h2>

    <div class="timer-container" onclick="event.stopPropagation()">
        <svg class="progress-ring" width="280" height="280">
            <circle stroke="#f0f0f0" stroke-width="12" fill="transparent" r="130" cx="140" cy="140"/>
            <circle id="progressCircle" class="progress-ring__circle" stroke="#2196F3" stroke-width="12" stroke-linecap="round" fill="transparent" r="130" cx="140" cy="140"/>
        </svg>
        <div class="timer-text-box">
            <span id="timerDisplay" class="timer-time">--:--</span>
            <span id="timerStatusLabel" class="timer-label">Charging</span>
        </div>
    </div>
    
    <div class="charging-actions" onclick="event.stopPropagation()">
        <button class="btn-charging btn-end-stay" onclick="updateChargingStatus('completed')">סיום השהות</button>

        <button id="finishChargeBtn" class="btn-charging btn-end-charge" 
                style="display: <?php echo ($charging_order['status'] === 'charging' ? 'flex' : 'none'); ?>; width: 100%;"
                onclick="updateChargingStatus('extra parking')">
            <div style="text-align: center">
            סיום הטעינה
            <span class="help-icon-small" data-tooltip="סיום הטעינה והמשך חנייה ללא טעינה">?</span></div>
        </button>
    </div>
</div>
<?php endif; ?>

<div id="feedbackModal" class="feedback-modal">
    <div class="feedback-content">
        <button class="fb-close-x" onclick="location.reload()">&times;</button>

        <div class="success-circle">✓</div>
        <div class="fb-title">הטעינה הושלמה!</div>
        <div id="fb-msg" class="fb-subtitle"></div>
        
        <div class="fb-cost-box">
            <span class="fb-cost-label">עלות כוללת</span>
            <span class="fb-cost-value">₪<span id="fb-price-val"></span></span>
        </div>
        
        <div style="font-weight: bold; margin-bottom: 12px; font-size: 17px;">דרג את החוויה שלך:</div>
        <div class="fb-stars">
            <input type="radio" id="st5" name="fb-grade" value="5"><label for="st5">★</label>
            <input type="radio" id="st4" name="fb-grade" value="4"><label for="st4">★</label>
            <input type="radio" id="st3" name="fb-grade" value="3"><label for="st3">★</label>
            <input type="radio" id="st2" name="fb-grade" value="2"><label for="st2">★</label>
            <input type="radio" id="st1" name="fb-grade" value="1"><label for="st1">★</label>
        </div>

        <textarea id="fb-comment-text" class="fb-textarea" placeholder="ספר לנו על מצב העמדה או השירות (אופציונלי)..."></textarea>
        
        <button onclick="saveFeedbackAndClose()" class="fb-btn-close">שמור וסגור</button>
    </div>
</div>

<?php if ($order_message): ?>
    <div id="orderStatusPopup" class="status-popup">
        <span><?php echo $order_message; ?></span>
        <button onclick="document.getElementById('orderStatusPopup').style.display='none'">×</button>
    </div>
<?php endif; ?>

<div class="map-wrapper">
    <div class="search-container">
        <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="searchInput" placeholder="חפש עיר, רחוב או עמדה..." autocomplete="off">
        </div>
        <ul id="searchResults" class="search-results"></ul>
    </div>

    <div id="map"></div>
    
    <?php if ($pending_approvals_count > 0): ?>
        <a href="my_stations_orders.php" class="pending-orders-badge">
            <?php echo $pending_approvals_count; ?> הזמנות ממתינות לאישורך
        </a>
    <?php endif; ?>

    <a href="add_station.php" class="add-station-btn">
        <div style="text-align: center">
        ➕ הוספת עמדה חדשה
    </div>
    </a>
</div>

<nav class="bottom-nav">
    <a href="personal_details.php" class="nav-item">
        <i class="fa-regular fa-user"></i>
        <span>אזור אישי</span>
    </a>
    <a href="recommended_stations.php" class="nav-item">
        <i class="fa-regular fa-star"></i>
        <span>עמדות מומלצות</span>
    </a>
    <a href="map.php" class="nav-item active">
        <div class="active-pill">
            <i class="fa-solid fa-house"></i>
            <span>מסך הבית</span>
        </div>
    </a>
</nav>

<div id="aiChatBubble" onclick="toggleAIChat()"><i class="fa-solid fa-robot"></i></div>

<div id="aiChatWindow">
    <div class="chat-header">
        <span>⚡ צ'אט בוט AI</span>
        <button onclick="toggleAIChat()" style="background:none; border:none; color:white; cursor:pointer; font-size:20px;">&times;</button>
    </div>
    <div id="aiChatContent">
        <div class="ai-msg bot-msg">היי! אני EvBot. איך אני יכול לעזור לך היום? 🚗⚡</div>
    </div>
    <div class="chat-input-area">
        <input type="text" id="aiUserInput" placeholder="כתוב הודעה...">
        <button onclick="sendToAI()" style="background:#2e7d32; color:white; border:none; width:35px; height:35px; border-radius:50%; cursor:pointer;"><i class="fa-solid fa-paper-plane"></i></button>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
     integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
     crossorigin=""></script>

<script>
    // --- תוספת: פונקציה להצגת התראות, הסתרת המספר ועדכון השרת ---
    function toggleNotifications() {
        var dropdown = document.getElementById('notifDropdown');
        var badge = document.getElementById('notifBadge');
        var isOpening = (dropdown.style.display !== 'flex');
        
        dropdown.style.display = isOpening ? 'flex' : 'none';
        
        if (isOpening && badge) {
            badge.style.display = 'none';
            // שליחת עדכון לשרת שראינו את ההתראות (שולח את מספר ההתראות הכולל ב-DB)
            const fd = new FormData();
            fd.append('mark_notifs_seen', '1');
            fd.append('total_count', '<?php echo $total_event_count; ?>');
            fetch('map.php', { method: 'POST', body: fd });
        }
    }

    // סגירת התפריט בלחיצה מחוץ אליו
    window.onclick = function(event) {
        if (!event.target.matches('.notif-bell') && !event.target.matches('.notif-badge') && !event.target.closest('.notif-dropdown')) {
            var dropdown = document.getElementById('notifDropdown');
            if(dropdown) dropdown.style.display = 'none';
        }
    }
    // ----------------------------------

    // --- משתנים גלובליים למשוב ---
    let currentFeedbackStationId = null;

    // --- לוגיקת טיימרSVG מעודכנת ---
    <?php if ($charging_order): ?>
    const orderStatus = "<?php echo $charging_order['status']; ?>";
    const startTime = new Date("<?php echo $charging_order['start_time']; ?>").getTime();
    const endTime = new Date("<?php echo $charging_order['end_time']; ?>").getTime();
    const totalDuration = endTime - startTime;
    
    // חישוב נקודת ה-75%
    const targetChargeDuration = totalDuration * 0.75;
    const simulatedChargeEndTime = startTime + targetChargeDuration;
    
    const orderId = <?php echo $charging_order['order_id']; ?>;

    const circle = document.getElementById('progressCircle');
    const radius = circle.r.baseVal.value;
    const circumference = radius * 2 * Math.PI;

    circle.style.strokeDasharray = `${circumference} ${circumference}`;

    function setProgress(percent, color) {
        const offset = circumference - (percent / 100 * circumference);
        circle.style.strokeDashoffset = offset;
        circle.style.stroke = color;
    }

    function updateTimer() {
        const now = new Date().getTime();
        const displayLabel = document.getElementById("timerStatusLabel");
        const finishBtn = document.getElementById("finishChargeBtn");
        const finishedHeader = document.getElementById("finishedHeader");

        if (orderStatus === 'charging') {
            const timeLeftForCharge = simulatedChargeEndTime - now;
            const timePassed = now - startTime;

            let progressPercent = (timePassed / targetChargeDuration) * 100;
            if (progressPercent > 100) progressPercent = 100;
            setProgress(progressPercent, "#2196F3");
            displayLabel.innerText = "Charging";
            finishedHeader.style.display = 'none';

            if (timeLeftForCharge <= 0) {
                updateChargingStatus('extra parking', false); 
                return;
            }

            formatTimeDisplay(timeLeftForCharge);
        } else {
            // מצב EXTRA PARKING
            const timeLeftForStay = endTime - now;
            const timeInExtra = now - simulatedChargeEndTime;
            const extraWindow = endTime - simulatedChargeEndTime;

            let progressPercent = (timeInExtra / extraWindow) * 100;
            if (progressPercent > 100) progressPercent = 100;
            setProgress(progressPercent, "#FF9800");
            
            displayLabel.innerText = "זמן לפינוי העמדה";
            finishedHeader.style.display = 'block';
            if(finishBtn) finishBtn.style.display = 'none';

            if (timeLeftForStay <= 0) {
                updateChargingStatus('completed', false);
                return;
            }

            formatTimeDisplay(timeLeftForStay);
        }
    }

    function formatTimeDisplay(ms) {
        const hours = Math.floor((ms % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((ms % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((ms % (1000 * 60)) / 1000);

        let timeStr = "";
        if (hours > 0) timeStr += (hours < 10 ? "0" + hours : hours) + ":";
        timeStr += (minutes < 10 ? "0" + minutes : minutes) + ":";
        timeStr += (seconds < 10 ? "0" + seconds : seconds);
        document.getElementById("timerDisplay").innerHTML = timeStr;
    }

    setInterval(updateTimer, 1000);
    updateTimer();

    function closeChargingOverlay(e) {
        // מונע סגירה אם פופ-אפ המשוב פתוח
        if(document.getElementById('feedbackModal').style.display === 'flex') return;
        document.getElementById('chargingOverlay').style.display = 'none';
    }

    function updateChargingStatus(status, needsConfirm = true) {
        if (needsConfirm && !confirm("האם אתה בטוח שברצונך לבצע פעולה זו?")) return;

        const formData = new FormData();
        formData.append('update_order_status', '1');
        formData.append('order_id', orderId);
        formData.append('new_status', status);

        fetch('map.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => { 
            if (data.success) {
                if (status === 'completed') {
                    // בדיקה האם להציג משוב או פשוט לרענן (אם כבר דורג)
                    if (data.already_rated) {
                        location.reload();
                    } else {
                        showFeedbackModal(data.final_price, data.station_name, data.station_id);
                    }
                } else {
                    location.reload(); 
                }
            } 
        });
    }

    // פונקציות המשוב החדשות
    function showFeedbackModal(price, name, sid) {
        document.getElementById('chargingOverlay').style.display = 'none';
        document.getElementById('fb-price-val').innerText = price;
        document.getElementById('fb-msg').innerText = `השהות שלך בעמדת ${name} הסתיימה בהצלחה.`;
        currentFeedbackStationId = sid;
        document.getElementById('feedbackModal').style.display = 'flex';
    }

    function saveFeedbackAndClose() {
        const gradeInput = document.querySelector('input[name="fb-grade"]:checked');
        const grade = gradeInput ? gradeInput.value : 0;
        const comment = document.getElementById('fb-comment-text').value;

        const fd = new FormData();
        fd.append('save_feedback', '1');
        fd.append('station_id', currentFeedbackStationId);
        fd.append('grade', grade);
        fd.append('comment', comment);

        fetch('map.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                location.reload(); // בסיום הכל חוזרים למפה
            }
        });
    }
    <?php endif; ?>

    // שאר לוגיקת המפה ללא שינוי...
    if (document.getElementById('orderStatusPopup')) {
        setTimeout(function() {
            var popup = document.getElementById('orderStatusPopup');
            popup.style.transition = "opacity 0.5s ease";
            popup.style.opacity = "0";
            setTimeout(() => popup.remove(), 500);
        }, 5000);
    }

    var map = L.map('map', {zoomControl: false}).setView([31.4117, 35.0818], 8);
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var greenIcon = new L.Icon({
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
        iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
    });

    var redIcon = new L.Icon({
        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
        iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
    });

    var homeIcon = L.divIcon({ html: '🏠', className: 'home-emoji-marker', iconSize: [32, 32], iconAnchor: [16, 16] });

    var stationsData = <?php echo $json_stations; ?>;
    var favoritesData = <?php echo $json_favorites; ?>;

    stationsData.forEach(function(station) {
        if (station.latitude && station.longitude) {
            var markerIcon;
            if (parseInt(station.is_my_station) > 0) markerIcon = homeIcon;
            else {
                var isOpen = parseInt(station.is_open_now) > 0;
                var isBusy = parseInt(station.is_busy_now) > 0;
                markerIcon = (isOpen && !isBusy) ? greenIcon : redIcon;
            }
            var marker = L.marker([station.latitude, station.longitude], {icon: markerIcon}).addTo(map);
            marker.on('click', function() {
                if (parseInt(station.is_my_station) > 0) window.location.href = `personal_details.php?edit_station=${station.station_id}`;
                else window.location.href = `station.php?id=${station.station_id}`;
            });
        }
    });

    var userLocationMarker = null;
    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(function(position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            if (!userLocationMarker) {
                userLocationMarker = L.circleMarker([lat, lng], { radius: 8, fillColor: "#2196F3", color: "#fff", weight: 2, opacity: 1, fillOpacity: 0.9 }).addTo(map).bindPopup("אתה כאן");
            } else userLocationMarker.setLatLng([lat, lng]);
        }, null, {enableHighAccuracy: true});
    }

    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');

    function displayFavorites() {
        if (favoritesData.length === 0) searchResults.innerHTML = '<div class="search-section-title">⭐ מועדפים</div><li style="text-align:center; color:#999;">אין עמדות מועדפות</li>';
        else {
            let html = '<div class="search-section-title">⭐ מועדפים</div>';
            favoritesData.forEach(station => { html += `<li onclick="window.location.href='station.php?id=${station.station_id}'"><span class="highlight-city">${station.city}</span> - ${station.address}<span class="station-subtitle">${station.name}</span></li>`; });
            searchResults.innerHTML = html;
        }
        searchResults.style.display = 'block';
    }

    searchInput.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        searchResults.innerHTML = '';
        if (query.length === 0) { displayFavorites(); return; }
        const filtered = stationsData.filter(s => (s.city||'').toLowerCase().includes(query) || (s.address||'').toLowerCase().includes(query) || (s.name||'').toLowerCase().includes(query));
        if (filtered.length > 0) {
            searchResults.style.display = 'block';
            filtered.slice(0, 8).forEach(s => {
                const li = document.createElement('li');
                li.innerHTML = `<span class="highlight-city">${s.city}</span> - ${s.address}<span class="station-subtitle">${s.name}</span>`;
                li.addEventListener('click', () => { window.location.href = `station.php?id=${s.station_id}`; });
                searchResults.appendChild(li);
            });
        } else { searchResults.style.display = 'block'; searchResults.innerHTML = '<li>לא נמצאו עמדות</li>'; }
    });

    document.addEventListener('click', e => { if (!document.querySelector('.search-container').contains(e.target)) searchResults.style.display = 'none'; });
    searchInput.addEventListener('focus', () => { if (searchInput.value.trim().length === 0) displayFavorites(); else searchResults.style.display = 'block'; });

    // --- חדש: לוגיקת ה-JavaScript של הצ'אט AI ---
    function toggleAIChat() {
        const windowChat = document.getElementById('aiChatWindow');
        const bubble = document.getElementById('aiChatBubble');
        if (windowChat.style.display === 'none' || windowChat.style.display === '') {
            windowChat.style.display = 'flex';
            bubble.style.display = 'none';
        } else {
            windowChat.style.display = 'none';
            bubble.style.display = 'flex';
        }
    }

    function sendToAI() {
        const input = document.getElementById('aiUserInput');
        const content = document.getElementById('aiChatContent');
        const msg = input.value.trim();
        if (!msg) return;

        // הצגת הודעת המשתמש
        content.innerHTML += `<div class="ai-msg user-msg">${msg}</div>`;
        input.value = '';
        content.scrollTop = content.scrollHeight;

        // הצגת אינדיקטור טעינה
        const loadingId = 'loading_' + Date.now();
        content.innerHTML += `<div id="${loadingId}" class="ai-msg bot-msg">...</div>`;
        content.scrollTop = content.scrollHeight;

        const fd = new FormData();
        fd.append('message', msg);

        fetch('ask_ai.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(answer => {
            const loadingEl = document.getElementById(loadingId);
            if(loadingEl) loadingEl.remove();
            content.innerHTML += `<div class="ai-msg bot-msg">${answer}</div>`;
            content.scrollTop = content.scrollHeight;
        });
    }

    document.getElementById('aiUserInput').addEventListener('keypress', e => {
        if (e.key === 'Enter') sendToAI();
    });
</script>

</body>
</html>