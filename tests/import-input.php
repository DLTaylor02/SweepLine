<?php
// Missing import sources must fail before touching geographic data and without PHP warnings.
class ImportInputStatement extends PDOStatement
{
    public function __construct(private ImportInputDatabase $db,private string $sql){}
    public function execute(?array $params=null):bool {$this->db->queries[]=['sql'=>$this->sql,'parameters'=>$params??[]];return true;}
}
class ImportInputDatabase extends PDO
{
    public array $queries=[];
    public int $executions=0;
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {return new ImportInputStatement($this,$query);}
    public function exec(string $statement):int|false {$this->executions++;throw new LogicException('Input validation must precede database setup');}
    public function inTransaction():bool {return false;}
}
$inputDirectory=sys_get_temp_dir().'/sl-import-input-'.bin2hex(random_bytes(6));mkdir($inputDirectory,0700);
try{
    foreach([$inputDirectory.'/missing.osm',$inputDirectory] as $source){
        $inputDb=new ImportInputDatabase();$message='';$warnings=[];
        set_error_handler(static function($severity,$message,$file,$line)use(&$warnings){$warnings[]=$message;throw new ErrorException($message,0,$severity,$file,$line);});
        try{(new SweepLine\Importer($inputDb))->run(['id'=>123,'region_id'=>456,'source_path'=>$source]);}
        catch(RuntimeException $e){$message=$e->getMessage();}
        finally{restore_error_handler();}
        check($message==='OSM source file is missing or not readable.'&&$warnings===[],'Missing/non-file import input produces a clear exception without PHP warnings');
        check($inputDb->executions===0&&count($inputDb->queries)===1&&str_contains($inputDb->queries[0]['sql'],"status='failed'"),'Invalid source records failure before running import setup or writing geographic data');
        check($inputDb->queries[0]['parameters']===['OSM source file is missing or not readable.',123],'Missing-source failure stores the expected job ID and safe error message');
    }
}finally{rmdir($inputDirectory);}
