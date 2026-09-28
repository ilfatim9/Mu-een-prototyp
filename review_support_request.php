<?php
session_start();
require_once __DIR__ . '/db.php';
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; }
$id=(int)($_POST['request_id'] ?? 0); $decision=$_POST['decision'] ?? ''; $note=trim($_POST['admin_note'] ?? ''); $need=trim($_POST['approved_need'] ?? '');
$stmt=$conn->prepare("SELECT sar.user_id, sar.status, sp.id AS student_id FROM support_access_requests sar JOIN student_profiles sp ON sp.user_id=sar.user_id WHERE sar.id=? LIMIT 1");
$stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc();
if(!$r || $r['status']!=='pending'){ header('Location: index.php'); exit; }
$userId=(int)$r['user_id']; $studentId=(int)$r['student_id'];
if($decision==='approve'){
 if($need===''){ header('Location: index.php'); exit; }
 $conn->begin_transaction();
 try {
  $st=$conn->prepare("UPDATE support_access_requests SET status='approved', admin_note=? WHERE id=?"); $st->bind_param('si',$note,$id); $st->execute();
  $st=$conn->prepare("SELECT id FROM student_needs WHERE student_id=? AND need_type=? LIMIT 1"); $st->bind_param('is',$studentId,$need); $st->execute();
  if(!$st->get_result()->fetch_assoc()){ $st=$conn->prepare("INSERT INTO student_needs(student_id,need_type,status) VALUES (?,?,'approved')"); $st->bind_param('is',$studentId,$need); $st->execute(); }
  $title='Support Request Approved'; $msg='Your Mu\'een support request was approved. Approved support: '.$need.'.';
  $st=$conn->prepare("INSERT INTO notifications(user_id,title,message,is_read) VALUES (?,?,?,0)"); $st->bind_param('iss',$userId,$title,$msg); $st->execute();
  $conn->commit();
 } catch(Throwable $e){ $conn->rollback(); throw $e; }
} elseif($decision==='reject'){
 $st=$conn->prepare("UPDATE support_access_requests SET status='rejected', admin_note=? WHERE id=?"); $st->bind_param('si',$note,$id); $st->execute();
 $title='Support Request Reviewed'; $msg='Your Mu\'een support request was not approved. Please contact the university if you need more information.';
 $st=$conn->prepare("INSERT INTO notifications(user_id,title,message,is_read) VALUES (?,?,?,0)"); $st->bind_param('iss',$userId,$title,$msg); $st->execute();
}
header('Location: index.php');
