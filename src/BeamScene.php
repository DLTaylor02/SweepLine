<?php
declare(strict_types=1);
namespace SweepLine;

/** Bound native scene load units without reducing geometry or the navigation graph. */
final class BeamScene
{
    public const BATCH_SIZE=256;
    public static function write(array $objects,string $id,callable $put): array
    {
        $encode=static fn(array $o)=>json_encode($o,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
        $put('main/items.level.json',$encode(['class'=>'SimGroup','name'=>'MissionGroup','persistentId'=>substr($id,0,8).'-'.substr($id,8,4).'-4'.substr($id,13,3).'-8'.substr($id,17,3).'-'.substr($id,20,12)]));
        $groups=0;$maximum=0;$files=1;
        $emit=static function(array $items,string $directory,string $parent,bool $root=false)use(&$emit,&$groups,&$maximum,&$files,$encode,$put):void{
            $direct=[];$bulk=[];
            if(count($items)<=self::BATCH_SIZE)$direct=$items;
            else{
                foreach($items as $o){
                    if($root&&in_array($o['class'],['LevelInfo','ScatterSky','TimeOfDay','TerrainBlock','SpawnSphere','Forest'],true))$direct[]=$o;
                    else $bulk[]=$o;
                }
                // A hierarchical index also keeps very large scenes bounded; no
                // object or network edge is dropped to fit a loading-file budget.
                $chunk=self::BATCH_SIZE;
                while(ceil(count($bulk)/$chunk)>max(1,self::BATCH_SIZE-count($direct)))$chunk*=self::BATCH_SIZE;
                foreach(array_chunk($bulk,$chunk) as $part){
                    $group='sl_scene_batch_'.$groups++;
                    $direct[]=['class'=>'SimGroup','name'=>$group,'__parent'=>$parent];
                    $emit($part,$directory.'/'.$group,$group);
                }
            }
            $text='';
            foreach($direct as $o){$o['__parent']=$parent;$text.=$encode($o);}
            $maximum=max($maximum,count($direct));$files++;
            $put($directory.'/items.level.json',$text);
        };
        $emit($objects,'main/MissionGroup','MissionGroup',true);$emit=null;
        return ['method'=>'Native SimGroup scene batches; complete object and navigation data retained','objects'=>count($objects),
            'groups'=>$groups,'files'=>$files,'maximum_objects_per_file'=>$maximum,'batch_size'=>self::BATCH_SIZE];
    }
}
