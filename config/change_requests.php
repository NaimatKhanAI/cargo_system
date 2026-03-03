<?php
require_once __DIR__ . '/activity_notifications.php';

function request_payload_decode_local($raw){
    if(trim((string)$raw) === '') return [];
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function request_action_summary_local($actionType, $entityId, $payload){
    $actionType = (string)$actionType;
    $entityId = (int)$entityId;
    if($actionType === 'feed_pay' || $actionType === 'haleeb_pay'){
        $amt = isset($payload['amount']) ? (float)$payload['amount'] : 0;
        $mode = isset($payload['amount_mode']) ? (string)$payload['amount_mode'] : '';
        return 'Payment request: Rs ' . number_format($amt, 2) . ' (' . $mode . ')';
    }
    if($actionType === 'feed_update' || $actionType === 'haleeb_update' || $actionType === 'account_update'){
        return 'Update request for entity #' . $entityId;
    }
    if($actionType === 'feed_delete' || $actionType === 'haleeb_delete' || $actionType === 'account_delete'){
        return 'Delete request for entity #' . $entityId;
    }
    return 'Change request submitted for entity #' . $entityId;
}

function create_change_request_local($conn, $moduleKey, $entityTable, $entityId, $actionType, $payload, $requestedBy){
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if($payloadJson === false){
        $payloadJson = '{}';
    }

    $stmt = $conn->prepare("INSERT INTO change_requests(module_key, entity_table, entity_id, action_type, payload, status, requested_by) VALUES(?, ?, ?, ?, ?, 'pending', ?)");
    $stmt->bind_param("ssissi", $moduleKey, $entityTable, $entityId, $actionType, $payloadJson, $requestedBy);
    $ok = $stmt->execute();
    $requestId = $stmt->insert_id;
    $stmt->close();
    if(!$ok) return 0;

    $summary = request_action_summary_local($actionType, $entityId, is_array($payload) ? $payload : []);
    activity_notify_local(
        $conn,
        (string)$moduleKey,
        'change_request_created',
        'change_request',
        (int)$requestId,
        $summary,
        [
            'action_type' => (string)$actionType,
            'entity_table' => (string)$entityTable,
            'entity_id' => (int)$entityId
        ],
        (int)$requestedBy
    );

    return (int)$requestId;
}

function fetch_pending_change_requests_local($conn, $excludeActionTypes = [], $includeActionTypes = []){
    $rows = [];
    $sql = "SELECT r.id, r.module_key, r.entity_table, r.entity_id, r.action_type, r.payload, r.status, r.requested_by, r.created_at,
                   u.username AS requested_by_name
            FROM change_requests r
            LEFT JOIN users u ON u.id = r.requested_by
            WHERE r.status='pending'
            ORDER BY r.id DESC";
    $res = $conn->query($sql);
    while($res && $row = $res->fetch_assoc()){
        $actionType = isset($row['action_type']) ? (string)$row['action_type'] : '';
        if(count($includeActionTypes) > 0 && !in_array($actionType, $includeActionTypes, true)) continue;
        if(count($excludeActionTypes) > 0 && in_array($actionType, $excludeActionTypes, true)) continue;
        $rows[] = $row;
    }
    return $rows;
}

function fetch_pending_change_request_by_id_local($conn, $requestId){
    $requestId = (int)$requestId;
    if($requestId <= 0){
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM change_requests WHERE id=? AND status='pending' LIMIT 1");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mark_change_request_handled_local($conn, $requestId, $reviewedBy, $reviewNote, &$error, $allowedActionTypes = [], $expectedEntityId = 0, $expectedEntityTable = ''){
    $error = '';
    $requestId = (int)$requestId;
    $reviewedBy = (int)$reviewedBy;
    $reviewNote = trim((string)$reviewNote);
    $expectedEntityId = (int)$expectedEntityId;
    $expectedEntityTable = trim((string)$expectedEntityTable);

    if($requestId <= 0){
        $error = 'Invalid request.';
        return false;
    }

    $requestRow = fetch_pending_change_request_by_id_local($conn, $requestId);
    if(!$requestRow){
        $error = 'Request not found or already reviewed.';
        return false;
    }

    $actionType = isset($requestRow['action_type']) ? (string)$requestRow['action_type'] : '';
    if(count($allowedActionTypes) > 0 && !in_array($actionType, $allowedActionTypes, true)){
        $error = 'This request cannot be handled here.';
        return false;
    }

    if($expectedEntityId > 0 && (int)$requestRow['entity_id'] !== $expectedEntityId){
        $error = 'Request entity mismatch.';
        return false;
    }

    if($expectedEntityTable !== '' && strcasecmp((string)$requestRow['entity_table'], $expectedEntityTable) !== 0){
        $error = 'Request table mismatch.';
        return false;
    }

    if($reviewNote === ''){
        $reviewNote = 'Handled via admin edit view.';
    }
    if(strlen($reviewNote) > 250){
        $reviewNote = substr($reviewNote, 0, 250);
    }

    $status = 'approved';
    $stmt = $conn->prepare("UPDATE change_requests SET status=?, reviewed_by=?, review_note=?, reviewed_at=NOW() WHERE id=? AND status='pending'");
    $stmt->bind_param("sisi", $status, $reviewedBy, $reviewNote, $requestId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if(!$ok || $affected <= 0){
        $error = 'Could not update request status.';
        return false;
    }

    activity_notify_local(
        $conn,
        (string)$requestRow['module_key'],
        'change_request_handled_via_admin_edit',
        'change_request',
        $requestId,
        'Request handled via admin edit: ' . $actionType,
        ['review_note' => $reviewNote],
        $reviewedBy
    );
    return true;
}

function review_change_request_local($conn, $requestId, $reviewedBy, $isApprove, $reviewNote, &$error, $allowedActionTypes = []){
    $error = '';
    $requestId = (int)$requestId;
    $reviewedBy = (int)$reviewedBy;
    $reviewNote = trim((string)$reviewNote);
    if($requestId <= 0){
        $error = 'Invalid request.';
        return false;
    }

    $rs = $conn->prepare("SELECT * FROM change_requests WHERE id=? AND status='pending' LIMIT 1");
    $rs->bind_param("i", $requestId);
    $rs->execute();
    $requestRow = $rs->get_result()->fetch_assoc();
    $rs->close();
    if(!$requestRow){
        $error = 'Request not found or already reviewed.';
        return false;
    }

    $actionType = isset($requestRow['action_type']) ? (string)$requestRow['action_type'] : '';
    if(count($allowedActionTypes) > 0 && !in_array($actionType, $allowedActionTypes, true)){
        $error = 'This request cannot be reviewed here.';
        return false;
    }

    if($isApprove){
        $conn->begin_transaction();
        try {
            $applyError = '';
            $ok = apply_change_request_local($conn, $requestRow, $applyError);
            if(!$ok){
                throw new Exception($applyError ?: 'Could not apply request.');
            }

            $status = 'approved';
            $u = $conn->prepare("UPDATE change_requests SET status=?, reviewed_by=?, review_note=?, reviewed_at=NOW() WHERE id=?");
            $u->bind_param("sisi", $status, $reviewedBy, $reviewNote, $requestId);
            $u->execute();
            $u->close();
            $conn->commit();

            activity_notify_local(
                $conn,
                (string)$requestRow['module_key'],
                'change_request_approved',
                'change_request',
                $requestId,
                'Request approved: ' . $actionType,
                ['review_note' => $reviewNote],
                $reviewedBy
            );
            return true;
        } catch(Throwable $e){
            $conn->rollback();
            $error = 'Approve failed: ' . $e->getMessage();
            return false;
        }
    }

    $status = 'rejected';
    $u = $conn->prepare("UPDATE change_requests SET status=?, reviewed_by=?, review_note=?, reviewed_at=NOW() WHERE id=?");
    $u->bind_param("sisi", $status, $reviewedBy, $reviewNote, $requestId);
    $ok = $u->execute();
    $u->close();
    if(!$ok){
        $error = 'Reject failed.';
        return false;
    }

    activity_notify_local(
        $conn,
        (string)$requestRow['module_key'],
        'change_request_rejected',
        'change_request',
        $requestId,
        'Request rejected: ' . $actionType,
        ['review_note' => $reviewNote],
        $reviewedBy
    );
    return true;
}

function apply_feed_delete_local($conn, $id){
    $biltyStmt = $conn->prepare("SELECT bilty_no FROM bilty WHERE id=? LIMIT 1");
    $biltyStmt->bind_param("i", $id);
    $biltyStmt->execute();
    $biltyRow = $biltyStmt->get_result()->fetch_assoc();
    $biltyStmt->close();
    if(!$biltyRow) return false;

    $entriesStmt = $conn->prepare("SELECT category, amount_mode, amount FROM account_entries WHERE bilty_id=? AND entry_type='debit'");
    $entriesStmt->bind_param("i", $id);
    $entriesStmt->execute();
    $entriesRes = $entriesStmt->get_result();

    $insReturn = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'credit', ?, NULL, NULL, ?, ?)");
    $today = date('Y-m-d');
    $biltyNo = isset($biltyRow['bilty_no']) ? (string)$biltyRow['bilty_no'] : (string)$id;
    while($entriesRes && $r = $entriesRes->fetch_assoc()){
        $category = isset($r['category']) ? (string)$r['category'] : 'feed';
        $amountMode = isset($r['amount_mode']) && $r['amount_mode'] !== '' ? (string)$r['amount_mode'] : 'cash';
        $amount = isset($r['amount']) ? (float)$r['amount'] : 0;
        if($amount <= 0) continue;
        $note = "Auto Return - Deleted Feed Bilty " . $biltyNo;
        $insReturn->bind_param("sssds", $today, $category, $amountMode, $amount, $note);
        $insReturn->execute();
    }
    $insReturn->close();
    $entriesStmt->close();

    $delStmt = $conn->prepare("DELETE FROM bilty WHERE id=?");
    $delStmt->bind_param("i", $id);
    $delStmt->execute();
    $ok = $delStmt->affected_rows > 0;
    $delStmt->close();
    return $ok;
}

function apply_haleeb_delete_local($conn, $id){
    $biltyStmt = $conn->prepare("SELECT token_no FROM haleeb_bilty WHERE id=? LIMIT 1");
    $biltyStmt->bind_param("i", $id);
    $biltyStmt->execute();
    $biltyRow = $biltyStmt->get_result()->fetch_assoc();
    $biltyStmt->close();
    if(!$biltyRow) return false;

    $entriesStmt = $conn->prepare("SELECT category, amount_mode, amount FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit'");
    $entriesStmt->bind_param("i", $id);
    $entriesStmt->execute();
    $entriesRes = $entriesStmt->get_result();

    $insReturn = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'credit', ?, NULL, NULL, ?, ?)");
    $today = date('Y-m-d');
    $tokenNo = isset($biltyRow['token_no']) ? (string)$biltyRow['token_no'] : (string)$id;
    while($entriesRes && $r = $entriesRes->fetch_assoc()){
        $category = isset($r['category']) ? (string)$r['category'] : 'haleeb';
        $amountMode = isset($r['amount_mode']) && $r['amount_mode'] !== '' ? (string)$r['amount_mode'] : 'cash';
        $amount = isset($r['amount']) ? (float)$r['amount'] : 0;
        if($amount <= 0) continue;
        $note = "Auto Return - Deleted Haleeb Token " . $tokenNo;
        $insReturn->bind_param("sssds", $today, $category, $amountMode, $amount, $note);
        $insReturn->execute();
    }
    $insReturn->close();
    $entriesStmt->close();

    $delStmt = $conn->prepare("DELETE FROM haleeb_bilty WHERE id=?");
    $delStmt->bind_param("i", $id);
    $delStmt->execute();
    $ok = $delStmt->affected_rows > 0;
    $delStmt->close();
    return $ok;
}

function apply_feed_pay_local($conn, $entityId, $payload, &$error){
    $error = '';
    if($entityId <= 0){ $error = 'Invalid feed entity id.'; return false; }

    $biltyStmt = $conn->prepare("SELECT bilty_no, COALESCE(original_freight, GREATEST((COALESCE(freight,0) - COALESCE(commission,0)),0)) AS freight_total FROM bilty WHERE id=? LIMIT 1");
    $biltyStmt->bind_param("i", $entityId);
    $biltyStmt->execute();
    $biltyRow = $biltyStmt->get_result()->fetch_assoc();
    $biltyStmt->close();
    if(!$biltyRow){ $error = 'Linked feed bilty not found.'; return false; }

    $entryDate = isset($payload['entry_date']) ? (string)$payload['entry_date'] : date('Y-m-d');
    $category = isset($payload['category']) ? trim((string)$payload['category']) : 'feed';
    $amountMode = isset($payload['amount_mode']) ? trim((string)$payload['amount_mode']) : 'account';
    $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0;
    $note = isset($payload['note']) ? (string)$payload['note'] : '';
    if($amount <= 0){ $error = 'Invalid payment amount.'; return false; }
    if(!in_array($amountMode, ['cash', 'account'], true)){ $error = 'Invalid payment mode.'; return false; }

    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE bilty_id=? AND entry_type='debit'");
    $paidStmt->bind_param("i", $entityId);
    $paidStmt->execute();
    $paidRow = $paidStmt->get_result()->fetch_assoc();
    $paidStmt->close();
    $remaining = max(0, (float)$biltyRow['freight_total'] - (float)($paidRow['paid_total'] ?? 0));
    if($amount > $remaining){
        $error = 'Requested payment exceeds current remaining freight.';
        return false;
    }

    $ins = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, amount, note) VALUES(?, ?, 'debit', ?, ?, ?, ?)");
    $ins->bind_param("sssids", $entryDate, $category, $amountMode, $entityId, $amount, $note);
    $ok = $ins->execute();
    $ins->close();
    return (bool)$ok;
}

