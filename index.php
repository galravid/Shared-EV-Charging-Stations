<?php
$signup_url = "signup.php";
$login_url = "login.php";
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <!-- התוספת החשובה ביותר לתיקון התצוגה בנייד -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type">
    <title>Landing Page</title>

    <style>
        * {
            box-sizing: border-box; /* מבטיח שה-Padding לא יחרוג מרוחב המסך */
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(to bottom, #e8f5e9, #ffffff);
            color: #333;
            text-align: center;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .container {
            width: 100%;
            max-width: 500px; /* מגביל את רוחב התוכן למראה של אפליקציה */
            margin: 0 auto;
        }

        .header {
            margin-top: 20px;
            animation: fadeIn 1.2s ease;
        }

        /* מכולת התמונה - מותאמת לנייד */
        .icon-container {
            width: 100%;
            height: 250px; /* גובה מופחת כברירת מחדל לניידים */
            margin: 0 auto 20px auto;
            overflow: hidden;
            border-radius: 30px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon {
            width: 100%;
            height: 100%;
            object-fit: cover; /* שומר על פרופורציות התמונה וחיתוך חכם */
        }

        h1 {
            font-size: 22px; /* גודל מותאם לנייד */
            font-weight: 700;
            color: #2e7d32;
            margin-bottom: 15px;
            line-height: 1.3;
        }

        p {
            font-size: 16px;
            line-height: 1.5;
            color: #444;
            margin-bottom: 30px;
        }

        .btn {
            width: 100%;
            padding: 16px;
            margin-bottom: 15px;
            border-radius: 12px;
            font-size: 18px;
            cursor: pointer;
            border: none;
            transition: 0.25s;
            font-weight: 600;
            display: block;
            text-decoration: none;
        }

        .signup-btn {
            background: #2e7d32;
            color: white;
            box-shadow: 0 4px 9px rgba(0, 0, 0, 0.15);
        }

        .login-btn {
            background: white;
            border: 2px solid #2e7d32;
            color: #2e7d32;
        }

        .collaboration {
            margin-top: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .collaboration span {
            font-size: 14px;
            color: #666;
        }

        .collaboration img {
            height: 30px;
            width: auto;
        }

        .footer-links {
            margin-top: 30px;
            padding: 20px 0;
            display: flex;
            justify-content: center;
            gap: 15px;
            border-top: 1px solid #ddd;
            width: 100%;
        }

        .footer-links span {
            color: #2e7d32;
            font-size: 13px;
            cursor: pointer;
            font-weight: bold;
        }

        /* פופ-אפים */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 400px;
            padding: 25px;
            border-radius: 20px;
            position: relative;
            text-align: right;
        }

        /* התאמות למסכים גדולים יותר (טאבלט ומחשב) */
        @media screen and (min-width: 481px) {
            h1 { font-size: 28px; }
            .icon-container { height: 350px; }
            .container { padding: 20px; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="icon-container">
            <img src="landing_page_image.jpeg" alt="EV Charging Station" class="icon" />
        </div>
        <h1>מערכת לניהול טעינת רכבים חשמליים בעמדות משותפות</h1>
        <p>העמדה שלכם עומדת ריקה? הפכו אותה להכנסה פסיבית!<br>
        נתקעתם בלי סוללה? סוף לחיפושים המייאשים.</p>
    </div>

    <a href="<?php echo $signup_url; ?>" style="text-decoration: none;">
        <button class="btn signup-btn">הרשמה למערכת</button>
    </a>

    <a href="<?php echo $login_url; ?>" style="text-decoration: none;">
        <button class="btn login-btn">התחברות</button>
    </a>

    <div class="collaboration">
        <span>בשיתוף</span>
        <img src="logo_EvEnergy.jpg" alt="EvEnergy Logo">
    </div>

    <div class="footer-links">
        <span onclick="toggleModal('terms')">תנאי שימוש</span>
        <span onclick="toggleModal('privacy')">מדיניות פרטיות</span>
        <span onclick="toggleModal('contact')">צור קשר</span>
    </div>
</div>

<div id="modalOverlay" class="modal-overlay" onclick="toggleModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <button class="close-modal" onclick="toggleModal()" style="position: absolute; top: 10px; left: 10px; border: none; background: #eee; border-radius: 50%; width: 30px; height: 30px; cursor: pointer;">&times;</button>
        <div id="modalBody"></div>
    </div>
</div>

<script>
    function toggleModal(type) {
        const modal = document.getElementById('modalOverlay');
        const body = document.getElementById('modalBody');
        
        if (!type) {
            modal.style.display = 'none';
            return;
        }

        let content = '';
        if (type === 'terms') {
            content = '<h2>תנאי שימוש</h2><p>השימוש במערכת כפוף להסכמה לתנאים. המערכת מקשרת בין בעלי עמדות לנהגים.</p>';
        } else if (type === 'privacy') {
            content = '<h2>מדיניות פרטיות</h2><p>המידע שלך מאובטח ומשמש לצורך תפעול המערכת בלבד.</p>';
        } else if (type === 'contact') {
            content = '<h2>צור קשר</h2><p>תמי גלמן<br>גל רביד</p>';
        }

        body.innerHTML = content;
        modal.style.display = 'flex';
    }
</script>

</body>
</html>