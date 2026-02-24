<?php
$host="localhost";
$user="root";
$pass="";
$db="cargo_system";

$conn=new mysqli($host,$user,$pass);
$conn->query("CREATE DATABASE IF NOT EXISTS $db");
$conn->select_db($db);

$conn->query("CREATE TABLE IF NOT EXISTS users(
id INT AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(50),
password VARCHAR(50)
)");

$conn->query("CREATE TABLE IF NOT EXISTS bilty(
id INT AUTO_INCREMENT PRIMARY KEY,
sr_no VARCHAR(50),
date DATE,
vehicle VARCHAR(50),
bilty_no VARCHAR(50),
party VARCHAR(100),
location VARCHAR(100),
freight INT,
original_freight INT NULL,
tender INT,
profit INT
)");

$colCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'party'");
if($colCheck && $colCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD party VARCHAR(100) AFTER bilty_no");
}

$srColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'sr_no'");
if($srColCheck && $srColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD sr_no VARCHAR(50) AFTER id");
}

$origFreightColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'original_freight'");
if($origFreightColCheck && $origFreightColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD original_freight INT NULL AFTER freight");
}

$conn->query("CREATE TABLE IF NOT EXISTS account_entries(
id INT AUTO_INCREMENT PRIMARY KEY,
entry_date DATE,
category VARCHAR(20),
entry_type VARCHAR(10),
amount_mode VARCHAR(10),
bilty_id INT NULL,
amount DECIMAL(12,2),
note VARCHAR(255),
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$modeColCheck = $conn->query("SHOW COLUMNS FROM account_entries LIKE 'amount_mode'");
if($modeColCheck && $modeColCheck->num_rows === 0){
$conn->query("ALTER TABLE account_entries ADD amount_mode VARCHAR(10) NOT NULL DEFAULT 'cash' AFTER entry_type");
}

$biltyColCheck = $conn->query("SHOW COLUMNS FROM account_entries LIKE 'bilty_id'");
if($biltyColCheck && $biltyColCheck->num_rows === 0){
$conn->query("ALTER TABLE account_entries ADD bilty_id INT NULL AFTER amount_mode");
}

$conn->query("CREATE TABLE IF NOT EXISTS image_processed_rates(
id INT AUTO_INCREMENT PRIMARY KEY,
source_file VARCHAR(255),
source_image_path VARCHAR(255),
sr_no VARCHAR(50),
station_english VARCHAR(255),
station_urdu VARCHAR(255),
rate_2026_01_01 VARCHAR(100),
rate_2026_01_02 VARCHAR(100),
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$sourceImgColCheck = $conn->query("SHOW COLUMNS FROM image_processed_rates LIKE 'source_image_path'");
if($sourceImgColCheck && $sourceImgColCheck->num_rows === 0){
$conn->query("ALTER TABLE image_processed_rates ADD source_image_path VARCHAR(255) AFTER source_file");
}

// Best-effort backfill for legacy data where freight was reduced after payments.
$conn->query("UPDATE bilty b
LEFT JOIN (
SELECT bilty_id, SUM(amount) AS paid_total
FROM account_entries
WHERE bilty_id IS NOT NULL AND entry_type='debit'
GROUP BY bilty_id
) p ON p.bilty_id = b.id
SET b.original_freight = b.freight + COALESCE(p.paid_total, 0)
WHERE b.original_freight IS NULL");

$check=$conn->query("SELECT * FROM users WHERE username='admin'");
if($check->num_rows==0){
$conn->query("INSERT INTO users(username,password) VALUES('admin','1234')");
}
?>
