<?php
function activity_payload_json_local($payload){
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if($json === false) return '{}';
    return $json;
}

function activity_notify_local($conn, $moduleKey, $activityType, $referenceType, $referenceId, $message, $payload, $createdBy){
    $moduleKey = trim((string)$moduleKey);
    $activityType = trim((string)$activityType);
    $referenceType = trim((string)$referenceType);
    $referenceId = (int)$referenceId;
    $message = trim((string)$message);
    $createdBy = (int)$createdBy;
    if($moduleKey === '' || $activityType === '' || $referenceType === '' || $message === ''){
        return 0;
    }

    $payloadJson = activity_payload_json_local($payload);
    $stmt = $conn->prepare("INSERT INTO activity_notifications(module_key, activity_type, reference_type, reference_id, message, payload, status, flagged_for_admin, created_by) VALUES(?, ?, ?, ?, ?, ?, 'new', 0, ?)");
    if(!$stmt) return 0;
    $stmt->bind_param("sssissi", $moduleKey, $activityType, $referenceType, $referenceId, $message, $payloadJson, $createdBy);
    $ok = $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    if(!$ok) return 0;
    return (int)$id;
}

function activity_count_flagged_for_admin_local($conn){
    $res = $conn->query("SELECT COUNT(*) AS c FROM activity_notifications WHERE status='flagged' AND flagged_for_admin=1");
    if(!$res) return 0;
    $row = $res->fetch_assoc();
    return $row ? (int)$row['c'] : 0;
}

function activity_review_mark_local($conn, $notificationId, $reviewedBy, $flagForAdmin, $reviewNote, &$error){
    $error = '';
    $notificationId = (int)$notificationId;
    $reviewedBy = (int)$reviewedBy;
    $reviewNote = trim((string)$reviewNote);
    if($notificationId <= 0){
        $error = 'Invalid notification.';
        return false;
    }

    $stmt = $conn->prepare("SELECT id, status, flagged_for_admin FROM activity_notifications WHERE id=? LIMIT 1");
    if(!$stmt){
        $error = 'Could not load notification.';
        return false;
    }
    $stmt->bind_param("i", $notificationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row){
        $error = 'Notification not found.';
        return false;
    }

    $newStatus = $flagForAdmin ? 'flagged' : 'ok';
    $flagVal = $flagForAdmin ? 1 : 0;
    $upd = $conn->prepare("UPDATE activity_notifications SET status=?, flagged_for_admin=?, review_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
    if(!$upd){
        $error = 'Could not save review.';
        return false;
    }
    $upd->bind_param("sisii", $newStatus, $flagVal, $reviewNote, $reviewedBy, $notificationId);
    $ok = $upd->execute();
    $upd->close();
    if(!$ok){
        $error = 'Review could not be saved.';
        return false;
    }
    return true;
}

function activity_fetch_items_local($conn, $status = 'all', $limit = 250){
    $status = strtolower(trim((string)$status));
    if(!in_array($status, ['all', 'new', 'ok', 'flagged'], true)) $status = 'all';
    $limit = (int)$limit;
    if($limit <= 0) $limit = 250;
    if($limit > 1000) $limit = 1000;

    $sql = "SELECT a.id, a.module_key, a.activity_type, a.reference_type, a.reference_id, a.message, a.payload, a.status, a.flagged_for_admin, a.review_note, a.created_at, a.reviewed_at,
                   cu.username AS created_by_name,
                   ru.username AS reviewed_by_name
            FROM activity_notifications a
            LEFT JOIN users cu ON cu.id = a.created_by
            LEFT JOIN users ru ON ru.id = a.reviewed_by";
    if($status !== 'all'){
        $escStatus = $conn->real_escape_string($status);
        $sql .= " WHERE a.status='" . $escStatus . "'";
    }
    $sql .= " ORDER BY a.id DESC LIMIT " . $limit;

    $rows = [];
    $res = $conn->query($sql);
    while($res && $r = $res->fetch_assoc()){
        $rows[] = $r;
    }
    return $rows;
}
?>
