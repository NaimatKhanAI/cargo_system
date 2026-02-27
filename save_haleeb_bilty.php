<?php
session_start();
include 'config/db.php';
require_once 'config/auth.php';
auth_require_login($conn);
auth_require_module_access('haleeb');

$d = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
$v = isset($_POST['vehicle']) ? trim($_POST['vehicle']) : '';
$vt = isset($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : '';
$dn = isset($_POST['delivery_note']) ? trim($_POST['delivery_note']) : '';
$tn = isset($_POST['token_no']) ? trim($_POST['token_no']) : '';
$party = isset($_POST['party']) ? trim($_POST['party']) : '';
$l = isset($_POST['location']) ? trim($_POST['location']) : '';
$sameCityCount = isset($_POST['same_city_count']) ? (int)$_POST['same_city_count'] : 0;
$outCityCount = isset($_POST['out_city_count']) ? (int)$_POST['out_city_count'] : 0;
$stops = 'SC:' . max(0, $sameCityCount) . '|OC:' . max(0, $outCityCount);
$t = isset($_POST['tender']) ? (int)$_POST['tender'] : 0;
$f = isset($_POST['freight']) ? (int)$_POST['freight'] : 0;

$p = $t - $f;

$stmt = $conn->prepare("INSERT INTO haleeb_bilty(date, vehicle, vehicle_type, delivery_note, token_no, party, location, stops, freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssssiii", $d, $v, $vt, $dn, $tn, $party, $l, $stops, $f, $t, $p);
$stmt->execute();
$stmt->close();

header("location:haleeb.php");
?>
