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
auth_require_module_access('haleeb');
$isSuperAdmin = auth_is_super_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){
    http_response_code(400);
    die('Invalid id');
}

$stmt = $conn->prepare("SELECT * FROM haleeb_bilty WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$row){
    http_response_code(404);
    die('Record not found');
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
<h2>HALEEB BILTY SLIP</h2>

<div class='row'><b>Date:</b> {$row['date']}</div>
<div class='row'><b>Vehicle:</b> {$row['vehicle']}</div>
<div class='row'><b>Vehicle Type:</b> {$row['vehicle_type']}</div>
<div class='row'><b>Delivery Note:</b> {$row['delivery_note']}</div>
<div class='row'><b>Token No:</b> {$row['token_no']}</div>
<div class='row'><b>Party:</b> {$row['party']}</div>
<div class='row'><b>Location:</b> {$row['location']}</div>
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
$dompdf->stream('haleeb_bilty.pdf');
?>
