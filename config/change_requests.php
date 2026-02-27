<?php
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
    return (int)$requestId;
}

function fetch_pending_change_requests_local($conn){
    $rows = [];
    $sql = "SELECT r.id, r.module_key, r.entity_table, r.entity_id, r.action_type, r.payload, r.status, r.requested_by, r.created_at,
                   u.username AS requested_by_name
            FROM change_requests r
            LEFT JOIN users u ON u.id = r.requested_by
            WHERE r.status='pending'
            ORDER BY r.id DESC";
    $res = $conn->query($sql);
    while($res && $row = $res->fetch_assoc()){
        $rows[] = $row;
    }
    return $rows;
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

    $biltyStmt = $conn->prepare("SELECT bilty_no, COALESCE(original_freight, freight) AS freight_total FROM bilty WHERE id=? LIMIT 1");
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

    $biltyStmt = $conn->prepare("SELECT token_no, freight FROM haleeb_bilty WHERE id=? LIMIT 1");
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
    $remaining = max(0, (float)$biltyRow['freight'] - (float)($paidRow['paid_total'] ?? 0));
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
        $f = isset($payload['freight']) ? (int)$payload['freight'] : 0;
        $t = isset($payload['tender']) ? (int)$payload['tender'] : 0;
        $p = $t - $f;
        $stmt = $conn->prepare("UPDATE bilty SET sr_no=?, date=?, vehicle=?, bilty_no=?, party=?, location=?, bags=?, freight=?, original_freight=?, tender=?, profit=? WHERE id=?");
        $stmt->bind_param("sssssssiiiii", $sr, $d, $v, $b, $party, $l, $bags, $f, $f, $t, $p, $entityId);
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
        $f = isset($payload['freight']) ? (int)$payload['freight'] : 0;
        $t = isset($payload['tender']) ? (int)$payload['tender'] : 0;
        $p = $t - $f;
        $stmt = $conn->prepare("UPDATE haleeb_bilty SET date=?, vehicle=?, vehicle_type=?, delivery_note=?, token_no=?, party=?, location=?, stops=?, freight=?, tender=?, profit=? WHERE id=?");
        $stmt->bind_param("ssssssssiiii", $d, $v, $vt, $dn, $tn, $party, $l, $stops, $f, $t, $p, $entityId);
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

    $error = 'Unsupported action type.';
    return false;
}
?>
