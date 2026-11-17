<?php
(function():void{
    $make=static function(int $id,array $points,int $lanes,array $opts=[]):array{
        $raw=array_fill(0,count($points),SweepLine\BeamLanes::infer(['lanes'=>(string)$lanes,'oneway'=>'yes'],'secondary',1));
        foreach($raw as &$p)$p['legal_forward']=$opts['legal_forward']??true;unset($p);
        $identity=['name'=>$opts['name']??'fixture avenue','ref'=>'','highway'=>$opts['highway']??'secondary','osm_id'=>$id];
        return ['name'=>'edge_'.$id,'network'=>true,'start_node'=>$opts['start']??$id,'end_node'=>$opts['end']??($id+1),
            'points'=>$points,'mapped_profiles'=>$raw,'profiles'=>SweepLine\BeamRoadSurface::shoulders(SweepLine\BeamRoadSurface::taper($points,$raw)),
            'lane_endpoints'=>[$identity,$identity],'bridges'=>$opts['bridges']??[]];
    };
    $a=[];$b=[];for($x=0;$x<=100;$x+=2)$a[]=[$x,100,10.];for($x=100;$x<=200;$x+=2)$b[]=[$x,100,10.];
    $roads=[$make(1,$a,2),$make(2,$b,4)];
    $result=SweepLine\BeamNetworkLanes::apply($roads);$out=$result['roads'];
    check($result['report']['joined_boundaries']===1&&abs(end($out[0]['profiles'])['width_m']-$out[1]['profiles'][0]['width_m'])<1e-9,'Connected same-road sections share an identical width at their boundary');
    check($out[0]['profiles'][0]['width_m']===9.2192&&end($out[1]['profiles'])['width_m']===16.2192&&$out[0]['profiles'][45]['width_m']>9.2192&&$out[1]['profiles'][5]['width_m']<16.2192,'A lane change spans 100 feet on both sides of a road-section boundary');
    check($out[0]['points']===$a&&$out[1]['points']===$b,'Network lane blending preserves every mapped XY and elevation point');
    check(end($out[0]['profiles'])['lane_paint']===$out[1]['profiles'][0]['lane_paint'],'Divider paths use the same transition state at a shared node');
    $reversed=[$make(1,$a,2),$make(2,array_reverse($b),4,['start'=>3,'end'=>2,'legal_forward'=>false])];
    $rev=SweepLine\BeamNetworkLanes::apply($reversed)['roads'];
    $paint=end($rev[1]['profiles'])['lane_paint'];
    check(abs(end($rev[0]['profiles'])['width_m']-end($rev[1]['profiles'])['width_m'])<1e-9&&abs(end($rev[0]['profiles'])['lane_paint']['f1']['u']+$paint['f1']['u']-1)<1e-9,'Reversed graph edges share physical widths and mirrored paint coordinates');
    $bridge=$roads;$bridge[1]['bridges']=[['start_m'=>0.,'end_m'=>70.]];
    $deck=SweepLine\BeamNetworkLanes::apply($bridge)['roads'];
    check(abs(end($deck[0]['profiles'])['width_m']-16.2192)<1e-8&&abs($deck[1]['profiles'][0]['width_m']-16.2192)<1e-8,'A wider bridge on a new edge finishes widening before its deck begins');
    foreach([
        ['name'=>'other avenue'],['highway'=>'secondary_link'],['legal_forward'=>false],
    ] as $opts){
        $r=SweepLine\BeamNetworkLanes::apply([$make(1,$a,2),$make(2,$b,4,$opts)]);
        check($r['report']['joined_boundaries']===0,'Unrelated roads, link roads and incompatible traffic direction are not conflated: '.json_encode($opts));
    }
    $high=array_map(static fn($p)=>[$p[0],$p[1],$p[2]+6],$b);
    check(SweepLine\BeamNetworkLanes::apply([$make(1,$a,2),$make(2,$high,4)])['report']['joined_boundaries']===0,'Vertically separate road endpoints are never joined');
    $fork=SweepLine\BeamNetworkLanes::apply([$make(1,$a,2),$make(2,$b,4),$make(3,$b,3,['start'=>2,'end'=>4])]);
    check($fork['report']['joined_boundaries']===0&&$fork['report']['ambiguous_endpoints']>0,'Ambiguous same-road forks retain separate profiles rather than choosing by processing order');
    $twoway=$roads;foreach($twoway as &$r)foreach($r['mapped_profiles'] as &$p){$p['oneway']=false;$p['forward']=$p['lanes']/2;$p['backward']=$p['lanes']/2;}unset($p,$r);
    check(SweepLine\BeamNetworkLanes::apply($twoway)['report']['joined_boundaries']===1,'Two-way continuous-road sections also share lane transitions');

    $ordered=SweepLine\BeamNetworkLanes::apply(array_reverse($roads))['roads'];$byName=array_column($ordered,null,'name');
    check($byName['edge_1']['profiles']===$out[0]['profiles']&&$byName['edge_2']['profiles']===$out[1]['profiles'],'Shared lane geometry does not depend on discovery or processing order');

    $raster=new SweepLine\BeamRaster(256,256,str_repeat(pack('g',100.),256*256),str_repeat(chr(100),256*256*3));
    $branch=static function($id,$points,$lanes,$start,$end,$parent=null):array{
        $tags=['name'=>'fixture avenue','lanes'=>(string)$lanes,'oneway'=>'yes'];$p=SweepLine\BeamLanes::infer($tags,'secondary',1);$p['legal_forward']=true;
        return ['context_id'=>'network_'.$id,'osm_id'=>$id,'network'=>true,'kind'=>'receiving_road','start_node'=>$start,'end_node'=>$end,
            'points'=>$points,'width_m'=>$p['width_m'],'profile'=>$p,'limit_m'=>250.,
            'source_parts'=>[['osm_id'=>$id,'points'=>$points,'tags'=>$tags,'highway'=>'secondary','direction'=>1]]]+($parent?['parent_context_id'=>$parent]:[]);
    };
    $data=['branches'=>[$branch(1,[[30,100],[110,100]],2,10,20),$branch(2,[[110,100],[210,100]],4,20,30,'network_1')],
        'graph'=>['attachments'=>[10=>[30,100]]]];
    $main=[[30,10,100.25],[30,230,100.25]];$mp=array_fill(0,2,SweepLine\BeamLanes::infer(['lanes'=>'2'],'primary',0));
    $prepared=SweepLine\BeamIntersections::prepare($data,$main,$mp,$raster,[0,0,256,256],0.,[],static fn($m)=>null);
    [$first,$second]=$prepared['roads'];
    check($prepared['lane_transitions']['joined_boundaries']===1&&abs(end($first['profiles'])['width_m']-$second['profiles'][0]['width_m'])<1e-8,
        'Production interchange preparation carries shared widths through junction marking');
    check($second['junction_parent']['profiles']=== $first['profiles'],'Prepared child geometry uses the updated parent lane profiles');

    $reversedSource=['points'=>[[100,0],[0,0]],'source_aligned'=>false,'direction'=>1,'highway'=>'secondary','tags'=>['lanes'=>'3','oneway'=>'yes']];
    $rp=SweepLine\BeamLanes::profiles([[90,0],[10,0]],[$reversedSource],[-1,-1,101,1]);
    check(!$rp[0]['legal_forward']&&!$rp[1]['legal_forward'],'Reversed graph source geometry retains the original OSM one-way direction');
    $reversedSource['direction']=0;$reversedSource['tags']=['lanes'=>'4','lanes:forward'=>'3','lanes:backward'=>'1'];
    $rp=SweepLine\BeamLanes::profiles([[90,0],[10,0]],[$reversedSource],[-1,-1,101,1]);
    check($rp[0]['forward']===1&&$rp[0]['backward']===3,'Asymmetric two-way lane attributes reverse with original source orientation');
    $seedTags=['oneway'=>'yes','lanes'=>'1'];$seedProfile=SweepLine\BeamLanes::infer($seedTags,'motorway_link',1);$seedProfile['legal_forward']=true;
    $seed=['osm_id'=>500,'points'=>[[0,0],[50,0]],'full_points'=>[[0,0],[50,0]],'arm_nodes'=>[1,2],'node_ids'=>[1,2],
        'highway'=>'motorway_link','direction'=>1,'tags'=>$seedTags,'profile'=>$seedProfile,'width_m'=>$seedProfile['width_m']];
    $network=SweepLine\BeamRampNetwork::walk([$seed],static fn($node)=>$node===2?[[
        'osm_id'=>501,'points'=>[[100,0],[50,0]],'node_ids'=>[3,2],'point_index'=>1,'highway'=>'secondary','direction'=>1,
        'tags'=>['oneway'=>'yes','lanes'=>'2','name'=>'receiving fixture']]]:[],static fn($m)=>null);
    $receiving=array_values(array_filter($network['branches'],static fn($r)=>$r['osm_id']===501))[0];
    $rp=SweepLine\BeamLanes::profiles($receiving['points'],$receiving['source_parts'],[-1,-1,101,1]);
    check($receiving['source_parts'][0]['source_aligned']===false&&!$rp[0]['legal_forward'],'Graph acquisition carries reversed source orientation into production lane matching');
})();
