<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$servername = "localhost";
$username = "root";
$password_db = "";
$dbname = "charging_station_db";

$conn = new mysqli($servername, $username, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$user_id = $_SESSION['user_id'];
$message = "";

// בדיקה האם למשתמש כבר יש חשבון בנק שמור
$has_bank_account = false;
$stmt_check_bank = $conn->prepare("SELECT id FROM bank_account WHERE user_id = ?");
$stmt_check_bank->bind_param("i", $user_id);
$stmt_check_bank->execute();
if ($stmt_check_bank->get_result()->num_rows > 0) {
    $has_bank_account = true;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $city = trim($_POST['city']);
    $address = trim($_POST['address']);
    $details = trim($_POST['details']);
    $lat = $_POST['latitude'];
    $lng = $_POST['longitude'];
    
    $instant_approval = isset($_POST['instant_approval']) ? 1 : 0;
    
    // קבלת מחירי טעינה וחניה
    $price_per_hour = (float)$_POST['price_per_hour'];
    $parking_fee = (float)$_POST['parking_fee'];

    $conn->begin_transaction();

    try {
        if (!$has_bank_account && isset($_POST['bank_name'])) {
            $acc_name = trim($_POST['account_name']);
            $b_name = trim($_POST['bank_name']);
            $b_branch = trim($_POST['branch_number']);
            $acc_num = trim($_POST['account_number']);

            $stmt_bank = $conn->prepare("INSERT INTO bank_account (user_id, account_name, bank_name, branch_number, account_number) VALUES (?, ?, ?, ?, ?)");
            $stmt_bank->bind_param("issss", $user_id, $acc_name, $b_name, $b_branch, $acc_num);
            $stmt_bank->execute();
        }

        $new_station_id = "IL-" . rand(1000, 9999) . rand(10, 99);
        $image_path = NULL;

        if (isset($_FILES['station_image']) && $_FILES['station_image']['error'] == 0) {
            $target_dir = "images/";
            $file_extension = strtolower(pathinfo($_FILES["station_image"]["name"], PATHINFO_EXTENSION));
            $new_filename = $new_station_id . "." . $file_extension;
            $target_file = $target_dir . $new_filename;
            if (move_uploaded_file($_FILES["station_image"]["tmp_name"], $target_file)) {
                $image_path = $target_file;
            }
        }

        // עדכון השאילתה להכללת המחירים
        $sql_station = "INSERT INTO station (station_id, name, operator, city, address, latitude, longitude, details, image, instant_approval, price_per_hour, parking_fee) VALUES (?, ?, 'EvEnergy', ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_stat = $conn->prepare($sql_station);
        $stmt_stat->bind_param("ssssddssidd", $new_station_id, $name, $city, $address, $lat, $lng, $details, $image_path, $instant_approval, $price_per_hour, $parking_fee);
        $stmt_stat->execute();

        // --- שמירת שעות פתיחה - תוקן לשמירת שעות גם כשסגור ---
        $always_open = isset($_POST['always_open']);
        for ($day = 1; $day <= 7; $day++) {
            if ($always_open) {
                $open_t = "00:00:00"; $close_t = "23:59:59"; $is_closed = 0;
            } else {
                $is_closed = isset($_POST["day_closed_$day"]) ? 1 : 0;
                $open_t = $_POST["open_time_$day"];
                $close_t = $_POST["close_time_$day"];
            }
            $stmt_hours = $conn->prepare("INSERT INTO opening_hours (station_id, day_of_week, open_time, close_time, is_closed) VALUES (?, ?, ?, ?, ?)");
            $stmt_hours->bind_param("sissi", $new_station_id, $day, $open_t, $close_t, $is_closed);
            $stmt_hours->execute();
        }

        $sql_owner = "INSERT INTO owner_stations (user_id, station_id) VALUES (?, ?)";
        $stmt_owner = $conn->prepare($sql_owner);
        $stmt_owner->bind_param("is", $user_id, $new_station_id);
        $stmt_owner->execute();

        $conn->commit();
        $message = "העמדה נשמרה בהצלחה!";
        header("refresh:2;url=personal_details.php");
    } catch (Exception $e) {
        $conn->rollback();
        $message = "שגיאה בשמירה: " . $e->getMessage();
    }
}

$my_stations = [];
$res = $conn->query("SELECT s.name, s.latitude, s.longitude FROM station s JOIN owner_stations os ON s.station_id = os.station_id WHERE os.user_id = $user_id");
while ($row = $res->fetch_assoc()) { $my_stations[] = $row; }
$conn->close();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <title>הוספת עמדה חדשה</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { margin: 0; font-family: "Segoe UI", sans-serif; background-color: #f4f4f4; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        
        /* 1. מירכוז הטקסט ב-Header */
        header { background-color: #2e7d32; color: white; padding: 15px 20px; display: flex; justify-content: center; align-items: center; z-index: 1000; box-shadow: 0 2px 5px rgba(0,0,0,0.1); position: relative; }
        header h1 { margin: 0; font-size: x-large; text-align: center; }
        .nav-buttons { position: absolute; left: 20px; display: flex; gap: 10px; }

        .btn-header { color: white; text-decoration: none; font-weight: bold; border: 1px solid white; padding: 5px 12px; border-radius: 4px; font-size: 14px; background: transparent; cursor: pointer; }
        
        /* הסתרת כפתורי ה-Header כפי שנתבקש */
        .btn-header { display: none !important; }

        #map { flex-grow: 1; width: 100%; cursor: crosshair; z-index: 1; }
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background: #fff; padding: 25px; padding-bottom: 110px; border-radius: 12px; width: 95%; max-width: 500px; max-height: 90vh; overflow-y: auto; position: relative; }
        .close-modal { position: absolute; top: 10px; left: 15px; color: #aaa; font-size: 28px; cursor: pointer; }
        .form-group { margin-bottom: 12px; position: relative; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type=text], input[type=time], input[type=number], textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; font-family: inherit; }
        
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

        .switch-container { display: flex; align-items: center; justify-content: space-between; padding: 10px; border-radius: 8px; margin-bottom: 10px; }
        .bg-blue { background: #e3f2fd; }
        .bg-green { background: #e8f5e9; }

        .help-icon { display: inline-block; width: 18px; height: 18px; background: #1976d2; color: white; border-radius: 50%; text-align: center; font-size: 13px; line-height: 18px; cursor: pointer; margin-right: 5px; position: relative; }
        .help-icon:hover::after { content: attr(data-tip); position: absolute; bottom: 25px; right: -20px; background: #333; color: #fff; padding: 8px; border-radius: 5px; width: 220px; font-size: 11px; line-height: 1.4; z-index: 100; text-align: right; }

        .hours-table { width: 100%; border-collapse: collapse; margin-top: 10px; display: none; font-size: 13px; }
        .hours-table td { padding: 8px 4px; border-bottom: 1px solid #eee; vertical-align: middle; }
        .bank-section { background: #f3e5f5; padding: 15px; border-radius: 8px; border: 1px solid #ce93d8; margin: 15px 0; }
        .submit-btn { width: 100%; padding: 12px; background: #2e7d32; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .red-marker-filter { filter: hue-rotate(160deg) brightness(0.9) saturate(1.5); }
        
        .price-row { display: flex; gap: 10px; margin-bottom: 12px; }
        .price-row .form-group { flex: 1; }

        /* 2+3. עיצוב כפתור הוספה ידנית - למעלה באמצע והגדלה */
        .manual-btn-attractive {
            position: fixed;
            top: 85px; /* מתחת ל-Header */
            left: 50%;
            transform: translateX(-50%);
            background-color: #2e7d32;
            color: white;
            padding: 15px 35px; /* הגדלת הכפתור */
            border-radius: 50px;
            font-size: 18px; /* הגדלת הפונט */
            font-weight: bold;
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
            border: 2px solid white;
            z-index: 3000;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .manual-btn-attractive:hover {
            background-color: #1b5e20;
            transform: translateX(-50%) scale(1.05);
        }
        .manual-btn-attractive i { font-size: 20px; }

        /* סרגל ניווט תחתון */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; width: 100%; background-color: white; display: flex; justify-content: space-around; align-items: center; padding: 10px 0 25px 0; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); z-index: 7000;
        }
        .nav-item {
            text-decoration: none; color: #757575; display: flex; flex-direction: column; align-items: center; font-size: 14px; font-weight: 500; flex: 1; transition: all 0.2s ease;
        }
        .nav-item i { font-size: 22px; margin-bottom: 5px; }

        /* הסתרת הכפתור המקורי מה-Header */
        .header-manual-btn { display: none; }
    </style>
</head>
<body>

<header>
    <h1>הוספת עמדה חדשה</h1>
    <div class="nav-buttons">
        <button onclick="openManualModal()" class="btn-header header-manual-btn" style="background:#f57c00; border:none;">הוספה ידנית</button>
        <a href="map.php" class="btn-header">חזרה למפה</a>
    </div>
</header>

<div id="map"></div>

<button onclick="openManualModal()" class="manual-btn-attractive">
    <i class="fa-solid fa-keyboard"></i>
    <span>הוספה ידנית</span>
</button>

<div id="stationModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 style="margin-top:0; color:#2e7d32; text-align:center;">פרטי העמדה</h2>
        
        <form id="stationForm" method="POST" enctype="multipart/form-data">
            <input type="hidden" id="lat_input" name="latitude">
            <input type="hidden" id="lng_input" name="longitude">
            
            <div class="form-group"><label>שם העמדה:</label><input type="text" name="name" required></div>
            <div class="form-group"><label>עיר:</label><input type="text" id="city_input" name="city" required></div>
            <div class="form-group"><label>כתובת:</label><input type="text" id="address_input" name="address" required></div>
            
            <div class="price-row">
                <div class="form-group">
                    <label><span class="help-icon" data-tip="המחיר המומלץ הוא מינימום 20 שח. ניתן לשנות בכל שלב מהאזור האישי. ממחיר זה ינוכו עמלות">?</span>עלות טעינה (שעה):</label>
                    <input type="number" name="price_per_hour" min="15" max="100" placeholder="15-100" required>
                </div>
                <div class="form-group">
                    <label><span class="help-icon" data-tip="המחיר המומלץ הוא מינימום 10 שח. ניתן לשנות בכל שלב מהאזור האישי. ממחיר זה ינוכו עמלות">?</span>עלות חניה (שעה):</label>
                    <input type="number" name="parking_fee" min="5" max="50" placeholder="5-50" required>
                </div>
            </div>

            <div class="form-group"><label>תמונת העמדה:</label><input type="file" name="station_image" accept="image/*"></div>

            <div class="switch-container bg-blue">
                <span style="font-weight:bold; color:#1976d2;">
                    <span class="help-icon" data-tip="במצב זה, כל הזמנה של לקוח תאושר באופן אוטומטי. אם המצב כבוי, תצטרך לאשר כל הזמנה באופן ידני.">?</span>
                    אישור הזמנות מיידי (אוטומטי)
                </span>
                <label class="switch">
                    <input type="checkbox" name="instant_approval" checked>
                    <span class="slider blue"></span>
                </label>
            </div>

            <div class="switch-container bg-green">
                <span style="font-weight:bold; color:#2e7d32;">עמדה תמיד פנויה (24/7)</span>
                <label class="switch">
                    <input type="checkbox" name="always_open" id="always_open" checked onchange="toggleHours()">
                    <span class="slider green"></span>
                </label>
            </div>

            <table id="manual_hours" class="hours-table">
                <?php 
                $days = ["א'", "ב'", "ג'", "ד'", "ה'", "ו'", "ש'"];
                foreach($days as $i => $day): $d = $i + 1; ?>
                <tr>
                    <td style="width: 60px;"><strong>יום <?php echo $day; ?></strong></td>
                    <td>מ- <input type="time" name="open_time_<?php echo $d; ?>" value="00:00" style="width:100px;"></td>
                    <td>עד <input type="time" name="close_time_<?php echo $d; ?>" value="23:59" style="width:100px;"></td>
                    <td style="text-align: left;">
                        <label class="switch" style="width: 40px; height: 20px;">
                            <input type="checkbox" name="day_closed_<?php echo $d; ?>">
                            <span class="slider red" style="border-radius: 20px;"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <?php if (!$has_bank_account): ?>
            <div class="bank-section">
                <h3 style="margin-top:0; font-size:15px; color:#7b1fa2;">🏦 פרטי בנק לקבלת תשלום</h3>
                <div class="form-group"><label>בעל החשבון:</label><input type="text" name="account_name" required></div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:2;"><label>בנק:</label><input type="text" name="bank_name" required></div>
                    <div class="form-group" style="flex:1;"><label>סניף:</label><input type="text" name="branch_number" required></div>
                </div>
                <div class="form-group"><label>מספר חשבון:</label><input type="text" name="account_number" required></div>
            </div>
            <?php endif; ?>

            <div class="form-group"><label>פרטים נוספים:</label><textarea name="details" rows="2"></textarea></div>
            <button type="submit" class="submit-btn">שמור עמדה וסיים</button>
        </form>
    </div>
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

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    var map = L.map('map').setView([31.4117, 35.0818], 8);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    var stations = <?php echo json_encode($my_stations); ?>;
    stations.forEach(s => L.marker([s.latitude, s.longitude]).addTo(map).bindPopup(s.name));

    var newMarker = null;
    var modal = document.getElementById("stationModal");

    var userLocationMarker = null;
    var isFirstLocation = true; 

    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(function(position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;

            if (!userLocationMarker) {
                userLocationMarker = L.circleMarker([lat, lng], {
                    radius: 8,
                    fillColor: "#2196F3",
                    color: "#fff",
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.9
                }).addTo(map).bindPopup("אתה כאן");

                if (isFirstLocation) {
                    map.setView([lat, lng], 15);
                    isFirstLocation = false;
                }
            } else {
                userLocationMarker.setLatLng([lat, lng]);
            }
        }, function(error) {
            console.warn("Geolocation error: " + error.message);
        }, {
            enableHighAccuracy: true
        });
    }

    map.on('click', function(e) {
        setMarker(e.latlng.lat, e.latlng.lng);
        document.getElementById('lat_input').value = e.latlng.lat;
        document.getElementById('lng_input').value = e.latlng.lng;
        // הסתרת הכפתור כשנפתח המודאל בלחיצה על המפה
        document.querySelector('.manual-btn-attractive').style.display = 'none';
        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${e.latlng.lat}&lon=${e.latlng.lng}&accept-language=he`)
            .then(r => r.json()).then(data => {
                if(data.address) {
                    document.getElementById('city_input').value = data.address.city || data.address.town || "";
                    document.getElementById('address_input').value = (data.address.road || "") + " " + (data.address.house_number || "");
                }
            });
        modal.style.display = "flex";
    });

    function openManualModal() {
        document.getElementById('lat_input').value = "";
        // הסתרת הכפתור כשנפתח המודאל בלחיצה עליו
        document.querySelector('.manual-btn-attractive').style.display = 'none';
        modal.style.display = "flex";
    }

    function setMarker(lat, lng) {
        if (newMarker) map.removeLayer(newMarker);
        newMarker = L.marker([lat, lng], {icon: L.icon({iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png', className: 'red-marker-filter'})}).addTo(map);
    }

    function toggleHours() {
        document.getElementById('manual_hours').style.display = document.getElementById('always_open').checked ? 'none' : 'table';
    }

    function closeModal() { 
        modal.style.display = "none"; 
        if(newMarker) map.removeLayer(newMarker); 
        // החזרת הכפתור כשהמודאל נסגר
        document.querySelector('.manual-btn-attractive').style.display = 'flex';
    }
</script>
</body>
</html>