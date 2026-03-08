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

if(!function_exists('format_amount_local')){
    function format_amount_local($value, $maxDecimals = 1, $groupThousands = true){
        $decimals = max(0, (int)$maxDecimals);
        $num = is_numeric($value) ? (float)$value : 0.0;
        $formatted = number_format($num, $decimals, '.', $groupThousands ? ',' : '');
        if($decimals > 0){
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
        }
        if($formatted === '-0') $formatted = '0';
        return $formatted;
    }
}
?>
