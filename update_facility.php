<?php

session_start();
require_once __DIR__ . "/db.php";


/* =========================
   SECURITY CHECK
========================= */

if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "facilities"
) {
    header("Location: index.php");
    exit;
}


if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}


/* =========================
   GET UPDATE DATA
========================= */

$facility_id = (int)($_POST["facility_id"] ?? 0);
$new_status = $_POST["status"] ?? "";


$allowed_statuses = [
    "available",
    "unavailable",
    "maintenance"
];


if (
    $facility_id <= 0 ||
    !in_array($new_status, $allowed_statuses, true)
) {
    die("Invalid facility update.");
}


/* =========================
   GET FACILITY
========================= */

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        building,
        facility_type
    FROM facilities
    WHERE id = ?
");

$stmt->bind_param("i", $facility_id);
$stmt->execute();

$facility = $stmt->get_result()->fetch_assoc();


if (!$facility) {
    die("Facility not found.");
}


/* =========================
   UPDATE FACILITY STATUS
========================= */

$stmt = $conn->prepare("
    UPDATE facilities
    SET status = ?
    WHERE id = ?
");

$stmt->bind_param(
    "si",
    $new_status,
    $facility_id
);

$stmt->execute();


/* =========================
   DETERMINE REQUIRED NEED
========================= */

$required_need = null;


if ($facility["facility_type"] === "elevator") {

    $required_need = "Elevator Access";

}


if ($facility["facility_type"] === "restroom") {

    $required_need = "Accessible Restroom Access";

}


/* =========================
   CONFLICT DETECTION
========================= */

if (
    $required_need !== null &&
    $new_status !== "available"
) {


    /*
     * Find students who:
     *
     * 1. Have the approved support need
     * 2. Have a class in the same building
     */


    $stmt = $conn->prepare("
        SELECT DISTINCT

            sp.id AS student_id,
            sp.user_id,

            s.id AS schedule_id,
            s.course_code,

            c.building,
            c.room_number,
            c.floor

        FROM student_profiles sp

        JOIN student_needs sn
            ON sn.student_id = sp.id

        JOIN schedules s
            ON s.student_id = sp.id

        JOIN classrooms c
            ON c.id = s.classroom_id

        WHERE sn.need_type = ?

        AND sn.status = 'approved'

        AND c.building = ?
    ");


    $stmt->bind_param(
        "ss",
        $required_need,
        $facility["building"]
    );


    $stmt->execute();

    $affected_students = $stmt->get_result();



    /* =========================
       PROCESS AFFECTED STUDENTS
    ========================= */

    while (
        $student = $affected_students->fetch_assoc()
    ) {


        /*
         * For elevators:
         * only create a conflict if
         * the classroom is above floor 1.
         */

        if (
            $facility["facility_type"] === "elevator" &&
            (int)$student["floor"] <= 1
        ) {
            continue;
        }



        /* =========================
           CHECK EXISTING CASE
        ========================= */

        $check = $conn->prepare("
            SELECT id

            FROM support_cases

            WHERE student_id = ?

            AND facility_id = ?

            AND schedule_id = ?

            AND status != 'resolved'

            LIMIT 1
        ");


        $check->bind_param(
            "iii",
            $student["student_id"],
            $facility_id,
            $student["schedule_id"]
        );


        $check->execute();

        $existing_case =
            $check->get_result()->fetch_assoc();



        /* =========================
           CREATE NEW CASE
        ========================= */

        if (!$existing_case) {


            /* Elevator conflict */

            if (
                $facility["facility_type"] === "elevator"
            ) {

                $description =
                    $facility["name"]
                    . " in "
                    . $facility["building"]
                    . " is currently "
                    . $new_status
                    . ". The student has an approved elevator access need and "
                    . $student["course_code"]
                    . " is located on floor "
                    . $student["floor"]
                    . ".";


                $suggested_solution =
                    "Review an accessible classroom alternative or another accessible route.";


                $case_type =
                    "Elevator Accessibility Conflict";
            }



            /* Restroom conflict */

            elseif (
                $facility["facility_type"] === "restroom"
            ) {

                $description =
                    $facility["name"]
                    . " in "
                    . $facility["building"]
                    . " is currently "
                    . $new_status
                    . ". The student has an approved accessible restroom need and has "
                    . $student["course_code"]
                    . " in this building.";


                $suggested_solution =
                    "Identify the nearest available accessible restroom and inform the student.";


                $case_type =
                    "Accessible Restroom Conflict";
            }



            /* =========================
               INSERT SUPPORT CASE
            ========================= */

            $case = $conn->prepare("
                INSERT INTO support_cases
                (
                    student_id,
                    facility_id,
                    schedule_id,
                    case_type,
                    description,
                    suggested_solution
                )

                VALUES (?, ?, ?, ?, ?, ?)
            ");


            $case->bind_param(
                "iiisss",

                $student["student_id"],
                $facility_id,
                $student["schedule_id"],
                $case_type,
                $description,
                $suggested_solution
            );


            $case->execute();



            /* =========================
               STUDENT NOTIFICATION
            ========================= */

            $notification =
                $conn->prepare("
                    INSERT INTO notifications
                    (
                        user_id,
                        title,
                        message
                    )

                    VALUES (?, ?, ?)
                ");


            $title =
                "Campus Accessibility Update";


            if (
                $facility["facility_type"] === "elevator"
            ) {

                $message =
                    $facility["name"]
                    . " in "
                    . $facility["building"]
                    . " is currently "
                    . $new_status
                    . ". This may affect access to your "
                    . $student["course_code"]
                    . " class. The university representative has been notified.";

            } else {

                $message =
                    "An accessible restroom in "
                    . $facility["building"]
                    . " is currently "
                    . $new_status
                    . ". The university representative has been notified.";

            }


            $notification->bind_param(
                "iss",
                $student["user_id"],
                $title,
                $message
            );


            $notification->execute();

        }

    }

}


/* =========================
   RETURN TO DASHBOARD
========================= */

header(
    "Location: index.php?facility_updated=1"
);

exit;