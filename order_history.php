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

// --- עדכון אוטומטי: אם ההזמנה בסטטוס pending ושעת ההזמנה הגיעה/עברה - הסטטוס משתנה ל-rejected ---
$auto_reject_sql = "UPDATE orders SET status = 'rejected' WHERE status = 'pending' AND start_time <= NOW()";
$conn->query($auto_reject_sql);

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = "";

// --- חדש: טיפול בביטול הזמנה ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_order'])) {
    $order_id_to_cancel = $_POST['order_id'];
    
    // --- תוספת: שליפת פרטי הבעלים והעמדה לפני הביטול לצורך שליחת מייל ---
    $get_owner_info = "SELECT s.name as station_name, u_owner.email as owner_email, u_owner.name as owner_name, u_booker.name as booker_name
                       FROM orders o
                       JOIN station s ON o.station_id = s.station_id
                       JOIN owner_stations os ON s.station_id = os.station_id
                       JOIN user u_owner ON os.user_id = u_owner.uid
                       JOIN user u_booker ON o.user_id = u_booker.uid
                       WHERE o.order_id = ?";
    $stmt_info = $conn->prepare($get_owner_info);
    $stmt_info->bind_param("i", $order_id_to_cancel);
    $stmt_info->execute();
    $owner_info = $stmt_info->get_result()->fetch_assoc();

    // עדכון הסטטוס ל-cancelled רק אם ההזמנה שייכת למשתמש ובסטטוס המתאים
    $cancel_sql = "UPDATE orders SET status = 'cancelled' WHERE order_id = ? AND user_id = ? AND status IN ('pending', 'approved')";
    $stmt_cancel = $conn->prepare($cancel_sql);
    $stmt_cancel->bind_param("ii", $order_id_to_cancel, $user_id);
    
    if ($stmt_cancel->execute() && $stmt_cancel->affected_rows > 0) {
        $message = "ההזמנה בוטלה בהצלחה.";
        $message_type = "success";

        // --- תוספת: שליחת המייל לבעל העמדה ---
        if ($owner_info) {
            $subject = "ביטול הזמנה - עמדת " . $owner_info['station_name'];
            $body = "
                <div dir='rtl' style='font-family: Arial, sans-serif;'>
                    <h2>שלום {$owner_info['owner_name']},</h2>
                    <p>ההזמנה לעמדת הטעינה שלך (<b>{$owner_info['station_name']}</b>) בוטלה על ידי המשתמש <b>{$owner_info['booker_name']}</b>.</p>
                    <p>העמדה זמינה כעת להזמנות חדשות במועד זה.</p>
                    <br>
                    <p>בברכה,<br>צוות EvEnergy</p>
                </div>";
            sendSystemEmail($owner_info['owner_email'], $subject, $body);
        }
    }
}

// --- טיפול בשליחת דירוג מהדף ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_rating'])) {
    $station_id = $_POST['station_id'];
    $new_grade = (int)$_POST['rating_value'];

    if ($new_grade >= 1 && $new_grade <= 5) {
        // בדיקה/עדכון בטבלת rating
        $check_rating = $conn->prepare("SELECT id FROM rating WHERE user_id = ? AND station_id = ?");
        $check_rating->bind_param("is", $user_id, $station_id);
        $check_rating->execute();
        
        if ($check_rating->get_result()->num_rows > 0) {
            $update = $conn->prepare("UPDATE rating SET grade = ? WHERE user_id = ? AND station_id = ?");
            $update->bind_param("iis", $new_grade, $user_id, $station_id);
            $update->execute();
        } else {
            $insert = $conn->prepare("INSERT INTO rating (user_id, station_id, grade) VALUES (?, ?, ?)");
            $insert->bind_param("isi", $user_id, $station_id, $new_grade);
            $insert->execute();
        }

        // עדכון הממוצע בטבלת station
        $avg_query = $conn->prepare("SELECT AVG(grade) as avg_grade FROM rating WHERE station_id = ?");
        $avg_query->bind_param("s", $station_id);
        $avg_query->execute();
        $avg_result = $avg_query->get_result()->fetch_assoc();
        $new_average = round($avg_result['avg_grade'], 2);

        $update_station = $conn->prepare("UPDATE station SET grade = ? WHERE station_id = ?");
        $update_station->bind_param("ds", $new_average, $station_id);
        $update_station->execute();

        $message = "תודה! הדירוג עודכן בהצלחה.";
        $message_type = "success";
    }
}

