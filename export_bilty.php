<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=bilty_entries_' . date('Y-m-d_H-i-s') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['id', 'sr_no', 'date', 'vehicle', 'bilty_no', 'party', 'location', 'freight', 'tender', 'profit']);

$result = $conn->query("SELECT id, sr_no, date, vehicle, bilty_no, party, location, freight, tender, profit FROM bilty ORDER BY id DESC");

while($row = $result->fetch_assoc()){
fputcsv($output, $row);
}

fclose($output);
exit();
?>
