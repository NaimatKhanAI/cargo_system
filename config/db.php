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
bags INT DEFAULT 0,
freight INT,
original_freight INT NULL,
tender INT,
profit INT
)");

$conn->query("CREATE TABLE IF NOT EXISTS haleeb_bilty(
id INT AUTO_INCREMENT PRIMARY KEY,
date DATE,
vehicle VARCHAR(50),
vehicle_type VARCHAR(50),
delivery_note VARCHAR(100),
token_no VARCHAR(50),
party VARCHAR(100),
location VARCHAR(100),
freight INT,
tender INT,
profit INT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

$bagsColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'bags'");
if($bagsColCheck && $bagsColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD bags INT DEFAULT 0 AFTER location");
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

$haleebBiltyColCheck = $conn->query("SHOW COLUMNS FROM account_entries LIKE 'haleeb_bilty_id'");
if($haleebBiltyColCheck && $haleebBiltyColCheck->num_rows === 0){
$conn->query("ALTER TABLE account_entries ADD haleeb_bilty_id INT NULL AFTER bilty_id");
}

$conn->query("CREATE TABLE IF NOT EXISTS image_processed_rates(
id INT AUTO_INCREMENT PRIMARY KEY,
source_file VARCHAR(255),
source_image_path VARCHAR(255),
sr_no VARCHAR(50),
station_english VARCHAR(255),
station_urdu VARCHAR(255),
rate1 VARCHAR(100),
rate2 VARCHAR(100),
extra_data TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$sourceImgColCheck = $conn->query("SHOW COLUMNS FROM image_processed_rates LIKE 'source_image_path'");
if($sourceImgColCheck && $sourceImgColCheck->num_rows === 0){
$conn->query("ALTER TABLE image_processed_rates ADD source_image_path VARCHAR(255) AFTER source_file");
}

$extraDataColCheck = $conn->query("SHOW COLUMNS FROM image_processed_rates LIKE 'extra_data'");
if($extraDataColCheck && $extraDataColCheck->num_rows === 0){
$conn->query("ALTER TABLE image_processed_rates ADD extra_data TEXT AFTER rate2");
}

$rate1ColCheck = $conn->query("SHOW COLUMNS FROM image_processed_rates LIKE 'rate1'");
if($rate1ColCheck && $rate1ColCheck->num_rows === 0){
$conn->query("ALTER TABLE image_processed_rates ADD rate1 VARCHAR(100) AFTER station_urdu");
}

$rate2ColCheck = $conn->query("SHOW COLUMNS FROM image_processed_rates LIKE 'rate2'");
if($rate2ColCheck && $rate2ColCheck->num_rows === 0){
$conn->query("ALTER TABLE image_processed_rates ADD rate2 VARCHAR(100) AFTER rate1");
}

$oldR1ColCheck = $conn->query("SHOW COLUMNS FROM image_processed_rates LIKE 'rate_2026_01_01'");
if($oldR1ColCheck && $oldR1ColCheck->num_rows > 0){
$conn->query("UPDATE image_processed_rates SET rate1 = COALESCE(NULLIF(rate1,''), rate_2026_01_01) WHERE rate_2026_01_01 IS NOT NULL");
}

$oldR2ColCheck = $conn->query("SHOW COLUMNS FROM image_processed_rates LIKE 'rate_2026_01_02'");
if($oldR2ColCheck && $oldR2ColCheck->num_rows > 0){
$conn->query("UPDATE image_processed_rates SET rate2 = COALESCE(NULLIF(rate2,''), rate_2026_01_02) WHERE rate_2026_01_02 IS NOT NULL");
}

$conn->query("CREATE TABLE IF NOT EXISTS rate_list_columns(
id INT AUTO_INCREMENT PRIMARY KEY,
column_key VARCHAR(100) UNIQUE,
column_label VARCHAR(255),
is_hidden TINYINT(1) DEFAULT 0,
is_deleted TINYINT(1) DEFAULT 0,
display_order INT DEFAULT 0,
is_base TINYINT(1) DEFAULT 1,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS app_settings(
id INT AUTO_INCREMENT PRIMARY KEY,
setting_key VARCHAR(100) UNIQUE,
setting_value TEXT,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Legacy migration: normalize old keys and remove any base-lock.
$conn->query("UPDATE rate_list_columns SET column_key='rate1' WHERE column_key='rate_2026_01_01'");
$conn->query("UPDATE rate_list_columns SET column_key='rate2' WHERE column_key='rate_2026_01_02'");
$conn->query("UPDATE rate_list_columns SET is_base=0");

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
