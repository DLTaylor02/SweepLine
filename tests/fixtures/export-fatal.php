<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
if(PHP_SAPI!=='cli'||count($argv)!==3)exit(2);
if(!class_exists(PDO::class)){fwrite(STDERR,"Fatal export fixture requires the PDO extension in the child PHP configuration.\n");exit(2);}
final class ExportFatalStatement extends PDOStatement
{
    public function __construct(private string $path){}
    public function execute(?array $params=null): bool {file_put_contents($this->path,json_encode($params,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);return true;}
}
final class ExportFatalDatabase extends PDO
{
    public function __construct(private string $path){}
    public function inTransaction(): bool {return false;}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new ExportFatalStatement($this->path);}
}
$db=new ExportFatalDatabase($argv[1]);SweepLine\BeamFailure::install($db);SweepLine\BeamFailure::install($db);
SweepLine\BeamFailure::begin(str_repeat('a',32));SweepLine\BeamFailure::stage('Serializing terrain');
if($argv[2]==='memory'){$memory=str_repeat('x',32*1024*1024);}elseif($argv[2]==='fatal'){sweeplineDeliberateUndefinedFixtureFunction();}else exit(2);
