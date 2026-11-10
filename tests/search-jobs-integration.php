<?php
// Disposable PostGIS integration only: real queue execution, snapshots and ownership.
(function()use($db,$administrator): void {
    $queue=new \SweepLine\SearchJobs($db);$user=(int)$administrator['id'];
    $tasks=(int)$db->query('SELECT count(*) FROM data_tasks')->fetchColumn();
    $input=['zip'=>'12345','radius_miles'=>5,'profile'=>'open','max_stops'=>100,'surface_filter'=>'any','record_history'=>true];
    $queued=$queue->enqueue($user,$input);$id=$queued['search_job']['id'];
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
    for($radius=10;$radius<14;$radius++)$queue->enqueue($user,array_replace($input,['radius_miles'=>$radius,'record_history'=>false]));
    try{$queue->enqueue($user,array_replace($input,['radius_miles'=>14,'record_history'=>false]));check(false,'Search admission bound');}
    catch(\SweepLine\HttpException $e){check(true,'Concurrent distinct searches are queued with explicit admission limits');}
    $db->prepare('DELETE FROM search_jobs WHERE user_id=?')->execute([$user]);
})();
