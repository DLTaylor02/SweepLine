<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamRaster
{
    public const TERRAIN_SATURATION=1.25;
    public function __construct(public int $size,public float $side,public string $heights,public string $colors)
    {
        if(strlen($heights)!==$size*$size*4 || strlen($colors)!==$size*$size*3)throw new \RuntimeException('Raster dimensions or band layout do not match the map.');
    }
    private ?self $sourceElevation=null;
    public static function fromElevation(int $size,float $side,string $heights,string $colors,int $elevationSize,callable $progress): self
    {
        if($size===$elevationSize)return new self($size,$side,$heights,$colors);
        $source=new self($elevationSize,$side,$heights,str_repeat("\0",$elevationSize*$elevationSize*3));
        $upsampled="";
        for($row=0;$row<$size;$row++){
            if($row%16===0)$progress(null);
            $y=($size-1-$row)/$size*$side;
            for($x=0;$x<$size;$x++)$upsampled.=pack('g',$source->height($x/$size*$side,$y));
        }
        $raster=new self($size,$side,$upsampled,$colors);$raster->sourceElevation=$source;return $raster;
    }
    public function roadHeight(float $x,float $y): float {return ($this->sourceElevation??$this)->height($x,$y);}
    public function elevationResolution(): int {return $this->sourceElevation?->size??$this->size;}
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
    public static function saturated(array $rgb,float $factor=self::TERRAIN_SATURATION): array
    {
        $gray=0.2126*$rgb[0]+0.7152*$rgb[1]+0.0722*$rgb[2];
        return array_map(static fn($c)=>max(0,min(255,(int)round($gray+($c-$gray)*$factor))),$rgb);
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

