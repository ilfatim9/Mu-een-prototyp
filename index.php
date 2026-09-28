<?php

session_start();
require_once __DIR__ . "/db.php";

$error = "";


/* =========================
   LOGOUT
========================= */

if (isset($_GET["logout"])) {
    session_unset();
    session_destroy();

    header("Location: index.php");
    exit;
}


/* =========================
   LOGIN
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["login"])) {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {

        $error = "Please enter your email and password.";

    } else {

        $stmt = $conn->prepare("
            SELECT
                id,
                university_id,
                full_name,
                email,
                password,
                role
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && $password === $user["password"]) {

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["university_id"] = $user["university_id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $user["role"];

            header("Location: index.php");
            exit;

        } else {

            $error = "Invalid email or password.";
        }

        $stmt->close();
    }
}


/* =========================
   CURRENT USER
========================= */

$role = $_SESSION["role"] ?? null;
$name = $_SESSION["full_name"] ?? "";
$email = $_SESSION["email"] ?? "";

// Student dashboard section selected from the sidebar.
$studentView = $_GET["view"] ?? "dashboard";
$allowedStudentViews = ["dashboard", "schedule", "alerts", "needs", "requests"];
if (!in_array($studentView, $allowedStudentViews, true)) {
    $studentView = "dashboard";
}


/* =========================
   STUDENT DATA
========================= */

$studentSchedules = [];
$studentNeeds = [];
$studentNotifications = [];
$unreadNotificationCount = 0;
$studentSupportRequests = [];

