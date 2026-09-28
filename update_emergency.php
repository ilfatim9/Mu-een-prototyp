<?php

session_start();
require_once __DIR__ . "/db.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "clinic") {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$emergency_id = (int)($_POST["emergency_id"] ?? 0);
$action = $_POST["action"] ?? "";

if ($emergency_id <= 0) {
    die("Invalid emergency request.");
}

if (!in_array($action, ["responding", "resolved"], true)) {
    die("Invalid emergency action.");
}

$stmt = $conn->prepare("
    SELECT er.id, er.status, er.location, sp.user_id
    FROM emergency_requests er
    JOIN student_profiles sp ON sp.id = er.student_id
    WHERE er.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $emergency_id);
$stmt->execute();
$emergency = $stmt->get_result()->fetch_assoc();

if (!$emergency) {
    die("Emergency request not found.");
}

$student_user_id = (int)$emergency["user_id"];

$stmt = $conn->prepare("
    UPDATE emergency_requests
    SET status = ?
    WHERE id = ?
");
$stmt->bind_param("si", $action, $emergency_id);
$stmt->execute();

if ($action === "responding") {
    $title = "Emergency Response Started";
    $message = "The University Health Center has received your SOS request and started responding. Your reported location is " . $emergency["location"] . ".";
} else {
    $title = "Emergency Request Resolved";
    $message = "The University Health Center has marked your emergency assistance request as resolved.";
}

$stmt = $conn->prepare("
    INSERT INTO notifications (user_id, title, message)
    VALUES (?, ?, ?)
");
$stmt->bind_param("iss", $student_user_id, $title, $message);
$stmt->execute();

header("Location: index.php?emergency_updated=1");
exit;
