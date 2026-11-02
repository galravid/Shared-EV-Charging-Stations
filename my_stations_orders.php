<?php
session_start();
include 'email_helper.php'; // הוספת הקובץ לשליחת מיילים

// 1. בדיקת אבטחה: האם המשתמש מחובר?
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// הגדרת אזור זמן ישראל
date_default_timezone_set('Asia/Jerusalem');

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

// --- תוספת: בדיקה כמה עמדות יש למשתמש בסך הכל ---
$count_stations_sql = "SELECT COUNT(*) as total_count FROM owner_stations WHERE user_id = ?";
$stmt_count = $conn->prepare($count_stations_sql);
$stmt_count->bind_param("i", $user_id);
$stmt_count->execute();
$total_owned_stations = $stmt_count->get_result()->fetch_assoc()['total_count'];
// ---------------------------------------------

// בדיקה האם המשתמש ביקש לראות עמדה ספציפית
$filter_station = isset($_GET['station_id']) ? $_GET['station_id'] : null;

// --- חלק א: טיפול באישור או דחייה של הזמנה ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && isset($_POST['order_id'])) {
        $order_id = $_POST['order_id'];
        $new_status = ($_POST['action'] == 'approve') ? 'approved' : 'rejected';
        
        // --- תוספת: שליפת פרטי המזמין והעמדה לפני העדכון לצורך שליחת מייל ---
        $info_sql = "SELECT u.email as customer_email, u.name as customer_name, s.name as station_name, o.start_time, o.end_time 
                     FROM orders o 
                     JOIN user u ON o.user_id = u.uid 
                     JOIN station s ON o.station_id = s.station_id 
                     WHERE o.order_id = ?";
        $stmt_info = $conn->prepare($info_sql);
        $stmt_info->bind_param("i", $order_id);
        $stmt_info->execute();
        $order_details = $stmt_info->get_result()->fetch_assoc();

        $update_sql = "UPDATE orders SET status = ? WHERE order_id = ?";
        $stmt_update = $conn->prepare($update_sql);
        $stmt_update->bind_param("si", $new_status, $order_id);
        
        if ($stmt_update->execute()) {
            
            // --- תוספת: שליחת המייל למזמין ---
            if ($order_details) {
                $to_email = $order_details['customer_email'];
                $customer_name = $order_details['customer_name'];
                $station_name = $order_details['station_name'];
                $start_formatted = date('d/m/Y H:i', strtotime($order_details['start_time']));
                
                if ($new_status == 'approved') {
                    $subject = "הזמנתך לעמדת הטעינה אושרה! ✅";
                    $body = "<h2>שלום {$customer_name},</h2>
                             <p>יש לנו עדכון משמח! בעל העמדה <b>{$station_name}</b> אישר את הזמנתך.</p>
                             <p><b>פרטי ההזמנה המאושרת:</b><br>
                             זמן התחלה: {$start_formatted}</p>
                             <p>ניתן להגיע לעמדה בזמן שנקבע. נסיעה טובה!</p>";
                } else {
                    $subject = "עדכון בנוגע להזמנת עמדת טעינה ❌";
                    $body = "<h2>שלום {$customer_name},</h2>
                             <p>מצטערים, אך בקשתך להזמנת העמדה <b>{$station_name}</b> עבור הזמן שביקשת ({$start_formatted}) נדחתה על ידי בעל העמדה.</p>
                             <p>אנו מזמינים אותך לחפש עמדות פנויות אחרות באזור דרך המפה.</p>";
                }
                
                sendSystemEmail($to_email, $subject, $body);
            }

            $message = ($new_status == 'approved') ? "ההזמנה אושרה בהצלחה! המזמין קיבל הודעה." : "ההזמנה נדחתה. המזמין קיבל הודעה.";
            $message_type = "success";
        } else {
            $message = "שגיאה בעיבוד הבקשה.";
            $message_type = "error";
        }
    }
}

