<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
// Arguments are deliberately local files, never shell commands or arbitrary URLs.
if ($argc!==5) { exit("Usage: php bin/import.php region-slug 'Region name' /path/complete.osm west,south,east,north\n"); }
[, $slug,$name,$path,$bbox]=$argv;
if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/',$slug)) { exit("Invalid region slug\n"); }
$bounds=array_map('floatval',explode(',',$bbox));
if (count($bounds)!==4 || !preg_match('/^-?[\d.]+,-?[\d.]+,-?[\d.]+,-?[\d.]+$/',$bbox)) { exit("Invalid bounding box\n"); }
[$w,$s,$e,$n]=$bounds;
if ($w>= $e || $s >= $n || $w< -180 || $e>180 || $s< -90 || $n>90 || $e-$w>5 || $n-$s>5) { exit("Bounding box must be valid and no larger than 5 degrees per side\n"); }
$path=realpath($path);
if (!$path || !is_readable($path) || !str_ends_with($path,'.osm') || filesize($path)>1500000000) { exit("Expected readable .osm XML, maximum 1.5 GB\n"); }
$dir=SWEEPLINE_ROOT.'/var/imports'; if (!is_dir($dir)) { mkdir($dir,0750,true); }
$target=$dir.'/'.bin2hex(random_bytes(12)).'.osm';
if (!copy($path,$target)) { throw new RuntimeException('Could not stage import file'); }
chmod($target,0640);
$db=SweepLine\Database::connect();
$db->beginTransaction();
$q=$db->prepare('INSERT INTO regions(slug,name) VALUES (?,?) ON CONFLICT(slug) DO UPDATE SET name=excluded.name RETURNING id'); $q->execute([$slug,$name]); $region=$q->fetchColumn();
$q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'queued',?,ST_MakeEnvelope(?,?,?,?,4326)) RETURNING id");
$q->execute([$region,$target,$w,$s,$e,$n]); $id=$q->fetchColumn(); $db->commit();
echo "Queued import $id. Worker progress: php bin/status.php\n";
