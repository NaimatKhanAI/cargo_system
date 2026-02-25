<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK){
header("location:feed.php?import=error");
exit();
}

$tmpName = $_FILES['csv_file']['tmp_name'];
$handle = fopen($tmpName, 'r');

if(!$handle){
header("location:feed.php?import=error");
exit();
}

$inserted = 0;
$skipped = 0;
$lineNo = 0;

$stmt = $conn->prepare("INSERT INTO bilty(sr_no, date, vehicle, bilty_no, party, location, freight, original_freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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

if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[0])){
$offset = 0;
} elseif(isset($data[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[1])){
$offset = 1;
} else {
$skipped++;
continue;
}

$remaining = count($data) - $offset;
if($remaining < 6){
$skipped++;
continue;
}

$date = $data[$offset];
$vehicle = isset($data[$offset + 1]) ? $data[$offset + 1] : "";
$biltyNo = isset($data[$offset + 2]) ? $data[$offset + 2] : "";
$srNo = "";

if($offset >= 2){
$srNo = isset($data[$offset - 1]) ? $data[$offset - 1] : "";
} elseif($offset === 1 && isset($data[0]) && !is_numeric($data[0])){
$srNo = $data[0];
}

if($remaining >= 8){
$party = isset($data[$offset + 3]) ? $data[$offset + 3] : "";
$location = isset($data[$offset + 4]) ? $data[$offset + 4] : "";
$freight = isset($data[$offset + 5]) ? $data[$offset + 5] : "";
$tender = isset($data[$offset + 6]) ? $data[$offset + 6] : "";
$profit = isset($data[$offset + 7]) ? $data[$offset + 7] : "";
} else {
$party = "";
$location = isset($data[$offset + 3]) ? $data[$offset + 3] : "";
$freight = isset($data[$offset + 4]) ? $data[$offset + 4] : "";
$tender = isset($data[$offset + 5]) ? $data[$offset + 5] : "";
$profit = isset($data[$offset + 6]) ? $data[$offset + 6] : "";
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
$profit = is_numeric($profit) ? (int)$profit : ($tender - $freight);

$stmt->bind_param("ssssssiiii", $srNo, $date, $vehicle, $biltyNo, $party, $location, $freight, $freight, $tender, $profit);
if($stmt->execute()){
$inserted++;
} else {
$skipped++;
}
}

fclose($handle);
$stmt->close();

header("location:feed.php?import=success&ins=$inserted&skip=$skipped");
exit();
?>

