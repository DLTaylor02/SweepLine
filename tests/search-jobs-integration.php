<?php
// Disposable PostGIS integration only: real queue execution, snapshots and ownership.
(function()use($db,$administrator): void {
    $queue=new \SweepLine\SearchJobs($db);$user=(int)$administrator['id'];
    $tasks=(int)$db->query('SELECT count(*) FROM data_tasks')->fetchColumn();
    $input=['zip'=>'12345','radius_miles'=>5,'profile'=>'open','max_stops'=>100,'surface_filter'=>'any','record_history'=>true];
    $historyId=null;
    try{
    $queued=$queue->enqueue($user,$input);$id=$queued['search_job']['id'];$historyId=$queued['history_id']??null;
    check($queued['search_job']['status']==='queued'&&(int)$db->query('SELECT count(*) FROM data_tasks')->fetchColumn()===$tasks,'Enqueuing discovery performs no coverage preparation or geographic search on the HTTP path');
    check($queue->enqueue($user,$input)['search_job']['id']===$id,'Duplicate pending discovery requests share one worker job');
    try{$queue->status($user+99999,$id);check(false,'Search status ownership');}catch(OutOfBoundsException $e){check(true,'Search job results are private to their owner');}
    $queue->runOne();$done=$queue->status($user,$id);
    check($done['search_job']['status']==='complete'&&is_array($done['result']['results']??null),'Existing PHP worker produces real discovery results outside the web request');
    $history=(new \SweepLine\SearchHistory($db))->detail($user,(int)$done['history_id']);
    check($history['result_snapshot']['results']===$done['result']['results'],'Worker saves the exact search result snapshot');
    $other=$queue->enqueue($user,array_replace($input,['radius_miles'=>6,'record_history'=>false]));$queue->cancel($other['search_job']['id']);
    check($queue->status($user,$other['search_job']['id'])['search_job']['status']==='cancelled','Queued search cancellation does not execute expensive discovery');
    $interrupted=$queue->enqueue($user,array_replace($input,['radius_miles'=>7,'record_history'=>false]));
    $db->prepare("UPDATE search_jobs SET status='running',progress='Grouping overlapping road geometry' WHERE id=?")->execute([$interrupted['search_job']['id']]);
    $queue->recover();$failed=$queue->status($user,$interrupted['search_job']['id']);
    check($failed['search_job']['status']==='failed'&&$failed['search_job']['progress']==='Grouping overlapping road geometry','Worker interruption preserves the last search stage with an explicit failure');
    $beforeTasks=(int)$db->query('SELECT count(*) FROM data_tasks')->fetchColumn();
    $diagnostic=$queue->enqueue($user,array_replace($input,['record_history'=>false]),'Synthetic');
    $queue->runOne();$diagnostic=$queue->status($user,$diagnostic['search_job']['id']);
    check($diagnostic['search_job']['status']==='complete'&&is_array($diagnostic['result']['candidates']??null),'Diagnostic worker returns real candidate exclusion details');
    check((int)$db->query('SELECT count(*) FROM data_tasks')->fetchColumn()===$beforeTasks,'Diagnostic worker never starts regional imports');
    $candidate=$db->query('SELECT c.id FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id LIMIT 1')->fetchColumn();
    $favoriteJob=$queue->enqueue($user,array_replace($input,['record_history'=>false]),null,['road_id'=>(int)$candidate,'search'=>$input,'profile'=>'open']);
    $queue->runOne();$favoriteDone=$queue->status($user,$favoriteJob['search_job']['id']);
    check($favoriteDone['search_job']['status']==='complete'&&($favoriteDone['result']['saved']??false),'Worker resolves and saves a real owned favorite');
    if(isset($favoriteDone['result']['id'])){
        $stored=(new \SweepLine\Favorites($db))->detail($user,(int)$favoriteDone['result']['id']);
        check(!isset($stored['road']['basemap']),'Favorite save defers optional basemap geometry');
        $db->beginTransaction();
        try{
            $db->prepare("INSERT INTO favorite_thumbnails(favorite_id,next_attempt_at) SELECT id,now()+interval '1 hour' FROM favorites WHERE id<>? ON CONFLICT(favorite_id) DO UPDATE SET next_attempt_at=excluded.next_attempt_at")->execute([$favoriteDone['result']['id']]);
            (new \SweepLine\FavoriteThumbnails($db))->runOne();
            $cached=$db->prepare('SELECT svg FROM favorite_thumbnails WHERE favorite_id=?');$cached->execute([$favoriteDone['result']['id']]);
            $svg=$cached->fetchColumn();
            check(is_string($svg)&&str_contains($svg,'<polyline')&&str_contains($svg,'OpenStreetMap contributors'),'Independent thumbnail worker persists a real geographic preview');
        }finally{$db->rollBack();}
        (new \SweepLine\Favorites($db))->delete($user,(int)$favoriteDone['result']['id']);
    }
    for($radius=10;$radius<14;$radius++)$queue->enqueue($user,array_replace($input,['radius_miles'=>$radius,'record_history'=>false]));
    try{$queue->enqueue($user,array_replace($input,['radius_miles'=>14,'record_history'=>false]));check(false,'Search admission bound');}
    catch(\SweepLine\HttpException $e){check(true,'Concurrent distinct searches are queued with explicit admission limits');}
    }finally{
        $db->prepare('DELETE FROM search_jobs WHERE user_id=?')->execute([$user]);
        if($historyId!==null)$db->prepare('DELETE FROM search_history WHERE id=? AND user_id=?')->execute([$historyId,$user]);
    }
    if($historyId!==null){
        $remaining=$db->prepare('SELECT count(*) FROM search_history WHERE id=? AND user_id=?');$remaining->execute([$historyId,$user]);
        check((int)$remaining->fetchColumn()===0,'Search job integration leaves no History fixture for subsequent HTTP tests');
    }
})();
