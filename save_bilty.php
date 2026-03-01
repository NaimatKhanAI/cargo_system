<?php
session_start();
include 'config/db.php';
require_once 'config/auth.php';
require_once 'config/activity_notifications.php';
auth_require_login($conn);
auth_require_module_access('feed');

$sr=isset($_POST['sr_no']) ? trim($_POST['sr_no']) : '';
$d=$_POST['date'];
$v=$_POST['vehicle'];
$b=$_POST['bilty'];
$party=$_POST['party'];
$l=$_POST['location'];
$bags=isset($_POST['bags']) ? (int)$_POST['bags'] : 0;
$f=$_POST['freight'];
$t=$_POST['tender'];

$p=$t-$f;

$stmt = $conn->prepare("INSERT INTO bilty(sr_no, date, vehicle, bilty_no, party, location, bags, freight, original_freight, tender, profit) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssiiiii", $sr, $d, $v, $b, $party, $l, $bags, $f, $f, $t, $p);
$ok = $stmt->execute();
$newId = (int)$stmt->insert_id;
$stmt->close();

if($ok){
    activity_notify_local(
        $conn,
        'feed',
        'bilty_added',
        'bilty',
        $newId,
        'Feed bilty added.',
        [
            'sr_no' => $sr,
            'bilty_no' => $b,
            'vehicle' => $v,
            'party' => $party,
            'freight' => $f,
            'tender' => $t
        ],
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0
    );
}

header("location:feed.php");
?>

