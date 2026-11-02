<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/src/bootstrap.php';
(new SweepLine\BeamJobs(SweepLine\Database::connect()))->maintain();

