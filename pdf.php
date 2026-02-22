<?php
$projectRoot = __DIR__;
$autoloadPaths = [
    $projectRoot . '/vendor/autoload.php',
    $projectRoot . '/dompdf/vendor/autoload.php',
    $projectRoot . '/dompdf/autoload.inc.php',
];

$autoloadLoaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    http_response_code(500);
    die('Dompdf autoloader not found. Install Dompdf with Composer or provide a valid autoload file.');
}

use Dompdf\Dompdf;

include 'config/db.php';

$id = $_GET['id'];
$row = $conn->query("SELECT * FROM bilty WHERE id=$id")->fetch_assoc();

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
<div class='row'><b>Location:</b> {$row['location']}</div>
<div class='row'><b>Freight:</b> Rs {$row['freight']}</div>
<div class='row'><b>Tender:</b> Rs {$row['tender']}</div>
<div class='row'><b>Profit:</b> Rs {$row['profit']}</div>

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
