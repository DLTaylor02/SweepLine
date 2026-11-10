<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamScenery
{
    public const RANGE_M=60.96; // 200 feet from the road edge, including object footprint.
    private array $segments=[],$index=[],$exclusions=[],$water=[];
    public function __construct(array $road,array $profiles,array $exclusions=[])
    {
        foreach($exclusions as $branch){if(isset($branch['rings'])){$this->water[]=$branch;continue;}$this->exclusions[]=new self($branch['points'],array_fill(0,count($branch['points']),['width_m'=>$branch['width_m']]));}
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$half=max($profiles[$i-1]["width_m"],$profiles[$i]["width_m"])/2;
            $id=count($this->segments);$this->segments[]=[$a,$b,$half];
            for($x=(int)floor((min($a[0],$b[0])-$half)/64);$x<=(int)floor((max($a[0],$b[0])+$half)/64);$x++)
                for($y=(int)floor((min($a[1],$b[1])-$half)/64);$y<=(int)floor((max($a[1],$b[1])+$half)/64);$y++)$this->index[$x.":".$y][]=$id;
        }
    }
    public function edgeDistance(float $x,float $y): float
    {
        $cx=(int)floor($x/64);$cy=(int)floor($y/64);$best=INF;$seen=[];
        for($ix=$cx-2;$ix<=$cx+2;$ix++)for($iy=$cy-2;$iy<=$cy+2;$iy++)foreach($this->index[$ix.":".$iy]??[] as $id){
            if(isset($seen[$id]))continue;$seen[$id]=true;[$a,$b,$half]=$this->segments[$id];
            $best=min($best,BeamLanes::distance([$x,$y],$a,$b)[0]-$half);
        }
        return $best;
    }
    public function allows(float $x,float $y,float $radius): bool
    {
        foreach($this->exclusions as $branch)if($branch->edgeDistance($x,$y)<$radius)return false;
        foreach($this->water as $water){[$x0,$y0,$x1,$y1]=$water['bounds'];if($x+$radius<$x0||$x-$radius>$x1||$y+$radius<$y0||$y-$radius>$y1)continue;if(BeamWaterGeometry::contains([$x,$y],$water['rings'])||BeamWaterGeometry::boundaryDistance([$x,$y],$water['rings'])<$radius)return false;}
        $distance=$this->edgeDistance($x,$y);
        return $distance+1e-8>=$radius && $distance+$radius<=self::RANGE_M+1e-8;
    }

    private function polygonHitsRoad(array $points): bool
    {
        $seen=[];
        for($x=(int)floor(min(array_column($points,0))/64);$x<=(int)floor(max(array_column($points,0))/64);$x++)
            for($y=(int)floor(min(array_column($points,1))/64);$y<=(int)floor(max(array_column($points,1))/64);$y++)
                foreach($this->index[$x.':'.$y]??[] as $id)$seen[$id]=true;
        foreach(array_keys($seen) as $id){[$a,$b,$half]=$this->segments[$id];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);if($length<0.001)continue;
            $ox=$dy/$length*$half;$oy=-$dx/$length*$half;
            if(BeamPolygon::overlaps($points,[[$a[0]+$ox,$a[1]+$oy],[$b[0]+$ox,$b[1]+$oy],[$b[0]-$ox,$b[1]-$oy],[$a[0]-$ox,$a[1]-$oy]]))return true;
        }
        return false;
    }
    public function polygonReason(array $points): ?string
    {
        foreach($this->water as $water){foreach($points as $p)if(BeamWaterGeometry::contains($p,$water['rings']))return 'mapped_water_overlap';foreach($water['rings'] as $ring)for($i=0;$i<count($ring);$i++)for($j=0;$j<count($points);$j++)if((BeamPolygon::cross($ring[$i],$ring[($i+1)%count($ring)],$points[$j])*BeamPolygon::cross($ring[$i],$ring[($i+1)%count($ring)],$points[($j+1)%count($points)])<0&&BeamPolygon::cross($points[$j],$points[($j+1)%count($points)],$ring[$i])*BeamPolygon::cross($points[$j],$points[($j+1)%count($points)],$ring[($i+1)%count($ring)])<0))return 'mapped_water_overlap';if(BeamPolygon::inside($water['rings'][0][0],$points))return 'mapped_water_overlap';}
        if($this->polygonHitsRoad($points))return 'pavement_overlap';
        foreach($this->exclusions as $road)if($road->polygonHitsRoad($points))return 'pavement_overlap';
        foreach($points as $p)if($this->edgeDistance($p[0],$p[1])>self::RANGE_M)return 'outside_roadside_range';
        return null;
    }

    public static function radius(string $kind,float $scale,?string $asset=null): float
    {
        if($asset!==null)return BeamVegetation::CATALOG[$asset]['radius']*$scale;
        return match($kind){"tree"=>7.0,"building"=>sqrt(41),"bush"=>1.2,default=>2.0}*$scale;
    }
    public static function place(array $road,array $profiles,BeamRaster $raster,int $seed,callable $progress,array $center=[-119,39],array $reserved=[],array $exclusions=[],int $objectBudget=24000): array
    {
        $geometry=new self($road,$profiles,$exclusions);$chain=[0.0];
        for($i=1;$i<count($road);$i++)$chain[]=$chain[$i-1]+hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
        $objectBudget=max(2,min(24000,$objectBudget));
        $length=$chain[count($chain)-1];$target=min((int)floor($objectBudget/2),12000,max(400,(int)ceil($length/1000*750)));$baseTarget=$target;$extraTreeTarget=0;$limit=min(400000,$target*50);
        $random=static function()use(&$seed): float {$seed=($seed*1664525+1013904223)&0xFFFFFFFF;return $seed/4294967296;};
        $out=[];$occupied=[];$types=["tree"=>0,"bush"=>0,"rock"=>0,"building"=>0];$zones=[];$variants=[];$skipped=["water"=>0,"footprint"=>0,"slope"=>0,"spacing"=>0,"bounds"=>0];
        foreach($reserved as $building){
            [$x,$y]=$building['position'];$radius=$building['radius'];
            for($ix=(int)floor(($x-$radius)/20);$ix<=(int)floor(($x+$radius)/20);$ix++)
                for($iy=(int)floor(($y-$radius)/20);$iy<=(int)floor(($y+$radius)/20);$iy++)
                    $occupied[$ix.':'.$iy][]=[$x,$y,$radius,'building'];
        }
        for($attempt=0;$attempt<$limit && count($out)<$target;$attempt++){
            if($attempt%100===0)$progress(null);
            $distance=$random()*$length;$lo=1;$hi=count($chain)-1;
            while($lo<$hi){$mid=(int)(($lo+$hi)/2);if($chain[$mid]<$distance)$lo=$mid+1;else $hi=$mid;}
            $i=$lo;$a=$road[$i-1];$b=$road[$i];$len=$chain[$i]-$chain[$i-1];if($len<0.001)continue;$t=($distance-$chain[$i-1])/$len;
            $offset=(max($profiles[$i-1]["width_m"],$profiles[$i]["width_m"])/2+$random()*self::RANGE_M)*($random()<0.5?-1:1);
            $x=$a[0]+$t*($b[0]-$a[0])+($b[1]-$a[1])/$len*$offset;$y=$a[1]+$t*($b[1]-$a[1])-($b[0]-$a[0])/$len*$offset;
            if($x<0||$y<0||$x>=$raster->side||$y>=$raster->side){$skipped["bounds"]++;continue;}
            $cover=BeamRaster::cover($raster->rgb((int)($x/$raster->side*$raster->size),(int)($y/$raster->side*$raster->size)));
            $biome=BeamBiome::sample($raster,$x,$y,$center);
            if($biome['water']){$skipped["water"]++;continue;}
            $pick=$random();$kind=$pick<$biome['tree_probability']?'tree':($random()<0.65?'bush':'rock');
            $biome['edge_distance_m']=max(0,abs($offset)-max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2);
            $asset=BeamVegetation::choose($kind,$center,$raster->roadHeight($x,$y),$cover,$random(),$biome);
            if($asset==='utah_rock'){
                $groundRgb=$raster->rgb((int)($x/$raster->side*$raster->size),(int)($y/$raster->side*$raster->size));
                $asset=BeamRockPalette::stockAsset($groundRgb);
            }
            $extra=count($out)>=$baseTarget;
            if($asset===null||($extra&&$kind!=='tree'))continue;
            $scale=0.7+$random()*0.6;$radius=self::radius($kind,$scale,$asset);
            if(!$geometry->allows($x,$y,$radius)){$skipped["footprint"]++;continue;}
            if($x<$radius||$y<$radius||$x+$radius>=$raster->side||$y+$radius>=$raster->side){$skipped["bounds"]++;continue;}
            $h=$raster->height($x,$y);$slope=0.0;
            foreach([[$radius,0],[-$radius,0],[0,$radius],[0,-$radius]] as [$dx,$dy])$slope=max($slope,abs($raster->height($x+$dx,$y+$dy)-$h)/$radius);
            if($slope>($kind==="building"?0.15:0.7)){$skipped["slope"]++;continue;}
            $cx=(int)floor($x/20);$cy=(int)floor($y/20);$overlap=false;
            for($ix=$cx-2;$ix<=$cx+2;$ix++)for($iy=$cy-2;$iy<=$cy+2;$iy++)foreach($occupied[$ix.":".$iy]??[] as $other)if(hypot($x-$other[0],$y-$other[1])<($radius+$other[2])*(($kind==='tree'&&($other[3]??'')==='tree'&&$biome['canopy_evidence']>=0.6)?0.45:1))$overlap=true;
            if($overlap){$skipped["spacing"]++;continue;}
            $occupied[$cx.":".$cy][]=[$x,$y,$radius,$kind];$types[$kind]++;
            $zones[$biome['zone']]=($zones[$biome['zone']]??0)+1;if($asset!==null)$variants[$asset]=($variants[$asset]??0)+1;
            $out[]=["kind"=>$kind,"position"=>[$x,$y,$h],"scale"=>$scale,"asset"=>$asset]+($kind==='rock'?['ground_rgb'=>$raster->rgb((int)($x/$raster->side*$raster->size),(int)($y/$raster->side*$raster->size))]:[]);
            if(count($out)===$baseTarget){$extraTreeTarget=$types['tree'];$target=min($objectBudget,$baseTarget+2*$extraTreeTarget);}
        }
        return ["objects"=>$out,"counts"=>$types,"skipped"=>$skipped,"forest_asset_policy"=>"Dense-canopy interiors allow overlapping crowns with stock Forest LOD assets; full mesh footprints still clear roads and water.","tree_density_multiplier"=>3,"additional_tree_target"=>$extraTreeTarget,"additional_trees_placed"=>max(0,count($out)-$baseTarget),"target"=>$target,"attempts"=>$attempt,"range_from_road_edge_ft"=>200,"biome_counts"=>$zones,"asset_counts"=>$variants,"biome_method"=>"Regional elevation prior plus nine raw aerial-color samples; heuristic, not surveyed land cover.","building_policy"=>"No buildings inferred from aerial colors; mapped footprint evidence is required."];
    }
}
