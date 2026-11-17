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
