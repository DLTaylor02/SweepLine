<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$db=SweepLine\Database::connect();
// Migrations/index backfills are CLI work, not subject to the short HTTP query timeout.
$db->exec("SET statement_timeout='0'");
SweepLine\Database::migrate($db);
