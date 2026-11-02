<?php
declare(strict_types=1);
namespace SweepLine;
/** Shared physical edges, not name similarity or nearby/crossing lines. */
final class RoadOverlap
{
    public static function pointKey(array $p): string {return sprintf('%.7f,%.7f',$p[0],$p[1]);}
    private static function edges(array $road): array
    {
        $points=$road['geometry']['coordinates'];$edges=[];$direction=(int)($road['travel_direction']??0);
        for ($i=1;$i<count($points);$i++) {
            $a=self::pointKey($points[$i-1]);$b=self::pointKey($points[$i]);if($a===$b)continue;
            $forward=strcmp($a,$b)<0;$key=$forward?$a.'|'.$b:$b.'|'.$a;
            $edges[$key]=['a'=>$forward?$a:$b,'b'=>$forward?$b:$a,'p'=>$forward?$points[$i-1]:$points[$i],'q'=>$forward?$points[$i]:$points[$i-1],
                'allowed'=>$direction===0?3:(($direction>0)===$forward?1:2)];
        }
        return $edges;
    }
    private static function path(array $edges): ?array
    {
        $adj=[];
        foreach($edges as $key=>$e) {$adj[$e['a']][$key]=$e['b'];$adj[$e['b']][$key]=$e['a'];}
        $ends=[];
        foreach($adj as $node=>$neighbors) {if(count($neighbors)>2)return null;if(count($neighbors)===1)$ends[]=$node;}
        if(count($ends)!==2 && count($ends)!==0)return null;
        $start=$ends?$ends[0]:array_key_first($adj);$at=$start;$used=[];$points=[];$forward=true;$backward=true;
        while(true) {
            $next=null;foreach($adj[$at] as $key=>$to){if(!isset($used[$key])){$next=[$key,$to];break;}}
            if($next===null)break;[$key,$to]=$next;$e=$edges[$key];$normal=$at===$e['a'];
            if(!$points)$points[]=$normal?$e['p']:$e['q'];$points[]=$normal?$e['q']:$e['p'];
            $forward=$forward && (bool)($e['allowed']&($normal?1:2));
            $backward=$backward && (bool)($e['allowed']&($normal?2:1));
            $used[$key]=true;$at=$to;
        }
        if(count($used)!==count($edges) || (!$forward && !$backward))return null;
        if(!$forward)$points=array_reverse($points);
        return ['points'=>$points,'direction'=>$forward && $backward?0:1];
    }
    public static function groups(array $roads): array
    {
        usort($roads,static fn($a,$b)=>($b['length_m']<=>$a['length_m'])?:($a['id']<=>$b['id']));
        $groups=[];$owners=[];$pointCount=0;
        foreach($roads as $road) {
            $pointCount+=count($road['geometry']['coordinates']);
            if($pointCount>200000)throw new HttpException(422,'Road geometry limit reached. Use a smaller search radius.');
            $edges=self::edges($road);if(!$edges)continue;
            $current=['members'=>[$road],'edges'=>$edges];$matches=[];
            foreach($edges as $key=>$_)foreach($owners[$key]??[] as $index=>$_owner)$matches[$index]=true;
            foreach(array_keys($matches) as $index) {
                if(!isset($groups[$index]))continue;$other=$groups[$index];
                if(count($current['members'])+count($other['members'])>50)continue;
                $shared=array_intersect_key($current['edges'],$other['edges']);
                $union=$current['edges']+$other['edges'];$compatible=true;
                foreach($shared as $key=>$_) {
                    $union[$key]['allowed']=$current['edges'][$key]['allowed']&$other['edges'][$key]['allowed'];
                    if(!$union[$key]['allowed']){$compatible=false;break;}
                }
                if(!$compatible || self::path($union)===null)continue;
                $current=['members'=>array_merge($other['members'],$current['members']),'edges'=>$union];
                foreach($other['edges'] as $key=>$_)unset($owners[$key][$index]);
                unset($groups[$index]);
            }
            $index=count($roads)+count($groups);
            while(isset($groups[$index]))$index++;
            $groups[$index]=$current;
            foreach($current['edges'] as $key=>$_)$owners[$key][$index]=true;
        }
        $out=[];
        foreach($groups as $g) {
            $path=self::path($g['edges']);
            // Original candidates may themselves pass a junction twice; keep them
            // intact rather than trying to invent a continuous union through a fork.
            $out[]=['members'=>$g['members'],'points'=>$path['points']??$g['members'][0]['geometry']['coordinates'],'direction'=>$path['direction']??(int)($g['members'][0]['travel_direction']??0)];
        }
        return $out;
    }
}

