<?php
use SweepLine\{BeamRaster,BeamStructures};
(function():void{
    $raster=static function(float $side,float $bump,float $grade=0.):BeamRaster{
        $n=64;$band='';$step=$side/$n;
        for($y=$n-1;$y>=0;$y--)for($x=0;$x<$n;$x++)$band.=pack('g',100+$x*$step*$grade+(abs($x*$step-300)<0.01?$bump:0));
        return new BeamRaster($n,$side,$band,str_repeat(chr(100),$n*$n*3));
    };
    $points=array_map(static fn($x)=>[$x,300],[100,180,190,200,300,400,410,420,500]);
    $chain=[0,80,90,100,200,300,310,320,400];$span=[['start_m'=>100,'end_m'=>300,'large'=>false]];
    foreach(['Selected road','Opposite carriageway'] as $context){
        $dem=$raster(1280.,0.16);
        $out=BeamStructures::deckSamples($points,$chain,array_fill(0,9,100.),$span,$dem,$context);
        check($out[4]>100.20&&$out[4]<100.22,'Small coarse-DEM conflict is corrected on '.$context);
        check($out[0]===100.&&$out[1]===100.&&$out[7]===100.&&$out[8]===100.,'Bridge correction preserves outer approach anchors');
        check($out[2]>100.&&$out[2]<$out[3]&&abs($out[2]-$out[6])<0.00001,'Bridge correction tapers smoothly and symmetrically');
        check(($dem->provenance['bridge_profile_adjustments'][0]['estimated']??false)===true,'Bridge sampling correction is recorded as an estimate');
    }
    $dem=$raster(1280.,0.);
    check(BeamStructures::deckSamples($points,$chain,array_fill(0,9,100.),$span,$dem)===array_fill(0,9,100.),'Already compatible bridge decks remain unchanged');
    foreach([[1280.,0.75,0.],[640.,0.25,0.],[1280.,0.,0.14],[1280.,-200.,0.],[6400.,0.75,0.]] as [$side,$bump,$grade]){
        try{BeamStructures::deckSamples($points,$chain,array_fill(0,9,100.),$span,$raster($side,$bump,$grade));check(false,'Unsafe bridge estimate rejected');}
        catch(RuntimeException $error){check(true,'Substantial conflicts, grade and clearance limits remain enforced');}
    }
})();
