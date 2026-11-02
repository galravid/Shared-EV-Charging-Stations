<?php
session_start();

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

// --- תוספת: בדיקה אם המשתמש יכול להתחיל טעינה כרגע ---
$can_start_charging = false;
$active_order_id = null;
$check_active_sql = "SELECT order_id FROM orders 
                     WHERE user_id = ? AND station_id = ? 
                     AND status = 'approved' 
                     AND NOW() BETWEEN start_time AND end_time 
                     LIMIT 1";
$stmt_active = $conn->prepare($check_active_sql);
$stmt_active->bind_param("is", $user_id, $station_id);
$stmt_active->execute();
$res_active = $stmt_active->get_result();
if ($res_active->num_rows > 0) {
    $order_row = $res_active->fetch_assoc();
    $active_order_id = $order_row['order_id'];
    $can_start_charging = true;
}

// --- בתוך station.php: עדכון הטיפול בלחיצה על התחל טעינה ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_start_charging'])) {
    $oid = $_POST['target_order_id'];
    $update_charging = $conn->prepare("UPDATE orders SET status = 'charging' WHERE order_id = ?");
    $update_charging->bind_param("i", $oid);
    if ($update_charging->execute()) {
        // הוספת הודעת הסטטוס לסשן לפני המעבר למפה
        $_SESSION['order_status_msg'] = "הטעינה החלה בהצלחה! ⚡"; 
        header("Location: map.php");
        exit();
    }
}

// --- תוספת: טיפול בהוספה/הסרה ממועדפים ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_favorite'])) {
    $check_fav = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND station_id = ?");
    $check_fav->bind_param("is", $user_id, $station_id);
    $check_fav->execute();
    $fav_res = $check_fav->get_result();

    if ($fav_res->num_rows > 0) {
        $del_fav = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND station_id = ?");
        $del_fav->bind_param("is", $user_id, $station_id);
        $del_fav->execute();
        $message = "העמדה הוסרה מהמועדפים.";
        $message_type = "success";
    } else {
        $ins_fav = $conn->prepare("INSERT INTO favorites (user_id, station_id) VALUES (?, ?)");
        $ins_fav->bind_param("is", $user_id, $station_id);
        $ins_fav->execute();
        $message = "העמדה נוספה למועדפים שלך!";
        $message_type = "success";
    }
}

// --- חלק 1: טיפול בשליחת דירוג חדש ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_rating'])) {
    $new_grade = (int)$_POST['rating_value'];
    
    if ($new_grade >= 1 && $new_grade <= 5) {
        // בדיקה האם המשתמש כבר דירג את העמדה הזו בעבר
        $check_rating = $conn->prepare("SELECT id FROM rating WHERE user_id = ? AND station_id = ?");
        $check_rating->bind_param("is", $user_id, $station_id);
        $check_rating->execute();
        $rating_result = $check_rating->get_result();

        if ($rating_result->num_rows > 0) {
            // עדכון דירוג קיים
            $update_rating = $conn->prepare("UPDATE rating SET grade = ? WHERE user_id = ? AND station_id = ?");
            $update_rating->bind_param("iis", $new_grade, $user_id, $station_id);
            $update_rating->execute();
        } else {
            // הכנסת דירוג חדש
            $insert_rating = $conn->prepare("INSERT INTO rating (user_id, station_id, grade) VALUES (?, ?, ?)");
            $insert_rating->bind_param("isi", $user_id, $station_id, $new_grade);
            $insert_rating->execute();
        }

        // חישוב הממוצע החדש של העמדה
        $avg_query = $conn->prepare("SELECT AVG(grade) as avg_grade FROM rating WHERE station_id = ?");
        $avg_query->bind_param("s", $station_id);
        $avg_query->execute();
        $avg_result = $avg_query->get_result();
        $avg_row = $avg_result->fetch_assoc();
        $new_average = round($avg_row['avg_grade'], 2);

        // עדכון הממוצע בטבלת station
        $update_station = $conn->prepare("UPDATE station SET grade = ? WHERE station_id = ?");
        $update_station->bind_param("ds", $new_average, $station_id);
        
        if ($update_station->execute()) {
            $message = "הדירוג שלך נשמר בהצלחה!";
            $message_type = "success";
        } else {
            $message = "שגיאה בעדכון הדירוג.";
            $message_type = "error";
        }
    }
}

