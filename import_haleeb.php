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
$origName = isset($_FILES['csv_file']['name']) ? (string)$_FILES['csv_file']['name'] : '';
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

$inserted = 0;
$skipped = 0;
$lineNo = 0;
$headerMap = null;
$importReport = [];

function normalize_header_name($v){
    $v = strtolower(trim((string)$v));
    $v = str_replace(['.', '-', '/', '\\'], ' ', $v);
    $v = preg_replace('/\s+/', '_', $v);
    $v = preg_replace('/[^a-z0-9_]/', '', $v);
    return trim($v, '_');
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

function parse_haleeb_date_to_mysql($value){
    $value = trim((string)$value);
    if($value === '') return '';

    if(is_numeric($value)){
        $num = (float)$value;
        if($num > 20000 && $num < 100000){
            $ts = ((int)$num - 25569) * 86400;
            return gmdate('Y-m-d', $ts);
        }
    }

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

function cell_ref_to_index($ref){
    if(!preg_match('/^([A-Z]+)\d+$/i', (string)$ref, $m)) return null;
    $letters = strtoupper($m[1]);
    $idx = 0;
    $len = strlen($letters);
    for($i = 0; $i < $len; $i++){
        $idx = $idx * 26 + (ord($letters[$i]) - 64);
    }
    return $idx - 1;
}

function read_xlsx_rows($filePath){
    if(!class_exists('ZipArchive')){
        return false;
    }

    $rows = [];
    $zip = new ZipArchive();
    if($zip->open($filePath) !== true){
        return $rows;
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if($sharedXml !== false){
        $sx = @simplexml_load_string($sharedXml);
        if($sx){
            $sx->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $siList = $sx->xpath('//x:si');
            if(is_array($siList)){
                foreach($siList as $si){
                    $parts = $si->xpath('.//x:t');
                    $txt = '';
                    if(is_array($parts)){
                        foreach($parts as $p) $txt .= (string)$p;
                    }
                    $sharedStrings[] = $txt;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if($sheetXml === false){
        $zip->close();
        return $rows;
    }

    $sheet = @simplexml_load_string($sheetXml);
    if(!$sheet){
        $zip->close();
        return $rows;
    }

    $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rowNodes = $sheet->xpath('//x:sheetData/x:row');
    if(is_array($rowNodes)){
        foreach($rowNodes as $rowNode){
            $row = [];
            $max = -1;
            $cells = $rowNode->xpath('./x:c');
            if(!is_array($cells)) $cells = [];
            foreach($cells as $c){
                $ref = (string)$c['r'];
                $idx = cell_ref_to_index($ref);
                if($idx === null) continue;
                $max = max($max, $idx);

                $type = (string)$c['t'];
                $val = '';
                if($type === 's'){
                    $sv = (int)((string)$c->v);
                    $val = isset($sharedStrings[$sv]) ? $sharedStrings[$sv] : '';
                } elseif($type === 'inlineStr'){
                    $val = (string)$c->is->t;
                } else {
                    $val = isset($c->v) ? (string)$c->v : '';
                }
                $row[$idx] = trim($val);
            }

            if($max >= 0){
                $dense = [];
                for($i = 0; $i <= $max; $i++){
                    $dense[] = isset($row[$i]) ? $row[$i] : '';
                }
                $rows[] = $dense;
            } else {
                $rows[] = [];
            }
        }
    }

    $zip->close();
    return $rows;
}

function read_csv_rows($filePath){
    $rows = [];
    $handle = fopen($filePath, 'r');
    if(!$handle) return $rows;
    while(($data = fgetcsv($handle)) !== false){
        $rows[] = array_map('trim', $data);
    }
    fclose($handle);
    return $rows;
}

if($ext === 'xlsx'){
    $allRows = read_xlsx_rows($tmpName);
    if($allRows === false){
        header("location:haleeb.php?import=error&reason=zip_missing");
        exit();
    }
} else {
    $allRows = read_csv_rows($tmpName);
}

if(count($allRows) === 0){
    header("location:haleeb.php?import=error");
    exit();
}

$stmt = $conn->prepare("INSERT INTO haleeb_bilty(date, vehicle, vehicle_type, delivery_note, token_no, party, location, freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach($allRows as $data){
    $lineNo++;
    if(empty($data) || implode('', $data) === ''){
        $importReport[] = ['skipped', $lineNo, 'Empty row', ''];
        $skipped++;
        continue;
    }

    $data = array_map('trim', $data);

    if($headerMap === null){
        $headers = array_map('normalize_header_name', $data);
        $hasDate = in_array('date', $headers, true);
        $hasVehicle = in_array('vehicle', $headers, true);
        if($hasDate && $hasVehicle){
            $headerMap = [];
            foreach($headers as $i => $name){
                $headerMap[$name] = $i;
            }
            continue;
        }
    }

    if($headerMap !== null){
        $dateRaw = isset($headerMap['date']) ? ($data[$headerMap['date']] ?? '') : '';
        $date = parse_haleeb_date_to_mysql($dateRaw);
        $vehicle = '';
        if(isset($headerMap['vehicle'])) $vehicle = $data[$headerMap['vehicle']] ?? '';
        elseif(isset($headerMap['vehicle_no'])) $vehicle = $data[$headerMap['vehicle_no']] ?? '';
        elseif(isset($headerMap['vehicle_'])) $vehicle = $data[$headerMap['vehicle_']] ?? '';

        $vehicleType = '';
        if(isset($headerMap['vehicle_type'])) $vehicleType = $data[$headerMap['vehicle_type']] ?? '';
        elseif(isset($headerMap['type'])) $vehicleType = $data[$headerMap['type']] ?? '';

        $deliveryNote = '';
        if(isset($headerMap['delivery_note'])) $deliveryNote = $data[$headerMap['delivery_note']] ?? '';
        elseif(isset($headerMap['deliverynote'])) $deliveryNote = $data[$headerMap['deliverynote']] ?? '';
        elseif(isset($headerMap['d_n'])) $deliveryNote = $data[$headerMap['d_n']] ?? '';
        elseif(isset($headerMap['dn'])) $deliveryNote = $data[$headerMap['dn']] ?? '';

        $tokenNo = '';
        if(isset($headerMap['token_no'])) $tokenNo = $data[$headerMap['token_no']] ?? '';
        elseif(isset($headerMap['token'])) $tokenNo = $data[$headerMap['token']] ?? '';

        $party = '';
        if(isset($headerMap['party'])) $party = $data[$headerMap['party']] ?? '';
        elseif(isset($headerMap['distributor'])) $party = $data[$headerMap['distributor']] ?? '';

        $location = '';
        if(isset($headerMap['location'])) $location = $data[$headerMap['location']] ?? '';
        elseif(isset($headerMap['city'])) $location = $data[$headerMap['city']] ?? '';

        $freight = parse_csv_number(isset($headerMap['freight']) ? ($data[$headerMap['freight']] ?? '') : '');
        $tender = null;
        if(isset($headerMap['tender'])) $tender = parse_csv_number($data[$headerMap['tender']] ?? '');
        elseif(isset($headerMap['npl_rate'])) $tender = parse_csv_number($data[$headerMap['npl_rate']] ?? '');
        $profit = parse_csv_number(isset($headerMap['profit']) ? ($data[$headerMap['profit']] ?? '') : '');
    } else {
        if(count($data) < 7){
            $importReport[] = ['skipped', $lineNo, 'Too few columns', implode(' | ', $data)];
            $skipped++;
            continue;
        }

        $offset = 0;
        for($i = 0; $i <= min(3, count($data) - 1); $i++){
            if(parse_haleeb_date_to_mysql($data[$i]) !== ''){
                $offset = $i;
                break;
            }
        }

        $date = parse_haleeb_date_to_mysql($data[$offset] ?? '');
        $vehicle = $data[$offset + 1] ?? '';
        $vehicleType = $data[$offset + 2] ?? '';
        $deliveryNote = $data[$offset + 3] ?? '';
        $tokenNo = $data[$offset + 4] ?? '';
        $party = $data[$offset + 5] ?? '';
        $location = $data[$offset + 6] ?? '';
        $freight = parse_csv_number($data[$offset + 7] ?? '');
        $tender = parse_csv_number($data[$offset + 8] ?? '');
        $profit = parse_csv_number($data[$offset + 9] ?? '');
    }

    if($date === ''){
        $date = date('Y-m-d');
        $importReport[] = ['adjusted', $lineNo, 'Invalid date -> today used', implode(' | ', $data)];
    }
    if($freight === null){
        $freight = 0;
        $importReport[] = ['adjusted', $lineNo, 'Invalid freight -> 0 used', implode(' | ', $data)];
    }
    if($tender === null){
        $tender = 0;
        $importReport[] = ['adjusted', $lineNo, 'Invalid tender -> 0 used', implode(' | ', $data)];
    }
    if($profit === null){
        $profit = $tender - $freight;
    }

    $stmt->bind_param("sssssssiii", $date, $vehicle, $vehicleType, $deliveryNote, $tokenNo, $party, $location, $freight, $tender, $profit);
    if($stmt->execute()){
        $inserted++;
    } else {
        $importReport[] = ['skipped', $lineNo, 'DB insert failed: ' . $stmt->error, implode(' | ', $data)];
        $skipped++;
    }
}

$stmt->close();

$reportFile = '';
if(count($importReport) > 0){
    $dir = __DIR__ . '/output/import_logs';
    if(!is_dir($dir)) @mkdir($dir, 0777, true);
    $reportFile = 'haleeb_import_' . date('Ymd_His') . '.csv';
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

$redirect = "location:haleeb.php?import=success&ins=$inserted&skip=$skipped";
if($reportFile !== ''){
    $redirect .= "&report=" . urlencode($reportFile);
}
header($redirect);
exit();
?>