// --- חלק ב: שליפת הזמנות וארגונן לפי עמדות ---

// 1. ארגון ממתינות
$pending_sql = "SELECT o.*, s.name as station_name, u.name as customer_name, u.phone_number as customer_phone
                FROM orders o
                JOIN station s ON o.station_id = s.station_id
                JOIN user u ON o.user_id = u.uid
                JOIN owner_stations os ON s.station_id = os.station_id
                WHERE os.user_id = ? AND o.status = 'pending'";
if ($filter_station) { $pending_sql .= " AND s.station_id = '" . $conn->real_escape_string($filter_station) . "'"; }
$pending_sql .= " ORDER BY s.name ASC, o.created_at DESC";

$stmt_p = $conn->prepare($pending_sql);
$stmt_p->bind_param("i", $user_id);
$stmt_p->execute();
$res_p = $stmt_p->get_result();

$pending_by_station = [];
while($row = $res_p->fetch_assoc()) {
    $pending_by_station[$row['station_name']][] = $row;
}

// 2. ארגון היסטוריה
$history_sql = "SELECT o.*, s.name as station_name, u.name as customer_name, u.phone_number as customer_phone
                FROM orders o
                JOIN station s ON o.station_id = s.station_id
                JOIN user u ON o.user_id = u.uid
                JOIN owner_stations os ON s.station_id = os.station_id
                WHERE os.user_id = ? AND o.status != 'pending'";
if ($filter_station) { $history_sql .= " AND s.station_id = '" . $conn->real_escape_string($filter_station) . "'"; }
$history_sql .= " ORDER BY s.name ASC, o.created_at DESC";

$stmt_h = $conn->prepare($history_sql);
$stmt_h->bind_param("i", $user_id);
$stmt_h->execute();
$res_h = $stmt_h->get_result();

$history_by_station = [];
while($row = $res_h->fetch_assoc()) {
    $history_by_station[$row['station_name']][] = $row;
}

$conn->close();
?>

<!DOCTYPE html>

