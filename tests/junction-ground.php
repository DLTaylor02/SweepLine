<?php
(function():void{
    $noop=static fn($m)=>null;$profile=SweepLine\BeamRoadSurface::shoulders([SweepLine\BeamLanes::infer(['lanes'=>'2'],'secondary',0)])[0];
    $make=static function($id,$start,$end,$a,$b,$parent=null)use($profile){
        $points=[];for($i=0;$i<=30;$i++){$p=[];foreach([0,1,2] as $k)$p[]=$a[$k]+($b[$k]-$a[$k])*$i/30;$points[]=$p;}
        return ['name'=>'edge_'.$id,'network'=>true,'kind'=>'receiving_road','start_node'=>$start,'end_node'=>$end,'points'=>$points,'profiles'=>array_fill(0,31,$profile),'bridges'=>[],'tunnels'=>[],'connector'=>[],
            'parent_name'=>$parent,'junction_parent'=>null,'junction_obstacles'=>[],'external_main'=>null,'join'=>['x'=>$a[0],'y'=>$a[1],'z'=>$a[2]]];
    };
    $roads=[$make(1,1,2,[20,80,9],[80,80,10]),$make(2,2,3,[80,80,10],[140,80,11],'edge_1'),$make(3,2,4,[80,80,10],[80,140,14],'edge_1')];
    $prepared=['roads'=>$roads,'main_surfaces'=>[],'junctions'=>[]];
    $out=SweepLine\BeamJunctionGrades::apply($prepared);$b=$out['roads'][1];$c=$out['roads'][2];
    $at=static function($r,$point):?float{
        $s=SweepLine\BeamJunctionBoundary::sections($r['points'],$r['profiles']);
        for($i=1;$i<count($s);$i++)foreach([[$s[$i-1][0],$s[$i-1][1],$s[$i][1]],[$s[$i-1][0],$s[$i][1],$s[$i][0]]] as $t){
            $w=SweepLine\BeamTerrainContact::weights($point,$t);if($w&&min($w)>=-1e-8){$z=0.;foreach($w as $k=>$v)$z+=$v*$t[$k][2];return $z;}
        }return null;
    };
    check($out['junction_grading']['junction_groups']===1&&abs($at($b,[82,82])-$at($c,[82,82]))<1e-7,'Intersecting streets share a continuous sloped surface across their width, not just matching center nodes');
    check(abs(end($b['points'])[2]-end($roads[1]['points'])[2])<1e-9&&array_column($b['points'],0)==array_column($roads[1]['points'],0),'Junction grading preserves distant approaches and mapped XY');
    check($c['junction_parent']['profiles']===$out['roads'][0]['profiles'],'Graded parent cross-sections propagate to child collision geometry');
    $protected=$prepared;$protected['roads'][0]['bridges']=[['start_m'=>40.,'end_m'=>60.]];
    $safe=SweepLine\BeamJunctionGrades::apply($protected);
    check($safe['roads'][0]['points']===$roads[0]['points']&&$safe['junction_grading']['protected_structure_groups']===1,'Mapped bridge endpoint grades are protected from an unrelated junction plane');

    $primary=['name'=>'primary','points'=>[[0,50,10],[200,50,10]],'profiles'=>array_fill(0,2,$profile),'spans'=>[]];
    $r=$make(5,5,6,[90,50,10],[160,80,10]);$r['kind']='ramp';$r['parent_name']=null;
    $r['join']=SweepLine\BeamIntersections::nearest([90,50],$primary['points'],$primary['profiles']);
    $filled=SweepLine\BeamRampJunctions::apply(['roads'=>[$r],'main_surfaces'=>[$primary]]);
    check($filled['ramp_junction_fills']['junctions']===1&&$filled['ramp_junction_fills']['area_before_parent_clipping_m2']>1,'Ramp merge concavities receive a bounded paved transition apron');
    check($filled['main_surfaces'][0]===$primary&&$filled['roads'][0]['points']===$r['points'],'Merge fill preserves the selected highway and mapped ramp centerlines');
    $r['points']=array_map(static fn($p)=>[$p[0],$p[1],$p[2]+8],$r['points']);
    check(SweepLine\BeamRampJunctions::apply(['roads'=>[$r],'main_surfaces'=>[$primary]])['ramp_junction_fills']['junctions']===0,'A ramp crossing at another elevation does not gain a false at-grade paved connection');

    $n=16;$raster=new SweepLine\BeamRaster($n,160,str_repeat(pack('g',0.),$n*$n),str_repeat(chr(100),$n*$n*3));
    $files=[];$put=static function($name,$bytes)use(&$files){$files[$name]=$bytes;};$layers=str_repeat("\0",$n*$n);
    $road=['road'=>[[20,80,10],[140,80,10]],'profiles'=>array_fill(0,2,['width_m'=>8.]),'spans'=>[]];
    $ground=SweepLine\BeamRoadGround::write([$road],$raster,0.,$layers,$layers,'stock','fixture','/levels/test/',$put,$noop);
    $edgeFound=false;$vertices=[];$seams=[];$consistent=true;$area=0.;
    foreach($files as $xml){
        preg_match('/<float_array id="positions-array"[^>]*>([^<]*)</',$xml,$match);
        if(!$match)throw new RuntimeException('Terrain mesh position stream is missing');
        $values=array_map('floatval',preg_split('/\s+/',trim($match[1])));$p=array_chunk($values,3);array_push($vertices,...$p);
        foreach($p as $v){
            if(abs($v[1]-76)<1e-6&&$v[0]>30&&$v[0]<130&&abs($v[2]-9.95)<1e-6)$edgeFound=true;
            $key=sprintf('%.7f:%.7f',$v[0],$v[1]);if(isset($seams[$key])&&abs($seams[$key]-$v[2])>1e-6)$consistent=false;$seams[$key]=$v[2];
        }
        foreach(array_chunk($p,3) as $tri){$area+=abs(SweepLine\BeamPolygon::cross(...$tri))/2;
            $center=[array_sum(array_column($tri,0))/3,array_sum(array_column($tri,1))/3];
            if($center[0]>20+1e-6&&$center[0]<140-1e-6&&$center[1]>76+1e-6&&$center[1]<84-1e-6)$consistent=false;
        }
    }
    check($edgeFound,'Coarse native terrain is refined to within five centimeters of an actual narrow pavement edge');
    check($consistent,'Refined neighboring cells agree in height and do not duplicate collision pavement');
    check(abs($area-($ground['report']['replacement_cells']*100-120*8))<.001,'Refined ground and pavement exactly cover replaced native cells without holes or duplicate areas');
    check(count($ground['materials'])>0&&str_contains(json_encode($ground['materials']),'palette-0')&&$ground['report']['replacement_cells']>0,'Exact road-edge terrain retains the native material palette and replaces only affected cells');
    $empty=SweepLine\BeamRoadGround::write([array_replace($road,['spans'=>[['start_m'=>0.,'end_m'=>120.]]])],$raster,0.,$layers,$layers,'stock','fixture','/levels/test/',$put,$noop);
    check($empty['report']['replacement_cells']===0,'Terrain is not pulled up to mapped bridge or tunnel decks');

    // Keep the native renderer and its material blending whenever it already fits,
    // including curves, lane changes and at-grade junctions in a complex map.
    $n=32;$native=new SweepLine\BeamRaster($n,160.,str_repeat(pack('g',10.),$n*$n),str_repeat(chr(100),$n*$n*3));
    $nativeLayers=str_repeat("\0",$n*$n);for($y=16;$y<$n;$y++)for($x=0;$x<$n;$x++)$nativeLayers[$y*$n+$x]="\2";
    $curved=['road'=>[[20,60,10.05],[55,66,10.05],[90,72,10.05],[140,60,10.05]],'profiles'=>[['width_m'=>8.],['width_m'=>10.],['width_m'=>12.],['width_m'=>8.]],'spans'=>[]];
    $cross=['road'=>[[90,20,10.05],[90,140,10.05]],'profiles'=>array_fill(0,2,['width_m'=>8.]),'spans'=>[]];
    $nativeBytes=$native->heights;$written=[];$save=static function($p,$b)use(&$written){$written[$p]=$b;};
    $keep=SweepLine\BeamRoadGround::write([$curved,$cross],$native,0.,$nativeLayers,$nativeLayers,'stock','keep','/levels/test/',$save,$noop,64.);
    check($keep['files']===[]&&$written===[]&&$keep['layers']===$nativeLayers&&$native->heights===$nativeBytes&&$keep['report']['native_cells_retained']>0,
        'Fitting curved and intersecting roads retain native terrain geometry and blended material IDs without replacement meshes');

    $graded=clone $native;for($y=0;$y<$n;$y++)for($x=0;$x<$n;$x++)$graded->put($x,$y,10.+.02*$x*5.);
    $gradedRoad=['road'=>[[20,80,10.45],[140,80,12.85]],'profiles'=>array_fill(0,2,['width_m'=>8.]),'spans'=>[]];
    $keep=SweepLine\BeamRoadGround::write([$gradedRoad],$graded,0.,$nativeLayers,$nativeLayers,'stock','grade','/levels/test/',$save,$noop,64.);
    check($keep['report']['required_cells']===0&&$keep['layers']===$nativeLayers,'Longitudinal grade and quantization are measured at the actual interpolated pavement edge');

    // A small local gap must not replace the rest of a well-supported road.
    $local=clone $native;$local->put(16,16,9.65);$written=[];
    $localRoad=['road'=>[[20,80,10.05],[140,80,10.05]],'profiles'=>array_fill(0,2,['width_m'=>8.]),'spans'=>[]];
    $localBytes=$local->heights;
    $patch=SweepLine\BeamRoadGround::write([$localRoad],$local,0.,$nativeLayers,$nativeLayers,'stock','local','/levels/test/',$save,$noop,64.);
    check($patch['report']['selection_reasons']['edge_gap']>0&&$patch['report']['transition_cells']>0&&$patch['report']['replacement_cells']<$patch['report']['inspected_cells']/2&&$patch['layers'][16*$n+6]!=="\xff"&&$local->heights===$localBytes,
        'Measured local gaps select a bounded patch and transition ring while distant roadside cells and native heights remain intact');
    $seamCount=0;$nativeSeams=true;$contact=false;$cellArea=0.;
    foreach($written as $xml){
        preg_match('/<float_array id="positions-array"[^>]*>([^<]*)</',$xml,$m);$mesh=array_chunk(array_map('floatval',preg_split('/\s+/',trim($m[1]))),3);
        foreach(array_chunk($mesh,3) as $tri)$cellArea+=abs(SweepLine\BeamPolygon::cross(...$tri))/2;
        foreach($mesh as $p){
            if(abs($p[0]-80)<1e-6&&abs(abs($p[1]-80)-4)<1e-6&&abs($p[2]-10)<1e-6)$contact=true;
            $gx=$p[0]/5.;$gy=$p[1]/5.;$xs=abs($gx-round($gx))<1e-6?[(int)round($gx)-1,(int)round($gx)]:[(int)floor($gx)];
            $ys=abs($gy-round($gy))<1e-6?[(int)round($gy)-1,(int)round($gy)]:[(int)floor($gy)];
            foreach($xs as $x)foreach($ys as $y)if($x>=0&&$y>=0&&$x<$n-1&&$y<$n-1&&$patch['layers'][$y*$n+$x]!=="\xff"){
                $seamCount++;if(abs($p[2]-SweepLine\BeamMedianSurface::nativeHeight($local,$p[0],$p[1],0.,64.))>1e-6)$nativeSeams=false;
            }
        }
    }
    check($contact&&$seamCount>0&&$nativeSeams,'Required pavement edges meet the patch while every patch-to-native boundary matches quantized terrain exactly');
    $pavedArea=0.;for($y=0;$y<$n-1;$y++)for($x=0;$x<$n-1;$x++)if($patch['layers'][$y*$n+$x]==="\xff")
        $pavedArea+=max(0.,min(($x+1)*5.,140.)-max($x*5.,20.))*max(0.,min(($y+1)*5.,84.)-max($y*5.,76.));
    check(abs($cellArea-($patch['report']['replacement_cells']*25.-$pavedArea))<1e-5,'Selective patches cover complete removed cells outside pavement without holes or duplicate ground collision');

    $raised=['road'=>[[20,96,13.05],[140,96,13.05]],'profiles'=>array_fill(0,2,['width_m'=>4.]),'spans'=>[]];$written=[];
    $network=SweepLine\BeamRoadGround::write([$localRoad,$raised],$native,0.,$nativeLayers,$nativeLayers,'stock','network','/levels/test/',$save,$noop);
    $reverse=SweepLine\BeamRoadGround::write([$raised,$localRoad],$native,0.,$nativeLayers,$nativeLayers,'stock','network','/levels/test/',$noop,$noop);
    check($network['report']['required_cells']>0&&$network['layers']===$reverse['layers']&&$network['layers'][15*$n+6]!=="\xff"&&$network['layers'][19*$n+16]==="\xff",
        'Complex nearby road-height conflicts retain local mesh support without replacing a fitting neighboring road or depending on road order');

    $wide=['road'=>[[20,80,13.05],[140,80,13.05]],'profiles'=>array_fill(0,2,['width_m'=>80.]),'spans'=>[]];
    $widePatch=SweepLine\BeamRoadGround::write([$wide],$native,0.,$nativeLayers,$nativeLayers,'stock','wide','/levels/test/',$noop,$noop);
    $bump=clone $native;$bump->put(16,16,14.);
    $bumpPatch=SweepLine\BeamRoadGround::write([$wide],$bump,0.,$nativeLayers,$nativeLayers,'stock','bump','/levels/test/',$noop,$noop);
    check($widePatch['layers'][16*$n+16]!=="\xff"&&$bumpPatch['layers'][16*$n+16]==="\xff"&&$bumpPatch['report']['selection_reasons']['terrain_intrusion']>0,
        'Pavement interiors and internal triangle edges need no gap patch, but terrain protruding through a pavement interior is removed');

    $quantized=new SweepLine\BeamRaster($n,160.,str_repeat(pack('g',10.04),$n*$n),$native->colors);
    $quantizedPatch=SweepLine\BeamRoadGround::write([$localRoad],$quantized,0.,$nativeLayers,$nativeLayers,'stock','quantized','/levels/test/',$noop,$noop,4096.);
    check($quantizedPatch['report']['selection_reasons']['terrain_intrusion']>0,'Native height quantization that would put terrain through pavement triggers local refinement');

    $upperEdge=$localRoad;$upperEdge['road']=[[20,80,10.20],[140,80,10.20]];
    $lowerEdge=$localRoad;$lowerEdge['road']=[[20,80.000003,10.05],[140,80.000003,10.05]];
    $nearEdges=SweepLine\BeamRoadGround::write([$upperEdge,$lowerEdge],$native,0.,$nativeLayers,$nativeLayers,'stock','near_edges','/levels/test/',$noop,$noop);
    check($nearEdges['files']===[]&&$nearEdges['layers']===$nativeLayers,
        'Nearly coincident pavement edges share the lower contact constraint and retain native ground that already meets it');

    $oldHoleLayers=$layers;$oldHoleLayers[8*16+8]="\xff";$written=[];
    $protected=SweepLine\BeamRoadGround::write([$road],$raster,0.,$oldHoleLayers,$layers,'stock','protected','/levels/test/',$save,$noop);
    $holePreserved=true;$besideHoleContact=false;foreach($written as $xml){
        preg_match('/<float_array id="positions-array"[^>]*>([^<]*)</',$xml,$m);$mesh=array_chunk(array_map('floatval',preg_split('/\s+/',trim($m[1]))),3);
        foreach(array_chunk($mesh,3) as $tri){$cx=array_sum(array_column($tri,0))/3;$cy=array_sum(array_column($tri,1))/3;if($cx>80&&$cx<90&&$cy>80&&$cy<90)$holePreserved=false;}
        foreach($mesh as $p)if($p[0]>=80&&$p[0]<=90&&$p[1]>=80&&$p[1]<=90&&abs($p[2])>1e-6)$holePreserved=false;
        foreach($mesh as $p)if($p[0]>=80&&$p[0]<=90&&abs($p[1]-76)<1e-6&&abs($p[2]-9.95)<1e-6)$besideHoleContact=true;
    }
    check($protected['report']['existing_hole_cells_skipped']===1&&$holePreserved&&$besideHoleContact,'Existing tunnel and median holes retain ownership and native-matching boundaries without pulling adjacent pavement contact down');

    $isolatedLayers=str_repeat("\xff",$raster->size*$raster->size);$isolatedLayers[7*$raster->size+8]="\0";$written=[];
    SweepLine\BeamRoadGround::write([$road],$raster,0.,$isolatedLayers,$layers,'stock','isolated','/levels/test/',$save,$noop);
    $isolatedContact=false;$isolatedBoundary=true;
    foreach($written as $xml){
        preg_match('/<float_array id="positions-array"[^>]*>([^<]*)</',$xml,$m);$mesh=array_chunk(array_map('floatval',preg_split('/\s+/',trim($m[1]))),3);
        foreach($mesh as $p){
            if(abs($p[0]-86)<1e-6&&abs($p[1]-76)<1e-6&&abs($p[2]-9.95)<1e-6)$isolatedContact=true;
            if((abs($p[0]-80)<1e-6||abs($p[0]-90)<1e-6||abs($p[1]-70)<1e-6||abs($p[1]-80)<1e-6)&&abs($p[2])>1e-6)$isolatedBoundary=false;
        }
    }
    check($isolatedContact&&$isolatedBoundary,'A cell enclosed by existing replacement patches keeps native boundary heights while its interior still meets pavement');

    $moved=$prepared;
    foreach($moved['roads'] as &$r){
        foreach($r['points'] as &$p){$p[0]+=500000.;$p[1]+=4000000.;}unset($p);
        $r['join']['x']+=500000.;$r['join']['y']+=4000000.;
    }unset($r);
    $translated=SweepLine\BeamJunctionGrades::apply($moved);
    $same=true;foreach($translated['roads'] as $i=>$r)foreach($r['points'] as $j=>$p)if(abs($p[2]-$out['roads'][$i]['points'][$j][2])>1e-7)$same=false;
    check($same,'Junction grading is invariant to geographic coordinate translation');

    $main=['points'=>[[20,80,10.],[140,80,10.]],'profiles'=>array_fill(0,2,$profile)];
    $child=$make(8,8,9,[70,80,10],[70,120,10]);$child['junction_parent']=$main;
    $child['join']=SweepLine\BeamIntersections::nearest([70,80],$main['points'],$main['profiles']);
    $owner=['vertices'=>[[20,75,11.],[140,75,11.],[140,85,11.],[20,85,11.]],'triangles'=>[
        ['v'=>[0,1,2]],['v'=>[0,2,3]]]];
    $child['junction_parent_top']=SweepLine\BeamJunctionBoundary::encode($owner);
    $mesh=SweepLine\BeamPavement::build($child,$main['points'],$main['profiles'],'test',null,[],true);
    $seam=false;$correct=true;foreach($mesh['vertices'] as $p)if(abs($p[1]-85)<1e-7){$seam=true;if(abs($p[2]-11)>1e-7)$correct=false;}
    check($seam&&$correct,'A child welds to its parent final collision top instead of the obsolete raw ribbon height');

    $beforeStreams=count(get_resources('stream'));$cancelled=false;
    try{
        SweepLine\BeamRoadGround::write([$road],$raster,0.,$layers,$layers,'stock','cancel','/levels/test/',$put,
            static function($m):void{throw new RuntimeException('fixture cancellation');});
    }catch(RuntimeException $e){$cancelled=$e->getMessage()==='fixture cancellation';}
    check($cancelled&&count(get_resources('stream'))===$beforeStreams,'Cancellation closes the private refinement geometry workspace');
})();
