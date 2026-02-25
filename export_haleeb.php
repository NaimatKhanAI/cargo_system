<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=haleeb_entries_' . date('Y-m-d_H-i-s') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['id', 'date', 'vehicle', 'vehicle_type', 'delivery_note', 'token_no', 'party', 'location', 'freight', 'tender', 'profit']);

$result = $conn->query("SELECT id, date, vehicle, vehicle_type, delivery_note, token_no, party, location, freight, tender, profit FROM haleeb_bilty ORDER BY id DESC");

while($row = $result->fetch_assoc()){
fputcsv($output, $row);
}

fclose($output);
exit();
?>
