<?php
// Service fixtures verify JSON and bound ownership; HTTP/PostGIS tests verify real access control.
class HistorySnapshotStatement extends PDOStatement {
    public array $parameters=[];
    public function __construct(private HistorySnapshotDatabase $db){}
    public function execute(?array $params=null): bool {$this->parameters=$params??[];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {return $this->db->row;}
    public function rowCount(): int {return $this->db->affected;}
}
class HistorySnapshotDatabase extends PDO {
    public mixed $row=false;public int $affected=1;public string $sql='';public HistorySnapshotStatement $statement;
    public function __construct(){}
    public function prepare(string $query,array $options=[]): PDOStatement|false {$this->sql=$query;return $this->statement=new HistorySnapshotStatement($this);}
}
(function(): void {
    $db=new HistorySnapshotDatabase();$service=new \SweepLine\SearchHistory($db);$parameters=['zip'=>'12345'];
    $results=['results'=>[['id'=>2,'score'=>72,'geometry'=>['type'=>'LineString','coordinates'=>[[0,0],[1,1]]]]],'preparation'=>['state'=>'ready']];
    $service->capture(7,12,$parameters,$results);
    check(json_decode($db->statement->parameters[0],true)===$results&&array_slice($db->statement->parameters,1,2)===[12,7]&&str_contains($db->sql,'user_id=? AND parameters=?::jsonb'),'Snapshots store the actual result and bind entry, owner and matching parameters');
    $db->row=['id'=>12,'parameters'=>json_encode($parameters),'created_at'=>'2026-11-01','snapshot_at'=>'2026-11-01','result_snapshot'=>json_encode($results)];
    $loaded=$service->detail(7,12);
    check($loaded['result_snapshot']===$results&&$db->statement->parameters===[12,7],'Snapshot reads restore exact scores and coordinates with bound ownership');
    $db->row['result_snapshot']=null;check($service->detail(7,12)['result_snapshot']===null,'Legacy history remains explicitly without a snapshot');
    $db->row=false;try{$service->detail(8,12);check(false,'Missing snapshot rejected');}catch(OutOfBoundsException $e){check(true,'Missing or unauthorized history does not expose a snapshot');}
    $db->affected=0;try{$service->capture(8,12,$parameters,$results);check(false,'Mismatched snapshot update rejected');}catch(OutOfBoundsException $e){check(true,'Snapshot update cannot silently ignore mismatched ownership/parameters');}
})();
