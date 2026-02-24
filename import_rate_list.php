<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK){
header("location:rate_list.php?import=error");
exit();
}

function normalize_header_token($v){
$v = strtolower(trim((string)$v));
$v = preg_replace('/\s+/', ' ', $v);
$v = str_replace(['.', '(', ')'], '', $v);
return $v;
}

function normalize_date_token($v){
$v = strtolower(trim((string)$v));
$v = str_replace(['.', '-', ' '], '/', $v);
$v = preg_replace('#/+#', '/', $v);
$parts = explode('/', $v);
if(count($parts) === 3){
$m = ltrim($parts[0], '0');
$d = ltrim($parts[1], '0');
$y = $parts[2];
if($m === ''){ $m = '0'; }
if($d === ''){ $d = '0'; }
return $m . '/' . $d . '/' . $y;
}
return $v;
}

$tmpName = $_FILES['csv_file']['tmp_name'];
$handle = fopen($tmpName, 'r');
if(!$handle){
header("location:rate_list.php?import=error");
exit();
}

$headers = fgetcsv($handle);
if($headers === false || !is_array($headers) || count($headers) === 0){
fclose($handle);
header("location:rate_list.php?import=error");
exit();
}

$headers = array_values(array_map(function($h){
return trim((string)$h);
}, $headers));

$normToKey = [];
$dateToKey = [];
$colRes = $conn->query("SELECT column_key, column_label FROM rate_list_columns WHERE is_deleted=0 ORDER BY display_order ASC, id ASC");
while($colRes && $c = $colRes->fetch_assoc()){
$key = (string)$c['column_key'];
$label = (string)$c['column_label'];

$kNorm = normalize_header_token($key);
$lNorm = normalize_header_token($label);
if($kNorm !== '' && !isset($normToKey[$kNorm])){ $normToKey[$kNorm] = $key; }
if($lNorm !== '' && !isset($normToKey[$lNorm])){ $normToKey[$lNorm] = $key; }

$kDate = normalize_date_token($key);
$lDate = normalize_date_token($label);
if($kDate !== '' && !isset($dateToKey[$kDate])){ $dateToKey[$kDate] = $key; }
if($lDate !== '' && !isset($dateToKey[$lDate])){ $dateToKey[$lDate] = $key; }
}

$headerDefs = [];
foreach($headers as $idx => $h){
$n = normalize_header_token($h);
$d = normalize_date_token($h);
$mappedKey = '';
if(isset($normToKey[$n])){
$mappedKey = $normToKey[$n];
} elseif(isset($dateToKey[$d])){
$mappedKey = $dateToKey[$d];
}
$headerDefs[$idx] = ['column_key' => $mappedKey];
}

$uploadedFileName = isset($_FILES['csv_file']['name']) ? (string)$_FILES['csv_file']['name'] : '';
$inserted = 0;
$skipped = 0;

$stmt = $conn->prepare("INSERT INTO image_processed_rates(source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, extra_data) VALUES(?, ?, ?, ?, ?, ?, ?, ?)");

while(($data = fgetcsv($handle)) !== false){
if(!is_array($data) || count($data) === 0){
$skipped++;
continue;
}

$data = array_values(array_map('trim', $data));
$data = array_slice(array_pad($data, count($headers), ''), 0, count($headers));

$sourceFile = $uploadedFileName;
$sourceImagePath = '';
$base = [
'sr_no' => '',
'station_english' => '',
'station_urdu' => '',
'rate1' => '',
'rate2' => '',
];
$extra = [];

foreach($headerDefs as $idx => $def){
$val = isset($data[$idx]) ? $data[$idx] : '';
$rawHeader = $headers[$idx];

if(normalize_header_token($rawHeader) === 'source_file'){ $sourceFile = $val; continue; }
if(normalize_header_token($rawHeader) === 'source_image_path'){ $sourceImagePath = $val; continue; }

if($def['column_key'] !== '' && array_key_exists($def['column_key'], $base)){
$base[$def['column_key']] = $val;
} elseif($def['column_key'] !== ''){
$extra[$def['column_key']] = $val;
}
}

if($base['sr_no'] === '' && $base['station_english'] === '' && $base['station_urdu'] === '' && $base['rate1'] === '' && $base['rate2'] === '' && empty(array_filter($extra, function($v){ return $v !== ''; }))){
$skipped++;
continue;
}

$extraJson = empty($extra) ? '' : json_encode($extra, JSON_UNESCAPED_UNICODE);
$stmt->bind_param(
"ssssssss",
$sourceFile,
$sourceImagePath,
$base['sr_no'],
$base['station_english'],
$base['station_urdu'],
$base['rate1'],
$base['rate2'],
$extraJson
);
if($stmt->execute()){
$inserted++;
} else {
$skipped++;
}
}

fclose($handle);
$stmt->close();

header("location:rate_list.php?import=success&ins=$inserted&skip=$skipped");
exit();
?>
