<?php
// Preparation summaries use explicit task fixtures; no database or provider required.
(function(): void {
    $queue=(new ReflectionClass(\SweepLine\DataQueue::class))->newInstanceWithoutConstructor();
    $summary=new ReflectionMethod($queue,'summary');
    $task=static fn($status,$age=0)=>['status'=>$status,'updated_at'=>gmdate(DATE_ATOM,time()-$age),'progress'=>'Reading roads','error'=>null,'cancel_requested'=>false];
    $done=$summary->invoke($queue,[$task('complete')],true);
    check($done['state']==='ready'&&$done['warning']===''&&$done['complete']===1,'Cached completed coverage reports ready without attention warnings');
    $mixed=$summary->invoke($queue,[$task('complete'),$task('failed'),$task('queued')],true);
    check($mixed['state']==='preparing'&&$mixed['failed']===1&&$mixed['queued']===1&&str_contains($mixed['warning'],'SuperAdmin'),'Pending work cannot hide failed-area warnings or completed-area counts');
    $retry=$task('retry');$retry['next_attempt_at']=gmdate(DATE_ATOM,time()+120);
    $stalled=$summary->invoke($queue,[$task('running',1800),$retry],true);
    check($stalled['stalled']===1&&$stalled['running']===1&&$stalled['retrying']===1&&$stalled['next_retry_at']!==null,'Progress older than fifteen minutes is reported separately from scheduled retries');
    check(str_contains($stalled['warning'],'may be slow or stalled')&&$stalled['state']==='preparing','Stall indication remains uncertain and does not cancel or fail an active task');
    $waiting=$summary->invoke($queue,[$task('queued',3600),$retry],true);
    check($waiting['stalled']===0&&$waiting['queued']===1&&$waiting['retrying']===1,'Queued and scheduled retry tasks are not mistaken for stalled running work');
    $terminal=$summary->invoke($queue,[$task('failed')],true);
    check($terminal['state']==='failed'&&$terminal['failed']===1,'Entirely failed preparation reports a terminal failure');
})();
