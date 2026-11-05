<?php
use SweepLine\ImportCleanup;
final class CleanupFixtureStatement extends PDOStatement
{
    private int $count=0;
    public function __construct(private CleanupFixtureDatabase $db,private string $sql) {}
    public function execute(?array $params=null): bool
    {
        $this->db->executions[]=[$this->sql,$params,$this->db->inTransaction()];
        if(str_starts_with($this->sql,'WITH batch')){
            if($this->db->errorState!==null)throw new CleanupFixtureError($this->db->errorState);
            $this->count=array_shift($this->db->counts)??0;
        }elseif(str_starts_with($this->sql,'DELETE FROM import_jobs'))$this->count=1;
        return true;
    }
    public function fetchColumn(int $column=0): mixed{return $this->db->job;}
    public function rowCount(): int{return $this->count;}
}
final class CleanupFixtureError extends PDOException
{
    public function __construct(string $state){$this->code=$state;$this->message='Fixture database failure';}
}
final class CleanupFixtureDatabase extends PDO
{
    public mixed $job=17;
    public array $counts=[],$sql=[],$executions=[];
    public ?string $errorState=null;
    public int $commits=0,$rollbacks=0;
    private bool $transaction=false;
    public function __construct(){}
    public function inTransaction(): bool{return $this->transaction;}
    public function beginTransaction(): bool{$this->transaction=true;return true;}
    public function commit(): bool{$this->transaction=false;$this->commits++;return true;}
    public function rollBack(): bool{$this->transaction=false;$this->rollbacks++;return true;}
    public function exec(string $statement): int|false{$this->sql[]=$statement;return 0;}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false{$this->sql[]=$query;return new CleanupFixtureStatement($this,$query);}
    public function prepare(string $query,array $options=[]): PDOStatement|false{$this->sql[]=$query;return new CleanupFixtureStatement($this,$query);}
}
$cdb=new CleanupFixtureDatabase();$cdb->job=false;
check(!(new ImportCleanup($cdb))->runOne()&&$cdb->commits===1,'Idle cleanup returns immediately when no eligible imports exist');
check(!array_filter($cdb->sql,static fn($sql)=>str_contains($sql,'DELETE FROM')),'Idle cleanup performs no deletion without an eligible import');
$cdb=new CleanupFixtureDatabase();$cdb->counts=[2000];
check((new ImportCleanup($cdb))->runOne()&&$cdb->commits===1,'One bounded cleanup batch commits and yields back to the worker');
$deletes=array_values(array_filter($cdb->executions,static fn($x)=>str_contains($x[0],'DELETE FROM')));
check(count($deletes)===1&&str_contains($deletes[0][0],'LIMIT 2000')&&$deletes[0][1]===[17],'Cleanup limits and parameterizes a single child-row batch');
check(str_contains($cdb->sql[0],"SET LOCAL statement_timeout='2s'")&&str_contains($cdb->sql[0],"lock_timeout='250ms'"),'Cleanup deadlines are transaction-local even after unlimited export processing');
check(!array_filter($cdb->sql,static fn($sql)=>str_starts_with($sql,'DELETE FROM import_jobs')),'A partially pruned import keeps its parent metadata for resumption');
$cdb=new CleanupFixtureDatabase();$cdb->counts=[0,0,0,0,0,0];
check((new ImportCleanup($cdb))->runOne(),'Empty obsolete import can be finalized');
$deletes=array_values(array_filter($cdb->executions,static fn($x)=>str_contains($x[0],'DELETE FROM')));
check(count($deletes)===7&&str_starts_with(end($deletes)[0],'DELETE FROM import_jobs'),'Parent import is removed only after every child table is empty');
foreach(['57014','55P03'] as $state){
 $cdb=new CleanupFixtureDatabase();$cdb->errorState=$state;
 check(!(new ImportCleanup($cdb))->runOne()&&$cdb->rollbacks===1&&$cdb->commits===0,'Busy cleanup rolls back and defers without preventing queued jobs: '.$state);
 $status=end($cdb->executions);check($status[2]===false&&str_contains($status[0],'worker_activity'),'Deferred cleanup activity is recorded after its transaction is rolled back');
}
$cdb=new CleanupFixtureDatabase();$cdb->errorState='42601';
try{(new ImportCleanup($cdb))->runOne();check(false,'Unexpected cleanup error must surface');}
catch(PDOException $e){check($cdb->rollbacks===1&&$e->getCode()==='42601','Unexpected cleanup errors roll back and remain visible for diagnosis');}
$cdb=new CleanupFixtureDatabase();$cdb->beginTransaction();
try{(new ImportCleanup($cdb))->runOne();check(false,'Cleanup must not adopt caller transactions');}
catch(LogicException $e){check($cdb->inTransaction()&&$cdb->rollbacks===0,'Cleanup preserves an existing caller transaction');}
