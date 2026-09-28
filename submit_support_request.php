<?php
session_start();
require_once __DIR__ . '/db.php';
if (($_SESSION['role'] ?? '') !== 'student') { header('Location: index.php'); exit; }
$userId=(int)$_SESSION['user_id'];
$category=trim($_POST['support_category'] ?? '');
$allowed=['Health Support','Mobility Support','Other Support'];
if (!in_array($category,$allowed,true) || !isset($_FILES['health_report']) || $_FILES['health_report']['error'] !== UPLOAD_ERR_OK) { header('Location: index.php?view=requests&request_error='.urlencode('Please select a category and valid report.')); exit; }
$f=$_FILES['health_report'];
if ($f['size'] > 5*1024*1024) { header('Location: index.php?view=requests&request_error='.urlencode('Report must be 5 MB or smaller.')); exit; }
$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
if (!in_array($ext,['pdf','jpg','jpeg','png'],true)) { header('Location: index.php?view=requests&request_error='.urlencode('Only PDF, JPG and PNG files are allowed.')); exit; }
$dir=__DIR__.'/uploads/health_reports'; if(!is_dir($dir)) mkdir($dir,0755,true);
$name='report_'.$userId.'_'.date('YmdHis').'_'.bin2hex(random_bytes(4)).'.'.$ext;
if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name)){ header('Location: index.php?view=requests&request_error='.urlencode('Could not save the report.')); exit; }
$path='uploads/health_reports/'.$name;
$stmt=$conn->prepare("INSERT INTO support_access_requests (user_id,support_category,report_file,status) VALUES (?,?,?,'pending')");
$stmt->bind_param('iss',$userId,$category,$path); $stmt->execute();
header('Location: index.php?view=requests&request_sent=1');
