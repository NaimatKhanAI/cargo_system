<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

$columns = [];
$colRes = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC");
while($colRes && $c = $colRes->fetch_assoc()){
if((int)$c['is_deleted'] === 1){ continue; }
$columns[] = [
'key' => (string)$c['column_key'],
'label' => (string)$c['column_label']
];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=rate_list_' . date('Y-m-d_H-i-s') . '.csv');

$output = fopen('php://output', 'w');
$headerRow = [];
foreach($columns as $c){
$headerRow[] = $c['label'];
}
fputcsv($output, $headerRow);

$result = $conn->query("SELECT sr_no, station_english, station_urdu, rate1, rate2, extra_data FROM image_processed_rates ORDER BY id DESC");
while($row = $result->fetch_assoc()){
$extra = [];
if(isset($row['extra_data']) && $row['extra_data'] !== ''){
$decoded = json_decode($row['extra_data'], true);
if(is_array($decoded)){ $extra = $decoded; }
}

$outRow = [];
foreach($columns as $c){
$val = '';
$key = $c['key'];
if(isset($row[$key])){
$val = (string)$row[$key];
} elseif(isset($extra[$key])){
$val = (string)$extra[$key];
}
$outRow[] = $val;
}
fputcsv($output, $outRow);
}

fclose($output);
exit();
?>
