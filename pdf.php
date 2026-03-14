<?php
$projectRoot = __DIR__;
function try_load_dompdf_autoloader($projectRoot){
    $autoloadPaths = [
        $projectRoot . '/vendor/autoload.php',
        $projectRoot . '/dompdf/vendor/autoload.php',
        $projectRoot . '/dompdf/autoload.inc.php',
    ];

    foreach($autoloadPaths as $autoloadPath){
        if(!file_exists($autoloadPath)){
            continue;
        }

        if(substr($autoloadPath, -strlen('/dompdf/vendor/autoload.php')) === '/dompdf/vendor/autoload.php'){
            $safeFile = $projectRoot . '/dompdf/vendor/thecodingmachine/safe/lib/special_cases.php';
            if(!file_exists($safeFile)){
                continue;
            }
        }

        require_once $autoloadPath;
        return true;
    }

    return false;
}

if(!try_load_dompdf_autoloader($projectRoot)){
    http_response_code(500);
    die('Dompdf dependencies are incomplete. Re-upload dompdf/vendor or run composer install in dompdf folder.');
}

use Dompdf\Dompdf;

require_once __DIR__ . '/config/session_bootstrap.php';
include 'config/db.php';
require_once 'config/auth.php';
auth_require_login($conn);
auth_require_module_access('feed');
$isSuperAdmin = auth_is_super_admin();
$userFeedPortions = auth_get_feed_portions();
$userFeedPortion = auth_get_feed_portion();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){
    header("location:feed.php");
    exit();
}

if($isSuperAdmin){
    $rowStmt = $conn->prepare("SELECT * FROM bilty WHERE id=? LIMIT 1");
    $rowStmt->bind_param("i", $id);
} else {
    if(count($userFeedPortions) === 1){
        $rowStmt = $conn->prepare("SELECT * FROM bilty WHERE id=? AND feed_portion=? LIMIT 1");
        $rowStmt->bind_param("is", $id, $userFeedPortions[0]);
    } else {
        $placeholders = implode(',', array_fill(0, count($userFeedPortions), '?'));
        $rowStmt = $conn->prepare("SELECT * FROM bilty WHERE id=? AND feed_portion IN ($placeholders) LIMIT 1");
        $types = 'i' . str_repeat('s', count($userFeedPortions));
        $params = array_merge([$types, $id], $userFeedPortions);
        $bindArgs = [];
        foreach($params as $k => $v){ $bindArgs[$k] = &$params[$k]; }
        call_user_func_array([$rowStmt, 'bind_param'], $bindArgs);
    }
}
$rowStmt->execute();
$row = $rowStmt->get_result()->fetch_assoc();
$rowStmt->close();
if(!$row){
    header("location:feed.php");
    exit();
}

$financialRows = '';
if($isSuperAdmin){
    $financialRows = "<div class='row'><b>Tender:</b> Rs {$row['tender']}</div>
<div class='row'><b>Profit:</b> Rs {$row['profit']}</div>";
}

$html = "
<style>
body{font-family:Arial}
.box{border:2px solid #000;padding:20px;width:600px}
h2{text-align:center}
.row{margin:8px 0;font-size:18px}
</style>

<div class='box'>
<h2>CARGO BILTY SLIP</h2>

<div class='row'><b>Date:</b> {$row['date']}</div>
<div class='row'><b>Vehicle:</b> {$row['vehicle']}</div>
<div class='row'><b>Bilty No:</b> {$row['bilty_no']}</div>
<div class='row'><b>Party:</b> {$row['party']}</div>
<div class='row'><b>Location:</b> {$row['location']}</div>
<div class='row'><b>Bags:</b> {$row['bags']}</div>
<div class='row'><b>Freight:</b> Rs {$row['freight']}</div>
{$financialRows}

<br><br>
Driver Sign: ____________<br>
Office Sign: ____________
</div>
";

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4');
$dompdf->render();
$dompdf->stream('bilty.pdf');
?>
