<?php
require 'auth.php';
requireRole(['EDITOR', 'DEVELOPER']);
require 'db.php';

header('Content-Type: application/json');

$tid    = (int)($_POST['testing_id'] ?? 0);
$table  = (int)($_POST['table_number'] ?? 1);
$state  = (int)($_POST['checked'] ?? 0); // 1 or 0

if (!$tid || !in_array($table, [1, 2])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

$col = ($table === 1) ? 'checked_by_engineer_t1' : 'checked_by_engineer_t2';
$s   = $conn->prepare("UPDATE testing SET $col = ? WHERE testing_id = ?");
$s->bind_param("ii", $state, $tid);
$ok = $s->execute();

echo json_encode([
    'success' => $ok,
    'checked' => $state,
    'table'   => $table,
    'message' => $ok ? 'Updated.' : $conn->error,
]);
$conn->close();
