<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamRaster
{
    public function __construct(public int $size,public float $side,public string $heights,public string $colors)
    {
        if(strlen($heights)!==$size*$size*4 || strlen($colors)!==$size*$size*3)throw new \RuntimeException('Raster dimensions or band layout do not match the map.');
    }
    public function cell(int $x,int $y): float
    {
        $x=max(0,min($this->size-1,$x));$y=max(0,min($this->size-1,$y));
        $v=unpack('g',substr($this->heights,(($this->size-1-$y)*$this->size+$x)*4,4))[1];
        if(!is_finite($v) || $v< -500 || $v>10000)throw new \RuntimeException('USGS elevation coverage is missing or invalid in this square. No synthetic terrain was substituted.');
        return $v;
    }
    public function put(int $x,int $y,float $height): void
    {
        $offset=(($this->size-1-$y)*$this->size+$x)*4;$bytes=pack('g',$height);
        for($i=0;$i<4;$i++)$this->heights[$offset+$i]=$bytes[$i];
    }
    public function height(float $x,float $y): float
    {
        $gx=max(0,min($this->size-1,$x/$this->side*$this->size));$gy=max(0,min($this->size-1,$y/$this->side*$this->size));
        $ix=(int)floor($gx);$iy=(int)floor($gy);$tx=$gx-$ix;$ty=$gy-$iy;
        return (1-$ty)*((1-$tx)*$this->cell($ix,$iy)+$tx*$this->cell($ix+1,$iy))+$ty*((1-$tx)*$this->cell($ix,$iy+1)+$tx*$this->cell($ix+1,$iy+1));
    }
    public function rgb(int $x,int $y): array
    {
        $x=max(0,min($this->size-1,$x));$y=max(0,min($this->size-1,$y));$offset=($this->size-1-$y)*$this->size+$x;$plane=$this->size*$this->size;
        return [ord($this->colors[$offset]),ord($this->colors[$plane+$offset]),ord($this->colors[2*$plane+$offset])];
    }
    public static function cover(array $rgb): int
    {
        [$r,$g,$b]=$rgb;
        if($b>$r*1.15 && $b>$g*1.05)return 4; // likely water: no roadside objects
        if($g>$r*1.08 && $g>$b*1.03)return 0; // vegetation
        if(max($rgb)-min($rgb)<25 && ($r+$g+$b)/3>125)return 3; // built/rock-like
        return ($r+$g+$b)/3<95?2:1; // rock / dry soil
    }
}

