<?php
require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';

auth_require_login($conn);
auth_require_super_admin('dashboard.php');

$msg = '';
$err = '';

function table_row_count_db_local($conn, $table){
    if(!preg_match('/^[a-zA-Z0-9_]+$/', (string)$table)) return 0;
    $res = $conn->query("SELECT COUNT(*) AS c FROM `$table`");
    if(!$res) return 0;
    $row = $res->fetch_assoc();
    return $row ? (int)$row['c'] : 0;
}
function run_query_list_db_local($conn, $queries, &$queryError){
    foreach($queries as $query){
        if(!$conn->query($query)){
            $queryError = (string)$conn->error;
            return false;
        }
    }
    return true;
}
function current_db_name_db_local($conn){
    $res = $conn->query("SELECT DATABASE() AS db_name");
    if($res){
        $row = $res->fetch_assoc();
        $dbName = isset($row['db_name']) ? preg_replace('/[^a-zA-Z0-9_]/', '', (string)$row['db_name']) : '';
        if($dbName !== '') return $dbName;
    }
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)env_get('DB_NAME', ''));
}

$dbActionConfig = [
    'clear_feed_bilty' => [
        'confirm_text' => 'DELETE FEED BILTY',
        'success' => 'Feed bilty data cleared.',
        'queries' => ["TRUNCATE TABLE bilty"],
    ],
    'clear_haleeb_bilty' => [
        'confirm_text' => 'DELETE HALEEB BILTY',
        'success' => 'Haleeb bilty data cleared.',
        'queries' => ["TRUNCATE TABLE haleeb_bilty"],
    ],
    'clear_account_entries' => [
        'confirm_text' => 'DELETE ACCOUNT ENTRIES',
        'success' => 'Account ledger entries cleared.',
        'queries' => ["TRUNCATE TABLE account_entries"],
    ],
    'clear_feed_rates' => [
        'confirm_text' => 'DELETE FEED RATES',
        'success' => 'Feed rate rows cleared.',
        'queries' => ["TRUNCATE TABLE image_processed_rates"],
    ],
    'clear_haleeb_rates' => [
        'confirm_text' => 'DELETE HALEEB RATES',
        'success' => 'Haleeb rate rows cleared.',
        'queries' => ["TRUNCATE TABLE haleeb_image_processed_rates"],
    ],
    'clear_change_requests' => [
        'confirm_text' => 'DELETE CHANGE REQUESTS',
        'success' => 'Pending/old change requests cleared.',
        'queries' => ["TRUNCATE TABLE change_requests"],
    ],
    'clear_activity_logs' => [
        'confirm_text' => 'DELETE ACTIVITY LOGS',
        'success' => 'Activity notifications cleared.',
        'queries' => ["TRUNCATE TABLE activity_notifications"],
    ],
    'clear_operational_data' => [
        'confirm_text' => 'DELETE OPERATIONAL DATA',
        'success' => 'All operational module data cleared. User accounts are kept.',
        'queries' => [
            "TRUNCATE TABLE bilty",
            "TRUNCATE TABLE haleeb_bilty",
            "TRUNCATE TABLE account_entries",
            "TRUNCATE TABLE image_processed_rates",
            "TRUNCATE TABLE haleeb_image_processed_rates",
            "TRUNCATE TABLE change_requests",
            "TRUNCATE TABLE activity_notifications",
        ],
    ],
    'optimize_tables' => [
        'confirm_text' => 'OPTIMIZE TABLES',
        'success' => 'Database tables optimized.',
        'queries' => [
            "OPTIMIZE TABLE users, bilty, haleeb_bilty, account_entries, image_processed_rates, haleeb_image_processed_rates, rate_list_columns, haleeb_rate_list_columns, change_requests, activity_notifications, app_settings",
        ],
    ],
    'rebuild_database' => [
        'confirm_text' => 'REBUILD FULL DATABASE',
        'success' => 'Full database rebuilt and schema recreated.',
        'action' => 'rebuild_database',
    ],
];

