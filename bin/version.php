<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/src/bootstrap.php';
$options=getopt('',['write','source:']);
$source=$options['source']??SWEEPLINE_ROOT;
$version=SweepLine\Version::number();
// Archives and deployed trees retain their bundled version. Shallow clones do not
// contain a reliable lifetime commit count and must retain the bundled stamp too.
$git=static function(array $args)use($source): ?string {
    $pipes=[];$process=@proc_open(['git','-c','safe.directory='.$source,'-C',$source,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))return null;
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
    stream_get_contents($pipes[2]);fclose($pipes[2]);
    return proc_close($process)===0?trim($out):null;
};
if($git(['rev-parse','--is-shallow-repository'])==='false'){
    $count=$git(['rev-list','--count','HEAD']);
    if($count!==null&&ctype_digit($count)&&(int)$count>0)$version=SweepLine\Version::fromCommitCount((int)$count);
}else{fwrite(STDERR,"Git history unavailable or shallow; retaining bundled version ".$version.".\n");}
if(isset($options['write'])&&file_put_contents(SWEEPLINE_ROOT.'/VERSION',$version."\n",LOCK_EX)===false)throw new RuntimeException('Cannot write application version.');
echo $version."\n";
