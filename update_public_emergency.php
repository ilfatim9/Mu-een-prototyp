<?php
session_start();
require_once __DIR__ . "/db.php";

if (($_SESSION["role"] ?? "") !== "clinic") {
    header("Location: index.php");
    exit;
}
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$emergencyId = (int)($_POST["emergency_id"] ?? 0);
$action = $_POST["action"] ?? "";

if ($emergencyId <= 0 || !in_array($action, ["responding", "resolved"], true)) {
    header("Location: index.php");
    exit;
}

$stmt = $conn->prepare("UPDATE public_emergency_reports SET status = ? WHERE id = ?");
$stmt->bind_param("si", $action, $emergencyId);
$stmt->execute();
$stmt->close();

header("Location: index.php?emergency_updated=1");
exit;
