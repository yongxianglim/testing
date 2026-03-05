<?php
session_start();
if (isset($_SESSION['user_id'])) {
    $r = $_SESSION['role'];
    if ($r === 'VIEWER') header("Location: viewer.php");
    elseif ($r === 'EDITOR') header("Location: editor.php");
    else header("Location: viewer.php");
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require 'db.php';
    $u = trim($_POST['username'] ?? '');
    $pw = $_POST['password'] ?? '';
    if ($u === '') $error = "Username is required.";
    elseif ($pw === '') $error = "Password is required.";
    else {
        $stmt = $conn->prepare("SELECT user_id,username,password,role FROM users WHERE username=?");
        $stmt->bind_param("s", $u);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $row = $res->fetch_assoc();
            if ($pw === $row['password']) {
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];
                if ($row['role'] === 'VIEWER') header("Location: viewer.php");
                elseif ($row['role'] === 'EDITOR') header("Location: editor.php");
                else header("Location: viewer.php");
                exit;
            } else $error = "Invalid password.";
        } else $error = "User not found.";
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Login — Subjective Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: linear-gradient(-45deg, #FFF8F0, #EEF2FF, #F0FFF4, #FFF0F6);
            background-size: 400% 400%;
            animation: bgShift 20s ease infinite;
            position: relative;
        }

        @keyframes bgShift {
            0% {
                background-position: 0% 50%
            }

            50% {
                background-position: 100% 50%
            }

            100% {
                background-position: 0% 50%
            }
        }

        #particles-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        .login-glow {
            position: fixed;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.2;
            z-index: 0;
        }

        .glow-1 {
            width: 450px;
            height: 450px;
            background: #A7C7E7;
            top: -8%;
            left: -3%;
            animation: gM1 12s ease-in-out infinite;
        }

        .glow-2 {
            width: 400px;
            height: 400px;
            background: #FADADD;
            bottom: -8%;
            right: -3%;
            animation: gM2 14s ease-in-out infinite;
        }

        .glow-3 {
            width: 300px;
            height: 300px;
            background: #C1E1C1;
            top: 45%;
            left: 55%;
            animation: gM3 10s ease-in-out infinite;
        }

        @keyframes gM1 {

            0%,
            100% {
                transform: translate(0, 0)
            }

            50% {
                transform: translate(80px, 60px)
            }
        }

        @keyframes gM2 {

            0%,
            100% {
                transform: translate(0, 0)
            }

            50% {
                transform: translate(-60px, -80px)
            }
        }

        @keyframes gM3 {

            0%,
            100% {
                transform: translate(-50%, -50%) scale(1)
            }

            50% {
                transform: translate(-30%, -30%) scale(1.2)
            }
        }

        .login-container {
            position: relative;
            z-index: 10;
            animation: loginReveal 1s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(40px) scale(0.95);
        }

        @keyframes loginReveal {
            to {
                opacity: 1;
                transform: translateY(0) scale(1)
            }
        }

        .login-card {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(30px);
            -webkit-backdrop-filter: blur(30px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 28px;
            padding: 50px 48px;
            width: 440px;
            max-width: 92vw;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.05), inset 0 1px 0 rgba(255, 255, 255, 0.8);
            transition: transform 0.4s, box-shadow 0.4s;
        }

        .login-card:hover {
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.07), inset 0 1px 0 rgba(255, 255, 255, 1);
        }

        .login-logo {
            text-align: center;
            margin-bottom: 10px;
        }

        .logo-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #6B8DB5, #8BB3D9, #A7C7E7);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: #fff;
            margin-bottom: 18px;
            box-shadow: 0 8px 30px rgba(107, 141, 181, 0.3);
            animation: logoFloat 4s ease-in-out infinite;
            position: relative;
        }

        .logo-icon::after {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 22px;
            background: conic-gradient(from 0deg, #6B8DB5, #C1A0D8, #68A87A, #D4A85A, #6B8DB5);
            z-index: -1;
            animation: spin 6s linear infinite;
            opacity: 0.25;
        }

        @keyframes logoFloat {

            0%,
            100% {
                transform: translateY(0) rotate(0)
            }

            50% {
                transform: translateY(-8px) rotate(2deg)
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        .login-card h1 {
            text-align: center;
            font-size: 26px;
            font-weight: 900;
            color: #2D3748;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #2D3748, #6B8DB5);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-subtitle {
            text-align: center;
            color: #A0AEC0;
            font-size: 14px;
            margin-bottom: 34px;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #718096;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #CBD5E0;
            font-size: 15px;
            transition: all 0.3s;
            z-index: 2;
        }

        .input-wrapper input {
            width: 100%;
            padding: 14px 16px 14px 46px;
            border: 1.5px solid rgba(107, 141, 181, 0.12);
            border-radius: 14px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            background: rgba(255, 255, 255, 0.5);
            color: #2D3748;
            transition: all 0.3s;
            outline: none;
        }

        .input-wrapper input::placeholder {
            color: #CBD5E0;
        }

        .input-wrapper input:focus {
            border-color: rgba(107, 141, 181, 0.4);
            box-shadow: 0 0 0 4px rgba(107, 141, 181, 0.08), 0 0 30px rgba(107, 141, 181, 0.04);
            background: rgba(255, 255, 255, 0.9);
        }

        .input-wrapper:focus-within i {
            color: #6B8DB5;
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            color: #fff;
            background: linear-gradient(135deg, #6B8DB5, #8BB3D9);
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 6px 20px rgba(107, 141, 181, 0.25);
            margin-top: 10px;
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:hover {
            box-shadow: 0 8px 30px rgba(107, 141, 181, 0.35);
            transform: translateY(-2px);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            margin-top: 28px;
            color: #CBD5E0;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .msg-error {
            background: rgba(208, 112, 112, 0.08);
            color: #9B2C2C;
            border: 1px solid rgba(208, 112, 112, 0.12);
            padding: 13px 18px;
            border-radius: 14px;
            margin-bottom: 22px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shakeError 0.5s ease;
        }

        .msg-error i {
            font-size: 16px;
            flex-shrink: 0;
            color: #D07070;
        }

        @keyframes shakeError {

            0%,
            100% {
                transform: translateX(0)
            }

            20% {
                transform: translateX(-6px)
            }

            40% {
                transform: translateX(6px)
            }

            60% {
                transform: translateX(-4px)
            }

            80% {
                transform: translateX(4px)
            }
        }
    </style>
</head>

<body>
    <canvas id="particles-canvas"></canvas>
    <div class="login-glow glow-1"></div>
    <div class="login-glow glow-2"></div>
    <div class="login-glow glow-3"></div>
    <div class="login-container">
        <div class="login-card" id="loginCard">
            <div class="login-logo">
                <div class="logo-icon"><i class="fas fa-flask"></i></div>
            </div>
            <h1>Subjective Portal</h1>
            <p class="login-subtitle">Sign in to continue</p>
            <?php if ($error): ?><div class="msg-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-group"><label>Username</label>
                    <div class="input-wrapper"><i class="fas fa-user"></i><input type="text" name="username" required autofocus placeholder="Enter your username"></div>
                </div>
                <div class="form-group"><label>Password</label>
                    <div class="input-wrapper"><i class="fas fa-lock"></i><input type="password" name="password" required placeholder="Enter your password"></div>
                </div>
                <button type="submit" class="login-btn"><i class="fas fa-arrow-right-to-bracket"></i>&nbsp; Sign In</button>
            </form>
            <div class="login-footer"><i class="fas fa-shield-halved"></i> PixArt Imaging </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
    <script>
        VanillaTilt.init(document.getElementById('loginCard'), {
            max: 6,
            speed: 600,
            glare: true,
            'max-glare': 0.1,
            scale: 1.02
        });
        var c = document.getElementById('particles-canvas'),
            ctx = c.getContext('2d');
        c.width = window.innerWidth;
        c.height = window.innerHeight;
        var pts = [];
        for (var i = 0; i < 70; i++) pts.push({
            x: Math.random() * c.width,
            y: Math.random() * c.height,
            vx: (Math.random() - 0.5) * 0.25,
            vy: (Math.random() - 0.5) * 0.25,
            r: Math.random() * 2 + 0.5,
            o: Math.random() * 0.3 + 0.05
        });

        function draw() {
            ctx.clearRect(0, 0, c.width, c.height);
            for (var i = 0; i < pts.length; i++) {
                var p = pts[i];
                p.x += p.vx;
                p.y += p.vy;
                if (p.x < 0) p.x = c.width;
                if (p.x > c.width) p.x = 0;
                if (p.y < 0) p.y = c.height;
                if (p.y > c.height) p.y = 0;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(107,141,181,' + p.o + ')';
                ctx.fill();
                for (var j = i + 1; j < pts.length; j++) {
                    var q = pts[j],
                        dx = p.x - q.x,
                        dy = p.y - q.y,
                        d = Math.sqrt(dx * dx + dy * dy);
                    if (d < 130) {
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(q.x, q.y);
                        ctx.strokeStyle = 'rgba(107,141,181,' + (0.05 * (1 - d / 130)) + ')';
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(draw);
        }
        draw();
        window.addEventListener('resize', function() {
            c.width = window.innerWidth;
            c.height = window.innerHeight;
        });
    </script>
</body>

</html>