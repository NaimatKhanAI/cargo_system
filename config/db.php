<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/feed_portions.php';
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

if(!function_exists('ensure_decimal_column_local')){
    function ensure_decimal_column_local($conn, $table, $column, $nullable = false){
        $colCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if(!$colCheck || $colCheck->num_rows === 0){
            return;
        }
        $col = $colCheck->fetch_assoc();
        $type = strtolower((string)($col['Type'] ?? ''));
        if(strpos($type, 'decimal(14,3)') === 0){
            return;
        }
        $nullSql = $nullable ? 'NULL DEFAULT NULL' : 'NOT NULL DEFAULT 0';
        $conn->query("ALTER TABLE `$table` MODIFY `$column` DECIMAL(14,3) $nullSql");
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS users(
id INT AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(50),
password VARCHAR(50)
)");

$userRoleColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if($userRoleColCheck && $userRoleColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD role VARCHAR(20) NOT NULL DEFAULT 'sub_admin' AFTER password");
}

$userActiveColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'is_active'");
if($userActiveColCheck && $userActiveColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
}

$userFeedAccessColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'can_access_feed'");
if($userFeedAccessColCheck && $userFeedAccessColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD can_access_feed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
}

$userFeedPortionColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'feed_portion'");
if($userFeedPortionColCheck && $userFeedPortionColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD feed_portion VARCHAR(30) NOT NULL DEFAULT 'al_amir' AFTER can_access_feed");
}

$userHaleebAccessColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'can_access_haleeb'");
if($userHaleebAccessColCheck && $userHaleebAccessColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD can_access_haleeb TINYINT(1) NOT NULL DEFAULT 0 AFTER can_access_feed");
}

$userAccountAccessColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'can_access_account'");
if($userAccountAccessColCheck && $userAccountAccessColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD can_access_account TINYINT(1) NOT NULL DEFAULT 0 AFTER can_access_haleeb");
}

$userImageAccessColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'can_access_image_processing'");
if($userImageAccessColCheck && $userImageAccessColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD can_access_image_processing TINYINT(1) NOT NULL DEFAULT 0 AFTER can_access_account");
}

$userManageColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'can_manage_users'");
if($userManageColCheck && $userManageColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD can_manage_users TINYINT(1) NOT NULL DEFAULT 0 AFTER can_access_image_processing");
}

$userReviewActivityColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'can_review_activity'");
if($userReviewActivityColCheck && $userReviewActivityColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD can_review_activity TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_users");
}

$userCreatedByColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'created_by'");
if($userCreatedByColCheck && $userCreatedByColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD created_by INT NULL AFTER can_review_activity");
}

$userCreatedAtColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'");
if($userCreatedAtColCheck && $userCreatedAtColCheck->num_rows === 0){
$conn->query("ALTER TABLE users ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by");
}

$conn->query("CREATE TABLE IF NOT EXISTS bilty(
id INT AUTO_INCREMENT PRIMARY KEY,
sr_no VARCHAR(50),
date DATE,
vehicle VARCHAR(50),
bilty_no VARCHAR(50),
party VARCHAR(100),
feed_portion VARCHAR(30) NOT NULL DEFAULT 'al_amir',
added_by_user_id INT NULL,
location VARCHAR(100),
bags INT DEFAULT 0,
freight DECIMAL(14,3) NOT NULL DEFAULT 0,
commission DECIMAL(14,3) NOT NULL DEFAULT 0,
freight_payment_type VARCHAR(20) NOT NULL DEFAULT 'to_pay',
original_freight DECIMAL(14,3) NULL,
tender DECIMAL(14,3) NOT NULL DEFAULT 0,
profit DECIMAL(14,3) NOT NULL DEFAULT 0
)");

$conn->query("CREATE TABLE IF NOT EXISTS haleeb_bilty(
id INT AUTO_INCREMENT PRIMARY KEY,
date DATE,
vehicle VARCHAR(50),
vehicle_type VARCHAR(50),
driver_phone_no VARCHAR(40) DEFAULT '',
delivery_status VARCHAR(20) NOT NULL DEFAULT 'not_received',
delivery_note VARCHAR(100),
token_no VARCHAR(50),
party VARCHAR(100),
added_by_user_id INT NULL,
location VARCHAR(100),
stops VARCHAR(50) DEFAULT '',
freight DECIMAL(14,3) NOT NULL DEFAULT 0,
commission DECIMAL(14,3) NOT NULL DEFAULT 0,
freight_payment_type VARCHAR(20) NOT NULL DEFAULT 'to_pay',
tender DECIMAL(14,3) NOT NULL DEFAULT 0,
profit DECIMAL(14,3) NOT NULL DEFAULT 0,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$haleebStopsColCheck = $conn->query("SHOW COLUMNS FROM haleeb_bilty LIKE 'stops'");
if($haleebStopsColCheck && $haleebStopsColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_bilty ADD stops VARCHAR(50) DEFAULT '' AFTER location");
}

$haleebDriverPhoneColCheck = $conn->query("SHOW COLUMNS FROM haleeb_bilty LIKE 'driver_phone_no'");
if($haleebDriverPhoneColCheck && $haleebDriverPhoneColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_bilty ADD driver_phone_no VARCHAR(40) DEFAULT '' AFTER vehicle_type");
}

$haleebDeliveryStatusColCheck = $conn->query("SHOW COLUMNS FROM haleeb_bilty LIKE 'delivery_status'");
if($haleebDeliveryStatusColCheck && $haleebDeliveryStatusColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_bilty ADD delivery_status VARCHAR(20) NOT NULL DEFAULT 'not_received' AFTER driver_phone_no");
}

$colCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'party'");
if($colCheck && $colCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD party VARCHAR(100) AFTER bilty_no");
}

$feedPortionColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'feed_portion'");
if($feedPortionColCheck && $feedPortionColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD feed_portion VARCHAR(30) NOT NULL DEFAULT 'al_amir' AFTER party");
}

$biltyAddedByColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'added_by_user_id'");
if($biltyAddedByColCheck && $biltyAddedByColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD added_by_user_id INT NULL AFTER feed_portion");
}

$srColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'sr_no'");
if($srColCheck && $srColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD sr_no VARCHAR(50) AFTER id");
}

$origFreightColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'original_freight'");
if($origFreightColCheck && $origFreightColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD original_freight DECIMAL(14,3) NULL AFTER freight");
}

$biltyCommissionColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'commission'");
if($biltyCommissionColCheck && $biltyCommissionColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD commission DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER freight");
}

$haleebCommissionColCheck = $conn->query("SHOW COLUMNS FROM haleeb_bilty LIKE 'commission'");
if($haleebCommissionColCheck && $haleebCommissionColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_bilty ADD commission DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER freight");
}

$biltyPaymentTypeColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'freight_payment_type'");
if($biltyPaymentTypeColCheck && $biltyPaymentTypeColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD freight_payment_type VARCHAR(20) NOT NULL DEFAULT 'to_pay' AFTER commission");
}

$haleebPaymentTypeColCheck = $conn->query("SHOW COLUMNS FROM haleeb_bilty LIKE 'freight_payment_type'");
if($haleebPaymentTypeColCheck && $haleebPaymentTypeColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_bilty ADD freight_payment_type VARCHAR(20) NOT NULL DEFAULT 'to_pay' AFTER commission");
}

$haleebAddedByColCheck = $conn->query("SHOW COLUMNS FROM haleeb_bilty LIKE 'added_by_user_id'");
if($haleebAddedByColCheck && $haleebAddedByColCheck->num_rows === 0){
$conn->query("ALTER TABLE haleeb_bilty ADD added_by_user_id INT NULL AFTER party");
}

ensure_decimal_column_local($conn, 'bilty', 'freight');
ensure_decimal_column_local($conn, 'bilty', 'commission');
ensure_decimal_column_local($conn, 'bilty', 'original_freight', true);
ensure_decimal_column_local($conn, 'bilty', 'tender');
ensure_decimal_column_local($conn, 'bilty', 'profit');
ensure_decimal_column_local($conn, 'haleeb_bilty', 'freight');
ensure_decimal_column_local($conn, 'haleeb_bilty', 'commission');
ensure_decimal_column_local($conn, 'haleeb_bilty', 'tender');
ensure_decimal_column_local($conn, 'haleeb_bilty', 'profit');

$conn->query("UPDATE bilty SET commission=0 WHERE commission IS NULL");
$conn->query("UPDATE haleeb_bilty SET commission=0 WHERE commission IS NULL");
$conn->query("UPDATE haleeb_bilty SET driver_phone_no='' WHERE driver_phone_no IS NULL");
$conn->query("UPDATE haleeb_bilty SET delivery_status='not_received' WHERE delivery_status IS NULL OR delivery_status=''");
$conn->query("UPDATE haleeb_bilty SET delivery_status='not_received' WHERE LOWER(REPLACE(delivery_status, ' ', '_')) NOT IN ('received', 'not_received')");
$conn->query("UPDATE haleeb_bilty SET delivery_status='received' WHERE LOWER(REPLACE(delivery_status, ' ', '_'))='received'");
$conn->query("UPDATE haleeb_bilty SET delivery_status='not_received' WHERE LOWER(REPLACE(delivery_status, ' ', '_'))='not_received'");
$conn->query("UPDATE bilty SET freight_payment_type='to_pay' WHERE freight_payment_type IS NULL OR freight_payment_type=''");
$conn->query("UPDATE haleeb_bilty SET freight_payment_type='to_pay' WHERE freight_payment_type IS NULL OR freight_payment_type=''");
$conn->query("UPDATE bilty SET freight_payment_type='to_pay' WHERE freight_payment_type NOT IN ('to_pay','paid')");
$conn->query("UPDATE haleeb_bilty SET freight_payment_type='to_pay' WHERE freight_payment_type NOT IN ('to_pay','paid')");
$conn->query("UPDATE bilty SET profit = COALESCE(tender,0) - GREATEST(COALESCE(freight,0) - COALESCE(commission,0), 0)");
$conn->query("UPDATE haleeb_bilty SET profit = COALESCE(tender,0) - GREATEST(COALESCE(freight,0) - COALESCE(commission,0), 0)");
$conn->query("UPDATE bilty SET original_freight = GREATEST(COALESCE(freight,0) - COALESCE(commission,0), 0) WHERE original_freight IS NULL OR original_freight=0");

$bagsColCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'bags'");
if($bagsColCheck && $bagsColCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD bags INT DEFAULT 0 AFTER location");
}

$allowedFeedPortionsSql = "'" . implode("','", array_keys(feed_portion_options_local())) . "'";
$defaultFeedPortion = feed_default_portion_key_local();
$conn->query("UPDATE users SET feed_portion='{$defaultFeedPortion}' WHERE feed_portion IS NULL OR feed_portion='' OR feed_portion NOT IN ($allowedFeedPortionsSql)");
$conn->query("UPDATE bilty SET feed_portion='{$defaultFeedPortion}' WHERE feed_portion IS NULL OR feed_portion='' OR feed_portion NOT IN ($allowedFeedPortionsSql)");
$feedPortionIdxCheck = $conn->query("SHOW INDEX FROM bilty WHERE Key_name='idx_bilty_feed_portion'");
if($feedPortionIdxCheck && $feedPortionIdxCheck->num_rows === 0){
$conn->query("CREATE INDEX idx_bilty_feed_portion ON bilty(feed_portion)");
}
$biltyAddedByIdxCheck = $conn->query("SHOW INDEX FROM bilty WHERE Key_name='idx_bilty_added_by_user_id'");
if($biltyAddedByIdxCheck && $biltyAddedByIdxCheck->num_rows === 0){
$conn->query("CREATE INDEX idx_bilty_added_by_user_id ON bilty(added_by_user_id)");
}
$haleebAddedByIdxCheck = $conn->query("SHOW INDEX FROM haleeb_bilty WHERE Key_name='idx_haleeb_added_by_user_id'");
if($haleebAddedByIdxCheck && $haleebAddedByIdxCheck->num_rows === 0){
$conn->query("CREATE INDEX idx_haleeb_added_by_user_id ON haleeb_bilty(added_by_user_id)");
}

$conn->query("CREATE TABLE IF NOT EXISTS account_entries(
id INT AUTO_INCREMENT PRIMARY KEY,
entry_date DATE,
category VARCHAR(20),
entry_type VARCHAR(10),
amount_mode VARCHAR(10),
bilty_id INT NULL,
amount DECIMAL(14,3) NOT NULL DEFAULT 0,
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

ensure_decimal_column_local($conn, 'account_entries', 'amount');

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

$conn->query("CREATE TABLE IF NOT EXISTS change_requests(
id INT AUTO_INCREMENT PRIMARY KEY,
module_key VARCHAR(20) NOT NULL,
entity_table VARCHAR(50) NOT NULL,
entity_id INT NULL,
action_type VARCHAR(20) NOT NULL,
payload LONGTEXT,
status VARCHAR(20) NOT NULL DEFAULT 'pending',
requested_by INT NOT NULL,
reviewed_by INT NULL,
review_note VARCHAR(255) NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
reviewed_at TIMESTAMP NULL DEFAULT NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS activity_notifications(
id INT AUTO_INCREMENT PRIMARY KEY,
module_key VARCHAR(30) NOT NULL,
activity_type VARCHAR(60) NOT NULL,
reference_type VARCHAR(40) NOT NULL,
reference_id INT NOT NULL DEFAULT 0,
message VARCHAR(255) NOT NULL,
payload LONGTEXT,
status VARCHAR(20) NOT NULL DEFAULT 'new',
flagged_for_admin TINYINT(1) NOT NULL DEFAULT 0,
created_by INT NOT NULL DEFAULT 0,
reviewed_by INT NULL,
review_note VARCHAR(255) NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
reviewed_at TIMESTAMP NULL DEFAULT NULL
)");

$activityStatusColCheck = $conn->query("SHOW COLUMNS FROM activity_notifications LIKE 'status'");
if($activityStatusColCheck && $activityStatusColCheck->num_rows === 0){
$conn->query("ALTER TABLE activity_notifications ADD status VARCHAR(20) NOT NULL DEFAULT 'new' AFTER payload");
}

$activityFlagColCheck = $conn->query("SHOW COLUMNS FROM activity_notifications LIKE 'flagged_for_admin'");
if($activityFlagColCheck && $activityFlagColCheck->num_rows === 0){
$conn->query("ALTER TABLE activity_notifications ADD flagged_for_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
}

$activityCreatedByColCheck = $conn->query("SHOW COLUMNS FROM activity_notifications LIKE 'created_by'");
if($activityCreatedByColCheck && $activityCreatedByColCheck->num_rows === 0){
$conn->query("ALTER TABLE activity_notifications ADD created_by INT NOT NULL DEFAULT 0 AFTER flagged_for_admin");
}

$activityReviewedByColCheck = $conn->query("SHOW COLUMNS FROM activity_notifications LIKE 'reviewed_by'");
if($activityReviewedByColCheck && $activityReviewedByColCheck->num_rows === 0){
$conn->query("ALTER TABLE activity_notifications ADD reviewed_by INT NULL AFTER created_by");
}

$activityReviewNoteColCheck = $conn->query("SHOW COLUMNS FROM activity_notifications LIKE 'review_note'");
if($activityReviewNoteColCheck && $activityReviewNoteColCheck->num_rows === 0){
$conn->query("ALTER TABLE activity_notifications ADD review_note VARCHAR(255) NULL AFTER reviewed_by");
}

$activityReviewedAtColCheck = $conn->query("SHOW COLUMNS FROM activity_notifications LIKE 'reviewed_at'");
if($activityReviewedAtColCheck && $activityReviewedAtColCheck->num_rows === 0){
$conn->query("ALTER TABLE activity_notifications ADD reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER created_at");
}

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
SET b.original_freight = GREATEST((COALESCE(b.freight,0) - COALESCE(b.commission,0)) + COALESCE(p.paid_total, 0), 0)
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
$ins = $conn->prepare("INSERT INTO users(username,password,role,is_active,can_access_feed,can_access_haleeb,can_access_account,can_access_image_processing,can_manage_users,can_review_activity) VALUES(?,?,'super_admin',1,1,1,1,1,1,1)");
$ins->bind_param("ss", $seedAdminUser, $seedAdminPass);
$ins->execute();
$ins->close();
}
}

$superAdminCountRes = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='super_admin'");
$superAdminCount = $superAdminCountRes ? (int)$superAdminCountRes->fetch_assoc()['c'] : 0;
if($superAdminCount === 0){
$fallbackSuperUser = trim((string)env_get('SUPER_ADMIN_USER', 'admin'));
$fallbackSuperPass = trim((string)env_get('SUPER_ADMIN_PASS', '1234'));
if($fallbackSuperUser === '') $fallbackSuperUser = 'admin';
if($fallbackSuperPass === '') $fallbackSuperPass = '1234';

$promoteStmt = $conn->prepare("UPDATE users SET role='super_admin', is_active=1, can_access_feed=1, can_access_haleeb=1, can_access_account=1, can_access_image_processing=1, can_manage_users=1, can_review_activity=1 WHERE username=? LIMIT 1");
$promoteStmt->bind_param("s", $fallbackSuperUser);
$promoteStmt->execute();
$promoteStmt->close();

// Avoid duplicate usernames: insert only when fallback user does not already exist.
$fallbackExistsStmt = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
$fallbackExistsStmt->bind_param("s", $fallbackSuperUser);
$fallbackExistsStmt->execute();
$fallbackExists = $fallbackExistsStmt->get_result()->num_rows > 0;
$fallbackExistsStmt->close();

if(!$fallbackExists){
$insertSuper = $conn->prepare("INSERT INTO users(username,password,role,is_active,can_access_feed,can_access_haleeb,can_access_account,can_access_image_processing,can_manage_users,can_review_activity) VALUES(?,?,'super_admin',1,1,1,1,1,1,1)");
$insertSuper->bind_param("ss", $fallbackSuperUser, $fallbackSuperPass);
$insertSuper->execute();
$insertSuper->close();
}
}
?>
