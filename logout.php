<?php
session_start();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$uname = isset($_SESSION['user']) ? (string)$_SESSION['user'] : '';
if($uid > 0){
include 'config/db.php';
require_once 'config/activity_notifications.php';
activity_notify_local(
    $conn,
    'auth',
    'logout',
    'user',
    $uid,
    'User logged out.',
    ['username' => $uname],
    $uid
);
}
session_destroy();
header("location:index.php");
?>
