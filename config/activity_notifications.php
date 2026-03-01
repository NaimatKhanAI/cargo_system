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

function activity_clear_pending_flag_requests_local($conn, $notificationId, $reviewedBy, $note = ''){
    $notificationId = (int)$notificationId;
    $reviewedBy = (int)$reviewedBy;
    if($notificationId <= 0) return;

    $reviewNote = trim((string)$note);
    if($reviewNote === '') $reviewNote = 'Auto closed: activity status changed.';
    $status = 'rejected';
    $stmt = $conn->prepare("UPDATE change_requests SET status=?, reviewed_by=?, review_note=?, reviewed_at=NOW() WHERE entity_table='activity_notifications' AND entity_id=? AND action_type='activity_flag' AND status='pending'");
    if(!$stmt) return;
    $stmt->bind_param("sisi", $status, $reviewedBy, $reviewNote, $notificationId);
    $stmt->execute();
    $stmt->close();
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
    if(!$flagForAdmin){
        activity_clear_pending_flag_requests_local($conn, $notificationId, $reviewedBy, 'Auto closed: marked as OK.');
    }
    return true;
}

function activity_mark_new_local($conn, $notificationId, $reviewedBy, &$error){
    $error = '';
    $notificationId = (int)$notificationId;
    $reviewedBy = (int)$reviewedBy;
    if($notificationId <= 0){
        $error = 'Invalid notification.';
        return false;
    }

    $stmt = $conn->prepare("UPDATE activity_notifications SET status='new', flagged_for_admin=0, review_note=NULL, reviewed_by=NULL, reviewed_at=NULL WHERE id=?");
    if(!$stmt){
        $error = 'Could not reset notification.';
        return false;
    }
    $stmt->bind_param("i", $notificationId);
    $ok = $stmt->execute();
    $stmt->close();
    if(!$ok){
        $error = 'Could not reset status.';
        return false;
    }
    activity_clear_pending_flag_requests_local($conn, $notificationId, $reviewedBy, 'Auto closed: reset to new.');
    return true;
}

function activity_raise_admin_flag_request_local($conn, $notificationId, $requestedBy, $issueNote){
    $notificationId = (int)$notificationId;
    $requestedBy = (int)$requestedBy;
    $issueNote = trim((string)$issueNote);
    if($notificationId <= 0 || $requestedBy <= 0) return 0;

    $chk = $conn->prepare("SELECT id FROM change_requests WHERE entity_table='activity_notifications' AND entity_id=? AND action_type='activity_flag' AND status='pending' LIMIT 1");
    if($chk){
        $chk->bind_param("i", $notificationId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if($exists) return (int)$exists['id'];
    }

    $payload = activity_payload_json_local([
        'notification_id' => $notificationId,
        'issue_note' => $issueNote
    ]);

    $ins = $conn->prepare("INSERT INTO change_requests(module_key, entity_table, entity_id, action_type, payload, status, requested_by) VALUES('activity', 'activity_notifications', ?, 'activity_flag', ?, 'pending', ?)");
    if(!$ins) return 0;
    $ins->bind_param("isi", $notificationId, $payload, $requestedBy);
    $ok = $ins->execute();
    $id = $ins->insert_id;
    $ins->close();
    if(!$ok) return 0;
    return (int)$id;
}

function activity_fetch_items_local($conn, $status = 'all', $limit = 250, $excludeFlagged = false){
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
    $whereParts = [];
    if($status !== 'all'){
        $escStatus = $conn->real_escape_string($status);
        $whereParts[] = "a.status='" . $escStatus . "'";
    }
    if($excludeFlagged){
        $whereParts[] = "a.status<>'flagged'";
    }
    if(count($whereParts) > 0){
        $sql .= " WHERE " . implode(" AND ", $whereParts);
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
