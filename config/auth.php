<?php
if(session_status() !== PHP_SESSION_ACTIVE){
session_start();
}

function auth_get_user_by_id_local($conn, $userId){
    $stmt = $conn->prepare("SELECT id, username, role, is_active, can_access_feed, can_access_haleeb, can_access_account, can_manage_users FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function auth_get_user_by_username_local($conn, $username){
    $stmt = $conn->prepare("SELECT id, username, role, is_active, can_access_feed, can_access_haleeb, can_access_account, can_manage_users FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function auth_store_session_local($row){
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['user'] = (string)$row['username'];
    $_SESSION['role'] = (string)$row['role'];
    $_SESSION['can_access_feed'] = (int)$row['can_access_feed'];
    $_SESSION['can_access_haleeb'] = (int)$row['can_access_haleeb'];
    $_SESSION['can_access_account'] = (int)$row['can_access_account'];
    $_SESSION['can_manage_users'] = (int)$row['can_manage_users'];
}

function auth_sync_session_user($conn){
    if(isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0){
        $row = auth_get_user_by_id_local($conn, (int)$_SESSION['user_id']);
        if($row){
            auth_store_session_local($row);
            return $row;
        }
    }

    if(isset($_SESSION['user']) && trim((string)$_SESSION['user']) !== ''){
        $row = auth_get_user_by_username_local($conn, trim((string)$_SESSION['user']));
        if($row){
            auth_store_session_local($row);
            return $row;
        }
    }

    return null;
}

function auth_require_login($conn){
    $row = auth_sync_session_user($conn);
    if(!$row || (int)$row['is_active'] !== 1){
        session_unset();
        session_destroy();
        header("location:index.php");
        exit();
    }
    return $row;
}

function auth_is_super_admin(){
    return isset($_SESSION['role']) && (string)$_SESSION['role'] === 'super_admin';
}

function auth_has_module_access($module){
    if(auth_is_super_admin()) return true;

    if($module === 'feed') return isset($_SESSION['can_access_feed']) && (int)$_SESSION['can_access_feed'] === 1;
    if($module === 'haleeb') return isset($_SESSION['can_access_haleeb']) && (int)$_SESSION['can_access_haleeb'] === 1;
    if($module === 'account') return isset($_SESSION['can_access_account']) && (int)$_SESSION['can_access_account'] === 1;
    return false;
}

function auth_require_module_access($module, $fallback = 'dashboard.php'){
    if(!auth_has_module_access($module)){
        header("location:" . $fallback . "?denied=" . urlencode($module));
        exit();
    }
}

function auth_require_super_admin($fallback = 'dashboard.php'){
    if(!auth_is_super_admin()){
        header("location:" . $fallback);
        exit();
    }
}

function auth_can_manage_users(){
    return auth_is_super_admin() || (isset($_SESSION['can_manage_users']) && (int)$_SESSION['can_manage_users'] === 1);
}

function auth_can_direct_modify(){
    return auth_is_super_admin();
}
?>
