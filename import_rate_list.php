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
$v = (string)$v;
$v = preg_replace('/^\xEF\xBB\xBF/u', '', $v);
$v = str_replace("\xC2\xA0", ' ', $v);
$v = strtolower(trim($v));
$v = preg_replace('/\s+/', ' ', $v);
$v = str_replace(['.', '(', ')'], '', $v);
return $v;
}

function normalize_date_token($v){
$v = (string)$v;
$v = preg_replace('/^\xEF\xBB\xBF/u', '', $v);
$v = str_replace("\xC2\xA0", ' ', $v);
$v = strtolower(trim($v));
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

function resolve_column_key_for_header($header, $columns){
$hNorm = normalize_header_token($header);
$hDate = normalize_date_token($header);
$bestKey = '';
$bestScore = PHP_INT_MAX;

foreach($columns as $c){
$labelNorm = normalize_header_token($c['column_label']);
$labelDate = normalize_date_token($c['column_label']);
$keyNorm = normalize_header_token($c['column_key']);
$keyDate = normalize_date_token($c['column_key']);

$score = null;
if($labelNorm === $hNorm || $labelDate === $hDate){
$score = 0; // prefer label matches
} elseif($keyNorm === $hNorm || $keyDate === $hDate){
$score = 10; // fallback: key match
} else {
continue;
}

if((int)$c['is_hidden'] === 1){
$score += 100; // visible columns first
}
$score += ((int)$c['display_order']) * 2;
$score += (int)$c['id'];

if($score < $bestScore){
$bestScore = $score;
$bestKey = (string)$c['column_key'];
}
}

return $bestKey;
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

$columns = [];
$colRes = $conn->query("SELECT id, column_key, column_label, is_hidden, display_order FROM rate_list_columns WHERE is_deleted=0 ORDER BY is_hidden ASC, display_order ASC, id ASC");
while($colRes && $c = $colRes->fetch_assoc()){
$columns[] = $c;
}

if(count($columns) === 0){
fclose($handle);
header("location:rate_list.php?import=error&reason=no_columns");
exit();
}

$headerDefs = [];
foreach($headers as $idx => $h){
$mappedKey = resolve_column_key_for_header($h, $columns);
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