// --- חלק 2: שליפת פרטי העמדה ---
$stmt = $conn->prepare("SELECT * FROM station WHERE station_id = ?");
$stmt->bind_param("s", $station_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("העמדה לא נמצאה.");
}

$station = $result->fetch_assoc();

// --- תוספת: שליפת שעות פתיחה ---
$hours_stmt = $conn->prepare("SELECT * FROM opening_hours WHERE station_id = ? ORDER BY day_of_week ASC");
$hours_stmt->bind_param("s", $station_id);
$hours_stmt->execute();
$hours_result = $hours_stmt->get_result();
$opening_hours = [];
while($h_row = $hours_result->fetch_assoc()) {
    $opening_hours[$h_row['day_of_week']] = $h_row;
}

// --- תוספת: בדיקה אם העמדה במועדפים ---
$is_fav = false;
$fav_check = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND station_id = ?");
$fav_check->bind_param("is", $user_id, $station_id);
$fav_check->execute();
if ($fav_check->get_result()->num_rows > 0) { $is_fav = true; }

// --- חלק 3: שליפת הדירוג הספציפי שהמשתמש הזה נתן בעבר ---
$user_grade = 0;
$user_grade_query = $conn->prepare("SELECT grade FROM rating WHERE user_id = ? AND station_id = ?");
$user_grade_query->bind_param("is", $user_id, $station_id);
$user_grade_query->execute();
$user_grade_result = $user_grade_query->get_result();
if ($user_grade_result->num_rows > 0) {
    $user_grade_row = $user_grade_result->fetch_assoc();
    $user_grade = $user_grade_row['grade'];
}

// --- תוספת: בדיקה האם המשתמש הטעין בעמדה זו וסיים (completed) כדי לאפשר דירוג ---
$can_rate = false;
$order_check_query = "SELECT order_id FROM orders WHERE user_id = ? AND station_id = ? AND status = 'completed' LIMIT 1";
$order_check_stmt = $conn->prepare($order_check_query);
$order_check_stmt->bind_param("is", $user_id, $station_id);
$order_check_stmt->execute();
if ($order_check_stmt->get_result()->num_rows > 0) {
    $can_rate = true;
}

// --- תוספת: בדיקה האם קיים אמצעי תשלום ---
$has_payment = false;
$payment_check = $conn->prepare("SELECT id FROM payment_method WHERE user_id = ? LIMIT 1");
$payment_check->bind_param("i", $user_id);
$payment_check->execute();
if ($payment_check->get_result()->num_rows > 0) {
    $has_payment = true;
}

// --- תוספת: טיפול בהוספה/הסרה ממועדפים ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_card_submit'])) {
    $cardholder = trim($_POST['cardholder_name']); 
    $card_number = str_replace(' ', '', trim($_POST['card_number'])); 
    $expiry = trim($_POST['expiry_date']); 
    $cvv = trim($_POST['cvv']);
    
    $stmt_p = $conn->prepare("INSERT INTO payment_method (user_id, card_number, cardholder_name, expiry_date, cvv, is_default) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt_p->bind_param("issss", $user_id, $card_number, $cardholder, $expiry, $cvv);
    if ($stmt_p->execute()) {
        header("Location: order_station.php?id=" . $station_id);
        exit();
    }
}

$conn->close();

