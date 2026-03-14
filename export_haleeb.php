<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
auth_require_login($conn);
auth_require_module_access('haleeb');
auth_require_super_admin('dashboard.php');

$idsRaw = '';
if(isset($_POST['ids'])){ $idsRaw = (string)$_POST['ids']; }
elseif(isset($_GET['ids'])){ $idsRaw = (string)$_GET['ids']; }
$ids = [];
if(trim($idsRaw) !== ''){
    $parts = preg_split('/[,\s]+/', $idsRaw);
    foreach($parts as $p){
        $v = (int)trim((string)$p);
        if($v > 0) $ids[$v] = true;
    }
    $ids = array_keys($ids);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=haleeb_entries_' . date('Y-m-d_H-i-s') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['id', 'date', 'vehicle', 'vehicle_type', 'delivery_note', 'token_no', 'party', 'location', 'stops', 'freight', 'commission', 'freight_payment_type', 'tender', 'profit']);

$baseSql = "SELECT id, date, vehicle, vehicle_type, delivery_note, token_no, party, location, stops, freight, commission, COALESCE(NULLIF(freight_payment_type, ''), 'to_pay') AS freight_payment_type, tender, profit FROM haleeb_bilty";
if(count($ids) > 0){
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = $baseSql . " WHERE id IN (" . $placeholders . ") ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($ids));
    $params = [$types];
    foreach($ids as $k => $v){ $params[] = &$ids[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($baseSql . " ORDER BY id DESC");
}

while($result && $row = $result->fetch_assoc()){
    fputcsv($output, $row);
}

if(isset($stmt) && $stmt){ $stmt->close(); }
fclose($output);
exit();
?>
