<?php
session_start();

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

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = ""; 
$edit_mode = false;
$current_station = null;
$current_hours = [];

// --- 1. טיפול במחיקת עמדה ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_station_id'])) {
    $id_to_delete = $_POST['delete_station_id'];
    $check_sql = "SELECT id FROM owner_stations WHERE user_id = ? AND station_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("is", $user_id, $id_to_delete);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $conn->prepare("DELETE FROM owner_stations WHERE user_id = ? AND station_id = ?")->execute([$user_id, $id_to_delete]);
        $conn->prepare("DELETE FROM opening_hours WHERE station_id = ?")->execute([$id_to_delete]);
        $conn->prepare("DELETE FROM station WHERE station_id = ?")->execute([$id_to_delete]);
        $message = "העמדה נמחקה בהצלחה!";
        $message_type = "success";
    }
}

// --- 2. טיפול בעדכון פרטי עמדה + שעות פעילות + אישור מיידי ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_station'])) {
    $station_id_to_update = $_POST['station_id'];
    $s_name = trim($_POST['station_name']);
    $s_city = trim($_POST['city']);
    $s_address = trim($_POST['address']);
    $s_details = trim($_POST['details']);
    
    // קליטת שדות העלות החדשים (מול העמודות price_per_hour ו-parking_fee)
    $hourly_charging_cost = isset($_POST['hourly_charging_cost']) ? (int)$_POST['hourly_charging_cost'] : 0;
    $hourly_parking_cost = isset($_POST['hourly_parking_cost']) ? (int)$_POST['hourly_parking_cost'] : 0;

    // קבלת הערך מה-Toggle של אישור מיידי
    $instant_approval = isset($_POST['instant_approval']) ? 1 : 0;

    $check_sql = "SELECT s.image FROM station s JOIN owner_stations os ON s.station_id = os.station_id WHERE os.user_id = ? AND s.station_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("is", $user_id, $station_id_to_update);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $final_image_path = $row['image'];

        if (isset($_FILES['station_image']) && $_FILES['station_image']['error'] == 0) {
            $target_dir = "images/";
            $file_extension = strtolower(pathinfo($_FILES["station_image"]["name"], PATHINFO_EXTENSION));
            $new_filename = $station_id_to_update . "_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;
            if (move_uploaded_file($_FILES["station_image"]["tmp_name"], $target_file)) {
                $final_image_path = $target_file;
            }
        }

        $conn->begin_transaction();
        try {
            // עדכון פרטי עמדה כולל עמודות העלות המעודכנות: price_per_hour ו-parking_fee
            $update_sql = "UPDATE station SET name=?, city=?, address=?, details=?, image=?, instant_approval=?, price_per_hour=?, parking_fee=? WHERE station_id=?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("sssssiiis", $s_name, $s_city, $s_address, $s_details, $final_image_path, $instant_approval, $hourly_charging_cost, $hourly_parking_cost, $station_id_to_update);
            $update_stmt->execute();

            // עדכון שעות פעילות (מחיקה והכנסה מחדש)
            $conn->prepare("DELETE FROM opening_hours WHERE station_id = ?")->execute([$station_id_to_update]);
            
            $always_open = isset($_POST['always_open']);
            for ($day = 1; $day <= 7; $day++) {
                if ($always_open) {
                    $open_t = "00:00:00"; $close_t = "23:59:59"; $is_closed = 0;
                } else {
                    $is_closed = isset($_POST["day_closed_$day"]) ? 1 : 0;
                    $open_t = $_POST["open_time_$day"];
                    $close_t = $_POST["close_time_$day"];
                }
                $stmt_h = $conn->prepare("INSERT INTO opening_hours (station_id, day_of_week, open_time, close_time, is_closed) VALUES (?, ?, ?, ?, ?)");
                $stmt_h->bind_param("sissi", $station_id_to_update, $day, $open_t, $close_t, $is_closed);
                $stmt_h->execute();
            }

            $conn->commit();
            $message = "העמדה ושעות הפעילות עודכנו בהצלחה!";
            $message_type = "success";
            header("Refresh:1; url=personal_details.php");
        } catch (Exception $e) {
            $conn->rollback();
            $message = "שגיאה בעדכון: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// --- 3. טיפול בעדכון פרטי משתמש (כולל אימות 1 ו-2) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_user'])) {
    $name = trim($_POST['name']); $email = trim($_POST['email']); $phone = trim($_POST['phone_number']); $password = trim($_POST['password']);
    
    if (preg_match('~[0-9]~', $name)) {
        $message = "השם המלא לא יכול לכלול מספרים."; $message_type = "error";
    } elseif (!preg_match('/^05\d{8}$/', $phone)) {
        $message = "מספר הטלפון חייב לכלול 10 ספרות ולהתחיל ב-05."; $message_type = "error";
    } elseif (!empty($name) && !empty($email) && !empty($password)) {
        $check_email = "SELECT uid FROM user WHERE email = ? AND uid != ?";
        $stmt_check = $conn->prepare($check_email);
        $stmt_check->bind_param("si", $email, $user_id);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            $message = "כתובת האימייל הזו כבר רשומה במערכת."; $message_type = "error";
        } else {
            $update_sql = "UPDATE user SET name=?, email=?, phone_number=?, password=? WHERE uid=?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssssi", $name, $email, $phone, $password, $user_id);
            if ($update_stmt->execute()) {
                $message = "הפרטים עודכנו בהצלחה!"; $message_type = "success"; $_SESSION['user_name'] = $name;
            }
        }
    }
}

