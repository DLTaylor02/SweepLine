<?php
require dirname(__DIR__).'/src/bootstrap.php';
$q=SweepLine\Database::connect()->query('SELECT id,region_id,status,progress,error,created_at,finished_at FROM import_jobs ORDER BY id DESC LIMIT 20');
echo json_encode($q->fetchAll(PDO::FETCH_ASSOC),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