// קביעת לאן חוזרים (מפה)
$back_url = "map.php";
// תוספת: אם קיים דף קודם בהיסטוריה, נשתמש בו במקום בברירת המחדל
if(isset($_SERVER['HTTP_REFERER'])) { $back_url = $_SERVER['HTTP_REFERER']; } 
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <title>פרטי עמדה - <?php echo htmlspecialchars($station['name']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: linear-gradient(to bottom, #e8f5e9, #ffffff); padding: 20px 20px 100px 20px; min-height: 100vh; }
        .container { max-width: 600px; width: 100%; margin: 20px auto; background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #e8f5e9; padding-bottom: 15px; }
        h1 { color: #2e7d32; margin: 0; font-size: 24px; }
        
        /* הסתרת כפתור חזרה למפה באמצעות CSS */
        .back-btn { display: none !important; }
        
        .fav-btn { background: none; border: none; font-size: 28px; cursor: pointer; color: #ccc; transition: transform 0.2s; padding: 0; line-height: 1; }
        .fav-btn.active { color: #e91e63; }
        .fav-btn:hover { transform: scale(1.1); }

        .station-image-container { width: 100%; margin-bottom: 25px; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .station-image-container img { width: 100%; height: auto; display: block; object-fit: cover; max-height: 300px; }

        .station-info { font-size: 16px; line-height: 1.8; color: #444; margin-bottom: 20px; }
        .station-info strong { color: #2e7d32; display: inline-block; width: 90px; }
        
        /* עיצוב תגי מחיר */
        .price-container { display: flex; gap: 10px; margin: 15px 0; }
        .price-tag { background: #e3f2fd; color: #1565c0; padding: 8px 15px; border-radius: 8px; font-weight: bold; border: 1px solid #bbdefb; font-size: 15px; flex: 1; text-align: center; }
        .price-tag span { display: block; font-size: 12px; color: #546e7a; font-weight: normal; }

        .schedule-container { background: #f9f9f9; border-radius: 8px; padding: 15px; margin-bottom: 25px; border: 1px solid #eee; }
        .schedule-title { font-weight: bold; color: #2e7d32; margin-bottom: 10px; display: flex; align-items: center; gap: 5px; }
        .schedule-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; border-bottom: 1px dotted #ddd; }
        .schedule-row:last-child { border-bottom: none; }
        .schedule-row.today { font-weight: bold; color: #2e7d32; background: #e8f5e9; padding: 4px 8px; border-radius: 4px; }

        .details-badge { display: inline-block; background: #e8f5e9; color: #1b5e20; padding: 5px 10px; border-radius: 6px; font-size: 14px; margin-top: 10px; font-weight: bold; }
        .grade-display { background: #fff8e1; border: 1px solid #ffe082; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 25px; }
        .grade-display h2 { margin: 0 0 10px 0; color: #f57c00; font-size: 32px; }

        .rating-section { border-top: 2px solid #e8f5e9; padding-top: 20px; margin-bottom: 25px; text-align: center; }
        .star-rating { display: flex; flex-direction: row-reverse; justify-content: center; gap: 5px; }
        .star-rating input { display: none; }
        .star-rating label { font-size: 40px; color: #ddd; cursor: pointer; transition: color 0.2s; }
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #ffca28; }
        .rate-btn { background: #2e7d32; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 15px; transition: 0.2s; }

        .waze-btn { display: flex; justify-content: center; align-items: center; width: 100%; padding: 15px; background-color: #33ccff; color: #000; text-decoration: none; border-radius: 8px; font-size: 18px; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.1); cursor: pointer; border: none; }
        
        .distance-info { text-align: center; margin-top: 30px; margin-bottom: 15px; font-weight: bold; color: #1565c0; font-size: 16px; display: none; }

        .book-btn { display: block; width: 100%; padding: 15px; background-color: #2e7d32; color: white; text-align: center; text-decoration: none; border-radius: 8px; font-size: 18px; font-weight: bold; margin-bottom: 15px; border: none; cursor: pointer; }
        .add-card-container { display: none; background: #f9f9f9; padding: 20px; border-radius: 10px; border: 1px solid #ddd; margin-bottom: 20px; }
        .form-group { margin-bottom: 10px; }
        .form-group label { display: block; font-size: 14px; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }

        .start-charging-container {
            display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 30px 0; margin-bottom: 25px;
        }

        .start-charging-circle {
            width: 180px; height: 180px; background: radial-gradient(circle, #4caf50, #2e7d32); color: white; border-radius: 50%; display: flex; justify-content: center; align-items: center; text-align: center; font-size: 22px; font-weight: bold; cursor: pointer; border: 8px solid #e8f5e9; box-shadow: 0 8px 25px rgba(46, 125, 50, 0.4); transition: all 0.3s ease; animation: pulse-green 2s infinite; text-decoration: none;
        }

        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 20px rgba(46, 125, 50, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(46, 125, 50, 0); }
        }

        /* --- סרגל ניווט תחתון --- */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; width: 100%; background-color: white; display: flex; justify-content: space-around; align-items: center; padding: 10px 0 25px 0; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); z-index: 7000;
        }

        .nav-item {
            text-decoration: none; color: #757575; display: flex; flex-direction: column; align-items: center; font-size: 14px; font-weight: 500; flex: 1; transition: all 0.2s ease;
        }

        .nav-item i { font-size: 22px; margin-bottom: 5px; }
    </style>
</head>
<body>

<div class="container">
    
    <header>
        <div style="display: flex; align-items: center; gap: 15px;">
            <form method="POST" style="margin:0;">
                <button type="submit" name="toggle_favorite" class="fav-btn <?php echo $is_fav ? 'active' : ''; ?>" title="מועדפים">
                    <?php echo $is_fav ? '❤' : '♡'; ?>
                </button>
            </form>
            <h1>פרטי עמדה</h1>
        </div>
        <a href="<?php echo $back_url; ?>" class="back-btn">חזרה למפה</a>
    </header>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($station['image'])): ?>
        <div class="station-image-container">
            <img src="<?php echo htmlspecialchars($station['image']); ?>" alt="תמונה">
        </div>
    <?php endif; ?>

    <div class="grade-display">
        <?php if (!empty($station['grade'])): ?>
            <h2>⭐ <?php echo number_format($station['grade'], 1); ?> <span style="font-size:18px; color:#aaa;">/ 5</span></h2>
            <p>ממוצע דירוגי משתמשים</p>
        <?php else: ?>
            <h2 style="color: #aaa;">⭐ -.-</h2>
            <p>עמדה זו טרם דורגה</p>
        <?php endif; ?>
    </div>

    <?php if ($can_rate): ?>
    <div class="rating-section">
        <h3 style="color:#2e7d32; margin-top:0;">דרג את העמדה:</h3>
        <form method="POST">
            <div class="star-rating">
                <input type="radio" id="star5" name="rating_value" value="5" <?php echo ($user_grade == 5) ? 'checked' : ''; ?> required />
                <label for="star5">★</label>
                <input type="radio" id="star4" name="rating_value" value="4" <?php echo ($user_grade == 4) ? 'checked' : ''; ?> />
                <label for="star4">★</label>
                <input type="radio" id="star3" name="rating_value" value="3" <?php echo ($user_grade == 3) ? 'checked' : ''; ?> />
                <label for="star3">★</label>
                <input type="radio" id="star2" name="rating_value" value="2" <?php echo ($user_grade == 2) ? 'checked' : ''; ?> />
                <label for="star2">★</label>
                <input type="radio" id="star1" name="rating_value" value="1" <?php echo ($user_grade == 1) ? 'checked' : ''; ?> />
                <label for="star1">★</label>
            </div>
            <button type="submit" name="submit_rating" class="rate-btn">שמור דירוג</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="station-info">
        <p><strong style="width: 86px">שם העמדה:</strong> <?php echo htmlspecialchars($station['name']); ?></p>
        <p><strong>עיר:</strong> <?php echo htmlspecialchars($station['city']); ?></p>
        <p><strong>כתובת:</strong> <?php echo htmlspecialchars($station['address']); ?></p>
        <p><strong>מפעיל:</strong> <?php echo htmlspecialchars($station['operator']); ?></p>

        <div class="price-container">
            <div class="price-tag">
                <span>עלות טעינה</span>
                ₪<?php echo htmlspecialchars($station['price_per_hour']); ?> / שעה
            </div>
            <div class="price-tag" style="background: #fff3e0; color: #e65100; border-color: #ffe0b2;">
                <span>עלות חניה לאחר סיום הטעינה</span>
                ₪<?php echo htmlspecialchars($station['parking_fee']); ?> / שעה
            </div>
        </div>

        <?php if (!empty($station['details'])): ?>
            <div class="details-badge"><?php echo htmlspecialchars($station['details']); ?></div>
        <?php endif; ?>
    </div>

    <?php if ($can_start_charging): ?>
        <div class="start-charging-container">
            <form method="POST">
                <input type="hidden" name="target_order_id" value="<?php echo $active_order_id; ?>">
                <button type="submit" name="action_start_charging" class="start-charging-circle">
                    התחל טעינה!
                </button>
            </form>
            <p style="color: #2e7d32; font-weight: bold; margin-top: 10px;">יש לך הזמנה פעילה לעכשיו ✅</p>
        </div>
    <?php else: ?>
        <div class="schedule-container">
            <div class="schedule-title">📅 שעות פעילות:</div>
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
    <?php endif; ?>

    <?php if ($has_payment): ?>
        <a href="order_station.php?id=<?php echo $station_id; ?>" class="book-btn">⚡ הזמנת העמדה</a>
    <?php else: ?>
        <button type="button" onclick="showAddCardForm()" class="book-btn">⚡ הזמנת העמדה</button>
        
        <div id="addCardSection" class="add-card-container">
            <h3 style="margin-top:0; color:#c62828;">💳 נדרש אמצעי תשלום</h3>
            <p style="font-size:14px;">כדי להזמין עמדה, עליך להוסיף כרטיס אשראי למערכת:</p>
            <form method="POST">
                <div class="form-group"><label>שם בעל הכרטיס:</label><input type="text" name="cardholder_name" required></div>
                <div class="form-group"><label>מספר כרטיס:</label><input type="text" name="card_number" maxlength="16" placeholder="16 ספרות" required pattern="\d{16}"></div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:2;"><label>תוקף (MM/YY):</label><input type="text" name="expiry_date" placeholder="05/28" required pattern="(0[1-9]|1[0-2])\/\d{2}"></div>
                    <div class="form-group" style="flex:1;"><label>CVV:</label><input type="text" name="cvv" maxlength="3" required pattern="\d{3}"></div>
                </div>
                <button type="submit" name="add_card_submit" class="rate-btn" style="width:100%; margin-top:10px;">שמור כרטיס והמשך להזמנה</button>
            </form>
        </div>
    <?php endif; ?>

    <div id="distance-display" class="distance-info"></div>

    <button onclick="navigateWaze(<?php echo $station['latitude']; ?>, <?php echo $station['longitude']; ?>)" class="waze-btn">
        🚗 ניווט בוויז לעמדה זו
    </button>

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

<script>
    function showAddCardForm() {
        document.getElementById('addCardSection').style.display = 'block';
        window.scrollTo({ top: document.getElementById('addCardSection').offsetTop, behavior: 'smooth' });
    }

    function navigateWaze(lat, lng) {
        window.open(`https://waze.com/ul?ll=${lat},${lng}&navigate=yes`, '_blank');
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; 
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = 
            Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    if (navigator.geolocation) {
        const stationLat = <?php echo $station['latitude']; ?>;
        const stationLng = <?php echo $station['longitude']; ?>;

        navigator.geolocation.getCurrentPosition(function(position) {
            const userLat = position.coords.latitude;
            const userLng = position.coords.longitude;
            const distance = calculateDistance(userLat, userLng, stationLat, stationLng);
            const displayDiv = document.getElementById('distance-display');
            displayDiv.innerHTML = `📍 המרחק ממך: ${distance.toFixed(1)} ק"מ`;
            displayDiv.style.display = 'block';
        });
    }
</script>

</body>
</html>