if ($role === "student") {

    $currentUserId = (int)($_SESSION["user_id"] ?? 0);

    $stmt = $conn->prepare("
        SELECT
            s.course_code,
            s.class_day,
            s.start_time,
            s.end_time,
            c.building,
            c.room_number,
            c.floor
        FROM student_profiles sp
        JOIN schedules s ON s.student_id = sp.id
        JOIN classrooms c ON c.id = s.classroom_id
        WHERE sp.user_id = ?
        ORDER BY s.start_time
    ");
    $stmt->bind_param("i", $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $studentSchedules[] = $row;
    }

    $stmt = $conn->prepare("
        SELECT sn.need_type, sn.status
        FROM student_profiles sp
        JOIN student_needs sn ON sn.student_id = sp.id
        WHERE sp.user_id = ?
          AND sn.status = 'approved'
        ORDER BY sn.need_type
    ");
    $stmt->bind_param("i", $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $studentNeeds[] = $row;
    }

    $stmt = $conn->prepare("
        SELECT id, title, message, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY
            CASE
                WHEN title LIKE 'Emergency %' THEN 0
                ELSE 1
            END,
            created_at DESC,
            id DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $studentNotifications[] = $row;
        if ((int)$row["is_read"] === 0) {
            $unreadNotificationCount++;
        }
    }

    // Health/support reports submitted after university registration.
    $stmt = $conn->prepare("
        SELECT id, support_category, report_file, status, admin_note, created_at
        FROM support_access_requests
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $studentSupportRequests[] = $row;
    }
}


/* =========================
   FACILITIES DATA
========================= */

$facilities = [];

$availableCount = 0;
$unavailableCount = 0;
$maintenanceCount = 0;

if ($role === "facilities") {

    $result = $conn->query("
        SELECT
            id,
            name,
            building,
            facility_type,
            status,
            updated_at
        FROM facilities
        ORDER BY building, name
    ");

    if ($result) {

        while ($row = $result->fetch_assoc()) {

            $facilities[] = $row;

            if ($row["status"] === "available") {
                $availableCount++;
            }

            if ($row["status"] === "unavailable") {
                $unavailableCount++;
            }

            if ($row["status"] === "maintenance") {
                $maintenanceCount++;
            }
        }
    }
}


/* =========================
   ADMIN CASE DATA
========================= */

$adminCases = [];

$activeCasesCount = 0;
$newCasesCount = 0;
$resolvedCasesCount = 0;
$adminSupportRequests = [];
$pendingSupportRequestsCount = 0;

if ($role === "admin") {

    $result = $conn->query("
        SELECT sar.id, sar.support_category, sar.report_file, sar.status, sar.admin_note, sar.created_at,
               u.full_name AS student_name, u.university_id
        FROM support_access_requests sar
        JOIN users u ON u.id = sar.user_id
        ORDER BY CASE sar.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END, sar.created_at DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $adminSupportRequests[] = $row;
            if ($row["status"] === "pending") $pendingSupportRequestsCount++;
        }
    }

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM support_cases
        WHERE status IN ('new', 'in_progress')
    ");

    if ($result) {
        $row = $result->fetch_assoc();
        $activeCasesCount = (int)$row["total"];
    }


    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM support_cases
        WHERE status = 'new'
    ");

    if ($result) {
        $row = $result->fetch_assoc();
        $newCasesCount = (int)$row["total"];
    }


    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM support_cases
        WHERE status = 'resolved'
    ");

    if ($result) {
        $row = $result->fetch_assoc();
        $resolvedCasesCount = (int)$row["total"];
    }


    $result = $conn->query("
        SELECT
            sc.id,
            sc.case_type,
            sc.description,
            sc.suggested_solution,
            sc.status,
            sc.created_at,

            u.full_name AS student_name,
            u.university_id,

            f.name AS facility_name,
            f.building AS facility_building,
            f.facility_type,
            f.status AS facility_status,

            s.course_code,

            c.room_number,
            c.floor,
            c.building AS classroom_building

        FROM support_cases sc

        JOIN student_profiles sp
            ON sp.id = sc.student_id

        JOIN users u
            ON u.id = sp.user_id

        LEFT JOIN facilities f
            ON f.id = sc.facility_id

        LEFT JOIN schedules s
            ON s.id = sc.schedule_id

        LEFT JOIN classrooms c
            ON c.id = s.classroom_id

        ORDER BY
            CASE sc.status
                WHEN 'new' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'resolved' THEN 3
                ELSE 4
            END,
            sc.created_at DESC
    ");

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $adminCases[] = $row;
        }
    }
}


/* =========================
   CLINIC EMERGENCY DATA
========================= */

$clinicEmergencies = [];
$publicEmergencies = [];
$activeEmergenciesCount = 0;
$casesTodayCount = 0;

if ($role === "clinic") {

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM emergency_requests
        WHERE status IN ('new', 'responding')
    ");

    if ($result) {
        $activeEmergenciesCount = (int)$result->fetch_assoc()["total"];
    }

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM emergency_requests
        WHERE DATE(created_at) = CURDATE()
    ");

    if ($result) {
        $casesTodayCount = (int)$result->fetch_assoc()["total"];
    }

    $result = $conn->query("
        SELECT
            er.id,
            er.location,
            er.emergency_type,
            er.description,
            er.status,
            er.created_at,
            u.full_name AS student_name,
            u.university_id
        FROM emergency_requests er
        JOIN student_profiles sp ON sp.id = er.student_id
        JOIN users u ON u.id = sp.user_id
        ORDER BY
            CASE er.status
                WHEN 'new' THEN 1
                WHEN 'responding' THEN 2
                WHEN 'resolved' THEN 3
                ELSE 4
            END,
            er.created_at DESC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $clinicEmergencies[] = $row;
        }
    }

    $result = $conn->query("
        SELECT id, emergency_type, location, description, status, created_at
        FROM public_emergency_reports
        ORDER BY
            CASE status
                WHEN 'new' THEN 1
                WHEN 'responding' THEN 2
                WHEN 'resolved' THEN 3
                ELSE 4
            END,
            created_at DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $publicEmergencies[] = $row;
        }
    }

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM public_emergency_reports
        WHERE status IN ('new', 'responding')
    ");
    if ($result) {
        $activeEmergenciesCount += (int)$result->fetch_assoc()["total"];
    }

    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM public_emergency_reports
        WHERE DATE(created_at) = CURDATE()
    ");
    if ($result) {
        $casesTodayCount += (int)$result->fetch_assoc()["total"];
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Mu'een | Smart Campus Support</title>

    <link rel="stylesheet" href="css/styles.css">

</head>


<body>


<?php if (!$role): ?>


<!-- =========================
     LOGIN PAGE
========================= -->

<main class="login-page">

    <section class="login-hero">
        <div class="login-hero-content">
            <div class="login-logo">M</div>
            <div class="login-brand">MU'EEN</div>

            <h1>Smart Campus Support</h1>

            <p>
                A connected campus support system designed to help students
                access the services and assistance they need.
            </p>

            <div class="login-feature"><span>✓</span> Proactive accessibility support</div>
            <div class="login-feature"><span>✓</span> Campus service coordination</div>
            <div class="login-feature"><span>✓</span> Emergency assistance</div>
        </div>
    </section>

    <section class="login-form-side">
        <div class="login-card">

            <div class="login-mobile-brand">
                MU'EEN
                <span>Smart Campus Support</span>
            </div>

            <p class="login-eyebrow">UNIVERSITY SUPPORT PORTAL</p>
            <h1>Welcome back</h1>
            <p class="login-subtitle">
                Sign in to access your Mu'een dashboard.
            </p>

            <?php if ($error !== ""): ?>
                <div class="alert danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php">
                <input type="hidden" name="login" value="1">

                <label for="email">University Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    placeholder="name@mueen.edu"
                    autocomplete="email"
                    required
                >

                <label for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >

                <button type="submit" class="login-button">
                    Sign In
                </button>
            </form>

            <div class="login-footer">
                <strong>MU'EEN</strong>
                <span>Supporting a more accessible campus experience.</span>
            </div>

        </div>
    </section>

</main>


<?php else: ?>


<!-- =========================
     HEADER
========================= -->

<header>

    <div class="header-brand">

        <?php if ($role === "student"): ?>
            <button
                type="button"
                class="student-menu-button"
                id="studentMenuButton"
                aria-label="Open menu"
                aria-expanded="false"
            >
                <span></span>
                <span></span>
                <span></span>
            </button>
        <?php endif; ?>

        <b>MU'EEN</b>

        <span>
            Smart Campus Support
        </span>

    </div>


    <div class="header-user">

        <?php if ($role === "student"): ?>
            <a
                href="index.php?view=alerts"
                class="student-notification-button"
                aria-label="Notifications"
                title="Notifications"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <?php if ($unreadNotificationCount > 0): ?>
                    <span class="notification-badge"><?= $unreadNotificationCount ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <span class="header-user-name">
            <?= htmlspecialchars($name) ?>
        </span>

        <a href="?logout=1" class="desktop-logout">
            Logout
        </a>

    </div>

</header>



<div class="shell">


    <!-- =========================
         SIDEBAR
    ========================= -->

    <?php if ($role === "student"): ?>
        <div class="student-menu-overlay" id="studentMenuOverlay"></div>
    <?php endif; ?>

    <aside class="<?= $role === "student" ? "student-sidebar" : "" ?>" <?= $role === "student" ? 'id="studentSidebar"' : "" ?>>

        <?php if ($role === "student"): ?>
            <div class="mobile-menu-heading">
                <div>
                    <strong>MU'EEN</strong>
                    <span>Student Portal</span>
                </div>
                <button type="button" class="mobile-menu-close" id="studentMenuClose" aria-label="Close menu">×</button>
            </div>
        <?php endif; ?>

        <div class="logo">
            M
        </div>

        <?php if ($role === "student"): ?>
            <a href="index.php?view=dashboard" class="<?= $studentView === "dashboard" ? "active" : "" ?>">Dashboard</a>
            <a href="index.php?view=schedule" class="<?= $studentView === "schedule" ? "active" : "" ?>">My Schedule</a>
            <a href="index.php?view=alerts" class="<?= $studentView === "alerts" ? "active" : "" ?>">Alerts</a>
            <a href="index.php?view=needs" class="<?= $studentView === "needs" ? "active" : "" ?>">Support Needs</a>
            <a href="index.php?view=requests" class="<?= $studentView === "requests" ? "active" : "" ?>">My Requests</a>
            <a href="?logout=1" class="mobile-menu-logout">Logout</a>

        <?php elseif ($role === "admin"): ?>
            <a class="active">Dashboard</a>
            <a>Active Cases</a>
            <a>Students</a>
            <a>Facility Conflicts</a>
            <a>Resolved Cases</a>

        <?php elseif ($role === "clinic"): ?>
            <a class="active">Dashboard</a>
            <a>Emergency Cases</a>
            <a>Active Responses</a>
            <a>Case History</a>

        <?php elseif ($role === "facilities"): ?>
            <a class="active">Dashboard</a>
            <a>Facilities</a>
            <a>Status Updates</a>
            <a>Maintenance</a>
        <?php endif; ?>

    </aside>

    <main class="dashboard">

        <?php if ($role === "student"): ?>

            <?php
                $latestNotification = !empty($studentNotifications) ? $studentNotifications[0] : null;
                $nextClass = null;

                $dayMap = [
                    "Sunday" => 0,
                    "Monday" => 1,
                    "Tuesday" => 2,
                    "Wednesday" => 3,
                    "Thursday" => 4
                ];

                $todayIndex = (int)date("w");
                $currentMinutes = ((int)date("H") * 60) + (int)date("i");
                $bestDistance = PHP_INT_MAX;

                foreach ($studentSchedules as $schedule) {
                    if (!isset($dayMap[$schedule["class_day"]])) {
                        continue;
                    }

                    $classDayIndex = $dayMap[$schedule["class_day"]];
                    $daysAhead = ($classDayIndex - $todayIndex + 7) % 7;
                    $classMinutes = ((int)date("H", strtotime($schedule["start_time"])) * 60)
                                  + (int)date("i", strtotime($schedule["start_time"]));

                    if ($daysAhead === 0 && $classMinutes < $currentMinutes) {
                        $daysAhead = 7;
                    }

                    $distance = ($daysAhead * 1440) + ($classMinutes - ($daysAhead === 0 ? $currentMinutes : 0));

                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $nextClass = $schedule;
                    }
                }
            ?>

            <?php if ($studentView === "dashboard"): ?>

                <div class="page-heading">
                    <div>
                        <h1>Hello, <?= htmlspecialchars($name) ?> 👋</h1>
                        <p class="muted">Here is what you need right now.</p>
                    </div>
                </div>

                <section class="grid student-overview">

                    <div class="card">
                        <p class="dashboard-label">NEXT CLASS</p>
                        <?php if ($nextClass): ?>
                            <h2><?= htmlspecialchars($nextClass["course_code"]) ?></h2>
                            <p class="next-class-time">
                                <?= htmlspecialchars($nextClass["class_day"]) ?> ·
                                <?= htmlspecialchars(date("h:i A", strtotime($nextClass["start_time"]))) ?>
                            </p>
                            <p class="muted">
                                <?= htmlspecialchars($nextClass["building"]) ?> ·
                                Room <?= htmlspecialchars($nextClass["room_number"]) ?> ·
                                Floor <?= htmlspecialchars($nextClass["floor"]) ?>
                            </p>
                            <a class="text-link" href="index.php?view=schedule">View full schedule →</a>
                        <?php else: ?>
                            <p class="muted">No upcoming classes found.</p>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <div class="card-title-row">
                            <p class="dashboard-label">LATEST ALERT</p>
                            <?php if ($unreadNotificationCount > 0): ?>
                                <span class="notification-count"><?= $unreadNotificationCount ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($latestNotification): ?>
                            <h2><?= htmlspecialchars($latestNotification["title"]) ?></h2>
                            <p><?= htmlspecialchars($latestNotification["message"]) ?></p>
                            <a class="text-link" href="index.php?view=alerts">View all alerts →</a>
                        <?php else: ?>
                            <p class="muted">No alerts right now.</p>
                        <?php endif; ?>
                    </div>

                    <div class="card emergency-card compact-emergency">
                        <p class="dashboard-label emergency-label">EMERGENCY</p>
                        <h2>Need immediate assistance?</h2>
                        <p>Send an SOS request to the University Health Center.</p>
                        <form method="POST" action="create_emergency.php">
                            <button type="submit" class="sos"
                                onclick="return confirm('Send an emergency assistance request to the University Health Center?');">SOS</button>
                        </form>
                        <?php if (isset($_GET["sos_sent"])): ?>
                            <div class="alert success">
                                Emergency request sent successfully. The University Health Center has been notified.
                            </div>
                        <?php endif; ?>
                    </div>
                    


                </section>

            <?php elseif ($studentView === "schedule"): ?>

                <div class="page-heading">
                    <div>
                        <h1>My Schedule</h1>
                        <p class="muted">Your weekly class schedule and campus locations.</p>
                    </div>
                </div>

                <section class="grid">
                    <div class="card wide">
                        <h2>Weekly Schedule</h2>

                        <?php if (empty($studentSchedules)): ?>
                            <p class="muted">No classes found in your schedule.</p>
                        <?php else: ?>
                            <?php
                                $weekDays = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday"];
                                $timeSlots = [];
                                $scheduleMap = [];

                                foreach ($studentSchedules as $schedule) {
                                    $slotKey = $schedule["start_time"] . "|" . $schedule["end_time"];
                                    $timeSlots[$slotKey] = [
                                        "start" => $schedule["start_time"],
                                        "end" => $schedule["end_time"]
                                    ];
                                    $scheduleMap[$slotKey][$schedule["class_day"]][] = $schedule;
                                }

                                uasort($timeSlots, function ($a, $b) {
                                    return strcmp($a["start"], $b["start"]);
                                });
                            ?>

                            <div class="schedule-table-wrap">
                                <table class="weekly-schedule">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <?php foreach ($weekDays as $day): ?>
                                                <th><?= htmlspecialchars($day) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($timeSlots as $slotKey => $slot): ?>
                                            <tr>
                                                <td class="schedule-time">
                                                    <?= htmlspecialchars(date("h:i A", strtotime($slot["start"]))) ?>
                                                    <span>to</span>
                                                    <?= htmlspecialchars(date("h:i A", strtotime($slot["end"]))) ?>
                                                </td>

                                                <?php foreach ($weekDays as $day): ?>
                                                    <td>
                                                        <?php if (!empty($scheduleMap[$slotKey][$day])): ?>
                                                            <?php foreach ($scheduleMap[$slotKey][$day] as $class): ?>
                                                                <div class="class-block">
                                                                    <strong><?= htmlspecialchars($class["course_code"]) ?></strong>
                                                                    <span><?= htmlspecialchars($class["building"]) ?></span>
                                                                    <span>Room <?= htmlspecialchars($class["room_number"]) ?> · Floor <?= htmlspecialchars($class["floor"]) ?></span>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <span class="empty-class">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            <?php elseif ($studentView === "alerts"): ?>

                <div class="page-heading">
                    <div>
                        <h1>Alerts</h1>
                        <p class="muted">Campus support updates and notifications.</p>
                    </div>
                </div>

                <section class="grid">
                    <div class="card wide">
                        <div class="card-title-row">
                            <h2>Alerts &amp; Notifications</h2>
                            <span class="notification-count"><?= $unreadNotificationCount ?> unread</span>
                        </div>

                        <?php if (empty($studentNotifications)): ?>
                            <p class="muted">No notifications yet.</p>
                        <?php else: ?>
                            <?php foreach ($studentNotifications as $notification): ?>
                                <?php
                                    $isSosNotification = str_starts_with(
                                        $notification["title"],
                                        "Emergency "
                                    );
                                ?>
                                <div class="case-item <?= $isSosNotification ? "sos-notification" : "" ?>">
                                    <h3>
                                        <?= htmlspecialchars($notification["title"]) ?>
                                        <?php
                                            $isSosNotification = str_starts_with(
                                                $notification["title"],
                                                "Emergency "
                                            );
                                        ?>
                                        <?php if ($isSosNotification): ?>
                                            <span class="status sos-status">SOS</span>
                                        <?php elseif ((int)$notification["is_read"] === 0): ?>
                                            <span class="status warning">New</span>
                                        <?php endif; ?>
                                    </h3>
                                    <p><?= htmlspecialchars($notification["message"]) ?></p>
                                    <p class="muted"><?= htmlspecialchars($notification["created_at"]) ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            <?php elseif ($studentView === "needs"): ?>

                <div class="page-heading">
                    <div>
                        <h1>Support Needs</h1>
                        <p class="muted">Your approved accessibility support on campus.</p>
                    </div>
                </div>

                <section class="grid">
                    <div class="card wide">
                        <h2>Approved Support Needs</h2>
                        <?php if (empty($studentNeeds)): ?>
                            <p class="muted">No approved support needs found.</p>
                        <?php else: ?>
                            <div class="needs-list">
                                <?php foreach ($studentNeeds as $need): ?>
                                    <div class="need-chip">✓ <?= htmlspecialchars($need["need_type"]) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            <?php elseif ($studentView === "requests"): ?>

                <div class="page-heading">
                    <div>
                        <h1>My Requests</h1>
                        <p class="muted">Your campus support requests will appear here.</p>
                    </div>
                </div>

                <section class="grid">
                    <div class="card wide">
                        <h2>Request Mu'een Support</h2>
                        <p class="muted">If your health condition was not already registered and approved by the university, submit a health report for review.</p>
                        <form method="POST" action="submit_support_request.php" enctype="multipart/form-data" class="support-request-form">
                            <label for="support_category">Support Category</label>
                            <select id="support_category" name="support_category" required>
                                <option value="">Select a category</option>
                                <option value="Health Support">Health Support</option>
                                <option value="Mobility Support">Mobility Support</option>
                                <option value="Other Support">Other Support</option>
                            </select>
                            <label for="health_report">Health Report</label>
                            <input id="health_report" type="file" name="health_report" accept=".pdf,.jpg,.jpeg,.png" required>
                            <small>Accepted: PDF, JPG, PNG · Maximum 5 MB</small>
                            <button type="submit">Submit for Review</button>
                        </form>
                        <?php if (isset($_GET["request_sent"])): ?><div class="alert success">Your report was submitted for university review.</div><?php endif; ?>
                        <?php if (isset($_GET["request_error"])): ?><div class="alert warning"><?= htmlspecialchars($_GET["request_error"]) ?></div><?php endif; ?>
                    </div>
                    <div class="card wide">
                        <h2>Request History</h2>
                        <?php if (empty($studentSupportRequests)): ?>
                            <p class="muted">No submitted support requests yet.</p>
                        <?php else: foreach ($studentSupportRequests as $request): ?>
                            <div class="case-item">
                                <h3><?= htmlspecialchars($request["support_category"]) ?> <span class="status <?= $request["status"] === "approved" ? "success" : ($request["status"] === "pending" ? "warning" : "") ?>"><?= htmlspecialchars(ucfirst($request["status"])) ?></span></h3>
                                <p><a class="text-link" target="_blank" href="<?= htmlspecialchars($request["report_file"]) ?>">View submitted report →</a></p>
                                <?php if (!empty($request["admin_note"])): ?><p><strong>University note:</strong> <?= htmlspecialchars($request["admin_note"]) ?></p><?php endif; ?>
                                <p class="muted">Submitted: <?= htmlspecialchars($request["created_at"]) ?></p>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </section>

            <?php endif; ?>


        <!-- =====================
             ADMIN
        ====================== -->

        <?php elseif ($role === "admin"): ?>


            <div class="page-heading">

                <div>

                    <h1>
                        College Representative Dashboard
                    </h1>

                    <p class="muted">
                        Review detected conflicts and
                        coordinate student support.
                    </p>

                </div>

            </div>


            <?php if (isset($_GET["case_resolved"])): ?>

                <div class="alert success">
                    Support case resolved successfully.
                    The student has been notified.
                </div>

            <?php endif; ?>


            <section class="grid">


                <div class="card">

                    <h3>
                        Active Cases
                    </h3>

                    <h1>
                        <?= $activeCasesCount ?>
                    </h1>

                    <p class="muted">
                        Cases requiring review
                    </p>

                </div>


                <div class="card">

                    <h3>
                        New Cases
                    </h3>

                    <h1>
                        <?= $newCasesCount ?>
                    </h1>

                    <p class="muted">
                        Newly detected conflicts
                    </p>

                </div>


                <div class="card">

                    <h3>
                        Resolved
                    </h3>

                    <h1>
                        <?= $resolvedCasesCount ?>
                    </h1>

                    <p class="muted">
                        Successfully handled
                    </p>

                </div>



                <div class="card wide">
                    <div class="card-title-row"><h2>Support Access Requests</h2><span class="notification-count"><?= $pendingSupportRequestsCount ?> pending</span></div>
                    <?php if (empty($adminSupportRequests)): ?>
                        <p class="muted">No support access requests.</p>
                    <?php else: foreach ($adminSupportRequests as $request): ?>
                        <div class="case-item">
                            <h3><?= htmlspecialchars($request["student_name"]) ?> <span class="status <?= $request["status"] === "approved" ? "success" : "warning" ?>"><?= htmlspecialchars(ucfirst($request["status"])) ?></span></h3>
                            <p><strong>Student ID:</strong> <?= htmlspecialchars($request["university_id"] ?? "") ?></p>
                            <p><strong>Requested category:</strong> <?= htmlspecialchars($request["support_category"]) ?></p>
                            <p><a class="text-link" target="_blank" href="<?= htmlspecialchars($request["report_file"]) ?>">Open health report →</a></p>
                            <?php if ($request["status"] === "pending"): ?>
                                <form method="POST" action="review_support_request.php" class="review-request-form">
                                    <input type="hidden" name="request_id" value="<?= (int)$request["id"] ?>">
                                    <label>Approved Support Need</label>
                                    <select name="approved_need">
                                        <option value="">Select when approving</option>
                                        <option value="Elevator Access">Elevator Access</option>
                                        <option value="Accessible Restroom Access">Accessible Restroom Access</option>
                                        <option value="Mobility Assistance">Mobility Assistance</option>
                                        <option value="Health Support">Health Support</option>
                                    </select>
                                    <label>Note (optional)</label><input type="text" name="admin_note" placeholder="University review note">
                                    <div class="request-actions">
                                        <button type="submit" name="decision" value="approve">Approve</button>
                                        <button type="submit" name="decision" value="reject" class="secondary-action">Reject</button>
                                    </div>
                                </form>
                            <?php elseif (!empty($request["admin_note"])): ?><p><strong>Note:</strong> <?= htmlspecialchars($request["admin_note"]) ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="card wide">

                    <h2>
                        Detected Support Cases
                    </h2>


                    <?php if (empty($adminCases)): ?>

                        <p class="muted">
                            No support cases detected.
                        </p>


                    <?php else: ?>


                        <?php foreach ($adminCases as $case): ?>


                            <div class="case-item">


                                <h3>
                                    <?= htmlspecialchars($case["case_type"]) ?>
                                </h3>


                                <p>

                                    <strong>Student:</strong>

                                    <?= htmlspecialchars($case["student_name"]) ?>

                                    <?php if (!empty($case["university_id"])): ?>

                                        ·
                                        <?= htmlspecialchars($case["university_id"]) ?>

                                    <?php endif; ?>

                                </p>


                                <?php if (!empty($case["course_code"])): ?>

                                    <p>

                                        <strong>Course:</strong>

                                        <?= htmlspecialchars($case["course_code"]) ?>

                                    </p>

                                <?php endif; ?>


                                <?php if (!empty($case["classroom_building"])): ?>

                                    <p>

                                        <strong>Class Location:</strong>

                                        <?= htmlspecialchars($case["classroom_building"]) ?>

                                        · Room

                                        <?= htmlspecialchars($case["room_number"]) ?>

                                        · Floor

                                        <?= htmlspecialchars($case["floor"]) ?>

                                    </p>

                                <?php endif; ?>


                                <?php if (!empty($case["facility_name"])): ?>

                                    <p>

                                        <strong>Affected Facility:</strong>

                                        <?= htmlspecialchars($case["facility_name"]) ?>

                                        ·

                                        <?= htmlspecialchars($case["facility_building"]) ?>

                                    </p>

                                <?php endif; ?>


                                <div class="alert warning">

                                    <?= htmlspecialchars($case["description"]) ?>

                                </div>


                                <?php if (!empty($case["suggested_solution"])): ?>

                                    <p>

                                        <strong>Suggested Action:</strong>

                                        <?= htmlspecialchars($case["suggested_solution"]) ?>

                                    </p>

                                <?php endif; ?>


                                <p>

                                    <strong>Status:</strong>

                                    <?= htmlspecialchars(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $case["status"]
                                            )
                                        )
                                    ) ?>

                                </p>


                                <p class="muted">

                                    Detected:
                                    <?= htmlspecialchars($case["created_at"]) ?>

                                </p>


                                <?php if ($case["status"] !== "resolved"): ?>

                                    <form
                                        method="POST"
                                        action="resolve_case.php"
                                    >

                                        <input
                                            type="hidden"
                                            name="case_id"
                                            value="<?= (int)$case["id"] ?>"
                                        >

                                        <button type="submit">
                                            Resolve Case
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="status success">
                                        Resolved
                                    </span>

                                <?php endif; ?>


                            </div>


                        <?php endforeach; ?>


                    <?php endif; ?>


                </div>


            </section>



        <!-- =====================
             CLINIC
        ====================== -->

        <?php elseif ($role === "clinic"): ?>

            <div class="page-heading">
                <div>
                    <h1>University Health Center</h1>
                    <p class="muted">
                        Monitor and respond to campus emergency requests.
                    </p>
                </div>
            </div>

            <?php if (isset($_GET["emergency_updated"])): ?>
                <div class="alert success">
                    Emergency request updated successfully.
                </div>
            <?php endif; ?>

            <section class="grid">

                <div class="card">
                    <h3>Active Emergencies</h3>
                    <h1><?= $activeEmergenciesCount ?></h1>
                    <p class="muted">New or responding requests</p>
                </div>

                <div class="card">
                    <h3>Cases Today</h3>
                    <h1><?= $casesTodayCount ?></h1>
                    <p class="muted">Emergency requests received today</p>
                </div>

                <div class="card wide">
                    <h2>Emergency Dashboard</h2>

                    <?php if (empty($publicEmergencies) && empty($clinicEmergencies)): ?>

                        <p class="muted">
                            No emergency requests at this time.
                        </p>

                    <?php else: ?>

                        <?php foreach ($publicEmergencies as $emergency): ?>

                            <div class="case-item sos-notification">
                                <h3>
                                    <span class="status sos-status">PUBLIC SOS</span>
                                    <?= htmlspecialchars($emergency["emergency_type"]) ?>
                                </h3>

                                <p><strong>Reported by:</strong> Campus QR / Public Reporter</p>

                                <p>
                                    <strong>Location:</strong>
                                    <?= htmlspecialchars($emergency["location"]) ?>
                                </p>

                                <?php if (!empty($emergency["description"])): ?>
                                    <div class="alert danger">
                                        <?= htmlspecialchars($emergency["description"]) ?>
                                    </div>
                                <?php endif; ?>

                                <p>
                                    <strong>Status:</strong>
                                    <?= htmlspecialchars(ucwords(str_replace("_", " ", $emergency["status"]))) ?>
                                </p>

                                <p class="muted">
                                    Reported: <?= htmlspecialchars($emergency["created_at"]) ?>
                                </p>

                                <?php if ($emergency["status"] === "new"): ?>
                                    <form method="POST" action="update_public_emergency.php">
                                        <input type="hidden" name="emergency_id" value="<?= (int)$emergency["id"] ?>">
                                        <input type="hidden" name="action" value="responding">
                                        <button type="submit">Start Response</button>
                                    </form>
                                <?php elseif ($emergency["status"] === "responding"): ?>
                                    <form method="POST" action="update_public_emergency.php">
                                        <input type="hidden" name="emergency_id" value="<?= (int)$emergency["id"] ?>">
                                        <input type="hidden" name="action" value="resolved">
                                        <button type="submit" class="done">Resolve Emergency</button>
                                    </form>
                                <?php else: ?>
                                    <span class="status success">Resolved</span>
                                <?php endif; ?>
                            </div>

                        <?php endforeach; ?>

                        <?php foreach ($clinicEmergencies as $emergency): ?>

                            <div class="case-item">

                                <h3>
                                    <?= htmlspecialchars($emergency["emergency_type"]) ?>
                                </h3>

                                <p>
                                    <strong>Student:</strong>
                                    <?= htmlspecialchars($emergency["student_name"]) ?>

                                    <?php if (!empty($emergency["university_id"])): ?>
                                        · <?= htmlspecialchars($emergency["university_id"]) ?>
                                    <?php endif; ?>
                                </p>

                                <p>
                                    <strong>Location:</strong>
                                    <?= htmlspecialchars($emergency["location"]) ?>
                                </p>

                                <?php if (!empty($emergency["description"])): ?>
                                    <div class="alert warning">
                                        <?= htmlspecialchars($emergency["description"]) ?>
                                    </div>
                                <?php endif; ?>

                                <p>
                                    <strong>Status:</strong>
                                    <?= htmlspecialchars(
                                        ucwords(str_replace("_", " ", $emergency["status"]))
                                    ) ?>
                                </p>

                                <p class="muted">
                                    Requested:
                                    <?= htmlspecialchars($emergency["created_at"]) ?>
                                </p>

                                <?php if ($emergency["status"] === "new"): ?>

                                    <form method="POST" action="update_emergency.php">
                                        <input type="hidden" name="emergency_id" value="<?= (int)$emergency["id"] ?>">
                                        <input type="hidden" name="action" value="responding">
                                        <button type="submit">Start Response</button>
                                    </form>

                                <?php elseif ($emergency["status"] === "responding"): ?>

                                    <form method="POST" action="update_emergency.php">
                                        <input type="hidden" name="emergency_id" value="<?= (int)$emergency["id"] ?>">
                                        <input type="hidden" name="action" value="resolved">
                                        <button type="submit" class="done">Resolve Emergency</button>
                                    </form>

                                <?php else: ?>

                                    <span class="status success">Resolved</span>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </section>


