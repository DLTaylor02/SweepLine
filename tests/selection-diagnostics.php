<?php
(function():void{
    $road=['id'=>7,'returned'=>false,'profile_score'=>80];
    $why=\SweepLine\RoadDiagnostics::selectionReasons($road,['planned'=>[]]);
    check(str_contains($why[0],'top 600'),'Diagnosis identifies the pre-analysis candidate limit');
    $why=\SweepLine\RoadDiagnostics::selectionReasons(array_replace($road,['profile_score'=>null]),[]);
    check(str_contains($why[0],'No cached score'),'Diagnosis distinguishes missing scores from ranking');
    $group=['id'=>8,'name'=>'Combined road','reasons'=>['Mapped stops plus uncertain controls exceed the limit.']];
    $trace=['planned'=>[7],'groups'=>[7=>$group]];
    $why=\SweepLine\RoadDiagnostics::selectionReasons($road,$trace);
    check(str_contains($why[0],'Combined road')&&str_contains($why[0],'stops'),'Diagnosis reports the actual consolidated-road filter failure');
    $trace['groups'][7]['reasons']=[];$trace['ranked']=array_merge(range(100,199),[8]);
    $why=\SweepLine\RoadDiagnostics::selectionReasons($road,$trace);
    check(str_contains($why[0],'Ranked 101'),'Diagnosis reports the final top-100 ranking limit');
    $why=\SweepLine\RoadDiagnostics::selectionReasons(array_replace($road,['returned'=>true]),$trace);
    check(str_contains($why[0],'Included'),'Returned source candidates are not reported as excluded');
    $why=\SweepLine\RoadDiagnostics::selectionReasons($road,['planned'=>[7]]);
    check(str_contains($why[0],'no continuous geometry group'),'Unaccounted geometry is explicit instead of claiming eligibility');
})();
