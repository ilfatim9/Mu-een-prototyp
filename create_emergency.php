<?php

session_start();
require_once __DIR__ . "/db.php";

/* Student only */
if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "student"
) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];


/* Get student profile */
$stmt = $conn->prepare("
    SELECT id
    FROM student_profiles
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    die("Student profile not found.");
}

$student_id = (int)$student["id"];


/* Get student's current class location */
$stmt = $conn->prepare("
    SELECT
        c.building,
        c.room_number
    FROM schedules s

    JOIN classrooms c
        ON c.id = s.classroom_id

    WHERE s.student_id = ?

    ORDER BY s.start_time ASC
    LIMIT 1
");

$stmt->bind_param("i", $student_id);
$stmt->execute();

$schedule = $stmt->get_result()->fetch_assoc();


/* Demo location */
if ($schedule) {

    $location =
        $schedule["building"] .
        " - Room " .
        $schedule["room_number"];

} else {

    $location = "Campus - Location not specified";
}


/* Create emergency request */
$emergency_type = "Emergency Assistance";

$description =
    "Student requested immediate assistance using the Mu'een SOS button.";

$stmt = $conn->prepare("
    INSERT INTO emergency_requests
    (
        student_id,
        location,
        emergency_type,
        description,
        status
    )
    VALUES (?, ?, ?, ?, 'new')
");

$stmt->bind_param(
    "isss",
    $student_id,
    $location,
    $emergency_type,
    $description
);

$stmt->execute();


/* Return to student dashboard */
header("Location: index.php?sos_sent=1");
exit;