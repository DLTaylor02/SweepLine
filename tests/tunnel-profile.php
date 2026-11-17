<?php
(function():void{
    $road=[];for($x=0;$x<=300;$x+=5)$road[]=[$x,100.,10.];
    $profiles=array_fill(0,count($road),['width_m'=>8.]);
    $span=['start_m'=>100.,'end_m'=>200.,'clearance_m'=>4.8,'clearance_source'=>'generic_estimate','estimated'=>true];
    $tunnel=['name'=>'bore','road'=>$road,'profiles'=>$profiles,'spans'=>[$span]];
    $cross=['name'=>'overlying_street','road'=>[[150.,60.,14.8],[150.,140.,14.8]],'profiles'=>array_fill(0,2,['width_m'=>8.]),'spans'=>[]];
    $reject=static function(callable $fn):bool{try{$fn();return false;}catch(\RuntimeException $e){return true;}};
    $before=serialize([$tunnel,$cross]);$noop=static fn($m)=>null;
    $fit=SweepLine\BeamTunnelProfile::fit([$tunnel,$cross],5.,$noop);
    check(count($fit['adjustments'])===1&&abs($fit['adjustments'][0]['lowering_m']-.62)<1e-6,'Estimated tunnel floor fits below the overlying pavement, roof shell and retained cover margin');
    $corrected=$fit['surfaces'][0];
    check($corrected['road'][0]===$road[0]&&$corrected['road'][10]===$road[10]&&$corrected['road'][50]===$road[50]&&$corrected['road'][60]===$road[60]
        &&$fit['surfaces'][1]===$cross&&$corrected['spans']===$tunnel['spans']&&serialize([$tunnel,$cross])===$before,'Tunnel fitting preserves XY, far approach anchors, clearance and crossing-road geometry without mutating inputs');
    $smooth=true;foreach($corrected['road'] as $i=>$p)if($i&&abs($p[2]-$corrected['road'][$i-1][2])/5>.12)$smooth=false;
    check($smooth&&SweepLine\BeamTunnelProfile::fit($fit['surfaces'],5.,$noop)['adjustments']===[],'Corrected tunnel approaches satisfy grade limits and profile fitting converges');
    $raster=new SweepLine\BeamRaster(64,320,str_repeat(pack('g',15.),4096),str_repeat(chr(100),12288));
    $triangles=SweepLine\BeamTerrainContact::ordinaryTriangles($cross['road'],$cross['profiles'],[]);
    $constraints=SweepLine\BeamTerrainContact::structureConstraints($fit['surfaces'],$raster,$noop);
    $report=SweepLine\BeamTerrainContact::triangles($triangles,$raster,0.,$constraints['mask'],$noop,true,'',$constraints['bridge_undersides'],$constraints['tunnel_roofs']);
    $retained=true;foreach($constraints['tunnel_roofs'] as $id=>$roof)if($raster->cell($id%64,intdiv($id,64))<$roof-.00001)$retained=false;
    check($report['tunnel_cover_vertices_lowered']>0&&$retained&&SweepLine\BeamTerrainContact::obstruction($triangles,$raster,0.)===null,'Crossing-road terrain clears after fitting without cutting through the tunnel outer roof');
    $conflict=new SweepLine\BeamRaster(64,320,str_repeat(pack('g',15.),4096),str_repeat(chr(100),12288));
    $raw=SweepLine\BeamTerrainContact::structureConstraints([$tunnel,$cross],$conflict,$noop);
    SweepLine\BeamTerrainContact::triangles($triangles,$conflict,0.,$raw['mask'],$noop,true,'',$raw['bridge_undersides'],$raw['tunnel_roofs']);
    check(SweepLine\BeamTerrainContact::obstruction($triangles,$conflict,0.)!==null,'An unfitted tunnel roof cannot be excavated just to make the upper road pass validation');
    $fixed=$tunnel;$fixed['spans'][0]['estimated']=false;
    check($reject(fn()=>SweepLine\BeamTunnelProfile::fit([$fixed,$cross],5.,$noop)),'Non-estimated tunnel geometry is never silently adjusted');
    $deep=$tunnel;$deep['spans'][0]['clearance_m']=8.;
    check($reject(fn()=>SweepLine\BeamTunnelProfile::fit([$deep,$cross],5.,$noop)),'Excessive underground correction fails instead of shrinking mapped clearance');
    $steep=$tunnel;foreach($steep['road'] as &$p)$p[2]+=.12*($p[0]-150);unset($p);
    check($reject(fn()=>SweepLine\BeamTunnelProfile::fit([$steep,$cross],5.,$noop)),'An estimated profile cannot be fitted by exceeding the tunnel approach grade');
    $posted=$tunnel;$posted['spans'][0]['clearance_m']=5.;$posted['spans'][0]['clearance_source']='posted_limit_plus_estimated_margin';
    $f=SweepLine\BeamTunnelProfile::fit([$posted,$cross],5.,$noop);
    check($f['surfaces'][0]['spans'][0]['clearance_m']===5.,'Profile fitting retains posted tunnel clearance and its existing margin');
})();

(function():void{
    // Local measured replay, translated and anonymized; not a full export fixture.
    $data=json_decode(file_get_contents(__DIR__.'/fixtures/tunnel-overpass-contact.json'),true,512,JSON_THROW_ON_ERROR);
    check(SweepLine\BeamTerrainDiagnostic::replay($data)!==null&&SweepLine\BeamTerrainDiagnostic::replay($data,true)!==null,'Measured tunnel/upper-road conflict remains blocked when only terrain correction is attempted');
    $surfaces=[];
    foreach($data['nearby_surfaces'] as $s){
        $road=[];$profiles=[];$offset=null;$last=null;
        foreach($s['segments'] as $seg){
            if($last!==null&&abs($last-$seg['start_m'])>.001)throw new RuntimeException('Fixture contains a non-contiguous local road');
            if(!$road){$road[]=$seg['a'];$profiles[]=['width_m'=>$seg['width_a_m']];$offset=$seg['start_m'];}
            $road[]=$seg['b'];$profiles[]=['width_m'=>$seg['width_b_m']];$last=$seg['end_m'];
        }
        $surfaces[]=['name'=>$s['name'],'road'=>$road,'profiles'=>$profiles,'spans'=>$s['spans'],'chainage_offset'=>$offset];
    }
    $fit=SweepLine\BeamTunnelProfile::fit($surfaces,$data['raster']['spacing_m'],static fn($m)=>null);
    foreach($data['nearby_surfaces'] as $i=>&$s)foreach($s['segments'] as $j=>&$seg){
        $seg['a']=$fit['surfaces'][$i]['road'][$j];$seg['b']=$fit['surfaces'][$i]['road'][$j+1];
    }unset($s,$seg);
    check(count($fit['adjustments'])===2&&SweepLine\BeamTerrainDiagnostic::replay($data,true)===null,'General profile fitting and bounded cover correction clear the measured two-bore crossing without location-specific rules');
})();
