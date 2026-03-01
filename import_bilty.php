<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/feed_portions.php';
auth_require_login($conn);
auth_require_module_access('feed');
auth_require_super_admin('dashboard.php');

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
$headerMap = null;
$importReport = [];

function normalize_header_name($v){
    $v = strtolower(trim((string)$v));
    $v = preg_replace('/\s+/', '_', $v);
    return $v;
}

function parse_csv_date_to_mysql($value){
    $value = trim((string)$value);
    if($value === '') return '';
    $formats = ['Y-m-d', 'n/j/Y', 'm/d/Y', 'j/n/Y', 'd/m/Y', 'n-j-Y', 'm-d-Y', 'd-m-Y'];
    foreach($formats as $fmt){
        $dt = DateTime::createFromFormat($fmt, $value);
        if($dt instanceof DateTime){
            return $dt->format('Y-m-d');
        }
    }
    $ts = strtotime($value);
    if($ts === false) return '';
    return date('Y-m-d', $ts);
}

function parse_csv_number($value){
    $value = trim((string)$value);
    if($value === '' || $value === '-' || $value === '--') return null;
    $value = str_replace([',', ' '], '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    if($value === '' || $value === '-' || $value === '--') return null;
    if(!is_numeric($value)) return null;
    return (int)round((float)$value);
}

$stmt = $conn->prepare("INSERT INTO bilty(sr_no, date, vehicle, bilty_no, party, feed_portion, location, bags, freight, original_freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

while(($data = fgetcsv($handle)) !== false){
    $lineNo++;
    if(empty($data)){
        $importReport[] = ['skipped', $lineNo, 'Empty row', ''];
        $skipped++;
        continue;
    }

    $data = array_map('trim', $data);

    if($lineNo === 1){
        $headers = array_map('normalize_header_name', $data);
        if(in_array('date', $headers, true) && in_array('vehicle', $headers, true)){
            $headerMap = [];
            foreach($headers as $i => $name){
                $headerMap[$name] = $i;
            }
            continue;
        }
    }

    if($headerMap !== null){
        $dateRaw = isset($headerMap['date']) ? ($data[$headerMap['date']] ?? '') : '';
        $date = parse_csv_date_to_mysql($dateRaw);
        $srNo = isset($headerMap['sr_no']) ? ($data[$headerMap['sr_no']] ?? '') : '';
        $vehicle = isset($headerMap['vehicle']) ? ($data[$headerMap['vehicle']] ?? '') : '';
        $biltyNo = isset($headerMap['bilty_no']) ? ($data[$headerMap['bilty_no']] ?? '') : '';
        $party = isset($headerMap['party']) ? ($data[$headerMap['party']] ?? '') : '';
        $portionRaw = '';
        if(isset($headerMap['feed_portion'])) $portionRaw = $data[$headerMap['feed_portion']] ?? '';
        elseif(isset($headerMap['portion'])) $portionRaw = $data[$headerMap['portion']] ?? '';
        elseif(isset($headerMap['section'])) $portionRaw = $data[$headerMap['section']] ?? '';
        $feedPortion = normalize_feed_portion_local($portionRaw);
        $location = isset($headerMap['location']) ? ($data[$headerMap['location']] ?? '') : '';
        $bags = parse_csv_number(isset($headerMap['bags']) ? ($data[$headerMap['bags']] ?? '') : '');
        $freight = parse_csv_number(isset($headerMap['freight']) ? ($data[$headerMap['freight']] ?? '') : '');
        $tender = parse_csv_number(isset($headerMap['tender']) ? ($data[$headerMap['tender']] ?? '') : '');
        $profit = parse_csv_number(isset($headerMap['profit']) ? ($data[$headerMap['profit']] ?? '') : '');
    } else {
        if(count($data) < 6){
            $importReport[] = ['skipped', $lineNo, 'Too few columns', implode(' | ', $data)];
            $skipped++;
            continue;
        }

        $offset = -1;
        for($i = 0; $i <= min(3, count($data) - 1); $i++){
            if(parse_csv_date_to_mysql($data[$i]) !== ''){
                $offset = $i;
                break;
            }
        }
        if($offset < 0){
            $offset = 0;
        }

        $date = parse_csv_date_to_mysql($data[$offset]);
        $vehicle = $data[$offset + 1] ?? '';
        $biltyNo = $data[$offset + 2] ?? '';
        $party = $data[$offset + 3] ?? '';
        $location = $data[$offset + 4] ?? '';
        $feedPortion = feed_default_portion_key_local();
        $remaining = count($data) - $offset;
        if($remaining >= 9){
            // New format with bags.
            $bags = parse_csv_number($data[$offset + 5] ?? '');
            $freight = parse_csv_number($data[$offset + 6] ?? '');
            $tender = parse_csv_number($data[$offset + 7] ?? '');
            $profit = parse_csv_number($data[$offset + 8] ?? '');
        } else {
            // Legacy format without bags.
            $bags = 0;
            $freight = parse_csv_number($data[$offset + 5] ?? '');
            $tender = parse_csv_number($data[$offset + 6] ?? '');
            $profit = parse_csv_number($data[$offset + 7] ?? '');
        }
        $srNo = ($offset > 0) ? ($data[$offset - 1] ?? '') : '';
    }

    if($date === ''){
        $date = date('Y-m-d');
        $importReport[] = ['adjusted', $lineNo, 'Invalid date -> today used', implode(' | ', $data)];
    }
    if($freight === null){
        $freight = 0;
        $importReport[] = ['adjusted', $lineNo, 'Invalid freight -> 0 used', implode(' | ', $data)];
    }
    if($bags === null){
        $bags = 0;
    }
    if($tender === null){
        $tender = 0;
        $importReport[] = ['adjusted', $lineNo, 'Invalid tender -> 0 used', implode(' | ', $data)];
    }

    if($profit === null){
        $profit = $tender - $freight;
    }

    $stmt->bind_param("sssssssiiiii", $srNo, $date, $vehicle, $biltyNo, $party, $feedPortion, $location, $bags, $freight, $freight, $tender, $profit);
    if($stmt->execute()){
        $inserted++;
    } else {
        $importReport[] = ['skipped', $lineNo, 'DB insert failed: ' . $stmt->error, implode(' | ', $data)];
        $skipped++;
    }
}

fclose($handle);
$stmt->close();

$reportFile = '';
if(count($importReport) > 0){
    $dir = __DIR__ . '/output/import_logs';
    if(!is_dir($dir)) @mkdir($dir, 0777, true);
    $reportFile = 'feed_import_' . date('Ymd_His') . '.csv';
    $fullPath = $dir . '/' . $reportFile;
    $rf = @fopen($fullPath, 'w');
    if($rf){
        fputcsv($rf, ['status', 'line', 'reason', 'raw_row']);
        foreach($importReport as $r){
            fputcsv($rf, $r);
        }
        fclose($rf);
    } else {
        $reportFile = '';
    }
}

$redirect = "location:feed.php?import=success&ins=$inserted&skip=$skipped";
if($reportFile !== ''){
    $redirect .= "&report=" . urlencode($reportFile);
}
header($redirect);
exit();
?>

