<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK){
header("location:haleeb.php?import=error");
exit();
}

$tmpName = $_FILES['csv_file']['tmp_name'];
$handle = fopen($tmpName, 'r');
if(!$handle){
header("location:haleeb.php?import=error");
exit();
}

$inserted = 0;
$skipped = 0;
$lineNo = 0;

$stmt = $conn->prepare("INSERT INTO haleeb_bilty(date, vehicle, vehicle_type, delivery_note, token_no, party, location, freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

while(($data = fgetcsv($handle)) !== false){
$lineNo++;
if(empty($data) || count($data) < 8){
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

$offset = 0;
if(isset($data[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[1])){
$offset = 1; // id at first column
}

$date = isset($data[$offset]) ? $data[$offset] : '';
$vehicle = isset($data[$offset + 1]) ? $data[$offset + 1] : '';
$vehicleType = isset($data[$offset + 2]) ? $data[$offset + 2] : '';
$deliveryNote = isset($data[$offset + 3]) ? $data[$offset + 3] : '';
$tokenNo = isset($data[$offset + 4]) ? $data[$offset + 4] : '';
$party = isset($data[$offset + 5]) ? $data[$offset + 5] : '';
$location = isset($data[$offset + 6]) ? $data[$offset + 6] : '';
$freight = isset($data[$offset + 7]) ? $data[$offset + 7] : '';
$tender = isset($data[$offset + 8]) ? $data[$offset + 8] : '';
$profit = isset($data[$offset + 9]) ? $data[$offset + 9] : '';

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
$profit = is_numeric($profit) ? (int)$profit : ($tender - $freight);

$stmt->bind_param("sssssssiii", $date, $vehicle, $vehicleType, $deliveryNote, $tokenNo, $party, $location, $freight, $tender, $profit);
if($stmt->execute()){
$inserted++;
} else {
$skipped++;
}
}

fclose($handle);
$stmt->close();

header("location:haleeb.php?import=success&ins=$inserted&skip=$skipped");
exit();
?>
