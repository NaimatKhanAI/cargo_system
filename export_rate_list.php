<?php
session_start();
if(!isset($_SESSION['user'])){
header("location:index.php");
exit();
}

include 'config/db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=rate_list_' . date('Y-m-d_H-i-s') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['source_file', 'source_image_path', 'sr_no', 'station_english', 'station_urdu', 'rate1', 'rate2', 'created_at']);

$result = $conn->query("SELECT source_file, source_image_path, sr_no, station_english, station_urdu, rate1, rate2, created_at FROM image_processed_rates ORDER BY id DESC");
while($row = $result->fetch_assoc()){
fputcsv($output, $row);
}

fclose($output);
exit();
?>
