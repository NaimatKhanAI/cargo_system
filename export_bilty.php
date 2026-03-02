<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
auth_require_login($conn);
auth_require_module_access('feed');
auth_require_super_admin('dashboard.php');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=bilty_entries_' . date('Y-m-d_H-i-s') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['id', 'sr_no', 'date', 'vehicle', 'bilty_no', 'party', 'feed_portion', 'location', 'bags', 'freight', 'commission', 'tender', 'profit']);

$result = $conn->query("SELECT id, sr_no, date, vehicle, bilty_no, party, feed_portion, location, bags, freight, commission, tender, profit FROM bilty ORDER BY id DESC");

while($row = $result->fetch_assoc()){
fputcsv($output, $row);
}

fclose($output);
exit();
?>
