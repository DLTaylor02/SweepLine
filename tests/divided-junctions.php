<?php
// Synthetic shared-road / divided-road junctions. No named-road fixture or provider.
(function(): void {
    $main=[];$raw=[];for($x=0;$x<=500;$x+=5){$main[]=[$x,100,10.];$one=$x>100&&$x<400;$p=\SweepLine\BeamLanes::infer(['lanes'=>$one?'2':'4'],'trunk',$one?1:0);$p['legal_forward']=true;$raw[]=$p;}
    $profiles=\SweepLine\BeamRoadSurface::shoulders(\SweepLine\BeamRoadSurface::taper($main,$raw));
    $road=[];for($x=400;$x>=100;$x-=5){$distance=min(400-$x,$x-100);$road[]=[$x,100+15*min(1,$distance/80),10.];}
    $one=\SweepLine\BeamLanes::infer(['lanes'=>'2'],'trunk',1);$one['legal_forward']=true;
    $own=\SweepLine\BeamRoadSurface::shoulders(\SweepLine\BeamRoadSurface::taper($road,array_fill(0,count($road),$one)));
    $chain=[0.];foreach($road as $i=>$p)if($i)$chain[]=$chain[$i-1]+hypot($p[0]-$road[$i-1][0],$p[1]-$road[$i-1][1]);
    $track=['name'=>'opposite','road'=>$road,'profiles'=>$own,'chain'=>$chain,'merge_nodes'=>[[400,100],[100,100]],'bridges'=>[],'tunnels'=>[],'context'=>['roads'=>[],'junctions'=>[]]];
    $aligned=\SweepLine\BeamCarriageways::conformMerges($track,$main,$profiles,[0,0,512,512]);
    check(($aligned['divided_junction_alignment']['junctions']??0)===2,'Both verified divided junctions receive coordinated lane handoffs');
    $last=count($road)-1;
    check(abs($aligned['road'][0][1]-103.75)<1e-7&&abs($aligned['road'][$last][1]-103.75)<1e-7,'Both returning endpoints enter the opposing lane group rather than the shared-road centre');
    check(abs($aligned['profiles'][0]['carriageway_width_m']-7.5)<1e-7&&abs($aligned['profiles'][$last]['carriageway_width_m']-7.5)<1e-7,'Endpoint driving widths match the receiving directional lane allocation');
    $sections=\SweepLine\BeamJunctionBoundary::sections($aligned['road'],$aligned['profiles']);
    check(abs($sections[0][0][1]-99.3904)<1e-7&&abs($sections[0][1][1]-108.1096)<1e-7,'The shared endpoint cross-section exactly matches the receiving divider and outer edge');
    $reverseMain=array_reverse($main);$reverseRaw=array_reverse($raw);foreach($reverseRaw as &$p)if($p['oneway'])$p['legal_forward']=false;unset($p);
    $reverseProfiles=\SweepLine\BeamRoadSurface::shoulders(\SweepLine\BeamRoadSurface::taper($reverseMain,$reverseRaw));
    $reverseAligned=\SweepLine\BeamCarriageways::conformMerges($track,$reverseMain,$reverseProfiles,[0,0,512,512]);
    check(abs($reverseAligned['road'][0][1]-103.75)<1e-7&&abs($reverseAligned['road'][$last][1]-103.75)<1e-7,'Reversing the selected road preserves the same physical directional handoff');

    $without=$track;$without['merge_nodes']=[];
    check(\SweepLine\BeamDividedJunctions::align($without,$main,$profiles,[0,0,512,512])===$without,'Nearby roads without a mapped shared node are not repositioned');
    $allOne=array_fill(0,count($main),$own[0]);
    check(\SweepLine\BeamDividedJunctions::align($track,$main,$allOne,[0,0,512,512])===$track,'Fully divided highways retain their established geometry');
    $protected=$track;$protected['bridges']=[['start_m'=>0,'end_m'=>20]];
    $protected=\SweepLine\BeamDividedJunctions::align($protected,$main,$profiles,[0,0,512,512]);
    check($protected['road'][0]===$road[0]&&!isset($protected['profiles'][0]['section_override']),'A bridge-protected endpoint is never moved by junction alignment');
    $aligned=\SweepLine\BeamMedian::separate($main,$profiles,$aligned);
    $parse=static function(string $xml,array $origin): array {
        $doc=simplexml_load_string($xml);$doc->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
        $v=array_map('floatval',preg_split('/\s+/',trim((string)$doc->xpath('//c:source[@id="positions"]/c:float_array')[0])));$out=[];
        foreach($doc->xpath('//c:triangles') as $group){$indices=array_map('intval',preg_split('/\s+/',trim((string)$group->p)));$stride=count($group->input);
            for($i=0;$i<count($indices);$i+=3*$stride){$triangle=[];for($j=0;$j<3;$j++){$point=array_slice($v,3*$indices[$i+$j*$stride],3);foreach([0,1,2] as $axis)$point[$axis]+=$origin[$axis];$triangle[]=$point;}$out[]=['points'=>$triangle,'material'=>(string)$group['material']];}
        }return $out;
    };
    $mainMesh=\SweepLine\BeamPavement::build(['points'=>$main,'profiles'=>$profiles],$main,$profiles,'fixture');
    $obstacle=['road'=>$main,'profiles'=>$profiles,'weld_boundary'=>false,'preserve_junction_paint'=>true];
    $branch=['points'=>$aligned['road'],'profiles'=>$aligned['profiles']];$sections=\SweepLine\BeamJunctionBoundary::sections($branch['points'],$branch['profiles']);
    $mesh=\SweepLine\BeamPavement::build($branch,$main,$profiles,'fixture',$sections,[$obstacle]);
    $paint=\SweepLine\BeamLanePaint::build($branch,$sections,$main,$profiles,'fixture',[$obstacle]);
    check($paint!==null,'Returning lane markings are generated through the shared handoff');
    $pavement=array_merge($parse($mainMesh['xml'],$mainMesh['position']),$parse($mesh['xml'],$mesh['position']));
    $white=array_values(array_filter($parse($paint['xml'],$paint['position']),static fn($t)=>str_ends_with($t['material'],'_white')));
    $contains=static function($triangles,$p):bool {foreach($triangles as $triangle){$w=\SweepLine\BeamTerrainContact::weights($p,$triangle['points']);if($w&&min($w)>=-1e-7)return true;}return false;};
    $actual=[0.];foreach($branch['points'] as $i=>$p)if($i)$actual[]=$actual[$i-1]+hypot($p[0]-$branch['points'][$i-1][0],$p[1]-$branch['points'][$i-1][1]);$total=end($actual);
    $covered=true;$marked=true;$wide=true;$sampleCount=0;
    for($at=1.5;$at<$total;$at+=12){
        if(min($at,$total-$at)>65)continue;$sampleCount++;$j=1;while($actual[$j]<$at)$j++;$t=($at-$actual[$j-1])/($actual[$j]-$actual[$j-1]);$ring=[];
        foreach([0,1] as $side)foreach([0,1,2] as $axis)$ring[$side][$axis]=$sections[$j-1][$side][$axis]*(1-$t)+$sections[$j][$side][$axis]*$t;
        $width=hypot($ring[1][0]-$ring[0][0],$ring[1][1]-$ring[0][1]);$shoulder=$branch['profiles'][$j]['shoulder_m'];$wide=$wide&&($width-2*$shoulder)/2>=3.5-1e-6;
        for($k=0;$k<=10;$k++){$u=$shoulder/$width+(1-2*$shoulder/$width)*$k/10;$p=[];foreach([0,1,2] as $axis)$p[$axis]=$ring[0][$axis]+($ring[1][$axis]-$ring[0][$axis])*$u;$covered=$covered&&$contains($pavement,$p);}
        $center=[($ring[0][0]+$ring[1][0])/2,($ring[0][1]+$ring[1][1])/2];$marked=$marked&&$contains($white,$center);
    }
    check($sampleCount>8&&$wide,'Both returning lanes retain full driving width through both junctions');
    check($covered,'Combined collision pavement covers the entire returning lane width after overlap clipping');
    check($marked,'Returning dashed dividers survive clipping over the shared primary pavement');
    $objects=[];foreach([0,$last] as $i){$join=$aligned['merge_junctions'][$i===0?0:1];$objects[]=['class'=>'BeamNGWaypoint','name'=>'opposite_'.$i,'position'=>$aligned['road'][$i]];$objects[]=['class'=>'BeamNGWaypoint','name'=>'main_'.$i,'position'=>$join['primary_position']];}
    $segments=[];check(\SweepLine\BeamCarriageways::connectNavigation($objects,[$aligned],$segments)===2,'Aligned directional endpoints retain both mapped traffic connections');
    $unverified=$aligned;foreach($unverified['merge_junctions'] as &$join)unset($join['primary_position']);unset($join);$segments=[];
    check(\SweepLine\BeamCarriageways::connectNavigation($objects,[$unverified],$segments)===0,'Lateral proximity alone cannot create traffic links');
})();
