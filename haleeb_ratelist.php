<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
auth_require_login($conn);
auth_require_module_access('haleeb');
auth_require_super_admin('dashboard.php');
$canDirectModify = auth_can_direct_modify('haleeb');

function slugify_label_to_key($label){
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim($key, '_');
    if($key === '') $key = 'column';
    if(strpos($key, 'custom_') !== 0) $key = 'custom_' . $key;
    return $key;
}
function load_columns($conn){
    $cols = [];
    $res = $conn->query("SELECT id, column_key, column_label, is_hidden, is_deleted, display_order, is_base FROM haleeb_rate_list_columns ORDER BY display_order ASC, id ASC");
    while($r = $res->fetch_assoc()) $cols[] = $r;
    return $cols;
}
function normalize_lookup_token_local($v){ $v = strtolower(trim((string)$v)); $v = preg_replace('/\s+/', ' ', $v); return $v; }
function normalize_header_local($v){ $v = strtolower(trim((string)$v)); $v = preg_replace('/\s+/', ' ', $v); $v = str_replace(['.','(',')'], '', $v); return $v; }
function normalize_digits_local($v){ $v = (string)$v; $map = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']; return strtr($v, $map); }
function canonical_sr_local($v){ $v = normalize_digits_local((string)$v); $v = strtolower(trim($v)); return preg_replace('/[^a-z0-9]/u', '', $v); }
function detect_sr_column_key_local($columns){ foreach($columns as $c){ if(isset($c['column_key']) && $c['column_key'] === 'sr_no') return 'sr_no'; } foreach($columns as $c){ $lbl = isset($c['column_label']) ? normalize_header_local($c['column_label']) : ''; if(in_array($lbl, ['sr','sr no','serial','serial no','serial number'], true)) return (string)$c['column_key']; } return ''; }
function sr_exists_local($conn, $srKey, $srValue, $excludeId = 0, $listName = ''){
  $srCanon = canonical_sr_local($srValue);
  if($srCanon === '' || $srKey === '') return false;
  $rows = null;
  $listName = normalize_rate_list_name_local($listName);
  if($listName !== ''){
    $stmt = $conn->prepare("SELECT id, sr_no, extra_data FROM haleeb_image_processed_rates WHERE COALESCE(NULLIF(rate_list_name,''), 'Base List')=?");
    if($stmt){
      $stmt->bind_param("s", $listName);
      $stmt->execute();
      $rows = $stmt->get_result();
      while($rows && $row = $rows->fetch_assoc()){
        $id = (int)$row['id'];
        if($excludeId > 0 && $id === $excludeId) continue;
        $candidate = '';
        if($srKey === 'sr_no') $candidate = isset($row['sr_no']) ? (string)$row['sr_no'] : '';
        else {
          $extra = json_decode((string)$row['extra_data'], true);
          if(is_array($extra) && isset($extra[$srKey])) $candidate = (string)$extra[$srKey];
        }
        if(canonical_sr_local($candidate) === $srCanon){ $stmt->close(); return true; }
      }
      $stmt->close();
      return false;
    }
  }
  $res = $conn->query("SELECT id, sr_no, extra_data FROM haleeb_image_processed_rates");
  while($res && $row = $res->fetch_assoc()){
    $id = (int)$row['id'];
    if($excludeId > 0 && $id === $excludeId) continue;
    $candidate = '';
    if($srKey === 'sr_no') $candidate = isset($row['sr_no']) ? (string)$row['sr_no'] : '';
    else {
      $extra = json_decode((string)$row['extra_data'], true);
      if(is_array($extra) && isset($extra[$srKey])) $candidate = (string)$extra[$srKey];
    }
    if(canonical_sr_local($candidate) === $srCanon) return true;
  }
  return false;
}

function parse_rate_numeric_local($value){ $value = trim((string)$value); if($value === '') return null; $clean = str_replace([',', ' '], '', $value); $clean = preg_replace('/[^0-9.\-]/', '', $clean); if($clean === '' || $clean === '-' || $clean === '.' || $clean === '-.') return null; if(!is_numeric($clean)) return null; return (float)$clean; }
function decimal_places_local($value){ $clean = preg_replace('/[^0-9.\-]/', '', trim((string)$value)); if($clean === '' || strpos($clean, '.') === false) return 0; $parts = explode('.', $clean, 2); if(count($parts) < 2) return 0; return min(4, max(0, strlen(rtrim($parts[1], '0')))); }
function format_rate_numeric_local($number, $sourceRaw){ $dec = decimal_places_local($sourceRaw); $n = round((float)$number, 4); if($dec > 0){ return number_format($n, $dec, '.', ','); } if(abs($n - round($n)) < 0.0000001){ return number_format((float)round($n), 0, '.', ','); } $out = number_format($n, 4, '.', ','); $out = rtrim($out, '0'); $out = rtrim($out, '.'); if($out === '-0') $out = '0'; return $out; }
function is_valid_ymd_date_local($value){ $v = trim((string)$value); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false; $dt = DateTime::createFromFormat('Y-m-d', $v); return $dt && $dt->format('Y-m-d') === $v; }
function normalize_rate_list_name_local($value){ $v = trim((string)$value); return $v === '' ? 'Base List' : $v; }
function load_haleeb_rate_lists_local($conn){
  $lists = [];
  $res = $conn->query("SELECT COALESCE(NULLIF(rate_list_name,''), 'Base List') AS list_name, COUNT(*) AS row_count, MAX(id) AS max_id, MAX(created_at) AS last_created_at FROM haleeb_image_processed_rates GROUP BY COALESCE(NULLIF(rate_list_name,''), 'Base List') ORDER BY CASE WHEN COALESCE(NULLIF(rate_list_name,''), 'Base List')='Base List' THEN 0 ELSE 1 END, MAX(id) DESC");
  while($res && $row = $res->fetch_assoc()){
    $lists[] = [
      'list_name' => normalize_rate_list_name_local($row['list_name'] ?? 'Base List'),
      'row_count' => (int)($row['row_count'] ?? 0),
      'max_id' => (int)($row['max_id'] ?? 0),
      'last_created_at' => (string)($row['last_created_at'] ?? ''),
    ];
  }
  if(count($lists) === 0){
    $lists[] = ['list_name' => 'Base List', 'row_count' => 0, 'max_id' => 0, 'last_created_at' => ''];
  }
  return $lists;
}
function latest_haleeb_rate_list_name_local($conn){
  $row = $conn->query("SELECT COALESCE(NULLIF(rate_list_name,''), 'Base List') AS list_name FROM haleeb_image_processed_rates ORDER BY id DESC LIMIT 1")->fetch_assoc();
  return normalize_rate_list_name_local($row['list_name'] ?? 'Base List');
}

$msg = ''; $err = ''; $editingId = 0; $openAddRow = false; $openRateChange = false; $openTenderSync = false; $rateChangeMode = 'increment'; $rateChangePercent = ''; $rateChangeLabel = ''; $rateChangePetrolOld = ''; $rateChangePetrolNew = ''; $rateChangeSourceList = ''; $tenderSyncDateFrom = ''; $tenderSyncDateTo = '';

if(!$canDirectModify){
  $blockedPostActions = ['add_column', 'apply_rate_change', 'apply_haleeb_tender_sync', 'save_columns', 'delete_column', 'delete_rate', 'update_rate', 'add_rate'];
  foreach($blockedPostActions as $blockedKey){
    if(isset($_POST[$blockedKey])){
      $err = 'Only super admin can modify rate list.';
      unset($_POST[$blockedKey]);
    }
  }
  if(isset($_GET['delete_all']) && $_GET['delete_all'] === '1'){
    $err = 'Only super admin can clear rate list.';
    unset($_GET['delete_all']);
  }
}
if(isset($_GET['open']) && $_GET['open'] === 'tender_sync'){
  $openTenderSync = true;
}
if(isset($_GET['open']) && $_GET['open'] === 'rate_change'){
  $openRateChange = true;
}

if(isset($_GET['delete_all']) && $_GET['delete_all'] === '1'){ $conn->query("DELETE FROM haleeb_image_processed_rates"); $msg = 'Rate list cleared.'; }
if(isset($_GET['import'])){ if($_GET['import'] === 'success'){ $ins = isset($_GET['ins']) ? (int)$_GET['ins'] : 0; $skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0; $msg = "Import completed. Inserted: $ins, Skipped: $skip"; } elseif($_GET['import'] === 'error'){ $reason = isset($_GET['reason']) ? trim((string)$_GET['reason']) : ''; $err = $reason === 'no_columns' ? 'Import failed. No active Rate List columns found.' : 'Import failed. Please upload a valid CSV file.'; } }

if(isset($_POST['apply_rate_change'])){
  $openRateChange = true;
  $rateChangeSourceList = normalize_rate_list_name_local(isset($_POST['rc_source_list']) ? (string)$_POST['rc_source_list'] : '');
  $rateChangeMode = isset($_POST['rc_mode']) ? trim((string)$_POST['rc_mode']) : 'increment';
  $rateChangePercent = isset($_POST['rc_percent']) ? trim((string)$_POST['rc_percent']) : '';
  $rateChangeLabel = isset($_POST['rc_new_column_label']) ? trim((string)$_POST['rc_new_column_label']) : '';
  $rateChangePetrolOld = isset($_POST['rc_petrol_old']) ? trim((string)$_POST['rc_petrol_old']) : '';
  $rateChangePetrolNew = isset($_POST['rc_petrol_new']) ? trim((string)$_POST['rc_petrol_new']) : '';

  $oldPetrolValue = parse_rate_numeric_local($rateChangePetrolOld);
  $newPetrolValue = parse_rate_numeric_local($rateChangePetrolNew);
  if($oldPetrolValue !== null && $newPetrolValue !== null && $oldPetrolValue > 0){
    $petrolChangePercent = (($newPetrolValue - $oldPetrolValue) / $oldPetrolValue) * 100;
    $rateChangeMode = $petrolChangePercent >= 0 ? 'increment' : 'decrement';
    $rateChangePercent = (string)(abs($petrolChangePercent) / 2);
  }

  $allCols = load_columns($conn);
  $activeCols = array_values(array_filter($allCols, function($c){ return (int)$c['is_deleted'] === 0; }));
  $skipKeys = ['sr_no' => true, 'station_english' => true, 'station_urdu' => true, 'custom_to' => true];
  $targetKeys = [];
  foreach($activeCols as $col){
    $key = (string)$col['column_key'];
    if($key === '' || isset($skipKeys[$key])) continue;
    $targetKeys[] = $key;
  }

  $listRows = load_haleeb_rate_lists_local($conn);
  $listLookup = [];
  foreach($listRows as $listRow){
    $name = normalize_rate_list_name_local($listRow['list_name']);
    $listLookup[strtolower($name)] = $name;
  }
  if($rateChangeSourceList === ''){
    $rateChangeSourceList = latest_haleeb_rate_list_name_local($conn);
  }
  $sourceLookupKey = strtolower($rateChangeSourceList);
  if(isset($listLookup[$sourceLookupKey])){
    $rateChangeSourceList = $listLookup[$sourceLookupKey];
  }
  $targetListName = normalize_rate_list_name_local($rateChangeLabel);
  $targetLookupKey = strtolower($targetListName);

  if($rateChangeSourceList === '' || !isset($listLookup[$sourceLookupKey])){
    $err = 'Rate Change: source list not found.';
  } elseif($rateChangeLabel === ''){
    $err = 'Rate Change: new list name required.';
  } elseif(isset($listLookup[$targetLookupKey])){
    $err = 'Rate Change: this list name already exists.';
  } elseif($rateChangePercent === '' || !is_numeric($rateChangePercent)){
    $err = 'Rate Change: enter a valid percent value.';
  } elseif(count($targetKeys) === 0){
    $err = 'Rate Change: no rate columns available for update.';
  } else {
    $percent = abs((float)$rateChangePercent);
    $factor = $rateChangeMode === 'decrement' ? (1 - ($percent / 100)) : (1 + ($percent / 100));
    if($factor < 0) $factor = 0;

    $rowsStmt = $conn->prepare("SELECT source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, custom_to, custom_mazda, custom_14ft, custom_20ft, custom_40ft_22t, custom_40ft_28t, custom_40ft_32t, extra_data FROM haleeb_image_processed_rates WHERE COALESCE(NULLIF(rate_list_name,''), 'Base List')=? ORDER BY id ASC");
    $rowsStmt->bind_param("s", $rateChangeSourceList);
    $rowsStmt->execute();
    $rowsRes = $rowsStmt->get_result();

    $ins = $conn->prepare("INSERT INTO haleeb_image_processed_rates(source_file, source_image_path, rate_list_name, sr_no, station_english, station_urdu, rate1, rate2, custom_to, custom_mazda, custom_14ft, custom_20ft, custom_40ft_22t, custom_40ft_28t, custom_40ft_32t, extra_data) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insertedCount = 0;
    $changedCount = 0;
    $totalRows = 0;

    while($rowsRes && $row = $rowsRes->fetch_assoc()){
      $totalRows++;
      $extra = [];
      if(isset($row['extra_data']) && $row['extra_data'] !== ''){
        $decoded = json_decode((string)$row['extra_data'], true);
        if(is_array($decoded)) $extra = $decoded;
      }

      $newRate1 = (string)($row['rate1'] ?? '');
      $newRate2 = (string)($row['rate2'] ?? '');
      $newCustomTo = (string)($row['custom_to'] ?? '');
      $newMazda = (string)($row['custom_mazda'] ?? '');
      $new14ft = (string)($row['custom_14ft'] ?? '');
      $new20ft = (string)($row['custom_20ft'] ?? '');
      $new40ft22 = (string)($row['custom_40ft_22t'] ?? '');
      $new40ft28 = (string)($row['custom_40ft_28t'] ?? '');
      $new40ft32 = (string)($row['custom_40ft_32t'] ?? '');

      $changed = false;
      foreach($targetKeys as $targetKey){
        $sourceRaw = '';
        if(array_key_exists($targetKey, $row)){
          $sourceRaw = (string)$row[$targetKey];
        } elseif(isset($extra[$targetKey])){
          $sourceRaw = (string)$extra[$targetKey];
        }

        $sourceNumber = parse_rate_numeric_local($sourceRaw);
        if($sourceNumber === null) continue;

        $newValue = format_rate_numeric_local(($sourceNumber * $factor), $sourceRaw);
        if($targetKey === 'rate1'){ $newRate1 = $newValue; $changed = true; continue; }
        if($targetKey === 'rate2'){ $newRate2 = $newValue; $changed = true; continue; }
        if($targetKey === 'custom_mazda'){ $newMazda = $newValue; $changed = true; continue; }
        if($targetKey === 'custom_14ft'){ $new14ft = $newValue; $changed = true; continue; }
        if($targetKey === 'custom_20ft'){ $new20ft = $newValue; $changed = true; continue; }
        if($targetKey === 'custom_40ft_22t'){ $new40ft22 = $newValue; $changed = true; continue; }
        if($targetKey === 'custom_40ft_28t'){ $new40ft28 = $newValue; $changed = true; continue; }
        if($targetKey === 'custom_40ft_32t'){ $new40ft32 = $newValue; $changed = true; continue; }
        $extra[$targetKey] = $newValue;
        $changed = true;
      }

      if($changed) $changedCount++;
      $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);
      if($extraJson === false) $extraJson = '{}';
      $sourceFile = (string)($row['source_file'] ?? '');
      $sourceImagePath = (string)($row['source_image_path'] ?? '');
      $srNo = (string)($row['sr_no'] ?? '');
      $stationEnglish = (string)($row['station_english'] ?? '');
      $stationUrdu = (string)($row['station_urdu'] ?? '');
      $ins->bind_param("ssssssssssssssss", $sourceFile, $sourceImagePath, $targetListName, $srNo, $stationEnglish, $stationUrdu, $newRate1, $newRate2, $newCustomTo, $newMazda, $new14ft, $new20ft, $new40ft22, $new40ft28, $new40ft32, $extraJson);
      if($ins->execute()) $insertedCount++;
    }

    $ins->close();
    $rowsStmt->close();
    if($totalRows === 0){
      $err = 'Rate Change: source list has no rows.';
    } else {
      $modeText = $rateChangeMode === 'decrement' ? 'decrease' : 'increase';
      $msg = "New list '{$targetListName}' created from '{$rateChangeSourceList}' ({$modeText} {$percent}%). Inserted rows: {$insertedCount}/{$totalRows}, changed rows: {$changedCount}.";
      $openRateChange = false;
      $rateChangeMode = 'increment'; $rateChangePercent = ''; $rateChangeLabel = ''; $rateChangePetrolOld = ''; $rateChangePetrolNew = ''; $rateChangeSourceList = '';
    }
  }
}

if(isset($_POST['apply_haleeb_tender_sync'])){
  $openTenderSync = true;
  $tenderSyncDateFrom = isset($_POST['ts_date_from']) ? trim((string)$_POST['ts_date_from']) : '';
  $tenderSyncDateTo = isset($_POST['ts_date_to']) ? trim((string)$_POST['ts_date_to']) : '';

  if(!is_valid_ymd_date_local($tenderSyncDateFrom) || !is_valid_ymd_date_local($tenderSyncDateTo)){
    $err = 'Tender Sync: valid From and To dates are required.';
  } elseif($tenderSyncDateFrom > $tenderSyncDateTo){
    $err = 'Tender Sync: From date must be less than or equal to To date.';
  } else {
    $syncSourceList = latest_haleeb_rate_list_name_local($conn);
    $vehicleTypeLookup = [];
    $vehicleKeys = [];
    $vtRes = $conn->query("SELECT column_key, column_label FROM haleeb_rate_list_columns WHERE is_deleted=0 AND column_key LIKE 'custom_%' ORDER BY display_order ASC, id ASC");
    while($vtRes && $row = $vtRes->fetch_assoc()){
      $columnKey = (string)$row['column_key'];
      if($columnKey === '') continue;
      $columnLabel = trim((string)$row['column_label']);
      if($columnLabel === '') $columnLabel = $columnKey;
      $vehicleTypeLookup[normalize_lookup_token_local($columnLabel)] = $columnKey;
      $vehicleTypeLookup[normalize_lookup_token_local($columnKey)] = $columnKey;
      $vehicleKeys[$columnKey] = true;
    }

    $rateLookup = [];
    $rateStmt = $conn->prepare("SELECT custom_to, custom_mazda, custom_14ft, custom_20ft, custom_40ft_22t, custom_40ft_28t, custom_40ft_32t, extra_data FROM haleeb_image_processed_rates WHERE COALESCE(NULLIF(rate_list_name,''), 'Base List')=? ORDER BY id DESC");
    $rateStmt->bind_param("s", $syncSourceList);
    $rateStmt->execute();
    $rateRes = $rateStmt->get_result();
    while($rateRes && $rateRow = $rateRes->fetch_assoc()){
      $location = trim((string)($rateRow['custom_to'] ?? ''));
      if($location === '') continue;
      $locKey = normalize_lookup_token_local($location);
      if(isset($rateLookup[$locKey])) continue;

      $extra = [];
      if(isset($rateRow['extra_data']) && $rateRow['extra_data'] !== ''){
        $decoded = json_decode((string)$rateRow['extra_data'], true);
        if(is_array($decoded)) $extra = $decoded;
      }

      $values = [];
      foreach($vehicleKeys as $key => $_unused){
        $raw = '';
        if(array_key_exists($key, $rateRow)) $raw = (string)$rateRow[$key];
        elseif(isset($extra[$key])) $raw = (string)$extra[$key];
        $values[$key] = $raw;
      }
      $rateLookup[$locKey] = $values;
    }
    $rateStmt->close();

    $updatedCount = 0;
    $totalRows = 0;
    $missingLocation = 0;
    $missingVehicleType = 0;
    $missingRate = 0;
    $invalidRate = 0;

    $fetchStmt = $conn->prepare("SELECT id, location, vehicle_type, freight, commission FROM haleeb_bilty WHERE date >= ? AND date <= ? ORDER BY id ASC");
    $fetchStmt->bind_param("ss", $tenderSyncDateFrom, $tenderSyncDateTo);
    $fetchStmt->execute();
    $rowsRes = $fetchStmt->get_result();

    $updateStmt = $conn->prepare("UPDATE haleeb_bilty SET tender=?, profit=? WHERE id=?");
    while($rowsRes && $b = $rowsRes->fetch_assoc()){
      $totalRows++;
      $locationKey = normalize_lookup_token_local((string)($b['location'] ?? ''));
      if($locationKey === '' || !isset($rateLookup[$locationKey])){
        $missingLocation++;
        continue;
      }

      $vehicleTypeKey = normalize_lookup_token_local((string)($b['vehicle_type'] ?? ''));
      $vehicleColumn = isset($vehicleTypeLookup[$vehicleTypeKey]) ? (string)$vehicleTypeLookup[$vehicleTypeKey] : '';
      if($vehicleColumn === ''){
        $missingVehicleType++;
        continue;
      }

      $rateRaw = isset($rateLookup[$locationKey][$vehicleColumn]) ? (string)$rateLookup[$locationKey][$vehicleColumn] : '';
      if(trim($rateRaw) === ''){
        $missingRate++;
        continue;
      }

      $resolvedTender = parse_rate_numeric_local($rateRaw);
      if($resolvedTender === null || $resolvedTender <= 0){
        $invalidRate++;
        continue;
      }

      $resolvedTender = round($resolvedTender, 3);
      $freight = isset($b['freight']) ? (float)$b['freight'] : 0.0;
      $commission = isset($b['commission']) ? (float)$b['commission'] : 0.0;
      $totalCost = max(0, $freight - $commission);
      $profit = round($resolvedTender - $totalCost, 3);
      $rowId = (int)$b['id'];
      $updateStmt->bind_param("ddi", $resolvedTender, $profit, $rowId);
      if($updateStmt->execute()) $updatedCount++;
    }
    $updateStmt->close();
    $fetchStmt->close();

    $msg = "Haleeb tender sync complete ({$tenderSyncDateFrom} to {$tenderSyncDateTo}, list: {$syncSourceList}). Updated: {$updatedCount}/{$totalRows}, missing location in rate list: {$missingLocation}, unknown vehicle type: {$missingVehicleType}, rate missing: {$missingRate}, invalid/non-positive rate: {$invalidRate}.";
  }
}

if(isset($_POST['add_column'])){ $label = isset($_POST['new_column_label']) ? trim($_POST['new_column_label']) : ''; if($label === ''){ $err = 'Column name required.'; } else { $baseKey = slugify_label_to_key($label); $key = $baseKey; $suffix = 1; while(true){ $chk = $conn->prepare("SELECT id FROM haleeb_rate_list_columns WHERE column_key=? LIMIT 1"); $chk->bind_param("s", $key); $chk->execute(); $exists = $chk->get_result()->num_rows > 0; $chk->close(); if(!$exists) break; $suffix++; $key = $baseKey . '_' . $suffix; } $maxOrderRes = $conn->query("SELECT COALESCE(MAX(display_order),0) AS m FROM haleeb_rate_list_columns")->fetch_assoc(); $nextOrder = ((int)$maxOrderRes['m']) + 1; $ins = $conn->prepare("INSERT INTO haleeb_rate_list_columns(column_key, column_label, is_hidden, is_deleted, display_order, is_base) VALUES(?, ?, 0, 0, ?, 0)"); $ins->bind_param("ssi", $key, $label, $nextOrder); $ins->execute(); $ins->close(); $msg = 'New column added.'; } }
if(isset($_POST['save_columns'])){ $cols = load_columns($conn); foreach($cols as $c){ $key = $c['column_key']; $labelField = 'label_' . $key; $hideField = 'hide_' . $key; $newLabel = isset($_POST[$labelField]) ? trim($_POST[$labelField]) : $c['column_label']; $isHidden = isset($_POST[$hideField]) ? 1 : 0; if($newLabel === '') $newLabel = $c['column_label']; $upd = $conn->prepare("UPDATE haleeb_rate_list_columns SET column_label=?, is_hidden=? WHERE column_key=?"); $upd->bind_param("sis", $newLabel, $isHidden, $key); $upd->execute(); $upd->close(); } $msg = 'Column settings updated.'; }
if(isset($_POST['delete_column'])){ $key = isset($_POST['column_key']) ? trim($_POST['column_key']) : ''; if($key !== ''){ $upd = $conn->prepare("UPDATE haleeb_rate_list_columns SET is_deleted=1 WHERE column_key=?"); $upd->bind_param("s", $key); $upd->execute(); $upd->close(); $msg = 'Column deleted.'; } }
if(isset($_POST['delete_rate'])){
  $deleteId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
  if($deleteId > 0){
    $del = $conn->prepare("DELETE FROM haleeb_image_processed_rates WHERE id=?");
    $del->bind_param("i", $deleteId);
    $del->execute();
    $del->close();
    $msg = 'Rate row deleted.';
    $editingId = 0;
  } else {
    $err = 'Invalid row selected.';
  }
}
if(isset($_POST['update_rate'])){
  $editingId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
  if($editingId <= 0){
    $err = 'Invalid row selected.';
  } else {
    $allCols = load_columns($conn);
    $activeCols = array_values(array_filter($allCols, function($c){ return (int)$c['is_deleted'] === 0; }));
    $base = ['sr_no'=>'','station_english'=>'','station_urdu'=>'','rate1'=>'','rate2'=>'','custom_to'=>'','custom_mazda'=>'','custom_14ft'=>'','custom_20ft'=>'','custom_40ft_22t'=>'','custom_40ft_28t'=>'','custom_40ft_32t'=>''];
    $extra = [];
    foreach($activeCols as $c){
      $key = $c['column_key'];
      $val = isset($_POST['col_' . $key]) ? trim($_POST['col_' . $key]) : '';
      if(array_key_exists($key, $base)) $base[$key] = $val; else $extra[$key] = $val;
    }
    $targetListName = normalize_rate_list_name_local(isset($_POST['rate_list_name']) ? (string)$_POST['rate_list_name'] : '');
    $availableListNames = array_map(function($s){ return strtolower(normalize_rate_list_name_local($s['list_name'])); }, load_haleeb_rate_lists_local($conn));
    if(!in_array(strtolower($targetListName), $availableListNames, true)){
      $err = 'Update Row: selected rate list not found.';
    }
    $srKey = detect_sr_column_key_local($activeCols);
    $srVal = $srKey === 'sr_no' ? $base['sr_no'] : ($extra[$srKey] ?? '');
    if($err === '' && $srKey !== '' && sr_exists_local($conn, $srKey, $srVal, $editingId, $targetListName)){
      $err = 'Duplicate SR not allowed in same list.';
    } elseif($err === '') {
      $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);
      $upd = $conn->prepare("UPDATE haleeb_image_processed_rates SET sr_no=?, station_english=?, station_urdu=?, rate1=?, rate2=?, custom_to=?, custom_mazda=?, custom_14ft=?, custom_20ft=?, custom_40ft_22t=?, custom_40ft_28t=?, custom_40ft_32t=?, extra_data=? WHERE id=?");
      $upd->bind_param("sssssssssssssi", $base['sr_no'], $base['station_english'], $base['station_urdu'], $base['rate1'], $base['rate2'], $base['custom_to'], $base['custom_mazda'], $base['custom_14ft'], $base['custom_20ft'], $base['custom_40ft_22t'], $base['custom_40ft_28t'], $base['custom_40ft_32t'], $extraJson, $editingId);
      $upd->execute();
      $upd->close();
      $msg = 'Rate row updated.';
      $editingId = 0;
    }
  }
}
if(isset($_POST['add_rate'])){
  $openAddRow = true;
  $allCols = load_columns($conn);
  $activeCols = array_values(array_filter($allCols, function($c){ return (int)$c['is_deleted'] === 0; }));
  $base = ['sr_no'=>'','station_english'=>'','station_urdu'=>'','rate1'=>'','rate2'=>'','custom_to'=>'','custom_mazda'=>'','custom_14ft'=>'','custom_20ft'=>'','custom_40ft_22t'=>'','custom_40ft_28t'=>'','custom_40ft_32t'=>''];
  $extra = [];
  foreach($activeCols as $c){
    $key = $c['column_key'];
    $val = isset($_POST['add_col_' . $key]) ? trim($_POST['add_col_' . $key]) : '';
    if(array_key_exists($key, $base)) $base[$key] = $val; else $extra[$key] = $val;
  }
  $targetListName = normalize_rate_list_name_local(isset($_POST['target_rate_list_name']) ? (string)$_POST['target_rate_list_name'] : latest_haleeb_rate_list_name_local($conn));
  $availableListNames = array_map(function($s){ return strtolower(normalize_rate_list_name_local($s['list_name'])); }, load_haleeb_rate_lists_local($conn));
  if(!in_array(strtolower($targetListName), $availableListNames, true)){
    $err = 'Add Row: selected rate list not found.';
  }
  $srKey = detect_sr_column_key_local($activeCols);
  $srVal = $srKey === 'sr_no' ? $base['sr_no'] : ($extra[$srKey] ?? '');
  if($err === '' && $srKey !== '' && $srVal !== '' && sr_exists_local($conn, $srKey, $srVal, 0, $targetListName)){
    $err = 'Duplicate SR not allowed in same list.';
  } elseif($err === '') {
    $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);
    $ins = $conn->prepare("INSERT INTO haleeb_image_processed_rates(source_file, source_image_path, rate_list_name, sr_no, station_english, station_urdu, rate1, rate2, custom_to, custom_mazda, custom_14ft, custom_20ft, custom_40ft_22t, custom_40ft_28t, custom_40ft_32t, extra_data) VALUES('', '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param("ssssssssssssss", $targetListName, $base['sr_no'], $base['station_english'], $base['station_urdu'], $base['rate1'], $base['rate2'], $base['custom_to'], $base['custom_mazda'], $base['custom_14ft'], $base['custom_20ft'], $base['custom_40ft_22t'], $base['custom_40ft_28t'], $base['custom_40ft_32t'], $extraJson);
    $ins->execute();
    $ins->close();
    $msg = "New rate row added in '{$targetListName}'.";
  }
}
if(isset($_GET['edit_id']) && !isset($_POST['update_rate'])) $editingId = (int)$_GET['edit_id'];

