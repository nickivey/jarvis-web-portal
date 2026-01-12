<?php
require_once __DIR__ . '/../db.php';
$pdo = jarvis_pdo();
var_dump((bool)$pdo);
$id = jarvis_enqueue_job('debug_test', ['x'=>1]);
var_dump($id);
echo "Done\n";