<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ניהול הזמנות עמדה</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: linear-gradient(to bottom, #e8f5e9, #ffffff); padding: 20px 20px 100px 20px; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; }
        
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 15px 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h1 { color: #2e7d32; margin: 0; font-size: 24px; }
        .back-btn { text-decoration: none; color: #666; border: 1px solid #ccc; padding: 8px 15px; border-radius: 6px; font-size: 14px; transition: 0.2s; }
        .back-btn:hover { background: #f4f4f4; }

        .section-main-title { background: #2e7d32; color: white; padding: 10px 20px; border-radius: 8px; margin: 40px 0 20px 0; font-size: 22px; display: flex; align-items: center; gap: 10px; }
        
        /* כותרת עמדה ספציפית */
        .station-group-header { background: #e8f5e9; color: #2e7d32; padding: 8px 15px; border-radius: 6px; margin: 20px 0 15px 0; font-weight: bold; border-right: 5px solid #2e7d32; font-size: 18px; }

        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

        .order-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .pending-card { border-right: 5px solid #ff9800; }
        .history-card { border-right: 5px solid #ccc; }

        .order-info-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 15px; align-items: center; }
        .info-item { font-size: 14px; color: #444; }
        .info-item strong { color: #2e7d32; display: block; font-size: 12px; margin-bottom: 2px; }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; display: inline-block; }
        .status-approved { background: #e3f2fd; color: #1565c0; }
        .status-rejected { background: #ffebee; color: #c62828; }
        .status-completed { background: #e8f5e9; color: #2e7d32; }
        .status-cancelled { background: #f5f5f5; color: #757575; }
        .status-charging { background: #e1f5fe; color: #0288d1; border: 1px solid #b3e5fc; } 
        .status-extra-parking { background: #f3e5f5; color: #7b1fa2; border: 1px solid #e1bee7; } 

        .actions { display: flex; gap: 8px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
        .btn { flex: 1; padding: 10px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-approve { background: #2e7d32; color: white; }
        .btn-reject { background: #c62828; color: white; }

        .empty-state { text-align: center; padding: 30px; background: rgba(255,255,255,0.5); border-radius: 12px; color: #888; font-style: italic; margin-bottom: 20px; }

        /* --- עיצוב סרגל ניווט תחתון --- */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: white;
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 10px 0 25px 0;
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

        .nav-item.active .active-pill {
            background-color: #e8f5e9;
            padding: 8px 25px;
            border-radius: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>ניהול הזמנות עמדה</h1>
    </header>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="section-main-title">🕒 בקשות חדשות הממתינות לאישור</div>
    
    <?php if (empty($pending_by_station)): ?>
        <div class="empty-state">אין בקשות חדשות כרגע.</div>
    <?php else: ?>
        <?php foreach ($pending_by_station as $s_name => $orders): ?>
            <div class="station-group-header">עמדה: <?php echo htmlspecialchars($s_name); ?></div>
            <?php foreach ($orders as $order): ?>
                <div class="order-card pending-card">
                    <div class="order-info-grid">
                        <div class="info-item">
                            <strong>מזמין</strong>
                            <b><?php echo htmlspecialchars($order['customer_name']); ?></b><br>
                            📞 <?php echo htmlspecialchars($order['customer_phone']); ?>
                        </div>
                        <div class="info-item">
                            <strong>זמן מבוקש</strong>
                            <?php echo date('d/m/Y', strtotime($order['start_time'])); ?><br>
                            <?php echo date('H:i', strtotime($order['start_time'])); ?> - <?php echo date('H:i', strtotime($order['end_time'])); ?>
                        </div>
                        <div class="info-item">
                            <strong>סכום לזיכוי</strong>
                            <span style="font-size:16px; font-weight:bold; color:#2e7d32;">₪<?php echo number_format($order['total_price'], 2); ?></span>
                        </div>
                    </div>
                    <form method="POST" class="actions">
                        <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                        <button type="submit" name="action" value="approve" class="btn btn-approve">אשר הזמנה</button>
                        <button type="submit" name="action" value="reject" class="btn btn-reject">דחה בקשה</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="section-main-title">📜 היסטוריית הזמנות מלאה</div>
    
    <?php if (empty($history_by_station)): ?>
        <div class="empty-state">טרם בוצעו הזמנות בעמדות אלו.</div>
    <?php else: ?>
        <?php foreach ($history_by_station as $s_name => $orders): ?>
            <div class="station-group-header">עמדה: <?php echo htmlspecialchars($s_name); ?></div>
            <?php foreach ($orders as $order): ?>
                <div class="order-card history-card">
                    <div class="order-info-grid">
                        <div class="info-item">
                            <strong>לקוח</strong>
                            <?php echo htmlspecialchars($order['customer_name']); ?>
                        </div>
                        <div class="info-item">
                            <strong>תאריך</strong>
                            <?php echo date('d/m/Y', strtotime($order['start_time'])); ?><br>
                            <small><?php echo date('H:i', strtotime($order['start_time'])); ?> - <?php echo date('H:i', strtotime($order['end_time'])); ?></small>
                        </div>
                        <div class="info-item" style="text-align: left;">
                            <span class="status-badge status-<?php echo $order['status']; ?>">
                                <?php 
                                    $st = [
                                        'approved'=>'אושר', 
                                        'rejected'=>'נדחה', 
                                        'completed'=>'הושלם', 
                                        'cancelled'=>'בוטל',
                                        'charging' => 'בטעינה פעילה ⚡',
                                        'extra parking' => 'חניה בחריגה 🕒'
                                    ];
                                    echo $st[$order['status']];
                                ?>
                            </span><br>
                            <b>₪<?php echo number_format($order['total_price'], 2); ?></b>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
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
    <a href="map.php" class="nav-item">
        <i class="fa-solid fa-house"></i>
        <span>מסך הבית</span>
    </a>
</nav>

</body>
</html>