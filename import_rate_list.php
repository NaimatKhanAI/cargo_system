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

function match_alias($value, $aliases){
$base = normalize_header_token($value);
foreach($aliases as $alias){
if($base === normalize_header_token($alias)){
return true;
}
}

$dateBase = normalize_date_token($value);
foreach($aliases as $alias){
if($dateBase === normalize_date_token($alias)){
return true;
}
}
return false;
}

function infer_base_key_from_column($columnKey, $columnLabel){
$key = strtolower(trim((string)$columnKey));
$label = trim((string)$columnLabel);

$keyProbe = $key;
if(strpos($keyProbe, 'custom_') === 0){
$keyProbe = substr($keyProbe, 7);
}
$keyProbe = str_replace('_', ' ', $keyProbe);
$candidates = [$key, $keyProbe, $label];

$aliases = [
'sr_no' => ['sr_no', 'sr', 'sr.', 'sr no', 'serial', 'serial no'],
'station_english' => ['station_english', 'station english', 'station (english)', 'english station'],
'station_urdu' => ['station_urdu', 'station urdu', 'station (urdu)', 'urdu station'],
'rate1' => ['rate1', 'rate 1', 'rate_1', '1/1/2026', '01/01/2026', '1-1-2026', '01-01-2026'],
'rate2' => ['rate2', 'rate 2', 'rate_2', '1/2/2026', '01/02/2026', '1-2-2026', '01-02-2026'],
];

foreach($aliases as $baseKey => $list){
foreach($candidates as $candidate){
if(match_alias($candidate, $list)){
return $baseKey;
}
}
}
return null;
}

$columnDefs = [];
$colRes = $conn->query("SELECT column_key, column_label, is_deleted FROM rate_list_columns ORDER BY display_order ASC, id ASC");
if($colRes){
while($c = $colRes->fetch_assoc()){
if((int)$c['is_deleted'] === 1){ continue; }
$columnDefs[] = [
'key' => (string)$c['column_key'],
'label' => (string)$c['column_label'],
'base' => infer_base_key_from_column($c['column_key'], $c['column_label'])
];
}
}

$tmpName = $_FILES['csv_file']['tmp_name'];
$handle = fopen($tmpName, 'r');
if(!$handle){
header("location:rate_list.php?import=error");
exit();
}

$inserted = 0;
$skipped = 0;
$lineNo = 0;
$headerMap = null;
$isHeaderRow = false;

$stmt = $conn->prepare("INSERT INTO image_processed_rates(source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, extra_data) VALUES(?, ?, ?, ?, ?, ?, ?, ?)");

while(($data = fgetcsv($handle)) !== false){
$lineNo++;
if(empty($data)){
$skipped++;
continue;
}

$data = array_map('trim', $data);

if($lineNo === 1){
$headerMap = [];

foreach($data as $idx => $name){
$headerMap[$idx] = [
'raw' => (string)$name,
'norm' => normalize_header_token($name),
'date_norm' => normalize_date_token($name)
];
}

$matched = 0;
foreach($columnDefs as $def){
$targetNorms = [normalize_header_token($def['key']), normalize_header_token($def['label'])];
$targetDates = [normalize_date_token($def['key']), normalize_date_token($def['label'])];
foreach($headerMap as $idx => $h){
if(in_array($h['norm'], $targetNorms, true) || in_array($h['date_norm'], $targetDates, true)){
$matched++;
break;
}
}
}

if($matched > 0){
$isHeaderRow = true;
continue;
}

// Legacy fallback: known base headers in first row.
$lower = array_map(function($v){ return strtolower(trim((string)$v)); }, $data);
if(in_array('sr_no', $lower, true) || in_array('station_english', $lower, true) || in_array('station (english)', $lower, true)){
$isHeaderRow = true;
continue;
}
}

$sourceFile = '';
$sourceImagePath = '';
$base = [
'sr_no' => '',
'station_english' => '',
'station_urdu' => '',
'rate1' => '',
'rate2' => '',
];
$extra = [];

if($isHeaderRow && is_array($headerMap)){
foreach($columnDefs as $def){
$foundIdx = null;
$targetNorms = [normalize_header_token($def['key']), normalize_header_token($def['label'])];
$targetDates = [normalize_date_token($def['key']), normalize_date_token($def['label'])];

foreach($headerMap as $idx => $h){
if(in_array($h['norm'], $targetNorms, true) || in_array($h['date_norm'], $targetDates, true)){
$foundIdx = $idx;
break;
}
}

if($foundIdx === null || !isset($data[$foundIdx])){
continue;
}

$val = $data[$foundIdx];
if($def['base'] !== null && array_key_exists($def['base'], $base)){
$base[$def['base']] = $val;
} else {
$extra[$def['key']] = $val;
}
}

// Direct base-header safety map even if column settings are missing.
foreach($headerMap as $idx => $h){
if(!isset($data[$idx])){ continue; }
$raw = $h['raw'];
$v = $data[$idx];
if(match_alias($raw, ['source_file'])){ $sourceFile = $v; continue; }
if(match_alias($raw, ['source_image_path'])){ $sourceImagePath = $v; continue; }
if(match_alias($raw, ['sr_no', 'sr', 'sr.'])){ if($base['sr_no'] === ''){ $base['sr_no'] = $v; } continue; }
if(match_alias($raw, ['station_english', 'station (english)', 'station english'])){ if($base['station_english'] === ''){ $base['station_english'] = $v; } continue; }
if(match_alias($raw, ['station_urdu', 'station (urdu)', 'station urdu'])){ if($base['station_urdu'] === ''){ $base['station_urdu'] = $v; } continue; }
if(match_alias($raw, ['rate1', 'rate 1', '1/1/2026', '01/01/2026'])){ if($base['rate1'] === ''){ $base['rate1'] = $v; } continue; }
if(match_alias($raw, ['rate2', 'rate 2', '1/2/2026', '01/02/2026'])){ if($base['rate2'] === ''){ $base['rate2'] = $v; } continue; }
}
} else {
// Fallback expected order: SR, Station English, Station Urdu, Rate1, Rate2
$base['sr_no'] = $data[0] ?? '';
$base['station_english'] = $data[1] ?? '';
$base['station_urdu'] = $data[2] ?? '';
$base['rate1'] = $data[3] ?? '';
$base['rate2'] = $data[4] ?? '';
}

if($base['sr_no'] === '' && $base['station_english'] === '' && $base['station_urdu'] === '' && $base['rate1'] === '' && $base['rate2'] === '' && empty($extra)){
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
