<?php
require_once __DIR__ . '/env.php';
load_env_file(dirname(__DIR__) . '/.env');

$host = env_get('DB_HOST', 'localhost');
$user = env_get('DB_USER', 'root');
$pass = env_get('DB_PASS', '');
$db = env_get('DB_NAME', 'cargo_system');
$port = (int)env_get('DB_PORT', '3306');
$autoCreateDb = env_get('DB_AUTO_CREATE', '0') === '1';

$db = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$db);
if($db === ''){
http_response_code(500);
exit('Invalid DB_NAME configuration.');
}

if($autoCreateDb){
$conn = @new mysqli($host, $user, $pass, '', $port);
if($conn->connect_errno){
http_response_code(500);
exit('Database connection failed. Check DB_HOST, DB_PORT, DB_USER and DB_PASS.');
}
$conn->query("CREATE DATABASE IF NOT EXISTS `$db`");
if(!$conn->select_db($db)){
http_response_code(500);
exit('Database selection failed. Create DB manually or disable DB_AUTO_CREATE.');
}
} else {
$conn = @new mysqli($host, $user, $pass, $db, $port);
if($conn->connect_errno){
http_response_code(500);
exit('Database connection failed. Check DB_HOST, DB_PORT, DB_USER, DB_PASS and DB_NAME.');
}
}

$conn->set_charset('utf8mb4');

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
stops VARCHAR(50) DEFAULT '',
freight INT,
tender INT,
profit INT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$haleebStopsColCheck = $conn->query("SHOW COLUMNS FROM haleeb_bilty LIKE 'stops'");
if($haleebStopsColCheck && $haleebStopsColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_bilty ADD stops VARCHAR(50) DEFAULT '' AFTER location");
}

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

$conn->query("CREATE TABLE IF NOT EXISTS haleeb_image_processed_rates(
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

$haleebSourceImgColCheck = $conn->query("SHOW COLUMNS FROM haleeb_image_processed_rates LIKE 'source_image_path'");
if($haleebSourceImgColCheck && $haleebSourceImgColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_image_processed_rates ADD source_image_path VARCHAR(255) AFTER source_file");
}

$haleebExtraDataColCheck = $conn->query("SHOW COLUMNS FROM haleeb_image_processed_rates LIKE 'extra_data'");
if($haleebExtraDataColCheck && $haleebExtraDataColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_image_processed_rates ADD extra_data TEXT AFTER rate2");
}

$haleebRate1ColCheck = $conn->query("SHOW COLUMNS FROM haleeb_image_processed_rates LIKE 'rate1'");
if($haleebRate1ColCheck && $haleebRate1ColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_image_processed_rates ADD rate1 VARCHAR(100) AFTER station_urdu");
}

$haleebRate2ColCheck = $conn->query("SHOW COLUMNS FROM haleeb_image_processed_rates LIKE 'rate2'");
if($haleebRate2ColCheck && $haleebRate2ColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_image_processed_rates ADD rate2 VARCHAR(100) AFTER rate1");
}

$haleebCustomColumns = [
    'custom_to',
    'custom_mazda',
    'custom_14ft',
    'custom_20ft',
    'custom_40ft_22t',
    'custom_40ft_28t',
    'custom_40ft_32t',
];
foreach($haleebCustomColumns as $customCol){
    $customColCheck = $conn->query("SHOW COLUMNS FROM haleeb_image_processed_rates LIKE '$customCol'");
    if($customColCheck && $customColCheck->num_rows === 0){
        $conn->query("ALTER TABLE haleeb_image_processed_rates ADD $customCol VARCHAR(255) AFTER rate2");
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS haleeb_rate_list_columns(
id INT AUTO_INCREMENT PRIMARY KEY,
column_key VARCHAR(100) UNIQUE,
column_label VARCHAR(255),
is_hidden TINYINT(1) DEFAULT 0,
is_deleted TINYINT(1) DEFAULT 0,
display_order INT DEFAULT 0,
is_base TINYINT(1) DEFAULT 1,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$haleebBaseColumns = [
    ['key' => 'custom_to', 'label' => 'To', 'order' => 1],
    ['key' => 'custom_mazda', 'label' => 'Mazda', 'order' => 2],
    ['key' => 'custom_14ft', 'label' => '14ft', 'order' => 3],
    ['key' => 'custom_20ft', 'label' => '20ft', 'order' => 4],
    ['key' => 'custom_40ft_22t', 'label' => '40ft@22T', 'order' => 5],
    ['key' => 'custom_40ft_28t', 'label' => '40ft@28T', 'order' => 6],
    ['key' => 'custom_40ft_32t', 'label' => '40ft@32T', 'order' => 7],
];

$haleebColumnSelect = $conn->prepare("SELECT id FROM haleeb_rate_list_columns WHERE column_key=? LIMIT 1");
$haleebColumnUpdate = $conn->prepare("UPDATE haleeb_rate_list_columns SET column_label=?, is_hidden=0, is_deleted=0, display_order=?, is_base=1 WHERE column_key=?");
$haleebColumnInsert = $conn->prepare("INSERT INTO haleeb_rate_list_columns(column_key, column_label, is_hidden, is_deleted, display_order, is_base) VALUES(?, ?, 0, 0, ?, 1)");

foreach($haleebBaseColumns as $col){
    $haleebColumnSelect->bind_param("s", $col['key']);
    $haleebColumnSelect->execute();
    $haleebColumnSelect->store_result();
    if($haleebColumnSelect->num_rows > 0){
        $haleebColumnUpdate->bind_param("sis", $col['label'], $col['order'], $col['key']);
        $haleebColumnUpdate->execute();
    } else {
        $haleebColumnInsert->bind_param("ssi", $col['key'], $col['label'], $col['order']);
        $haleebColumnInsert->execute();
    }
    $haleebColumnSelect->free_result();
}

$haleebColumnSelect->close();
$haleebColumnUpdate->close();
$haleebColumnInsert->close();

$legacyHiddenKeys = ['sr_no', 'station_english', 'station_urdu', 'rate1', 'rate2'];
$legacyUpdate = $conn->prepare("UPDATE haleeb_rate_list_columns SET is_hidden=1, is_base=0 WHERE column_key=?");
foreach($legacyHiddenKeys as $legacyKey){
    $legacyUpdate->bind_param("s", $legacyKey);
    $legacyUpdate->execute();
}
$legacyUpdate->close();

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

$seedAdminUser = trim((string)env_get('SEED_ADMIN_USER', ''));
$seedAdminPass = trim((string)env_get('SEED_ADMIN_PASS', ''));
if($seedAdminUser !== '' && $seedAdminPass !== ''){
$check = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
$check->bind_param("s", $seedAdminUser);
$check->execute();
$exists = $check->get_result()->num_rows > 0;
$check->close();
if(!$exists){
$ins = $conn->prepare("INSERT INTO users(username,password) VALUES(?,?)");
$ins->bind_param("ss", $seedAdminUser, $seedAdminPass);
$ins->execute();
$ins->close();
}
}
?>