// --- 4. טיפול באמצעי תשלום וחשבון בנק (כולל אימות 3-10) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_payment'])) {
    $cardholder = trim($_POST['cardholder_name']); 
    $card_number = str_replace(' ', '', trim($_POST['card_number'])); 
    $expiry = trim($_POST['expiry_date']); 
    $cvv = trim($_POST['cvv']);
    
    if (preg_match('~[0-9]~', $cardholder)) {
        $message = "שם בעל הכרטיס לא יכול לכלול מספרים."; $message_type = "error";
    } elseif (!preg_match('/^\d{16}$/', $card_number)) {
        $message = "מספר הכרטיס חייב לכלול בדיוק 16 ספרות."; $message_type = "error";
    } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry)) {
        $message = "פורמט תוקף לא תקין (MM/YY)."; $message_type = "error";
    } elseif (!preg_match('/^\d{3}$/', $cvv)) {
        $message = "ה-CVV חייב לכלול בדיוק 3 מספרים."; $message_type = "error";
    } else {
        $check_first = $conn->prepare("SELECT id FROM payment_method WHERE user_id = ?");
        $check_first->bind_param("i", $user_id); $check_first->execute();
        $is_default = ($check_first->get_result()->num_rows === 0) ? 1 : 0;
        $stmt_p = $conn->prepare("INSERT INTO payment_method (user_id, card_number, cardholder_name, expiry_date, cvv, is_default) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_p->bind_param("issssi", $user_id, $card_number, $cardholder, $expiry, $cvv, $is_default);
        if ($stmt_p->execute()) { $message = "אמצעי תשלום נוסף!"; $message_type = "success"; }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_payment_id'])) {
    $stmt = $conn->prepare("DELETE FROM payment_method WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $_POST['delete_payment_id'], $user_id);
    if ($stmt->execute()) { $message = "אמצעי תשלום נמחק!"; $message_type = "success"; }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_default_payment_id'])) {
    $conn->prepare("UPDATE payment_method SET is_default = 0 WHERE user_id = ?")->execute([$user_id]);
    $stmt = $conn->prepare("UPDATE payment_method SET is_default = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $_POST['set_default_payment_id'], $user_id);
    if ($stmt->execute()) { $message = "ברירת המחדל עודכנה!"; $message_type = "success"; }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_bank_account'])) {
    $acc_name = trim($_POST['account_name']); 
    $b_name = trim($_POST['bank_name']); 
    $b_branch = trim($_POST['branch_number']); 
    $acc_num = trim($_POST['account_number']);
    
    if (preg_match('~[0-9]~', $acc_name)) {
        $message = "שם בעל החשבון לא יכול לכלול מספרים."; $message_type = "error";
    } elseif (preg_match('~[0-9]~', $b_name)) {
        $message = "שם הבנק לא יכול לכלול מספרים."; $message_type = "error";
    } elseif (!preg_match('/^\d{3}$/', $b_branch)) {
        $message = "מספר הסניף חייב להיות בדיוק 3 מספרים."; $message_type = "error";
    } elseif (!preg_match('/^\d{6}$/', $acc_num)) {
        $message = "מספר החשבון חייב להיות בדיוק 6 מספרים."; $message_type = "error";
    } else {
        $check_bank = $conn->prepare("SELECT id FROM bank_account WHERE user_id = ?");
        $check_bank->bind_param("i", $user_id); $check_bank->execute();
        if ($check_bank->get_result()->num_rows > 0) {
            $update_bank = $conn->prepare("UPDATE bank_account SET account_name=?, bank_name=?, branch_number=?, account_number=? WHERE user_id=?");
            $update_bank->bind_param("ssssi", $acc_name, $b_name, $b_branch, $acc_num, $user_id);
            if ($update_bank->execute()) { $message = "חשבון בנק עודכן!"; $message_type = "success"; }
        } else {
            $insert_bank = $conn->prepare("INSERT INTO bank_account (user_id, account_name, bank_name, branch_number, account_number) VALUES (?, ?, ?, ?, ?)");
            $insert_bank->bind_param("issss", $user_id, $acc_name, $b_name, $b_branch, $acc_num);
            if ($insert_bank->execute()) { $message = "חשבון בנק נשמר!"; $message_type = "success"; }
        }
    }
}

// --- 5. חדש: טיפול בעדכון העדפות טעינה ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_preferences'])) {
    $max_dist = (int)$_POST['max_distance'];
    $min_rate = (float)$_POST['min_rating'];
    $max_pr = (float)$_POST['max_price'];

    $check_pref = $conn->prepare("SELECT id FROM preferences WHERE user_id = ?");
    $check_pref->bind_param("i", $user_id);
    $check_pref->execute();
    if ($check_pref->get_result()->num_rows > 0) {
        $upd = $conn->prepare("UPDATE preferences SET max_distance=?, min_rating=?, max_price=? WHERE user_id=?");
        $upd->bind_param("iddi", $max_dist, $min_rate, $max_pr, $user_id);
        if ($upd->execute()) { $message = "העדפות טעינה עודכנו!"; $message_type = "success"; }
    } else {
        $ins = $conn->prepare("INSERT INTO preferences (user_id, max_distance, min_rating, max_price) VALUES (?, ?, ?, ?)");
        $ins->bind_param("iidd", $user_id, $max_dist, $min_rate, $max_pr);
        if ($ins->execute()) { $message = "העדפות טעינה נשמרו!"; $message_type = "success"; }
    }
}

// --- חדש: טיפול בעדכון תמונת פרופיל משתמש ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['user_profile_img'])) {
    if ($_FILES['user_profile_img']['error'] == 0) {
        $target_dir = "images/profiles/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_ext = strtolower(pathinfo($_FILES["user_profile_img"]["name"], PATHINFO_EXTENSION));
        $new_name = "profile_" . $user_id . "_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_name;
        if (move_uploaded_file($_FILES["user_profile_img"]["tmp_name"], $target_file)) {
            $stmt_img = $conn->prepare("UPDATE user SET image = ? WHERE uid = ?");
            $stmt_img->bind_param("si", $target_file, $user_id);
            $stmt_img->execute();
            $message = "תמונת הפרופיל עודכנה!";
            $message_type = "success";
        }
    }
}

// --- כניסה למצב עריכת עמדה (GET) ---
if (isset($_GET['edit_station'])) {
    $edit_id = $_GET['edit_station'];
    $sql = "SELECT s.* FROM station s JOIN owner_stations os ON s.station_id = os.station_id WHERE os.user_id = ? AND s.station_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $user_id, $edit_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $edit_mode = true;
        $current_station = $res->fetch_assoc();
        
        // שליפת שעות פעילות קיימות
        $stmt_h = $conn->prepare("SELECT * FROM opening_hours WHERE station_id = ? ORDER BY day_of_week ASC");
        $stmt_h->bind_param("s", $edit_id);
        $stmt_h->execute();
        $res_h = $stmt_h->get_result();
        while($h_row = $res_h->fetch_assoc()) {
            $current_hours[$h_row['day_of_week']] = $h_row;
        }
    }
}

