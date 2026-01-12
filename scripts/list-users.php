<?php
require_once __DIR__ . '/../db.php';
$pdo = jarvis_pdo();
if (!$pdo) { echo "No DB\n"; exit(1); }
$stmt = $pdo->query('SELECT id,email,username AS display_name,role,created_at FROM users ORDER BY id DESC LIMIT 20');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