<?php elseif ($role === "facilities"): ?>


            <div class="page-heading">

                <div>

                    <h1>
                        Facilities Management
                    </h1>

                    <p class="muted">
                        Update campus facilities and
                        accessibility availability.
                    </p>

                </div>

            </div>


            <?php if (isset($_GET["facility_updated"])): ?>

                <div class="alert success">

                    Facility status updated successfully.
                    The system checked for affected students.

                </div>

            <?php endif; ?>


            <section class="grid">


                <div class="card">

                    <h3>
                        Available
                    </h3>

                    <h1>
                        <?= $availableCount ?>
                    </h1>

                    <p class="muted">
                        Currently operational
                    </p>

                </div>


                <div class="card">

                    <h3>
                        Unavailable
                    </h3>

                    <h1>
                        <?= $unavailableCount ?>
                    </h1>

                    <p class="muted">
                        Currently unavailable
                    </p>

                </div>


                <div class="card">

                    <h3>
                        Maintenance
                    </h3>

                    <h1>
                        <?= $maintenanceCount ?>
                    </h1>

                    <p class="muted">
                        Under maintenance
                    </p>

                </div>


                <div class="card wide">

                    <h2>
                        Campus Facilities
                    </h2>


                    <?php if (empty($facilities)): ?>

                        <p class="muted">
                            No facilities have been added yet.
                        </p>


                    <?php else: ?>


                        <?php foreach ($facilities as $facility): ?>


                            <div class="facility-item">


                                <div>

                                    <strong>
                                        <?= htmlspecialchars($facility["name"]) ?>
                                    </strong>

                                    <p class="muted">

                                        <?= htmlspecialchars($facility["building"]) ?>

                                        ·

                                        <?= htmlspecialchars(
                                            ucwords(
                                                str_replace(
                                                    "_",
                                                    " ",
                                                    $facility["facility_type"]
                                                )
                                            )
                                        ) ?>

                                    </p>

                                </div>


                                <form
                                    method="POST"
                                    action="update_facility.php"
                                    class="facility-form"
                                >

                                    <input
                                        type="hidden"
                                        name="facility_id"
                                        value="<?= (int)$facility["id"] ?>"
                                    >


                                    <select name="status">

                                        <option
                                            value="available"
                                            <?= $facility["status"] === "available"
                                                ? "selected"
                                                : "" ?>
                                        >
                                            Available
                                        </option>


                                        <option
                                            value="unavailable"
                                            <?= $facility["status"] === "unavailable"
                                                ? "selected"
                                                : "" ?>
                                        >
                                            Unavailable
                                        </option>


                                        <option
                                            value="maintenance"
                                            <?= $facility["status"] === "maintenance"
                                                ? "selected"
                                                : "" ?>
                                        >
                                            Maintenance
                                        </option>

                                    </select>


                                    <button type="submit">
                                        Update
                                    </button>

                                </form>


                            </div>


                        <?php endforeach; ?>


                    <?php endif; ?>


                </div>


            </section>



        <?php else: ?>


            <div class="card">

                <h2>
                    Invalid Account Role
                </h2>

                <p>
                    Please contact the system administrator.
                </p>

            </div>


        <?php endif; ?>


    </main>


</div>


<?php endif; ?>


<?php if ($role === "student"): ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const menuButton = document.getElementById("studentMenuButton");
    const menuClose = document.getElementById("studentMenuClose");
    const sidebar = document.getElementById("studentSidebar");
    const overlay = document.getElementById("studentMenuOverlay");

    function openStudentMenu() {
        if (!sidebar || !overlay) return;
        sidebar.classList.add("mobile-open");
        overlay.classList.add("show");
        document.body.classList.add("menu-open");
        if (menuButton) menuButton.setAttribute("aria-expanded", "true");
    }

    function closeStudentMenu() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove("mobile-open");
        overlay.classList.remove("show");
        document.body.classList.remove("menu-open");
        if (menuButton) menuButton.setAttribute("aria-expanded", "false");
    }

    if (menuButton) menuButton.addEventListener("click", openStudentMenu);
    if (menuClose) menuClose.addEventListener("click", closeStudentMenu);
    if (overlay) overlay.addEventListener("click", closeStudentMenu);
});
</script>
<?php endif; ?>

<script src="js/app.js"></script>


</body>

</html>