function apply_haleeb_pay_local($conn, $entityId, $payload, &$error){
    $error = '';
    if($entityId <= 0){ $error = 'Invalid haleeb entity id.'; return false; }

    $biltyStmt = $conn->prepare("SELECT token_no, GREATEST((COALESCE(freight,0) - COALESCE(commission,0)),0) AS freight_total FROM haleeb_bilty WHERE id=? LIMIT 1");
    $biltyStmt->bind_param("i", $entityId);
    $biltyStmt->execute();
    $biltyRow = $biltyStmt->get_result()->fetch_assoc();
    $biltyStmt->close();
    if(!$biltyRow){ $error = 'Linked haleeb bilty not found.'; return false; }

    $entryDate = isset($payload['entry_date']) ? (string)$payload['entry_date'] : date('Y-m-d');
    $category = isset($payload['category']) ? trim((string)$payload['category']) : 'haleeb';
    $amountMode = isset($payload['amount_mode']) ? trim((string)$payload['amount_mode']) : 'account';
    $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0;
    $note = isset($payload['note']) ? (string)$payload['note'] : '';
    if($amount <= 0){ $error = 'Invalid payment amount.'; return false; }
    if(!in_array($amountMode, ['cash', 'account'], true)){ $error = 'Invalid payment mode.'; return false; }

    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_total FROM account_entries WHERE haleeb_bilty_id=? AND entry_type='debit'");
    $paidStmt->bind_param("i", $entityId);
    $paidStmt->execute();
    $paidRow = $paidStmt->get_result()->fetch_assoc();
    $paidStmt->close();
    $remaining = max(0, (float)$biltyRow['freight_total'] - (float)($paidRow['paid_total'] ?? 0));
    if($amount > $remaining){
        $error = 'Requested payment exceeds current remaining freight.';
        return false;
    }

    $ins = $conn->prepare("INSERT INTO account_entries(entry_date, category, entry_type, amount_mode, bilty_id, haleeb_bilty_id, amount, note) VALUES(?, ?, 'debit', ?, NULL, ?, ?, ?)");
    $ins->bind_param("sssids", $entryDate, $category, $amountMode, $entityId, $amount, $note);
    $ok = $ins->execute();
    $ins->close();
    return (bool)$ok;
}

