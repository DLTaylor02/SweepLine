<?php
use SweepLine\BeamApproaches;
(function():void{
    $points=[[100,0],[200,0],[300,0]];
    $sources=[['points'=>[[0,0],[100,0],[200,0],[300,0],[400,0]],'direction'=>0]];
    $out=BeamApproaches::extend($points,$sources,30,55);
    check($out[0]===[70.,0.]&&end($out)===[355.,0.],'Bridge approach adds only the needed connected road at both ends');
    check(array_slice($out,1,3)===$points,'Bridge approach preserves the selected road interior');
    $middle=BeamApproaches::extend([[120,0],[280,0]],$sources,25,25);
    check($middle[0]===[95.,0.]&&end($middle)===[305.,0.],'Approach extension splits source edges at endpoints inside a way');
    $oneway=$sources;$oneway[0]['direction']=1;
    check(BeamApproaches::extend($points,$oneway,30,30)[0]===[70.,0.],'One-way continuation respects direction of travel');
    check(rejects(fn()=>BeamApproaches::extend(array_reverse($points),$oneway,30,0)),'Approach extension cannot follow a one-way source backwards');
    $fork=array_merge($sources,[['points'=>[[100,0],[70,10]],'direction'=>0]]);
    check(rejects(fn()=>BeamApproaches::extend($points,$fork,30,0)),'Ambiguous connected forks are rejected instead of guessed');
    check(rejects(fn()=>BeamApproaches::extend($points,[],30,0)),'Missing local continuation is rejected explicitly');
    check(rejects(fn()=>BeamApproaches::extend($points,$sources,301,0)),'Approach extension has a strict per-end distance budget');
    check(BeamApproaches::extend($points,$sources,0,0)===$points,'Ordinary selections retain identical geometry');
    [$before,$after]=BeamApproaches::needed($points,[['start_m'=>10,'end_m'=>180,'approach_m'=>25]]);
    check($before===20.&&$after===10.,'Required extension includes a small sampling margin');
    [$before,$after]=BeamApproaches::needed($points,[['start_m'=>50,'end_m'=>150,'approach_m'=>25]]);
    check($before===0.&&$after===0.,'Bridge selections with adequate approaches do not extend');
})();