if(isset($_POST['run_db_action'])){
    $actionKey = isset($_POST['db_action']) ? trim((string)$_POST['db_action']) : '';
    $confirmText = isset($_POST['confirm_text']) ? trim((string)$_POST['confirm_text']) : '';

    if($actionKey === '' || !isset($dbActionConfig[$actionKey])){
        $err = 'Invalid database action.';
    } else {
        $cfg = $dbActionConfig[$actionKey];
        $expected = isset($cfg['confirm_text']) ? (string)$cfg['confirm_text'] : '';
        if(strtoupper($confirmText) !== strtoupper($expected)){
            $err = 'Confirmation text mismatch.';
        } else {
            $mode = isset($cfg['action']) ? (string)$cfg['action'] : '';
            if($mode === 'rebuild_database'){
                $dropAllQueries = [
                    "DROP TABLE IF EXISTS activity_notifications",
                    "DROP TABLE IF EXISTS change_requests",
                    "DROP TABLE IF EXISTS app_settings",
                    "DROP TABLE IF EXISTS rate_list_columns",
                    "DROP TABLE IF EXISTS haleeb_rate_list_columns",
                    "DROP TABLE IF EXISTS image_processed_rates",
                    "DROP TABLE IF EXISTS haleeb_image_processed_rates",
                    "DROP TABLE IF EXISTS account_entries",
                    "DROP TABLE IF EXISTS bilty",
                    "DROP TABLE IF EXISTS haleeb_bilty",
                    "DROP TABLE IF EXISTS users",
                ];
                $dropError = '';
                if(run_query_list_db_local($conn, $dropAllQueries, $dropError)){
                    include __DIR__ . '/config/db.php';
                    $msg = $cfg['success'] . ' Please log in again if your old account is removed.';
                } else {
                    $err = 'Database rebuild failed: ' . $dropError;
                }
            } else {
                $queryError = '';
                $queries = isset($cfg['queries']) && is_array($cfg['queries']) ? $cfg['queries'] : [];
                if(count($queries) === 0){
                    $err = 'No queries configured for selected action.';
                } elseif(run_query_list_db_local($conn, $queries, $queryError)){
                    $msg = (string)$cfg['success'];
                } else {
                    $err = 'Database action failed: ' . $queryError;
                }
            }
        }
    }
}

