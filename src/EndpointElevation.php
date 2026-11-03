<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** Small cached USGS terrain samples, independent of BeamNG export downloads. */
final class EndpointElevation
{
    public static function points(array $input): array
    {
        $points=$input['points']??null;
        if(!is_array($points)||!array_is_list($points)||count($points)!==2)throw new \InvalidArgumentException('Exactly two endpoint coordinates are required.');
        foreach($points as &$point){
            if(!is_array($point)||!array_is_list($point)||count($point)!==2||!is_numeric($point[0])||!is_numeric($point[1]))throw new \InvalidArgumentException('Invalid endpoint coordinates.');
            $point=array_map('floatval',$point);
            if(!is_finite($point[0])||!is_finite($point[1])||abs($point[0])>180||abs($point[1])>85)throw new \InvalidArgumentException('Invalid endpoint coordinates.');
        }
        unset($point);return $points;
    }
    public static function sample(array $point,?callable $request=null): ?float
    {
        $url='https://epqs.nationalmap.gov/v1/json?'.http_build_query(['x'=>$point[0],'y'=>$point[1],'wkid'=>4326,'units'=>'Meters','includeDate'=>'false']);
        try{
            if($request)$raw=$request($url);
            else{
                $curl=curl_init($url);$body='';
                curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_FAILONERROR=>true,
                    CURLOPT_USERAGENT=>'SweepLine endpoint elevation',CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>16384)return 0;$body.=$chunk;return strlen($chunk);}]);
                try{$ok=curl_exec($curl);$raw=$ok!==false&&curl_getinfo($curl,CURLINFO_HTTP_CODE)===200?$body:'';}finally{curl_close($curl);}
            }
            $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            $value=$data['value']??null;
            if(!is_numeric($value)||!is_finite((float)$value)||(float)$value< -500||(float)$value>10000)return null;
            return (float)$value;
        }catch(\Throwable $e){return null;}
    }
    public static function load(PDO $db,array $input): array
    {
        $out=[];
        foreach(self::points($input) as $point){
            $key=sprintf('%.6F,%.6F',...$point);
            $q=$db->prepare('SELECT elevation_m FROM endpoint_elevations WHERE point_key=? AND expires_at>now()');
            $q->execute([$key]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($row!==false)$value=$row['elevation_m']===null?null:(float)$row['elevation_m'];
            else{
                $value=self::sample($point);
                $q=$db->prepare("INSERT INTO endpoint_elevations(point_key,elevation_m,expires_at) VALUES (?,?,now()+CAST(? AS interval)) ON CONFLICT(point_key) DO UPDATE SET elevation_m=excluded.elevation_m,expires_at=excluded.expires_at");
                $q->execute([$key,$value,$value===null?'5 minutes':'90 days']);
            }
            $out[]=$value;
        }
        return ['elevations_m'=>$out,'source'=>'USGS 3DEP terrain elevation'];
    }
}
