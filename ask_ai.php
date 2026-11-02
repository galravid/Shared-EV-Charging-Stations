<?php
session_start();

// בדיקה שהמשתמש מחובר (אבטחה בסיסית)
if (!isset($_SESSION['user_id'])) {
    die("גישה נדחתה. עליך להיות מחובר.");
}
?>
<html lang="he" dir="rtl">
<head>
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
</head>
<?php
// API key is read from config.php (not committed) - see config.example.php
$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$apiKey = $config['gemini_api_key'] ?? getenv('GEMINI_API_KEY');
$userMsg = $_POST['message'] ?? '';

if (empty($userMsg)) {
    die("לא התקבלה הודעה.");
}

// הנחיות המערכת ל-AI (System Instructions)
$systemInstruction = "אתה העוזר האישי של אפליקציית - רשת שיתופית לטעינת רכבים חשמליים.
- שמך הוא 'EvBot'.
- עזור למשתמשים בשאלות על תפעול האפליקציה (הזמנת עמדה, ביטול, ניווט).
- מחיר המינימום לטעינה באפליקציה הוא 10 שח.
- מחיר חניה נוספת נגבה רק לאחר סיום הטעינה.
- אם משתמש שואל איך מבטלים הזמנה, תגיד לו ללכת להיסטוריית ההזמנות וללחוץ על 'ביטול הזמנה'.
- ענה תמיד בעברית ידידותית עם אמוג'ים מתאימים ⚡🚗.";

// בניית גוף הבקשה לפי המפרט העדכני של גוגל
$data = [
    "contents" => [
        [
            "parts" => [
                ["text" => $systemInstruction . "\n\nשאלה מהמשתמש: " . $userMsg]
            ]
        ]
    ]
];

// שימוש ב-Endpoint היציב (v1) ובמודל flash הפשוט
$url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

// הגדרות ל-Localhost (ביטול אימות SSL שגורם לבעיות ב-Wamp)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);

// בדיקה אם cURL נכשל (בעיית אינטרנט או שרת מקומי)
if(curl_errno($ch)) {
    echo "שגיאת תקשורת בשרת: " . curl_error($ch);
    curl_close($ch);
    exit;
}

$result = json_decode($response, true);

// בדיקה אם התשובה קיימת במבנה ה-JSON
if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    echo $result['candidates'][0]['content']['parts'][0]['text'];
} else {
    // אם יש שגיאה מה-API, נציג אותה בצורה ברורה
    if (isset($result['error'])) {
        echo "שגיאת API: " . $result['error']['message'] . " (קוד: " . $result['error']['code'] . ")";
    } else {
        // במקרה של תשובה ריקה או מבנה לא צפוי
        echo "מצטער, ה-AI לא החזיר תשובה. תשובה גולמית: " . substr($response, 0, 150);
    }
}

curl_close($ch);
?>