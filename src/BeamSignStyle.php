<?php
declare(strict_types=1);
namespace SweepLine;
/** Approximate US sign families; no destination text, exit number or speed is invented. */
final class BeamSignStyle {
    public static function describe(string $code):array {
        $label=preg_replace('/^(?:regulatory|warning|information|complementary)--/','',$code);
        $label=preg_replace('/--g[0-9]+$/','',$label);
        $label=strtoupper(str_replace(['--','-','_'],' ',$label));
        $family='regulatory';$shape='rectangle';$bg=[242,242,232];$fg=[18,18,18];$heading=null;$number=null;$symbol=null;
        if(preg_match('/^(?:R2-1\[([0-9]+)(?: mph)?\]|regulatory--maximum-speed-limit-([0-9]+)--g[0-9]+)$/D',$code,$m)){
            $heading='SPEED LIMIT';$number=$m[1]!==''?$m[1]:$m[2];$label='SPEED LIMIT '.$number;
        }elseif(preg_match('/^complementary--maximum-speed-limit-([0-9]+)--g[0-9]+$/D',$code,$m)){
            $family='advisory';$bg=[244,194,28];$heading='ADVISORY';$number=$m[1];$label='ADVISORY '.$number;
        }elseif($code==='W13-2'){$family='advisory';$bg=[244,194,28];$label='EXIT SPEED';}
        elseif(preg_match('/do-not-enter|no-entry|wrong-way/',$code)||in_array($code,['R5-1','R5-1a'],true)){
            $family='prohibition';$bg=[190,25,25];$fg=[255,255,245];$label=str_contains($code,'wrong-way')||$code==='R5-1a'?'WRONG WAY':'DO NOT ENTER';if($label==='DO NOT ENTER')$symbol='do_not_enter';
        }elseif(preg_match('/no-parking/',$code)||$code==='R8-3'){$label='NO PARKING';$symbol='no_parking';}
        elseif(preg_match('/roadworks|road-work|construction/',$code)){$family='construction';$shape='diamond';$bg=[240,132,28];}
        elseif(str_starts_with($code,'W')||str_starts_with($code,'warning--')){
            $family='warning';$shape='diamond';$bg=[244,194,28];
            if(preg_match('/school|pedestrian|bicycle/',$code))$bg=[185,222,45];
            if(preg_match('/^W[0-9]+-/',$code))$label=match($code){'W10-1'=>'RAILROAD CROSSING','W11-2'=>'PEDESTRIAN CROSSING',default=>$label};
            if(preg_match('/^warning--texts(?:-|$)/',$code))$label='WARNING';
        }elseif(preg_match('/rest-area|hospital|fuel|gas-station|information--parking|^D9-/',$code)){
            $family='services';$bg=[24,76,151];$fg=[255,255,245];$shape='wide';
            if(str_contains($code,'rest-area'))$label='REST AREA';
            elseif(str_contains($code,'hospital'))$label='HOSPITAL';
            elseif(str_contains($code,'parking'))$label='PARKING';
        }elseif(preg_match('/camping|campsite|picnic|recreational|^RL-|^RS-/',$code)){
            $family='recreation';$bg=[110,65,36];$fg=[255,255,245];$shape='wide';
        }elseif(str_starts_with($code,'information--')||preg_match('/^[DE][0-9]+-/',$code)){
            $family='guide';$bg=[20,105,61];$fg=[255,255,245];$shape='wide';
            if(str_contains($code,'highway-interchange'))$label='INTERCHANGE';
            if($code==='E4-2')$label='EXIT';
        }
        return compact('family','shape','bg','fg','label','heading','number','symbol');
    }
}
