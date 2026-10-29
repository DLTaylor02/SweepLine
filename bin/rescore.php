<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$db=SweepLine\Database::connect();
$db->exec("SET statement_timeout='0'");
SweepLine\GeometryRefresh::run($db);
SweepLine\Scorer::refreshScores($db,true);