$currentDbName = current_db_name_db_local($conn);
$dbTableCounts = [
    'users' => table_row_count_db_local($conn, 'users'),
    'bilty' => table_row_count_db_local($conn, 'bilty'),
    'haleeb_bilty' => table_row_count_db_local($conn, 'haleeb_bilty'),
    'account_entries' => table_row_count_db_local($conn, 'account_entries'),
    'image_processed_rates' => table_row_count_db_local($conn, 'image_processed_rates'),
    'haleeb_image_processed_rates' => table_row_count_db_local($conn, 'haleeb_image_processed_rates'),
    'change_requests' => table_row_count_db_local($conn, 'change_requests'),
    'activity_notifications' => table_row_count_db_local($conn, 'activity_notifications'),
];
$operationalRows = $dbTableCounts['bilty'] + $dbTableCounts['haleeb_bilty'] + $dbTableCounts['account_entries'] + $dbTableCounts['image_processed_rates'] + $dbTableCounts['haleeb_image_processed_rates'] + $dbTableCounts['change_requests'] + $dbTableCounts['activity_notifications'];
$allRows = $operationalRows + $dbTableCounts['users'];
$dbActionCards = [
    ['key' => 'clear_feed_bilty', 'title' => 'Clear Feed Bilty', 'desc' => 'Delete all rows from feed bilty table.', 'rows' => $dbTableCounts['bilty'], 'tone' => 'danger'],
    ['key' => 'clear_haleeb_bilty', 'title' => 'Clear Haleeb Bilty', 'desc' => 'Delete all rows from haleeb bilty table.', 'rows' => $dbTableCounts['haleeb_bilty'], 'tone' => 'danger'],
    ['key' => 'clear_account_entries', 'title' => 'Clear Account Ledger', 'desc' => 'Delete all account debit/credit entries.', 'rows' => $dbTableCounts['account_entries'], 'tone' => 'danger'],
    ['key' => 'clear_feed_rates', 'title' => 'Clear Feed Rate Rows', 'desc' => 'Delete all imported/processed feed rates.', 'rows' => $dbTableCounts['image_processed_rates'], 'tone' => 'warn'],
    ['key' => 'clear_haleeb_rates', 'title' => 'Clear Haleeb Rate Rows', 'desc' => 'Delete all imported/processed haleeb rates.', 'rows' => $dbTableCounts['haleeb_image_processed_rates'], 'tone' => 'warn'],
    ['key' => 'clear_change_requests', 'title' => 'Clear Change Requests', 'desc' => 'Delete pending and historical change requests.', 'rows' => $dbTableCounts['change_requests'], 'tone' => 'warn'],
    ['key' => 'clear_activity_logs', 'title' => 'Clear Activity Logs', 'desc' => 'Delete activity notifications/review queue.', 'rows' => $dbTableCounts['activity_notifications'], 'tone' => 'warn'],
    ['key' => 'clear_operational_data', 'title' => 'Reset Operational Data', 'desc' => 'Clear feed, haleeb, account, rates, requests and logs. Users stay.', 'rows' => $operationalRows, 'tone' => 'critical'],
    ['key' => 'optimize_tables', 'title' => 'Optimize Tables', 'desc' => 'Run table optimization for performance maintenance.', 'rows' => $allRows, 'tone' => 'neutral'],
    ['key' => 'rebuild_database', 'title' => 'Rebuild Full Database', 'desc' => 'Drop and recreate complete database schema. Users may be reset.', 'rows' => $allRows, 'tone' => 'critical'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'config/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e0f11; --surface: #16181c; --surface2: #1e2128; --border: #2a2d35;
    --accent: #f0c040; --green: #22c55e; --red: #ef4444; --blue: #60a5fa; --purple: #c084fc;
    --text: #e8eaf0; --muted: #7c8091; --font: 'Syne', sans-serif; --mono: 'DM Mono', monospace;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }
  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 28px; border-bottom: 1px solid var(--border); background: var(--surface); }
  .topbar-logo { display: flex; align-items: center; gap: 12px; }
  .badge-pill { background: var(--purple); color: #fff; font-size: 10px; font-weight: 800; padding: 3px 8px; letter-spacing: 1.5px; text-transform: uppercase; }
  .topbar h1 { font-size: 17px; font-weight: 800; letter-spacing: -0.4px; }
  .nav-links { display: flex; gap: 6px; }
  .nav-btn { padding: 7px 14px; background: transparent; color: var(--muted); border: 1px solid var(--border); text-decoration: none; font-size: 12px; font-weight: 600; transition: all 0.15s; }
  .nav-btn:hover { background: var(--surface2); color: var(--text); border-color: var(--muted); }
  .main { max-width: 1400px; margin: 0 auto; padding: 24px 28px; }
  .alert { padding: 11px 14px; margin-bottom: 14px; font-size: 13px; border-left: 3px solid var(--green); background: rgba(34,197,94,0.08); color: var(--green); }
  .alert.error { border-color: var(--red); background: rgba(239,68,68,0.08); color: var(--red); }
  .section { background: var(--surface); border: 1px solid var(--border); margin-bottom: 16px; }
  .section-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border); }
  .section-title { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  .section-count { font-family: var(--mono); font-size: 11px; color: var(--accent); }
  .section-body { padding: 16px 18px; }
  .settings-note { font-size: 12px; color: var(--muted); margin-bottom: 12px; line-height: 1.5; }
  .db-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px; }
  .db-card { border: 1px solid var(--border); background: var(--surface2); padding: 12px; display: flex; flex-direction: column; gap: 8px; }
  .db-card.danger { border-color: rgba(239,68,68,0.4); }
  .db-card.warn { border-color: rgba(240,192,64,0.35); }
  .db-card.critical { border-color: rgba(239,68,68,0.6); background: rgba(239,68,68,0.08); }
  .db-card.neutral { border-color: rgba(96,165,250,0.35); }
  .db-title { font-size: 13px; font-weight: 700; }
  .db-desc { font-size: 11px; color: var(--muted); line-height: 1.45; min-height: 30px; }
  .db-meta { font-size: 10px; color: var(--accent); font-family: var(--mono); }
  .db-confirm { font-size: 10px; color: var(--muted); font-family: var(--mono); }
  .db-confirm code { background: var(--bg); border: 1px solid var(--border); padding: 1px 5px; color: var(--text); }
  .db-input { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 7px 8px; font-family: var(--mono); font-size: 11px; }
  .db-input:focus { outline: none; border-color: var(--accent); }
  .btn-db { padding: 8px 10px; border: 1px solid var(--border); background: var(--bg); color: var(--text); cursor: pointer; font-family: var(--font); font-size: 11px; font-weight: 700; transition: all 0.15s; }
  .btn-db:hover { background: rgba(255,255,255,0.04); }
  .btn-db.danger { border-color: rgba(239,68,68,0.4); color: var(--red); }
  .btn-db.warn { border-color: rgba(240,192,64,0.4); color: var(--accent); }
  .btn-db.critical { border-color: rgba(239,68,68,0.55); color: #fff; background: rgba(239,68,68,0.2); }
  .btn-db.critical:hover { background: rgba(239,68,68,0.32); }
  .btn-db.neutral { border-color: rgba(96,165,250,0.4); color: var(--blue); }
  @media(max-width: 700px){
    .topbar { padding: 14px 16px; }
    .main { padding: 16px; }
  }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-logo">
    <span class="badge-pill">DB</span>
    <h1>Database Management</h1>
  </div>
  <div class="nav-links">
    <a class="nav-btn" href="super_admin.php">Super Admin</a>
    <a class="nav-btn" href="dashboard.php">Dashboard</a>
    <a class="nav-btn" href="logout.php">Logout</a>
  </div>
</div>

<div class="main">
  <?php if($msg !== ''): ?>
    <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
  <?php endif; ?>
  <?php if($err !== ''): ?>
    <div class="alert error"><?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>

  <div class="section">
    <div class="section-head">
      <span class="section-title">Database</span>
      <span class="section-count"><?php echo htmlspecialchars($currentDbName !== '' ? $currentDbName : 'database'); ?></span>
    </div>
    <div class="section-body">
      <p class="settings-note">Type exact confirmation text for each action. High-risk actions can remove all rows permanently.</p>
      <div class="db-grid">
        <?php foreach($dbActionCards as $card): ?>
          <?php
            $cardKey = (string)$card['key'];
            if(!isset($dbActionConfig[$cardKey])) continue;
            $cardConfirm = (string)$dbActionConfig[$cardKey]['confirm_text'];
            $cardTone = (string)$card['tone'];
          ?>
          <form method="post" class="db-card <?php echo htmlspecialchars($cardTone); ?>" onsubmit="return confirm('Run this database action?');">
            <div class="db-title"><?php echo htmlspecialchars((string)$card['title']); ?></div>
            <div class="db-desc"><?php echo htmlspecialchars((string)$card['desc']); ?></div>
            <div class="db-meta">Rows in scope: <?php echo (int)$card['rows']; ?></div>
            <div class="db-confirm">Type <code><?php echo htmlspecialchars($cardConfirm); ?></code></div>
            <input type="hidden" name="db_action" value="<?php echo htmlspecialchars($cardKey); ?>">
            <input class="db-input" type="text" name="confirm_text" placeholder="Exact confirmation text" autocomplete="off" required>
            <button class="btn-db <?php echo htmlspecialchars($cardTone); ?>" type="submit" name="run_db_action">Run Action</button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