// שליפת נתונים לתצוגה (משתמש, עמדות, תשלומים, בנק, העדפות)
$stmt = $conn->prepare("SELECT * FROM user WHERE uid = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

$my_stations = [];
if (!$edit_mode) {
    $stmt_stations = $conn->prepare("SELECT s.* FROM station s JOIN owner_stations os ON s.station_id = os.station_id WHERE os.user_id = ?");
    $stmt_stations->bind_param("i", $user_id);
    $stmt_stations->execute();
    $result_stations = $stmt_stations->get_result();
    while ($row = $result_stations->fetch_assoc()) { $my_stations[] = $row; }
}

$payment_methods = [];
if (!$edit_mode) {
    $stmt_p = $conn->prepare("SELECT * FROM payment_method WHERE user_id = ? ORDER BY is_default DESC, id DESC");
    $stmt_p->bind_param("i", $user_id);
    $stmt_p->execute();
    $res_p = $stmt_p->get_result();
    while ($row = $res_p->fetch_assoc()) { $payment_methods[] = $row; }
}

$bank_account = null;
if (!$edit_mode) {
    $stmt_b = $conn->prepare("SELECT * FROM bank_account WHERE user_id = ?");
    $stmt_b->bind_param("i", $user_id);
    $stmt_b->execute();
    $res_b = $stmt_b->get_result();
    if ($res_b->num_rows > 0) { $bank_account = $res_b->fetch_assoc(); }
}

// שליפת העדפות
$user_pref = null;
if (!$edit_mode) {
    $stmt_pr = $conn->prepare("SELECT * FROM preferences WHERE user_id = ?");
    $stmt_pr->bind_param("i", $user_id);
    $stmt_pr->execute();
    $user_pref = $stmt_pr->get_result()->fetch_assoc();
    // ערכי ברירת מחדל אם לא קיים
    if (!$user_pref) {
        $user_pref = ['max_distance' => 10, 'min_rating' => 0.0, 'max_price' => 20.00];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <title>אזור אישי</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, sans-serif; background: linear-gradient(to bottom, #e8f5e9, #ffffff); padding: 20px 20px 100px 20px; min-height: 100vh; }
        .container { max-width: 650px; width: 100%; margin: 20px auto; background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        h1 { color: #2e7d32; margin: 0; font-size: 24px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e8f5e9; padding-bottom: 10px; margin-top: 40px; margin-bottom: 20px; }
        .section-title { color: #2e7d32; margin: 0; font-size: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        input[type=text], input[type=email], input[type=time], input[type=number], textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; font-family: inherit; }
        .btn-primary { width: 100%; padding: 12px; background: #2e7d32; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-primary:hover { background: #1b5e20; }
        .btn-secondary { background: #fdfdfd; color: #2e7d32; border: 2px solid #2e7d32; padding: 6px 12px; border-radius: 4px; font-weight: bold; font-size: 13px; cursor: pointer; text-decoration: none; }
        
        /* הסתרת כפתור חזרה למפה כפי שנתבקש */
        .back-btn { display: none !important; }

        .message { padding: 12px; margin-bottom: 20px; border-radius: 6px; text-align: center; font-weight: bold; }
        .message.success { background-color: #d4edda; color: #155724; }
        .message.error { background-color: #ffebee; color: #d32f2f; }
        
        /* Toggles עיצוב */
        .switch { position: relative; display: inline-block; width: 44px; height: 22px; vertical-align: middle; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 22px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider.green { background-color: #2e7d32; }
        input:checked + .slider.green:before { transform: translateX(22px); }
        input:checked + .slider.blue { background-color: #1976d2; }
        input:checked + .slider.blue:before { transform: translateX(22px); }
        input:checked + .slider.red { background-color: #d32f2f; }
        input:checked + .slider.red:before { transform: translateX(22px); }

        .switch-container { display: flex; align-items: center; justify-content: space-between; padding: 10px; border-radius: 8px; margin: 15px 0; }
        .bg-blue { background: #e3f2fd; }
        .bg-green { background: #e8f5e9; }

        /* Tooltip Style מעודכן */
        .help-icon { display: inline-block; width: 18px; height: 18px; background: #1976d2; color: white; border-radius: 50%; text-align: center; font-size: 13px; line-height: 18px; cursor: pointer; margin-right: 5px; position: relative; }
        .help-icon:hover::after { content: attr(data-tooltip); position: absolute; bottom: 25px; right: -20px; background: #333; color: #fff; padding: 8px; border-radius: 5px; width: 220px; font-size: 11px; line-height: 1.4; z-index: 100; text-align: right; white-space: normal; }

        .hours-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        .hours-table td { padding: 8px 4px; border-bottom: 1px solid #eee; vertical-align: middle; }

        .item-card { background: #fdfdfd; border: 1px solid #eee; border-radius: 8px; padding: 15px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; gap: 15px; }
        .station-card-img { width: 60px; height: 60px; border-radius: 6px; object-fit: cover; }
        .current-image-preview { margin-bottom: 15px; text-align: center; }
        .current-image-preview img { max-width: 100%; max-height: 180px; border-radius: 8px; }
        .toggle-form-container { background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 20px; }

        .view-history-button { 
            display: block; width: 100%; margin-top: 12px; background: #e3f2fd; color: #1565c0; text-align: center; padding: 12px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 15px; border: 1px solid #bbdefb; transition: all 0.2s ease; 
        }
        .view-history-button:hover { background: #1976d2; color: white; border-color: #1976d2; }

        /* --- עיצוב חדש לערכים וסליידרים --- */
        .pref-slider-group { margin-bottom: 20px; text-align: right; }
        .pref-slider-group label { display: flex; justify-content: space-between; align-items: center; font-weight: bold; margin-bottom: 10px; }
        .value-wrapper { display: flex; align-items: center; gap: 8px; }
        .manual-input { width: 65px; padding: 4px; border: 1px solid #ddd; border-radius: 8px; text-align: center; font-size: 14px; font-weight: bold; color: #2e7d32; outline: none; }
        .manual-input:focus { border-color: #2e7d32; box-shadow: 0 0 4px rgba(46,125,50,0.2); }
        .pref-badge { background: #e8f5e9; color: #2e7d32; padding: 3px 10px; border-radius: 10px; font-size: 14px; min-width: 60px; text-align: center; }
        .pref-slider-group input[type=range] { -webkit-appearance: none; width: 100%; height: 6px; background: #eee; border-radius: 5px; outline: none; margin: 10px 0; }
        .pref-slider-group input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 20px; height: 20px; background: #2e7d32; border: 3px solid white; border-radius: 50%; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }

        /* --- עיצוב לתמונת פרופיל עגולה --- */
        .profile-image-section { text-align: center; margin-bottom: 30px; }
        .profile-image-container { position: relative; width: 130px; height: 130px; margin: 0 auto; }
        .profile-image-circle { width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 4px solid #2e7d32; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .edit-profile-btn { position: absolute; bottom: 5px; left: 5px; background: #2e7d32; color: white; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 3px solid #fff; transition: 0.3s; font-size: 14px; }
        .edit-profile-btn:hover { background: #1b5e20; transform: scale(1.1); }
        #user_profile_img { display: none; }

        /* --- סרגל ניווט תחתון --- */
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
        <h1 style="font-size: xx-large">אזור אישי</h1>
        <?php if($edit_mode): ?>
            <a href="personal_details.php" class="back-btn">ביטול וחזרה</a>
        <?php else: ?>
            <a href="map.php" class="back-btn">חזרה למפה</a>
        <?php endif; ?>
    </header>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($edit_mode && $current_station): ?>
        <div class="section-header" style="margin-top:0;">
            <h2 class="section-title">עריכת עמדה: <?php echo htmlspecialchars($current_station['name']); ?></h2>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="station_id" value="<?php echo htmlspecialchars($current_station['station_id']); ?>">
            
            <div class="form-group"><label>שם העמדה:</label><input type="text" name="station_name" value="<?php echo htmlspecialchars($current_station['name']); ?>" required></div>
            <div class="form-group"><label>עיר:</label><input type="text" name="city" value="<?php echo htmlspecialchars($current_station['city']); ?>" required></div>
            <div class="form-group"><label>כתובת:</label><input type="text" name="address" value="<?php echo htmlspecialchars($current_station['address']); ?>" required></div>

            <div style="display: flex; gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label>
                        <span class="help-icon" data-tooltip="המחיר הממולץ הוא מינימום 20 שח. ניתן לשנות בכל שלב מהאזור האישי. ממחיר זה ינוכו עמלות">?</span>
                        עלות טעינה שעתית:
                    </label>
                    <input type="number" name="hourly_charging_cost" value="<?php echo htmlspecialchars($current_station['price_per_hour']); ?>" min="15" max="100" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>
                        <span class="help-icon" data-tooltip="המחיר הממולץ הוא מינימום 10 שח. ניתן לשנות בכל שלב מהאזור האישי. ממחיר זה ינוכו עמלות">?</span>
                        עלות חניה שעתית נוספת:
                    </label>
                    <input type="number" name="hourly_parking_cost" value="<?php echo htmlspecialchars($current_station['parking_fee']); ?>" min="5" max="50" required>
                </div>
            </div>

            <div class="form-group">
                <label>תמונת העמדה:</label>
                <?php if (!empty($current_station['image']) && file_exists($current_station['image'])): ?>
                    <div class="current-image-preview"><img src="<?php echo htmlspecialchars($current_station['image']); ?>" alt="תמונה"></div>
                <?php endif; ?>
                <input type="file" name="station_image" accept="image/*">
            </div>

            <div class="switch-container bg-blue">
                <span style="font-weight:bold; color:#1976d2;">
                    <span class="help-icon" data-tooltip="במצב זה, כל הזמנה של לקוח תאושר באופן אוטומטי. אם המצב כבוי, תצטרך לאשר כל הזמנה באופן ידני באזור האישי.">?</span>
                    אישור הזמנות מיידי (אוטומטי)
                </span>
                <label class="switch">
                    <input type="checkbox" name="instant_approval" <?php echo ($current_station['instant_approval'] == 1) ? 'checked' : ''; ?>>
                    <span class="slider blue"></span>
                </label>
            </div>

            <div class="section-header"><h2 class="section-title">שעות פעילות</h2></div>
            
            <?php 
            // בדיקה אם העמדה מוגדרת כרגע כ-24/7
            $is_always_open = true;
            if (count($current_hours) < 7) { $is_always_open = false; }
            else {
                foreach($current_hours as $h) {
                    if ($h['is_closed'] == 1 || $h['open_time'] != "00:00:00" || $h['close_time'] != "23:59:59") {
                        $is_always_open = false; break;
                    }
                }
            }
            ?>

            <div class="switch-container bg-green">
                <span style="font-weight:bold; color:#2e7d32;">עמדה תמיד פנויה (24/7)</span>
                <label class="switch">
                    <input type="checkbox" name="always_open" id="always_open" <?php echo $is_always_open ? 'checked' : ''; ?> onchange="toggleHours()">
                    <span class="slider green"></span>
                </label>
            </div>

            <table id="manual_hours" class="hours-table" style="display: <?php echo $is_always_open ? 'none' : 'table'; ?>;">
                <?php 
                $days = ["א'", "ב'", "ג'", "ד'", "ה'", "ו'", "ש'"];
                foreach($days as $i => $day_name): 
                    $d = $i + 1;
                    $h = isset($current_hours[$d]) ? $current_hours[$d] : ['open_time'=>'00:00:00', 'close_time'=>'23:59:59', 'is_closed'=>0];
                ?>
                <tr>
                    <td style="width: 60px;"><strong>יום <?php echo $day_name; ?></strong></td>
                    <td>מ- <input type="time" name="open_time_<?php echo $d; ?>" value="<?php echo substr($h['open_time'], 0, 5); ?>" style="width:110px;"></td>
                    <td>עד <input type="time" name="close_time_<?php echo $d; ?>" value="<?php echo substr($h['close_time'], 0, 5); ?>" style="width:110px;"></td>
                    <td style="text-align: left;">
                        <span style="font-size: 11px; color: #666; margin-left: 4px;">סגור?</span>
                        <label class="switch" style="width: 40px; height: 20px;">
                            <input type="checkbox" name="day_closed_<?php echo $d; ?>" <?php echo $h['is_closed'] ? 'checked' : ''; ?>>
                            <span class="slider red" style="border-radius: 20px;"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <div class="form-group"><label>פרטים נוספים:</label><textarea name="details" rows="3"><?php echo htmlspecialchars($current_station['details']); ?></textarea></div>
            <button type="submit" name="update_station" class="btn-primary">שמור שינויים בעמדה</button>
        </form>

    <?php else: ?>

        <div class="profile-image-section">
            <div class="profile-image-container">
                <img src="<?php echo (!empty($user_data['image']) && file_exists($user_data['image'])) ? $user_data['image'] : 'images/default_user.png'; ?>" class="profile-image-circle">
                <label for="user_profile_img" class="edit-profile-btn">
                    <i class="fa-solid fa-pencil"></i>
                </label>
            </div>
            <form id="profile_img_form" method="POST" enctype="multipart/form-data" style="display:none;">
                <input type="file" name="user_profile_img" id="user_profile_img" accept="image/*" onchange="document.getElementById('profile_img_form').submit();">
            </form>
        </div>

        <div class="section-header" style="margin-top:0;">
            <h2 class="section-title">עדכון פרטים אישיים</h2>
            <button type="button" class="btn-secondary" onclick="toggleElement('editPersonalForm')">ערוך פרטים</button>
        </div>

        <div id="personalDisplay" class="item-card" style="flex-direction: column; align-items: flex-start;">
            <div>👤 שם מלא: <b><?php echo htmlspecialchars($user_data['name']); ?></b></div>
            <div>📧 אימייל: <b><?php echo htmlspecialchars($user_data['email']); ?></b></div>
            <div>📞 טלפון: <b><?php echo htmlspecialchars($user_data['phone_number']); ?></b></div>
            <div>🔑 סיסמה: <b>********</b></div>
        </div>

        <div id="editPersonalForm" class="toggle-form-container" style="display:none;">
            <form method="POST">
                <div class="form-group">
                    <label>שם מלא:</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($user_data['name']); ?>" required 
                           pattern="^[^\d]*$" title="השם לא יכול להכיל מספרים">
                </div>
                <div class="form-group">
                    <label>אימייל:</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label>מספר טלפון:</label>
                    <input type="text" name="phone_number" value="<?php echo htmlspecialchars($user_data['phone_number']); ?>" 
                           required pattern="^05\d{8}$" maxlength="10" title="מספר הטלפון חייב להכיל 10 ספרות ולהתחיל ב-05">
                </div>
                <div class="form-group">
                    <label>סיסמה:</label>
                    <input type="text" name="password" value="<?php echo htmlspecialchars($user_data['password']); ?>" required>
                </div>
                <button type="submit" name="update_user" class="btn-primary">שמור פרטים אישיים</button>
            </form>
        </div>

        <div class="section-header">
            <h2 class="section-title">העדפות טעינה</h2>
            <button type="button" class="btn-secondary" onclick="toggleElement('editPreferencesForm')">ערוך העדפות</button>
        </div>
        
        <div id="prefDisplay" class="item-card" style="flex-direction: column; align-items: flex-start;">
            <div>📍 מרחק מקסימלי: <b><?php echo $user_pref['max_distance']; ?> ק"מ</b></div>
            <div>⭐ דירוג מינימלי: <b><?php echo number_format($user_pref['min_rating'], 1); ?>+</b></div>
            <div>💰 מחיר מקסימלי: <b>₪<?php echo number_format($user_pref['max_price'], 2); ?></b></div>
        </div>

        <div id="editPreferencesForm" class="toggle-form-container" style="display:none;">
            <form method="POST">
                <div class="pref-slider-group">
                    <label>מרחק מקסימלי: 
                        <div class="value-wrapper">
                            <input type="number" id="dist-num" class="manual-input" min="1" max="50" value="<?php echo $user_pref['max_distance']; ?>" oninput="syncInputs(this.value, 'dist-range', 'dist-badge', '', ' ק\'מ')">
                            <span class="pref-badge" id="dist-badge"><?php echo $user_pref['max_distance']; ?> ק"מ</span>
                        </div>
                    </label>
                    <input type="range" id="dist-range" name="max_distance" min="1" max="50" value="<?php echo $user_pref['max_distance']; ?>" 
                           oninput="syncInputs(this.value, 'dist-num', 'dist-badge', '', ' ק\'מ')">
                </div>

                <div class="pref-slider-group">
                    <label>דירוג מינימלי: 
                        <div class="value-wrapper">
                            <input type="number" id="rate-num" class="manual-input" min="0" max="5" step="0.1" value="<?php echo $user_pref['min_rating']; ?>" oninput="syncInputs(this.value, 'rate-range', 'rate-badge', '+', '')">
                            <span class="pref-badge" id="rate-badge"><?php echo number_format($user_pref['min_rating'], 1); ?>+</span>
                        </div>
                    </label>
                    <input type="range" id="rate-range" name="min_rating" min="0" max="5" step="0.5" value="<?php echo $user_pref['min_rating']; ?>" 
                           oninput="syncInputs(this.value, 'rate-num', 'rate-badge', '+', '')">
                </div>

                <div class="pref-slider-group">
                    <label>מחיר מקסימלי לשעה: 
                        <div class="value-wrapper">
                            <input type="number" id="price-num" class="manual-input" min="5" max="100" value="<?php echo $user_pref['max_price']; ?>" oninput="syncInputs(this.value, 'price-range', 'price-badge', '₪', '')">
                            <span class="pref-badge" id="price-badge">₪<?php echo $user_pref['max_price']; ?></span>
                        </div>
                    </label>
                    <input type="range" id="price-range" name="max_price" min="5" max="100" step="1" value="<?php echo $user_pref['max_price']; ?>" 
                           oninput="syncInputs(this.value, 'price-num', 'price-badge', '₪', '')">
                </div>

                <button type="submit" name="update_preferences" class="btn-primary">עדכן העדפות</button>
            </form>
        </div>

        <div class="section-header"><h2 class="section-title">אמצעי תשלום לחיוב</h2><button type="button" class="btn-secondary" onclick="toggleElement('addPaymentForm')">+ הוסף כרטיס</button></div>
        <div id="addPaymentForm" class="toggle-form-container" style="display:none;">
            <form method="POST">
                <div class="form-group">
                    <label>שם בעל הכרטיס:</label>
                    <input type="text" name="cardholder_name" required pattern="^[^\d]*$" title="שם בעל הכרטיס לא יכול לכלול מספרים">
                </div>
                <div class="form-group">
                    <label>מספר כרטיס:</label>
                    <input type="text" name="card_number" maxlength="16" required pattern="\d{16}" title="מספר הכרטיס חייב להכיל בדיוק 16 ספרות">
                </div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group">
                        <label>תוקף (MM/YY):</label>
                        <input type="text" name="expiry_date" maxlength="5" required pattern="(0[1-9]|1[0-2])\/\d{2}" title="פורמט חודש/שנה MM/YY">
                    </div>
                    <div class="form-group">
                        <label>CVV:</label>
                        <input type="text" name="cvv" maxlength="3" required pattern="\d{3}" title="ה-CVV חייב להכיל בדיוק 3 ספרות">
                    </div>
                </div>
                <button type="submit" name="add_payment" class="btn-primary">שמור כרטיס</button>
            </form>
        </div>
        <?php foreach ($payment_methods as $payment): ?>
            <div class="item-card <?php echo $payment['is_default'] ? 'payment-card default' : ''; ?>">
                <div>💳 **** <?php echo substr($payment['card_number'], -4); ?> <?php if($payment['is_default']) echo "<b>(ברירת מחדל)</b>"; ?></div>
                <div style="display:flex; gap:5px;">
                    <?php if(!$payment['is_default']): ?><form method="POST"><input type="hidden" name="set_default_payment_id" value="<?php echo $payment['id']; ?>"><button type="submit" class="btn-secondary">הגדר כברירת מחדל</button></form><?php endif; ?>
                    <form method="POST"><input type="hidden" name="delete_payment_id" value="<?php echo $payment['id']; ?>"><button type="submit" class="btn-secondary" style="color:red;" onclick="return confirm('למחוק כרטיס זה?')">מחק</button></form>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="section-header"><h2 class="section-title">חשבון בנק לזיכוי</h2><button type="button" class="btn-secondary" onclick="toggleElement('bankAccountForm')">ערוך פרטים</button></div>
        <?php if($bank_account): ?>
            <div class="item-card" style="border-right:4px solid #8e24aa;">🏦 <?php echo htmlspecialchars($bank_account['bank_name']); ?> | סניף <?php echo htmlspecialchars($bank_account['branch_number']); ?> | חשבון: <?php echo htmlspecialchars($bank_account['account_number']); ?></div>
        <?php endif; ?>
        <div id="bankAccountForm" class="toggle-form-container" style="display:none;">
            <form method="POST">
                <div class="form-group">
                    <label>שם בעל החשבון:</label>
                    <input type="text" name="account_name" value="<?php echo $bank_account ? htmlspecialchars($bank_account['account_name']) : ''; ?>" 
                           required pattern="^[^\d]*$" title="שם בעל החשבון לא יכול לכלול מספרים">
                </div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group">
                        <label>בנק:</label>
                        <input type="text" name="bank_name" value="<?php echo $bank_account ? htmlspecialchars($bank_account['bank_name']) : ''; ?>" 
                               required pattern="^[^\d]*$" title="שם הבנק לא יכול לכלול מספרים">
                    </div>
                    <div class="form-group">
                        <label>סניף (3 ספרות):</label>
                        <input type="text" name="branch_number" value="<?php echo $bank_account ? htmlspecialchars($bank_account['branch_number']) : ''; ?>" 
                               required pattern="\d{3}" maxlength="3" title="מספר סניף חייב להיות בדיוק 3 מספרים">
                    </div>
                </div>
                <div class="form-group">
                    <label>מספר חשבון (6 ספרות):</label>
                    <input type="text" name="account_number" value="<?php echo $bank_account ? htmlspecialchars($bank_account['account_number']) : ''; ?>" 
                           required pattern="\d{6}" maxlength="6" title="מספר חשבון חייב להיות בדיוק 6 מספרים">
                </div>
                <button type="submit" name="update_bank_account" class="btn-primary" style="background:#8e24aa;">שמור פרטי חשבון</button>
            </form>
        </div>

        <div class="section-header"><h2 class="section-title">עמדות הטעינה שלי</h2></div>
        
        <?php if (!empty($my_stations)): ?>
            <?php foreach ($my_stations as $station): ?>
                <div class="item-card" style="flex-direction: column; align-items: stretch;">
                    <div style="display:flex; justify-content: space-between; align-items:center; width: 100%;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <img src="<?php echo (!empty($station['image']) && file_exists($station['image'])) ? $station['image'] : 'images/default_station.png'; ?>" class="station-card-img">
                            <div>
                                <b><?php echo htmlspecialchars($station['name']); ?></b><br>
                                <small><?php echo htmlspecialchars($station['city']); ?></small>
                            </div>
                        </div>
                        <div style="display:flex; gap:5px;">
                            <a href="personal_details.php?edit_station=<?php echo $station['station_id']; ?>" class="btn-secondary">ערוך עמדה</a>
                            <form method="POST"><input type="hidden" name="delete_station_id" value="<?php echo $station['station_id']; ?>"><button type="submit" class="btn-secondary" style="color:red;" onclick="return confirm('למחוק את העמדה?')">מחק</button></form>
                        </div>
                    </div>
                    <a href="my_stations_orders.php?station_id=<?php echo $station['station_id']; ?>" class="view-history-button">
                        צפייה בהיסטוריית הזמנות העמדה
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 5px; background: #f9f9f9; border: 2px dashed #ccc; border-radius: 12px; margin-bottom: 20px;">
                <p style="font-size: 16px; color: #666; margin-bottom: 20px;">טרם הוספת עמדת טעינה בבעלותך.</p>
                <a href="add_station.php" class="btn-primary" style="display: inline-block; text-decoration: none; width: auto; padding: 12px 30px;">➕ הוספת עמדה חדשה עכשיו</a>
            </div>
        <?php endif; ?>

        <div style="margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px;">
            <a href="order_history.php" class="btn-primary" style="display: block; text-align: center; text-decoration: none; background-color: #1976d2; font-size: large;">צפייה בהיסטוריית ההזמנות שלי</a>
        </div>

    <?php endif; ?>

</div>

<nav class="bottom-nav">
    <a href="personal_details.php" class="nav-item active">
        <div class="active-pill">
            <i class="fa-solid fa-user"></i>
            <span>אזור אישי</span>
        </div>
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
    function toggleElement(id) {
        var el = document.getElementById(id);
        el.style.display = (el.style.display === "block") ? "none" : "block";
    }
    function toggleHours() {
        document.getElementById('manual_hours').style.display = document.getElementById('always_open').checked ? 'none' : 'table';
    }

    // פונקציית סנכרון לערכים ידניים וסליידרים
    function syncInputs(val, otherId, badgeId, prefix, suffix) {
        // עדכון האלמנט השני (אם זה סליידר או תיבה)
        document.getElementById(otherId).value = val;
        
        // עיצוב מיוחד לדירוג (הוספת .0 במידת הצורך)
        let displayVal = val;
        if (badgeId === 'rate-badge') {
            displayVal = parseFloat(val).toFixed(1);
        }
        
        // עדכון הבאדג' הוויזואלי
        document.getElementById(badgeId).innerText = prefix + displayVal + suffix;
    }
</script>

</body>
</html>