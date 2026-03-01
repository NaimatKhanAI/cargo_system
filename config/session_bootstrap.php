<?php
// Use a project-local session directory to avoid OS-level permission issues.
$sessionPath = dirname(__DIR__) . '/tmp/sessions';
if(!is_dir($sessionPath)){
    @mkdir($sessionPath, 0777, true);
}

if(is_dir($sessionPath) && is_writable($sessionPath)){
    @ini_set('session.save_path', $sessionPath);
}

if(session_status() !== PHP_SESSION_ACTIVE){
    @session_start();
}
?>