// --- שליפת ההזמנות של המשתמש כולל פרטי העמדה והדירוג האישי שלו ---
$sql = "SELECT o.*, s.name as station_name, s.image as station_image, s.city, s.address, MAX(r.grade) as user_rating 
        FROM orders o 
        JOIN station s ON o.station_id = s.station_id 
        LEFT JOIN rating r ON o.station_id = r.station_id AND r.user_id = o.user_id
        WHERE o.user_id = ? 
        GROUP BY o.order_id
        ORDER BY o.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders_result = $stmt->get_result();

$conn->close();
?>

<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>היסטוריית הזמנות</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: linear-gradient(to bottom, #e8f5e9, #ffffff); padding: 20px 20px 100px 20px; min-height: 100vh; }
        .container { max-width: 800px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 15px 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h1 { color: #2e7d32; margin: 0; font-size: 24px; }
        .back-btn { text-decoration: none; color: #666; border: 1px solid #ccc; padding: 8px 15px; border-radius: 6px; font-size: 14px; transition: 0.2s; }
        
        .order-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; display: flex; gap: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); transition: transform 0.2s; cursor: pointer; }
        .order-card:hover { transform: translateY(-3px); background-color: #f9f9f9; }
        .station-img { width: 120px; height: 120px; border-radius: 8px; object-fit: cover; background: #eee; }
        
        .order-details { flex-grow: 1; }
        .order-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .station-name { font-size: 18px; font-weight: bold; color: #2e7d32; margin: 0; }
        .order-date { font-size: 13px; color: #888; }
        
        .info-row { font-size: 14px; color: #444; margin-bottom: 5px; }
        .info-row strong { color: #333; }
        .actual-times { background: #f1f8e9; padding: 8px; border-radius: 6px; margin-top: 8px; border: 1px dashed #2e7d32; }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .status-completed { background: #e8f5e9; color: #2e7d32; }
        .status-pending { background: #fff3e0; color: #ef6c00; }
        .status-approved { background: #e3f2fd; color: #1565c0; }
        .status-rejected { background: #ffebee; color: #c62828; }
        .status-cancelled { background: #f5f5f5; color: #757575; border: 1px solid #ddd; }
        .status-charging { background: #e1f5fe; color: #0288d1; border: 1px solid #b3e5fc; }
        .status-extra-parking { background: #f3e5f5; color: #7b1fa2; border: 1px solid #e1bee7; }

        .total-price { font-size: 18px; font-weight: bold; color: #2e7d32; margin-top: 10px; }

        /* עיצוב כפתור ביטול */
        .cancel-order-btn { background: none; border: 1px solid #c62828; color: #c62828; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; transition: 0.2s; margin-top: 5px; }
        .cancel-order-btn:hover { background: #ffebee; }

        .rating-area { margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; display: flex; align-items: center; gap: 10px; }
        .star-rating { display: flex; flex-direction: row-reverse; gap: 3px; }
        .star-rating input { display: none; }
        .star-rating label { font-size: 22px; color: #ddd; cursor: pointer; }
        .star-rating input:checked ~ label { color: #ffca28; }
        .star-rating label:hover, .star-rating label:hover ~ label { color: #ffca28; }
        .rate-submit-btn { background: #2e7d32; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; }

        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .message.success { background: #d4edda; color: #155724; }

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

        @media (max-width: 600px) {
            .order-card { flex-direction: column; }
            .station-img { width: 100%; height: 150px; }
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>היסטוריית ההזמנות שלי</h1>
    </header>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($orders_result->num_rows > 0): ?>
        <?php while($order = $orders_result->fetch_assoc()): ?>
            <div class="order-card" onclick="window.location.href='station.php?id=<?php echo $order['station_id']; ?>'">
                <img src="<?php echo (!empty($order['station_image'])) ? $order['station_image'] : 'images/default_station.png'; ?>" class="station-img" alt="עמדה">
                
                <div class="order-details">
                    <div class="order-header">
                        <div>
                            <h3 class="station-name"><?php echo htmlspecialchars($order['station_name']); ?></h3>
                            <span class="order-date">הוזמן ב: <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></span>
                        </div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                            <span class="status-badge status-<?php echo $order['status']; ?>">
                                <?php 
                                    $status_names = [
                                        'pending' => 'ממתין', 
                                        'approved' => 'אושר', 
                                        'rejected' => 'נדחה', 
                                        'completed' => 'הושלם', 
                                        'cancelled' => 'בוטל',
                                        'charging' => 'בטעינה פעילה ⚡',
                                        'extra parking' => 'בחניה 🕒'
                                    ];
                                    echo $status_names[$order['status']]; 
                                ?>
                            </span>
                            
                            <?php if ($order['status'] == 'pending' || $order['status'] == 'approved'): ?>
                                <form method="POST" onsubmit="return confirm('האם אתה בטוח שברצונך לבטל את ההזמנה?');" onclick="event.stopPropagation();">
                                    <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                    <button type="submit" name="cancel_order" class="cancel-order-btn">ביטול הזמנה</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-row">📍 <strong>כתובת:</strong> <?php echo htmlspecialchars($order['city'] . ", " . $order['address']); ?></div>
                    <div class="info-row">📅 <strong>זמן מוזמן:</strong> <?php echo date('H:i', strtotime($order['start_time'])); ?> - <?php echo date('H:i', strtotime($order['end_time'])); ?></div>
                    
                    <?php if (in_array($order['status'], ['completed', 'charging', 'extra parking'])): ?>
                        <div class="actual-times">
                            <div class="info-row">⚡ <strong>זמן טעינה בפועל:</strong> <?php echo substr($order['charging_time'], 0, 5); ?> שעות</div>
                            <div class="info-row">🕒 <strong>חניה נוספת:</strong> <?php echo substr($order['extra_parking_time'], 0, 5); ?> שעות</div>
                        </div>
                    <?php endif; ?>

                    <div class="total-price">₪<?php echo number_format($order['total_price'], 2); ?></div>

                    <?php if ($order['status'] == 'completed'): ?>
                        <div class="rating-area" onclick="event.stopPropagation();">
                            <span style="font-size: 13px; font-weight: bold;">דרג את העמדה:</span>
                            <form method="POST" style="display: flex; align-items: center; gap: 10px;">
                                <input type="hidden" name="station_id" value="<?php echo $order['station_id']; ?>">
                                <div class="star-rating">
                                    <?php for($i=5; $i>=1; $i--): ?>
                                        <input type="radio" 
                                               id="star<?php echo $i . '_' . $order['order_id']; ?>" 
                                               name="rating_value" 
                                               value="<?php echo $i; ?>" 
                                               required
                                               <?php echo (isset($order['user_rating']) && $order['user_rating'] == $i) ? 'checked' : ''; ?>>
                                        <label for="star<?php echo $i . '_' . $order['order_id']; ?>">★</label>
                                    <?php endfor; ?>
                                </div>
                                <button type="submit" name="submit_rating" class="rate-submit-btn">עדכן דירוג</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 50px; background: white; border-radius: 12px;">
            <p style="font-size: 18px; color: #666;">טרם ביצעת הזמנות במערכת.</p>
            <a href="map.php" class="rate-submit-btn" style="text-decoration: none; padding: 10px 20px;">חפש עמדה קרובה</a>
        </div>
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