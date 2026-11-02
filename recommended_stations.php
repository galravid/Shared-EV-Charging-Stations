<?php
session_start();
date_default_timezone_set('Asia/Jerusalem');

$servername = "localhost";
$username = "root";
$password_db = "";
$dbname = "charging_station_db";

$conn = new mysqli($servername, $username, $password_db, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

$user_id = $_SESSION['user_id'];

// 1. שליפת העדפות המשתמש
$stmt_pref = $conn->prepare("SELECT max_distance, min_rating, max_price FROM preferences WHERE user_id = ?");
$stmt_pref->bind_param("i", $user_id);
$stmt_pref->execute();
$pref = $stmt_pref->get_result()->fetch_assoc();

// ערכי ברירת מחדל אם אין העדפות
$dmax = $pref['max_distance'] ?? 10;
$pmax = $pref['max_price'] ?? 20;
$rmin = $pref['min_rating'] ?? 0;

// נניח שמיקום ברירת מחדל להתחלה (תל אביב) אם ה-GPS לא זמין מיד
$default_lat = 32.1132; 
$default_lng = 34.8180;

// פונקציית עזר לחישוב מרחק (KM)
function getDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// 2. שליפת כל העמדות עם הדירוג הממוצע שלהן (מעודכן ל-grade ו-rating)
$sql = "SELECT s.*, IFNULL(AVG(r.grade), 5) as avg_rating 
        FROM station s 
        LEFT JOIN rating r ON s.station_id = r.station_id 
        GROUP BY s.station_id";
$result = $conn->query($sql);
$all_stations = [];

while($row = $result->fetch_assoc()) {
    $row['distance'] = getDistance($default_lat, $default_lng, $row['latitude'], $row['longitude']);
    
    $s_id = $row['station_id'];
    $now = date('Y-m-d H:i:s');
    
    $check_avail = $conn->query("SELECT order_id FROM orders WHERE station_id = '$s_id' AND '$now' BETWEEN start_time AND end_time");
    $row['available'] = ($check_avail->num_rows == 0) ? 1 : 0;
    
    $all_stations[] = $row;
}

// --- שלבי הסינון לפי האלגוריתם ---
$filtered = array_filter($all_stations, function($s) { return $s['available'] == 1; });

$step2 = array_filter($filtered, function($s) use ($dmax) { return $s['distance'] <= $dmax; });
if (count($step2) < 3) {
    usort($filtered, function($a, $b) { return $a['distance'] <=> $b['distance']; });
    $step2 = array_slice($filtered, 0, 3);
}

$step3 = array_filter($step2, function($s) use ($pmax) { return $s['price_per_hour'] <= $pmax; });
if (count($step3) < 3) {
    usort($step2, function($a, $b) { return $a['price_per_hour'] <=> $b['price_per_hour']; });
    $step3 = array_slice($step2, 0, 3);
}

$step4 = array_filter($step3, function($s) use ($rmin) { return $s['avg_rating'] >= $rmin; });
if (count($step4) < 3) {
    usort($step3, function($a, $b) { return $b['avg_rating'] <=> $a['avg_rating']; });
    $step4 = array_slice($step3, 0, 3);
}

// --- נרמול וחישוב ציון Sm ---
$recommended = [];
if (count($step4) > 0) {
    $dmin = min(array_column($step4, 'distance'));
    $pmin = min(array_column($step4, 'price_per_hour'));
    $rmax = max(array_column($step4, 'avg_rating'));

    foreach ($step4 as &$s) {
        $d_norm = ($dmax > $dmin) ? ($s['distance'] - $dmin) / ($dmax - $dmin) : 0;
        $p_norm = ($pmax > $pmin) ? ($s['price_per_hour'] - $pmin) / ($pmax - $pmin) : 0;
        $r_norm = ($rmax > $rmin) ? 1 - (($s['avg_rating'] - $rmin) / ($rmax - $rmin)) : 0;
        
        $s['sm_score'] = $d_norm + $p_norm + $r_norm;
    }

    usort($step4, function($a, $b) { return $a['sm_score'] <=> $b['sm_score']; });
    $recommended = array_slice($step4, 0, 3);
}
?>

<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <title>עמדות מומלצות עבורך</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { margin: 0; font-family: sans-serif; background: #f4f7f6; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        header { background: #2e7d32; color: white; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); z-index: 3000; }
        #map { flex-grow: 1; width: 100%; z-index: 1; }

        /* סרגל ניווט תחתון */
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

<header>
    <h1 style="margin:0; font-size: 20px;">עמדות הטעינה המומלצות ביותר עבורך</h1>
</header>

<div id="map"></div>

<nav class="bottom-nav">
    <a href="personal_details.php" class="nav-item">
        <i class="fa-regular fa-user"></i>
        <span>אזור אישי</span>
    </a>
    <a href="recommended_stations.php" class="nav-item active">
        <div class="active-pill">
            <i class="fa-solid fa-star"></i>
            <span>עמדות מומלצות</span>
        </div>
    </a>
    <a href="map.php" class="nav-item">
        <i class="fa-solid fa-house"></i>
        <span>מסך הבית</span>
    </a>
</nav>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    var map = L.map('map', {zoomControl: false}).setView([<?php echo $default_lat; ?>, <?php echo $default_lng; ?>], 12);
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    var userLocationMarker = null;
    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(function(position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            if (!userLocationMarker) {
                userLocationMarker = L.circleMarker([lat, lng], {
                    radius: 8, fillColor: "#2196F3", color: "#fff", weight: 2, opacity: 1, fillOpacity: 0.9
                }).addTo(map).bindPopup("אתה כאן");
                
            } else {
                userLocationMarker.setLatLng([lat, lng]);
            }
        }, null, {enableHighAccuracy: true});
    }

    var recommendedStations = <?php echo json_encode($recommended); ?>;
    recommendedStations.forEach(function(s, index) {
        var rank = index + 1;
        var customIcon = L.divIcon({
            className: 'custom-div-icon',
            html: "<div style='background-color:#2e7d32; color:white; border-radius:50%; width:35px; height:35px; display:flex; align-items:center; justify-content:center; border:2px solid white; font-weight:bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3); font-size:16px;'>" + rank + "</div>",
            iconSize: [35, 35],
            iconAnchor: [17, 17]
        });

        var marker = L.marker([s.latitude, s.longitude], {icon: customIcon}).addTo(map);
        marker.on('click', function() {
            window.location.href = 'station.php?id=' + s.station_id;
        });
        marker.bindTooltip("<b>" + s.name + "</b><br>מחיר: ₪" + s.price_per_hour, {direction: 'top'});
    });
</script>

</body>
</html>