$allColumns = load_columns($conn);
$displayColumns = array_values(array_filter($allColumns, function($c){ return (int)$c['is_deleted'] === 0; }));
$visibleColumns = array_values(array_filter($displayColumns, function($c){ return (int)$c['is_hidden'] === 0; }));
$rateListSections = load_haleeb_rate_lists_local($conn);
$latestRateListName = latest_haleeb_rate_list_name_local($conn);
$rowsByList = [];
$rowsRes = $conn->query("SELECT id, COALESCE(NULLIF(rate_list_name,''), 'Base List') AS rate_list_name, source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, custom_to, custom_mazda, custom_14ft, custom_20ft, custom_40ft_22t, custom_40ft_28t, custom_40ft_32t, extra_data, created_at FROM haleeb_image_processed_rates ORDER BY CASE WHEN COALESCE(NULLIF(rate_list_name,''), 'Base List')='Base List' THEN 0 ELSE 1 END, rate_list_name ASC, id DESC");
while($rowsRes && $rateRow = $rowsRes->fetch_assoc()){
  $listName = normalize_rate_list_name_local($rateRow['rate_list_name'] ?? 'Base List');
  if(!isset($rowsByList[$listName])) $rowsByList[$listName] = [];
  $rowsByList[$listName][] = $rateRow;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Rate List</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/mobile.css">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --green: #22c55e; --red: #ef4444; --blue: #60a5fa;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 28px; border-bottom: 1px solid var(--border); background: var(--surface); position: sticky; top: 0; z-index: 100; flex-wrap: wrap; gap: 10px; }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge { background: var(--accent); color: #0e0f11; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
  .nav-links { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .nav-btn { padding: 8px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border); cursor: pointer; text-decoration: none; font-family: var(--font); font-size: 13px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .nav-btn.primary { background: var(--accent); color: #0e0f11; border-color: var(--accent); }
  .nav-btn.primary:hover { background: #e0b030; }
  .nav-btn.danger { background: rgba(239,68,68,0.1); color: var(--red); border-color: rgba(239,68,68,0.25); }
  .nav-btn.danger:hover { background: rgba(239,68,68,0.2); }
  .menu-wrap { position: relative; }
  .menu-toggle { width: 38px; height: 36px; display: inline-flex; align-items: center; justify-content: center; padding: 0; font-size: 18px; line-height: 1; }
  .menu-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 8px); min-width: 170px; background: var(--surface2); border: 1px solid var(--border); z-index: 120; }
  .menu-dropdown.open { display: block; }
  .menu-item { width: 100%; display: block; text-align: left; padding: 10px 12px; background: transparent; color: var(--text); border: none; border-bottom: 1px solid var(--border); text-decoration: none; font-family: var(--font); font-size: 12px; cursor: pointer; }
  .menu-item:last-child { border-bottom: none; }
  .menu-item:hover { background: rgba(255,255,255,0.04); }
  .menu-item.danger { color: var(--red); }
  .menu-import-form { margin: 0; }
  .menu-import-input { display: none; }

  .main { padding: 24px 28px; max-width: 1600px; margin: 0 auto; }
  .alert { padding: 12px 16px; margin-bottom: 16px; font-size: 13px; border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green); }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }

  /* COLLAPSIBLE PANELS */
  .panel { background: var(--surface); border: 1px solid var(--border); margin-bottom: 14px; }
  .panel-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; cursor: pointer; user-select: none; }
  .panel-head:hover { background: var(--surface2); }
  .panel-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  .panel-toggle { font-size: 18px; color: var(--muted); line-height: 1; transition: transform 0.2s; }
  .panel-toggle.open { transform: rotate(45deg); }
  .panel-body { display: none; padding: 16px 18px; border-top: 1px solid var(--border); }
  .panel-body.open { display: block; }

  /* COLUMN SETTINGS */
  .add-col-row { display: flex; gap: 8px; align-items: center; margin-bottom: 14px; }
  .add-col-row input { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 9px 12px; font-family: var(--font); font-size: 13px; width: 260px; }
  .add-col-row select { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 9px 12px; font-family: var(--font); font-size: 13px; min-width: 170px; }
  .add-col-row input:focus { outline: none; border-color: var(--accent); }
  .add-col-row select:focus { outline: none; border-color: var(--accent); }
  .col-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; margin-bottom: 14px; }
  .col-item { background: var(--surface2); border: 1px solid var(--border); padding: 12px; }
  .col-item-key { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; font-family: var(--mono); }
  .col-item input[type="text"] { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 7px 10px; font-family: var(--font); font-size: 13px; margin-bottom: 8px; }
  .col-item input[type="text"]:focus { outline: none; border-color: var(--accent); }
  .col-item-footer { display: flex; align-items: center; justify-content: space-between; }
  .check-label { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); cursor: pointer; }
  .check-label input[type="checkbox"] { accent-color: var(--accent); width: 14px; height: 14px; }
  .btn-del-col { padding: 5px 10px; background: rgba(239,68,68,0.1); color: var(--red); border: 1px solid rgba(239,68,68,0.2); cursor: pointer; font-family: var(--font); font-size: 11px; font-weight: 700; transition: all 0.15s; }
  .btn-del-col:hover { background: rgba(239,68,68,0.2); }

  /* TABLE */
  .table-wrap { background: var(--surface); border: 1px solid var(--border); overflow-x: auto; }
  .tbl-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); }
  .tbl-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  table { width: 100%; border-collapse: collapse; min-width: 600px; }
  thead tr { background: var(--surface2); }
  th { padding: 11px 12px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
  td { padding: 9px 12px; font-size: 13px; border-bottom: 1px solid rgba(42,45,53,0.7); font-family: var(--mono); color: var(--text); }
  tbody tr { transition: background 0.1s; }
  tbody tr:hover { background: var(--surface2); }
  .edit-row td { background: rgba(240,192,64,0.04); }
  .edit-input { width: 100%; background: var(--bg); border: 1px solid var(--accent); color: var(--text); padding: 6px 8px; font-family: var(--mono); font-size: 12px; }
  .edit-input:focus { outline: none; }

  .act-btn { width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 13px; border: 1px solid transparent; transition: all 0.15s; cursor: pointer; margin-right: 3px; }
  .act-view { background: rgba(96,165,250,0.12); color: var(--blue); border-color: rgba(96,165,250,0.25); }
  .act-view:hover { background: rgba(96,165,250,0.25); }
  .act-edit { background: rgba(240,192,64,0.12); color: var(--accent); border-color: rgba(240,192,64,0.25); }
  .act-edit:hover { background: rgba(240,192,64,0.25); }
  .act-save { background: rgba(34,197,94,0.12); color: var(--green); border-color: rgba(34,197,94,0.25); padding: 6px 12px; width: auto; height: auto; font-family: var(--font); font-size: 12px; font-weight: 700; }
  .act-save:hover { background: rgba(34,197,94,0.25); }
  .act-cancel { background: var(--surface2); color: var(--muted); border-color: var(--border); padding: 6px 10px; width: auto; height: auto; font-family: var(--font); font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; }
  .act-cancel:hover { color: var(--text); }
  .act-delete { background: rgba(239,68,68,0.12); color: var(--red); border-color: rgba(239,68,68,0.25); padding: 6px 10px; width: auto; height: auto; font-family: var(--font); font-size: 12px; font-weight: 700; }
  .act-delete:hover { background: rgba(239,68,68,0.25); }
  .th-action { text-align: center; width: 80px; white-space: nowrap; }
  .td-action { text-align: center; white-space: nowrap; }
  .td-date { color: var(--muted); font-size: 11px; }
  .list-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-bottom: 14px; }
  .list-card { background: var(--surface); border: 1px solid var(--border); padding: 12px; }
  .list-card.current { border-color: rgba(34,197,94,0.55); }
  .list-name { font-size: 12px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
  .list-meta { font-size: 11px; color: var(--muted); font-family: var(--mono); }
  .tbl-badge { display: inline-flex; margin-left: 8px; padding: 2px 6px; font-size: 10px; border: 1px solid rgba(34,197,94,0.5); color: #86efac; }

  @media(max-width: 640px) {
    .topbar { padding: 14px 16px; }
    .main { padding: 16px; }
    .add-col-row input { width: 180px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-logo">
    <span class="badge">Rate List</span>
    <h1>Rates</h1>
  </div>
  <div class="nav-links">
    <a class="nav-btn" href="haleeb.php">Haleeb</a>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <div class="menu-wrap">
      <button type="button" class="nav-btn menu-toggle" id="menu_toggle" aria-label="Menu" aria-expanded="false">&#9776;</button>
      <div class="menu-dropdown" id="menu_dropdown">
        <form class="menu-import-form" action="import_haleeb_ratelist.php" method="post" enctype="multipart/form-data" id="menu_import_form">
          <input class="menu-import-input" id="menu_import_file" type="file" name="csv_file" accept=".csv" required>
          <button class="menu-item" type="button" id="menu_import_btn">Import</button>
        </form>
        <button class="menu-item" type="button" id="menu_rate_change_btn">Create New List</button>
        <button class="menu-item" type="button" id="menu_tender_sync_btn">Tender Sync</button>
        <a class="menu-item" href="export_haleeb_ratelist.php">Export</a>
        <a class="menu-item danger" href="haleeb_ratelist.php?delete_all=1" onclick="return confirm('Delete entire rate list?')">Clear List</a>
      </div>
    </div>
  </div>
</div>

<div class="main">
  <?php if($msg !== ""): ?>
    <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>
  <?php if($err !== ""): ?>
    <div class="alert error"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>
  <div class="list-grid">
    <?php foreach($rateListSections as $section): ?>
      <?php $sectionName = normalize_rate_list_name_local($section['list_name']); ?>
      <div class="list-card <?php echo $sectionName === $latestRateListName ? 'current' : ''; ?>">
        <div class="list-name"><?php echo htmlspecialchars($sectionName); ?><?php if($sectionName === $latestRateListName): ?><span class="tbl-badge">Current</span><?php endif; ?></div>
        <div class="list-meta">Rows: <?php echo (int)$section['row_count']; ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- COLUMN SETTINGS -->
  <div class="panel">
    <div class="panel-head" id="col_head">
      <span class="panel-title">Columns</span>
      <span class="panel-toggle" id="col_toggle">+</span>
    </div>
    <div class="panel-body" id="col_body">
      <form method="post">
        <div class="add-col-row">
          <input type="text" name="new_column_label" placeholder="Column name">
          <button class="nav-btn primary" type="submit" name="add_column">Add Column</button>
        </div>
      </form>
      <form method="post">
        <div class="col-grid">
          <?php foreach($allColumns as $c):
            if((int)$c['is_deleted'] === 1) continue;
          ?>
          <div class="col-item">
            <div class="col-item-key"><?php echo htmlspecialchars($c['column_key']); ?></div>
            <input type="text" name="label_<?php echo htmlspecialchars($c['column_key']); ?>" value="<?php echo htmlspecialchars($c['column_label']); ?>">
            <div class="col-item-footer">
              <label class="check-label">
                <input type="checkbox" name="hide_<?php echo htmlspecialchars($c['column_key']); ?>" <?php echo (int)$c['is_hidden'] === 1 ? 'checked' : ''; ?>>
                Hide column
              </label>
              <?php if((int)$c['is_base'] === 0): ?>
                <button class="btn-del-col" type="submit" name="delete_column" value="1"
                  onclick="document.getElementById('delete_col_key').value='<?php echo htmlspecialchars($c['column_key']); ?>'; return confirm('Delete this column?')">
                  Delete
                </button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="delete_col_key" name="column_key" value="">
        <button class="nav-btn primary" type="submit" name="save_columns">Save Columns</button>
      </form>
    </div>
  </div>

  <!-- RATE CHANGE -->
  <div class="panel">
    <div class="panel-head" id="rc_head">
      <span class="panel-title">Create New List</span>
      <span class="panel-toggle <?php echo $openRateChange ? 'open' : ''; ?>" id="rc_toggle">+</span>
    </div>
    <div class="panel-body <?php echo $openRateChange ? 'open' : ''; ?>" id="rc_body">
      <form method="post">
        <div class="add-col-row" style="flex-wrap:wrap;">
          <select name="rc_source_list" required>
            <?php foreach($rateListSections as $section): $sectionName = normalize_rate_list_name_local($section['list_name']); ?>
              <option value="<?php echo htmlspecialchars($sectionName); ?>" <?php echo ($rateChangeSourceList !== '' ? $rateChangeSourceList : $latestRateListName) === $sectionName ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($sectionName); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="number" step="any" min="0" id="rc_petrol_old" name="rc_petrol_old" placeholder="Old petrol rate" value="<?php echo htmlspecialchars($rateChangePetrolOld); ?>">
          <input type="number" step="any" min="0" id="rc_petrol_new" name="rc_petrol_new" placeholder="New petrol rate" value="<?php echo htmlspecialchars($rateChangePetrolNew); ?>">
          <select id="rc_mode" name="rc_mode" required>
            <option value="increment" <?php echo $rateChangeMode === 'increment' ? 'selected' : ''; ?>>Increase</option>
            <option value="decrement" <?php echo $rateChangeMode === 'decrement' ? 'selected' : ''; ?>>Decrease</option>
          </select>
          <input type="number" step="any" min="0" id="rc_percent" name="rc_percent" placeholder="Percent e.g. 2" value="<?php echo htmlspecialchars($rateChangePercent); ?>" required>
          <input type="text" name="rc_new_column_label" placeholder="New list name" value="<?php echo htmlspecialchars($rateChangeLabel); ?>" required>
          <button class="nav-btn primary" type="submit" name="apply_rate_change">Create List</button>
        </div>
        <div style="font-size:12px;color:var(--muted);">
          Yeh action selected source list se ek nayi separate list create karta hai. Numeric rate values par percent apply hota hai. Old/New petrol se percent auto-calculate hota hai, manual percent bhi de sakte hain.
        </div>
      </form>
    </div>
  </div>

  <!-- HALEEB TENDER SYNC -->
  <div class="panel">
    <div class="panel-head" id="ts_head">
      <span class="panel-title">Haleeb Tender Sync</span>
      <span class="panel-toggle <?php echo $openTenderSync ? 'open' : ''; ?>" id="ts_toggle">+</span>
    </div>
    <div class="panel-body <?php echo $openTenderSync ? 'open' : ''; ?>" id="ts_body">
      <form method="post">
        <div class="add-col-row" style="flex-wrap:wrap;">
          <input type="date" name="ts_date_from" value="<?php echo htmlspecialchars($tenderSyncDateFrom); ?>" required>
          <input type="date" name="ts_date_to" value="<?php echo htmlspecialchars($tenderSyncDateTo); ?>" required>
          <button class="nav-btn primary" type="submit" name="apply_haleeb_tender_sync" onclick="return confirm('Selected date range ki Haleeb bilties ka tender current rate list se update karna hai?')">Sync Tenders</button>
        </div>
        <div style="font-size:12px;color:var(--muted);">
          Selected date range ki Haleeb bilties ke tender, current Haleeb rate list ke location + vehicle type match ke mutabiq update honge.
        </div>
      </form>
    </div>
  </div>

  <!-- ADD ROW -->
  <div class="panel">
    <div class="panel-head" id="add_head">
      <span class="panel-title">Add Row</span>
      <span class="panel-toggle <?php echo $openAddRow ? 'open' : ''; ?>" id="add_toggle">+</span>
    </div>
    <div class="panel-body <?php echo $openAddRow ? 'open' : ''; ?>" id="add_body">
      <form method="post" style="overflow-x:auto;">
        <div class="add-col-row" style="margin-bottom:10px;">
          <select name="target_rate_list_name" required>
            <?php foreach($rateListSections as $section): $sectionName = normalize_rate_list_name_local($section['list_name']); ?>
              <option value="<?php echo htmlspecialchars($sectionName); ?>" <?php echo $sectionName === $latestRateListName ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($sectionName); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <table>
          <thead>
            <tr>
              <?php foreach($visibleColumns as $c): ?>
                <th><?php echo htmlspecialchars($c['column_label']); ?></th>
              <?php endforeach; ?>
              <th class="th-action">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <?php foreach($visibleColumns as $c): ?>
                <td><input class="edit-input" type="text" name="add_col_<?php echo htmlspecialchars($c['column_key']); ?>" value=""></td>
              <?php endforeach; ?>
              <td class="td-action">
                <button class="act-btn act-save" type="submit" name="add_rate">Add Row</button>
              </td>
            </tr>
          </tbody>
        </table>
      </form>
    </div>
  </div>
  <!-- DATA TABLE -->
  <?php foreach($rateListSections as $section): ?>
    <?php $sectionName = normalize_rate_list_name_local($section['list_name']); ?>
    <div class="table-wrap" style="margin-bottom:14px;">
      <div class="tbl-header">
        <span class="tbl-title"><?php echo htmlspecialchars($sectionName); ?><?php if($sectionName === $latestRateListName): ?><span class="tbl-badge">Current</span><?php endif; ?></span>
      </div>
      <table>
        <thead>
          <tr>
            <?php foreach($visibleColumns as $c): ?>
              <th><?php echo htmlspecialchars($c['column_label']); ?></th>
            <?php endforeach; ?>
            <th>Added</th>
            <th class="th-action">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $sectionRows = isset($rowsByList[$sectionName]) ? $rowsByList[$sectionName] : []; ?>
          <?php if(count($sectionRows) === 0): ?>
            <tr><td colspan="<?php echo count($visibleColumns) + 2; ?>" style="color:var(--muted);">No rows in this list.</td></tr>
          <?php endif; ?>
          <?php foreach($sectionRows as $r):
            $extra = [];
            if(isset($r['extra_data']) && $r['extra_data'] !== ''){
              $decoded = json_decode($r['extra_data'], true);
              if(is_array($decoded)) $extra = $decoded;
            }
          ?>
            <?php if($editingId === (int)$r['id']): ?>
              <tr class="edit-row">
                <form method="post">
                  <?php foreach($visibleColumns as $c):
                    $key = $c['column_key'];
                    $val = array_key_exists($key, $r) ? (string)$r[$key] : ($extra[$key] ?? '');
                  ?>
                    <td><input class="edit-input" type="text" name="col_<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>"></td>
                  <?php endforeach; ?>
                  <td class="td-date"><?php echo htmlspecialchars($r['created_at']); ?></td>
                  <td class="td-action">
                    <input type="hidden" name="edit_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="rate_list_name" value="<?php echo htmlspecialchars($sectionName); ?>">
                    <?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''): ?>
                      <a class="act-btn act-view" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="View">&#128065;</a>
                    <?php endif; ?>
                    <button class="act-btn act-save" type="submit" name="update_rate">Save</button>
                    <button class="act-btn act-delete" type="submit" name="delete_rate" onclick="return confirm('Delete this row?')">Delete</button>
                    <a class="act-btn act-cancel" href="haleeb_ratelist.php">x</a>
                  </td>
                </form>
              </tr>
            <?php else: ?>
              <tr>
                <?php foreach($visibleColumns as $c):
                  $key = $c['column_key'];
                  $val = array_key_exists($key, $r) ? (string)$r[$key] : ($extra[$key] ?? '');
                ?>
                  <td><?php echo htmlspecialchars($val); ?></td>
                <?php endforeach; ?>
                <td class="td-date"><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td class="td-action">
                  <?php if(isset($r['source_image_path']) && $r['source_image_path'] !== ''): ?>
                    <a class="act-btn act-view" href="<?php echo htmlspecialchars($r['source_image_path']); ?>" target="_blank" title="View">&#128065;</a>
                  <?php endif; ?>
                  <a class="act-btn act-edit" href="haleeb_ratelist.php?edit_id=<?php echo (int)$r['id']; ?>" title="Edit">&#9998;</a>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  function initToggle(headId, toggleId, bodyId){
    var head = document.getElementById(headId);
    var toggle = document.getElementById(toggleId);
    var body = document.getElementById(bodyId);
    if(!head || !toggle || !body) return;
    head.addEventListener('click', function(){
      var isOpen = body.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
    });
  }
  initToggle('col_head', 'col_toggle', 'col_body');
  initToggle('rc_head', 'rc_toggle', 'rc_body');
  initToggle('ts_head', 'ts_toggle', 'ts_body');
  initToggle('add_head', 'add_toggle', 'add_body');

  var menuToggle = document.getElementById('menu_toggle');
  var menuDropdown = document.getElementById('menu_dropdown');
  var importBtn = document.getElementById('menu_import_btn');
  var rateChangeBtn = document.getElementById('menu_rate_change_btn');
  var tenderSyncBtn = document.getElementById('menu_tender_sync_btn');
  var importFile = document.getElementById('menu_import_file');
  var importForm = document.getElementById('menu_import_form');

  if(menuToggle && menuDropdown){
    menuToggle.addEventListener('click', function(e){
      e.stopPropagation();
      var isOpen = menuDropdown.classList.toggle('open');
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function(e){
      if(!menuDropdown.contains(e.target) && e.target !== menuToggle){
        menuDropdown.classList.remove('open');
        menuToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  if(importBtn && importFile && importForm){
    importBtn.addEventListener('click', function(){ importFile.click(); });
    importFile.addEventListener('change', function(){
      if(importFile.files && importFile.files.length > 0) importForm.submit();
    });
  }
  var rcPetrolOld = document.getElementById('rc_petrol_old');
  var rcPetrolNew = document.getElementById('rc_petrol_new');
  var rcMode = document.getElementById('rc_mode');
  var rcPercent = document.getElementById('rc_percent');
  function autoCalcRateChange(){
    if(!rcPetrolOld || !rcPetrolNew || !rcMode || !rcPercent) return;
    var oldValue = parseFloat(rcPetrolOld.value);
    var newValue = parseFloat(rcPetrolNew.value);
    if(!isFinite(oldValue) || !isFinite(newValue) || oldValue <= 0) return;
    var percentChange = ((newValue - oldValue) / oldValue) * 100;
    rcMode.value = percentChange >= 0 ? 'increment' : 'decrement';
    var halfPercent = Math.abs(percentChange) / 2;
    rcPercent.value = halfPercent.toFixed(4).replace(/\.?0+$/, '');
  }
  if(rcPetrolOld && rcPetrolNew){
    rcPetrolOld.addEventListener('input', autoCalcRateChange);
    rcPetrolNew.addEventListener('input', autoCalcRateChange);
    autoCalcRateChange();
  }

  if(rateChangeBtn){
    rateChangeBtn.addEventListener('click', function(){
      var body = document.getElementById('rc_body');
      var toggle = document.getElementById('rc_toggle');
      if(body){
        body.classList.add('open');
        if(toggle) toggle.classList.add('open');
      }
      if(menuDropdown){
        menuDropdown.classList.remove('open');
        if(menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }
  if(tenderSyncBtn){
    tenderSyncBtn.addEventListener('click', function(){
      var body = document.getElementById('ts_body');
      var toggle = document.getElementById('ts_toggle');
      if(body){
        body.classList.add('open');
        if(toggle) toggle.classList.add('open');
      }
      if(menuDropdown){
        menuDropdown.classList.remove('open');
        if(menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }
})();
</script>
</body>
</html>



