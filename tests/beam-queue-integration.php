<?php
(function()use($db,$administrator):void{
    $db->beginTransaction();$jobs=new \SweepLine\BeamJobs($db);$prefix=bin2hex(random_bytes(12));$ids=[];
    try{
        $base=(int)$db->query("SELECT count(*) FROM beamng_exports WHERE status='running' OR (status='queued' AND cancel_requested=false)")->fetchColumn();
        $insert=$db->prepare("INSERT INTO beamng_exports(id,user_id,fingerprint,name,payload,status,created_at) VALUES (?,?,?,'Queue fixture','{}'::jsonb,?,'1900-01-01'::timestamptz)");
        for($i=0;$i<7;$i++){$ids[]=$prefix.sprintf('%08x',$i);$insert->execute([$ids[$i],$administrator['id'],'queue-'.$ids[$i],$i===0?'running':'queued']);}
        $running=$jobs->status((int)$administrator['id'],$ids[0]);$second=$jobs->status((int)$administrator['id'],$ids[1]);$third=$jobs->status((int)$administrator['id'],$ids[2]);
        check($running['queue_position']===1&&$running['queue_total']===$base+7,'Running export is first and included in total queue count');
        check($third['queue_position']===$second['queue_position']+1,'Equal-time queued exports retain deterministic worker ID order');
        $jobs->cancel($ids[1],(int)$administrator['id']);$updated=$jobs->status((int)$administrator['id'],$ids[2]);
        check($updated['queue_position']===$second['queue_position']&&$updated['queue_total']===$base+6,'Cancelled waiting export is removed from live queue positions');
        $cancelled=$jobs->status((int)$administrator['id'],$ids[1]);check($cancelled['queue_position']===null,'Terminal exports have no active queue position');
        $db->prepare("UPDATE beamng_exports SET status='complete',expires_at=now()+interval '30 minutes' WHERE id=?")->execute([$ids[0]]);
        check($jobs->status((int)$administrator['id'],$ids[2])['queue_total']===$base+5,'Completed exports are excluded from the active queue total');
    }finally{$db->rollBack();}
})();
