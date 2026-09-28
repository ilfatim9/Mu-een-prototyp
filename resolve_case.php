<?php

session_start();
require_once __DIR__ . "/db.php";

/* =========================
   ADMIN ONLY
========================= */

if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

$case_id = (int)($_POST["case_id"] ?? 0);

if ($case_id <= 0) {
    die("Invalid case.");
}


/* =========================
   GET CASE INFORMATION
========================= */

$stmt = $conn->prepare("
    SELECT
        sc.id,
        sc.student_id,
        sc.schedule_id,
        sc.facility_id,
        sc.case_type,
        sc.status,

        sp.user_id,

        f.name AS facility_name,
        f.building AS facility_building,
        f.facility_type,

        s.course_code,
        s.classroom_id,

        c.building AS classroom_building,
        c.room_number,
        c.floor

    FROM support_cases sc

    JOIN student_profiles sp
        ON sp.id = sc.student_id

    LEFT JOIN facilities f
        ON f.id = sc.facility_id

    LEFT JOIN schedules s
        ON s.id = sc.schedule_id

    LEFT JOIN classrooms c
        ON c.id = s.classroom_id

    WHERE sc.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $case_id);
$stmt->execute();

$case = $stmt->get_result()->fetch_assoc();

if (!$case) {
    die("Case not found.");
}


/* Already resolved */
if ($case["status"] === "resolved") {
    header("Location: index.php?case_resolved=1");
    exit;
}


$student_user_id = (int)$case["user_id"];

$title = "Support Case Resolved";
$message = "";


/* =========================
   ELEVATOR CONFLICT
========================= */

if ($case["facility_type"] === "elevator") {

    /*
        For our prototype:
        Find Room 105 in the same building.
        It must be accessible and on Floor 1.
    */

    $building = $case["classroom_building"];

    $stmt = $conn->prepare("
        SELECT
            id,
            building,
            room_number,
            floor
        FROM classrooms
        WHERE building = ?
          AND room_number = '105'
          AND floor = 1
          AND is_accessible = 1
        LIMIT 1
    ");

    $stmt->bind_param("s", $building);
    $stmt->execute();

    $alternative_room =
        $stmt->get_result()->fetch_assoc();


    if ($alternative_room && !empty($case["schedule_id"])) {

        $schedule_id =
            (int)$case["schedule_id"];

        $new_classroom_id =
            (int)$alternative_room["id"];


        /* Change student's classroom */

        $stmt = $conn->prepare("
            UPDATE schedules
            SET classroom_id = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ii",
            $new_classroom_id,
            $schedule_id
        );

        $stmt->execute();


        /* Student notification */

        $title = "Classroom Changed";

        $message =
            "Your " .
            $case["course_code"] .
            " classroom has been changed to " .
            $alternative_room["building"] .
            " - Room " .
            $alternative_room["room_number"] .
            " - Floor " .
            $alternative_room["floor"] .
            " due to an elevator accessibility issue.";

    } else {

        /*
            Fallback if no suitable room exists.
        */

        $title = "Accessibility Case Reviewed";

        $message =
            "Your university representative reviewed the elevator accessibility issue. Please check with your college representative for the arranged support.";
    }
}


/* =========================
   RESTROOM CONFLICT
========================= */

elseif ($case["facility_type"] === "restroom") {

    $building = $case["facility_building"];

    /*
        Find another available accessible restroom
        in the same building.
    */

    $stmt = $conn->prepare("
        SELECT
            id,
            name,
            building
        FROM facilities
        WHERE facility_type = 'restroom'
          AND status = 'available'
          AND building = ?
          AND id != ?
        LIMIT 1
    ");

    $facility_id =
        (int)$case["facility_id"];

    $stmt->bind_param(
        "si",
        $building,
        $facility_id
    );

    $stmt->execute();

    $alternative_facility =
        $stmt->get_result()->fetch_assoc();


    if ($alternative_facility) {

        $title =
            "Accessible Restroom Update";

        $message =
            $case["facility_name"] .
            " is currently unavailable. " .
            "Please use " .
            $alternative_facility["name"] .
            " in " .
            $alternative_facility["building"] .
            ".";

    } else {

        $title =
            "Accessible Restroom Update";

        $message =
            $case["facility_name"] .
            " is currently unavailable. " .
            "Your university representative has reviewed the case. Please contact campus support for the nearest available accessible restroom.";
    }
}


/* =========================
   OTHER CASE TYPES
========================= */

else {

    $message =
        "Your university representative has reviewed and resolved a campus support case affecting you.";
}


/* =========================
   RESOLVE CASE
========================= */

$stmt = $conn->prepare("
    UPDATE support_cases
    SET status = 'resolved'
    WHERE id = ?
");

$stmt->bind_param("i", $case_id);
$stmt->execute();


/* =========================
   SEND NOTIFICATION
========================= */

$stmt = $conn->prepare("
    INSERT INTO notifications
    (
        user_id,
        title,
        message
    )
    VALUES (?, ?, ?)
");

$stmt->bind_param(
    "iss",
    $student_user_id,
    $title,
    $message
);

$stmt->execute();


/* =========================
   RETURN TO ADMIN
========================= */

header("Location: index.php?case_resolved=1");
exit;