function apply_change_request_local($conn, $requestRow, &$error){
    $error = '';
    $payload = [];
    if(isset($requestRow['payload']) && $requestRow['payload'] !== ''){
        $decoded = json_decode((string)$requestRow['payload'], true);
        if(is_array($decoded)) $payload = $decoded;
    }

    $entityId = isset($requestRow['entity_id']) ? (int)$requestRow['entity_id'] : 0;
    $actionType = isset($requestRow['action_type']) ? (string)$requestRow['action_type'] : '';

    if($actionType === 'feed_update'){
        if($entityId <= 0){ $error = 'Invalid feed entity id.'; return false; }
        $sr = isset($payload['sr_no']) ? trim((string)$payload['sr_no']) : '';
        $d = isset($payload['date']) ? (string)$payload['date'] : date('Y-m-d');
        $v = isset($payload['vehicle']) ? trim((string)$payload['vehicle']) : '';
        $b = isset($payload['bilty_no']) ? trim((string)$payload['bilty_no']) : '';
        $party = isset($payload['party']) ? trim((string)$payload['party']) : '';
        $l = isset($payload['location']) ? trim((string)$payload['location']) : '';
        $bags = isset($payload['bags']) ? (int)$payload['bags'] : 0;
        $f = isset($payload['freight']) ? max(0, round((float)$payload['freight'], 3)) : 0.0;
        $commission = isset($payload['commission']) ? max(0, round((float)$payload['commission'], 3)) : 0.0;
        $t = isset($payload['tender']) ? max(0, round((float)$payload['tender'], 3)) : 0.0;
        $totalFreight = max(0, $f - $commission);
        $p = $t - $totalFreight;
        $stmt = $conn->prepare("UPDATE bilty SET sr_no=?, date=?, vehicle=?, bilty_no=?, party=?, location=?, bags=?, freight=?, commission=?, original_freight=?, tender=?, profit=? WHERE id=?");
        $stmt->bind_param("ssssssidddddi", $sr, $d, $v, $b, $party, $l, $bags, $f, $commission, $totalFreight, $t, $p, $entityId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    if($actionType === 'feed_delete'){
        if($entityId <= 0){ $error = 'Invalid feed entity id.'; return false; }
        return apply_feed_delete_local($conn, $entityId);
    }

    if($actionType === 'haleeb_update'){
        if($entityId <= 0){ $error = 'Invalid haleeb entity id.'; return false; }
        $d = isset($payload['date']) ? (string)$payload['date'] : date('Y-m-d');
        $v = isset($payload['vehicle']) ? trim((string)$payload['vehicle']) : '';
        $vt = isset($payload['vehicle_type']) ? trim((string)$payload['vehicle_type']) : '';
        $dn = isset($payload['delivery_note']) ? trim((string)$payload['delivery_note']) : '';
        $tn = isset($payload['token_no']) ? trim((string)$payload['token_no']) : '';
        $party = isset($payload['party']) ? trim((string)$payload['party']) : '';
        $l = isset($payload['location']) ? trim((string)$payload['location']) : '';
        $stops = isset($payload['stops']) ? trim((string)$payload['stops']) : '';
        $f = isset($payload['freight']) ? max(0, round((float)$payload['freight'], 3)) : 0.0;
        $commission = isset($payload['commission']) ? max(0, round((float)$payload['commission'], 3)) : 0.0;
        $t = isset($payload['tender']) ? max(0, round((float)$payload['tender'], 3)) : 0.0;
        $totalFreight = max(0, $f - $commission);
        $p = $t - $totalFreight;
        $stmt = $conn->prepare("UPDATE haleeb_bilty SET date=?, vehicle=?, vehicle_type=?, delivery_note=?, token_no=?, party=?, location=?, stops=?, freight=?, commission=?, tender=?, profit=? WHERE id=?");
        $stmt->bind_param("ssssssssddddi", $d, $v, $vt, $dn, $tn, $party, $l, $stops, $f, $commission, $t, $p, $entityId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    if($actionType === 'haleeb_delete'){
        if($entityId <= 0){ $error = 'Invalid haleeb entity id.'; return false; }
        return apply_haleeb_delete_local($conn, $entityId);
    }

    if($actionType === 'account_update'){
        if($entityId <= 0){ $error = 'Invalid account entry id.'; return false; }
        $entryDate = isset($payload['entry_date']) ? (string)$payload['entry_date'] : date('Y-m-d');
        $category = isset($payload['category']) ? (string)$payload['category'] : 'feed';
        $entryType = isset($payload['entry_type']) ? (string)$payload['entry_type'] : 'debit';
        $amountMode = isset($payload['amount_mode']) ? (string)$payload['amount_mode'] : 'cash';
        $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0;
        $note = isset($payload['note']) ? (string)$payload['note'] : '';
        $stmt = $conn->prepare("UPDATE account_entries SET entry_date=?, category=?, entry_type=?, amount_mode=?, amount=?, note=? WHERE id=?");
        $stmt->bind_param("ssssdsi", $entryDate, $category, $entryType, $amountMode, $amount, $note, $entityId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    if($actionType === 'account_delete'){
        if($entityId <= 0){ $error = 'Invalid account entry id.'; return false; }
        $stmt = $conn->prepare("DELETE FROM account_entries WHERE id=?");
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }

    if($actionType === 'feed_pay'){
        return apply_feed_pay_local($conn, $entityId, $payload, $error);
    }

    if($actionType === 'haleeb_pay'){
        return apply_haleeb_pay_local($conn, $entityId, $payload, $error);
    }

    if($actionType === 'activity_flag'){
        if($entityId <= 0){ $error = 'Invalid activity notification id.'; return false; }
        $stmt = $conn->prepare("UPDATE activity_notifications SET status='ok', flagged_for_admin=0 WHERE id=?");
        $stmt->bind_param("i", $entityId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    $error = 'Unsupported action type.';
    return false;
}
?>
