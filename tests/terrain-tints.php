<?php
(function():void{
    foreach([0,1,2,3,4,6,7] as $id){
        $base=\SweepLine\BeamTerrain::PALETTE[$id]['color'];
        [$low,$high,$chroma]=\SweepLine\BeamTerrain::TINT_LIMITS[$id];
        foreach([[220,40,190],[30,210,60],[180,170,140]] as $photo){
            $color=\SweepLine\BeamTerrain::blendedColor($id,$photo);$bounded=true;
            foreach($color as $channel=>$value)$bounded=$bounded&&$value>=round($base[$channel]*0.85)&&$value<=$base[$channel]+$high+$chroma+1;
            check($bounded,'Material '.$id.' retains its palette bounds under extreme aerial color');
            if($id<2)check($color[1]>=$color[0]+8&&$color[1]>=$color[2]+8,'Vegetation remains recognizable under non-green aerial tint');
        }
        check(\SweepLine\BeamTerrain::blendedColor($id,[0,0,0])===$base,'Missing illumination keeps material '.$id.' palette');
    }
    $rock=\SweepLine\BeamTerrain::blendedColor(4,[220,40,190]);
    check(max($rock)-min($rock)<50,'Strong magenta imagery cannot turn rock into a neon surface');
    check(\SweepLine\BeamTerrain::blendedColor(4,[90,90,90])!==\SweepLine\BeamTerrain::blendedColor(4,[170,170,170]),'Bounded material shading still preserves local exposure variation');
    check(\SweepLine\BeamTerrain::TINT_LIMITS[4][2]<\SweepLine\BeamTerrain::TINT_LIMITS[2][2],'Rock shading is more color-restrained than soil');
})();
