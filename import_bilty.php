<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK){
header("location:dashboard.php?import=error");
exit();
}

$tmpName = $_FILES['csv_file']['tmp_name'];
$handle = fopen($tmpName, 'r');

if(!$handle){
header("location:dashboard.php?import=error");
exit();
}

$inserted = 0;
$skipped = 0;
$lineNo = 0;

$stmt = $conn->prepare("INSERT INTO bilty(date, vehicle, bilty_no, location, freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?)");

while(($data = fgetcsv($handle)) !== false){
$lineNo++;
if(empty($data) || count($data) < 6){
$skipped++;
continue;
}

$data = array_map('trim', $data);

if($lineNo === 1){
$firstLine = strtolower(implode(',', $data));
if(strpos($firstLine, 'date') !== false && strpos($firstLine, 'vehicle') !== false){
continue;
}
}

if(count($data) >= 8){
$date = $data[1];
$vehicle = $data[2];
$biltyNo = $data[3];
$location = $data[4];
$freight = $data[5];
$tender = $data[6];
$profit = $data[7];
} else {
$date = $data[0];
$vehicle = $data[1];
$biltyNo = $data[2];
$location = $data[3];
$freight = $data[4];
$tender = $data[5];
$profit = isset($data[6]) ? $data[6] : "";
}

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)){
$skipped++;
continue;
}

if(!is_numeric($freight) || !is_numeric($tender)){
$skipped++;
continue;
}

$freight = (int)$freight;
$tender = (int)$tender;
$profit = is_numeric($profit) ? (int)$profit : ($freight - $tender);

$stmt->bind_param("ssssiii", $date, $vehicle, $biltyNo, $location, $freight, $tender, $profit);
if($stmt->execute()){
$inserted++;
} else {
$skipped++;
}
}

fclose($handle);
$stmt->close();

header("location:dashboard.php?import=success&ins=$inserted&skip=$skipped");
exit();
?>
