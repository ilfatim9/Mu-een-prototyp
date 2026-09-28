<?php
require_once __DIR__ . "/db.php";

$location = trim($_GET["location"] ?? "");
$sent = false;
$error = "";

if ($location === "") {
    $error = "This QR code does not contain a valid campus location.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $location !== "") {
    $emergencyType = "Emergency SOS";
    $description = "Public SOS reported through campus QR code.";

    $stmt = $conn->prepare("
        INSERT INTO public_emergency_reports
            (emergency_type, location, description, status)
        VALUES (?, ?, ?, 'new')
    ");

    $stmt->bind_param("sss", $emergencyType, $location, $description);

    if ($stmt->execute()) {
        $sent = true;
    } else {
        $error = "Unable to send the SOS. Please try again.";
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MU'EEN | Emergency SOS</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: #F8FAF9;
            color: #18343A;
        }

        .topbar {
            background: #176B68;
            color: white;
            text-align: center;
            padding: 18px;
        }

        .brand {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .brand-sub {
            margin-top: 4px;
            font-size: 13px;
            opacity: .9;
        }

        .page {
            width: min(92%, 480px);
            margin: 35px auto;
        }

        .card {
            background: white;
            border-radius: 22px;
            padding: 28px 22px;
            text-align: center;
            border-top: 5px solid #E76767;
            box-shadow: 0 8px 30px rgba(23, 107, 104, .10);
        }

        .sos-circle {
            width: 76px;
            height: 76px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #E76767;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 900;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 25px;
        }

        .subtitle {
            margin: 0 0 24px;
            color: #65777C;
            font-size: 14px;
            line-height: 1.5;
        }

        .location-box {
            margin: 20px 0 24px;
            padding: 17px;
            border-radius: 14px;
            background: #DDF3ED;
            border: 1px solid #B9E3D9;
        }

        .location-label {
            margin-bottom: 6px;
            color: #65777C;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .location {
            color: #176B68;
            font-size: 18px;
            font-weight: 800;
        }

        .send-button {
            width: 100%;
            padding: 17px;
            border: 0;
            border-radius: 13px;
            background: #E76767;
            color: white;
            font-size: 18px;
            font-weight: 900;
            cursor: pointer;
        }

        .send-button:active {
            transform: scale(.98);
        }

        .note {
            margin-top: 15px;
            color: #65777C;
            font-size: 12px;
            line-height: 1.5;
        }

        .error {
            padding: 14px;
            border-radius: 12px;
            background: #FFF1F1;
            border: 1px solid #F1CACA;
            color: #B64F4F;
            line-height: 1.5;
        }

        .success-icon {
            width: 78px;
            height: 78px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #DDF3ED;
            color: #176B68;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            font-weight: 900;
        }

        .success-title {
            color: #176B68;
        }

        @media (max-width: 500px) {
            .page {
                margin: 24px auto;
            }

            .card {
                padding: 25px 18px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">MU'EEN</div>
    <div class="brand-sub">Smart Campus Support</div>
</header>

<main class="page">
    <section class="card">

        <?php if ($sent): ?>

            <div class="success-icon">✓</div>

            <h1 class="success-title">SOS Sent</h1>

            <p class="subtitle">
                The University Health Center has been notified
                and received the emergency location.
            </p>

            <div class="location-box">
                <div class="location-label">Emergency Location</div>
                <div class="location">
                    <?= htmlspecialchars($location) ?>
                </div>
            </div>

        <?php elseif ($error !== ""): ?>

            <div class="sos-circle">SOS</div>
            <h1>Emergency SOS</h1>
            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php else: ?>

            <div class="sos-circle">SOS</div>

            <h1>Emergency SOS</h1>

            <p class="subtitle">
                A student needs medical assistance.
                Send an SOS to the University Health Center.
            </p>

            <div class="location-box">
                <div class="location-label">Your QR Location</div>

                <div class="location">
                    <?= htmlspecialchars($location) ?>
                </div>
            </div>

            <form method="POST">
                <button class="send-button" type="submit">
                    SEND SOS
                </button>
            </form>

            <div class="note">
                No login or student information is required.
            </div>

        <?php endif; ?>

    </section>
</main>

</body>
</html>
