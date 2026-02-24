<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

require_once __DIR__ . '/config/env.php';
load_env_file(__DIR__ . '/.env');
include 'config/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');

$apiKey = env_get('OPENAI_API_KEY');
if(!$apiKey){
http_response_code(500);
echo "OPENAI_API_KEY is not configured. Add it in project .env file.";
exit();
}

if(!isset($_FILES['image'])){
header("location:process_img.php");
exit();
}

while (ob_get_level() > 0) {
ob_end_flush();
}
ob_implicit_flush(true);

$files = $_FILES['image'];
$count = is_array($files['name']) ? count($files['name']) : 1;

echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
echo "<title>Processing</title>";
echo "<style>
body{font-family:'Trebuchet MS',Arial,sans-serif;background:#f6f7fb;margin:0;padding:32px;}
.card{max-width:760px;margin:20px auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;
box-shadow:0 12px 40px rgba(17,24,39,0.08);padding:24px;}
h2{margin:0 0 10px 0;}
.item{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #f1f5f9;padding:10px 0;}
.item:last-child{border-bottom:none;}
.name{font-weight:600;}
.status{color:#2563eb;}
.muted{color:#6b7280;font-size:13px;}
.bar{height:8px;background:#eef2ff;border-radius:999px;overflow:hidden;margin:12px 0;}
.bar > span{display:block;height:100%;background:#2563eb;width:0%;}
.logs{background:#0b1020;color:#c7d2fe;border-radius:12px;padding:12px;font-family:Consolas,monospace;
font-size:12px;max-height:220px;overflow:auto;margin-top:12px;}
a.button{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;
padding:12px 18px;border-radius:10px;margin-right:8px;}
a.secondary{display:inline-block;border:1px solid #e5e7eb;color:#111827;text-decoration:none;
padding:12px 18px;border-radius:10px;background:#fafafa;}
</style></head><body>";
echo "<div class='card'>";
echo "<h2>Processing Images</h2>";
echo "<div class='muted'>Please wait while we extract table rows.</div>";
echo "<div class='bar'><span id='bar'></span></div>";
echo "<div id='list'>";

for ($i = 0; $i < $count; $i++) {
$name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
$safeName = htmlspecialchars($name, ENT_QUOTES);
echo "<div class='item' id='item_$i'><div class='name'>$safeName</div><div class='status' id='status_$i'>Queued</div></div>";
}
echo "</div>";
echo "<div id='final'></div>";
echo "<div class='logs' id='logs'></div>";
echo "</div>";
echo "<script>
function setStatus(i, text){var el=document.getElementById('status_'+i); if(el){el.textContent=text;}}
function setBar(p){var b=document.getElementById('bar'); if(b){b.style.width=p+'%';}}
function logLine(t){var l=document.getElementById('logs'); if(!l) return; var d=document.createElement('div'); d.textContent=t; l.appendChild(d); l.scrollTop=l.scrollHeight;}
</script>";
flush();

$outDir = __DIR__ . "/output";
if(!is_dir($outDir)){
mkdir($outDir, 0777, true);
}
$imgDir = $outDir . "/source_images";
if(!is_dir($imgDir)){
mkdir($imgDir, 0777, true);
}

$fileName = "image_extract_" . date("Ymd_His") . ".csv";
$file = "output/" . $fileName;
$fullPath = $outDir . "/" . $fileName;
$fp = fopen($fullPath,'w');
fwrite($fp, "\xEF\xBB\xBF");

$rowsWritten = 0;
$dbRowsSaved = 0;
$errors = [];

function js($s){
echo "<script>".$s."</script>";
flush();
}

function normalize_header($v){
$v = strtolower(trim((string)$v));
$v = preg_replace('/\s+/', ' ', $v);
$v = str_replace(['.', '(', ')'], '', $v);
return $v;
}

function normalize_dateish($v){
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

function find_header_index($headers, $aliases){
$normalized = [];
foreach($headers as $idx => $h){
$base = normalize_header($h);
$normalized[$idx] = $base;
}

foreach($aliases as $alias){
$aliasBase = normalize_header($alias);
foreach($normalized as $idx => $val){
if($val === $aliasBase){
return $idx;
}
}
}

// date-like alias match (handles 01/01/2026 vs 1/1/2026 etc.)
foreach($aliases as $alias){
$aliasDate = normalize_dateish($alias);
foreach($headers as $idx => $h){
if(normalize_dateish($h) === $aliasDate){
return $idx;
}
}
}

return null;
}

for ($i = 0; $i < $count; $i++) {
$tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
$err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
$name = is_array($files['name']) ? $files['name'][$i] : $files['name'];

js("setStatus($i,'Uploading'); logLine('Image ".($i+1).": upload started');");

if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
$errors[] = "Upload failed for image ".($i+1);
js("setStatus($i,'Upload failed'); logLine('Image ".($i+1).": upload failed');");
continue;
}

$mime = @mime_content_type($tmp);
if($mime === false || strpos($mime, 'image/') !== 0){
$errors[] = "Invalid image type for image ".($i+1);
js("setStatus($i,'Invalid file'); logLine('Image ".($i+1).": invalid mime type');");
continue;
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if($ext === '' || !preg_match('/^[a-z0-9]+$/', $ext)){
$ext = 'jpg';
}
$safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($name, PATHINFO_FILENAME));
if($safeBase === ''){
$safeBase = 'image';
}
$savedName = $safeBase . '_' . date('Ymd_His') . '_' . $i . '.' . $ext;
$savedPath = $imgDir . '/' . $savedName;
$sourceImageRelPath = 'output/source_images/' . $savedName;
if(!move_uploaded_file($tmp, $savedPath)){
$errors[] = "Could not save source image for image ".($i+1);
js("setStatus($i,'Save failed'); logLine('Image ".($i+1).": source image save failed');");
continue;
}

js("setStatus($i,'Extracting'); logLine('Image ".($i+1).": sending to OpenAI');");

$img = base64_encode(file_get_contents($savedPath));
$data = [
"model"=>"gpt-4o-mini",
"messages"=>[
[
"role"=>"user",
"content"=>[
["type"=>"text","text"=>"Extract the table exactly from this image and return strict JSON only (no markdown). Format: {\"headers\":[\"exact heading text\"],\"rows\":[[\"cell1\",\"cell2\"]]}. Keep exact header names and same column order as image."],
["type"=>"image_url","image_url"=>["url"=>"data:image/jpeg;base64,$img"]]
]
]
],
"max_tokens"=>3000
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,"https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch, CURLOPT_POST,true);
curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dl_total, $dl_now, $ul_total, $ul_now) use ($i) {
if ($ul_total > 0) {
$p = (int)(($ul_now / $ul_total) * 30);
echo "<script>setStatus($i,'Uploading'); setBar($p);</script>";
flush();
}
if ($dl_total > 0) {
$p = 30 + (int)(($dl_now / $dl_total) * 60);
if ($p > 90) { $p = 90; }
echo "<script>setStatus($i,'Receiving'); setBar($p);</script>";
flush();
}
return 0;
});
curl_setopt($ch, CURLOPT_HTTPHEADER,[
"Content-Type: application/json",
"Authorization: Bearer ".$apiKey
]);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
$errors[] = "Request failed for image ".($i+1);
js("setStatus($i,'Request failed'); logLine('Image ".($i+1).": cURL error: ".addslashes($curlErr)."');");
continue;
}

js("logLine('Image ".($i+1).": HTTP ".$httpCode.", bytes ".strlen($response)."');");

$res = json_decode($response,true);
$text = isset($res["choices"][0]["message"]["content"]) ? $res["choices"][0]["message"]["content"] : "";
$clean = preg_replace('/^```(json)?|```$/m', '', trim($text));
$json = json_decode($clean,true);

if(!$json || !is_array($json)){
$errors[] = "Parse failed for image ".($i+1);
$snippet = addslashes(substr(trim($text), 0, 120));
js("setStatus($i,'Parse failed'); logLine('Image ".($i+1).": parse failed. Snippet: ".$snippet."');");
continue;
}

js("setStatus($i,'Saving');");

$headers = [];
$rows = [];

if(isset($json['headers']) && isset($json['rows']) && is_array($json['headers']) && is_array($json['rows'])){
$headers = array_values($json['headers']);
$rows = $json['rows'];
} elseif(isset($json[0]) && is_array($json[0])) {
// Fallback: first row as header, remaining as data
$headers = array_values($json[0]);
$rows = array_slice($json, 1);
}

$headers = array_values(array_map(function($h){
return trim((string)$h);
}, $headers));

if(count($headers) === 0){
$errors[] = "No headers found for image ".($i+1);
js("setStatus($i,'No headers'); logLine('Image ".($i+1).": headers not found');");
continue;
}

if($i > 0){
// Separator between multiple image tables
fputcsv($fp, []);
}

fputcsv($fp, $headers);

foreach($rows as $row){
if(!is_array($row)){
continue;
}
$normalized = array_values($row);
$normalized = array_map(function($v){
return is_scalar($v) ? (string)$v : "";
}, $normalized);
$normalized = array_slice(array_pad($normalized, count($headers), ""), 0, count($headers));
fputcsv($fp, $normalized);
$rowsWritten++;

// Save required columns in DB if exact columns are available in extracted headers.
$idxSr = find_header_index($headers, ['sr', 'sr.', 'sr no', 'serial', 'serial no']);
$idxEng = find_header_index($headers, ['station english', 'station (english)', 'english station']);
$idxUrdu = find_header_index($headers, ['station urdu', 'station (urdu)', 'urdu station']);
$idxR1 = find_header_index($headers, ['1/1/2026', '01/01/2026', '1-1-2026', '01-01-2026']);
$idxR2 = find_header_index($headers, ['1/2/2026', '01/02/2026', '1-2-2026', '01-02-2026']);

if($idxSr !== null || $idxEng !== null || $idxUrdu !== null || $idxR1 !== null || $idxR2 !== null){
$srVal = $idxSr !== null && isset($normalized[$idxSr]) ? $normalized[$idxSr] : "";
$engVal = $idxEng !== null && isset($normalized[$idxEng]) ? $normalized[$idxEng] : "";
$urduVal = $idxUrdu !== null && isset($normalized[$idxUrdu]) ? $normalized[$idxUrdu] : "";
$r1Val = $idxR1 !== null && isset($normalized[$idxR1]) ? $normalized[$idxR1] : "";
$r2Val = $idxR2 !== null && isset($normalized[$idxR2]) ? $normalized[$idxR2] : "";

$insStmt = $conn->prepare("INSERT INTO image_processed_rates(source_file, source_image_path, sr_no, station_english, station_urdu, rate_2026_01_01, rate_2026_01_02) VALUES(?, ?, ?, ?, ?, ?, ?)");
$insStmt->bind_param("sssssss", $name, $sourceImageRelPath, $srVal, $engVal, $urduVal, $r1Val, $r2Val);
if($insStmt->execute()){
$dbRowsSaved++;
}
$insStmt->close();
}
}

js("setStatus($i,'Done');");
$progress = intval((($i+1)/$count) * 100);
js("setBar($progress);");
}

fclose($fp);

echo "<script>setBar(100);</script>";
echo "<script>
var final=document.getElementById('final');
final.innerHTML = '<div style=\"margin-top:16px;\">'
+ '<div class=\"muted\">Rows written: ".$rowsWritten."</div>'
+ '<div class=\"muted\">Rows saved in DB: ".$dbRowsSaved."</div>'
+ '<div style=\"margin-top:10px;\">'
+ '<a class=\"button\" href=\"".$file."\" download>Download Excel CSV</a>'
+ '<a class=\"secondary\" href=\"rate_list.php\">Rate List</a>'
+ '<a class=\"secondary\" href=\"process_img.php\">Convert Another</a>'
+ '</div></div>';
</script>";

if (count($errors) > 0) {
$errText = htmlspecialchars(implode(' | ', $errors), ENT_QUOTES);
echo "<script>
var final=document.getElementById('final');
final.innerHTML += '<div class=\"muted\" style=\"margin-top:8px;color:#b91c1c;\">Errors: ".$errText."</div>';
</script>";
}

echo "</body></html>";
?>
