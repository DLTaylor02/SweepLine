<?php
(function(): void {
    $version=\SweepLine\Version::number();$label=\SweepLine\Version::label();
    check(preg_match('/^0\.[1-9][0-9]*\.0$/D',$version)===1,'Bundled version follows the release numbering format');
    $pipes=[];$child=proc_open([PHP_BINARY,'-n',SWEEPLINE_ROOT.'/bin/version.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($child))throw new RuntimeException('Cannot run version CLI check.');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($child);
    check($status===0&&trim($output)===$version&&$errors==='','Version CLI reports the bundled stamp without Git-derived changes');
    $road=\SweepLine\RoadExport::prepare(['name'=>'Version test','length_m'=>100,'mandatory_stops'=>0,'uncertain_controls'=>0,'geometry'=>['type'=>'LineString','coordinates'=>[[-100,40],[-99.999,40.001]]]]);
    check(str_contains(\SweepLine\RoadExport::gpx($road),'creator="'.$label.'"') && str_contains(\SweepLine\RoadExport::gpx($road),'<desc>'.$label),'GPX exposes its generating version');
    check(str_contains(\SweepLine\RoadExport::kml($road),'<description>'.$label),'KMZ document description exposes its generating version');
    check(str_contains(\SweepLine\RoadExport::pdf($road),strtoupper($label)),'PDF prints the generating version');
    ob_start();require SWEEPLINE_ROOT.'/public/home.php';$html=ob_get_clean();
    check(str_contains($html,'class="app-version">v'.$version) && str_contains($html,'<title>'.$label.'</title>'),'Shared page header and title show the version');
    $log=(new \SweepLine\BeamBuildLog())->render([],[],[],[]);
    check(str_contains($log,$label) && str_contains($log,'"sweepline_version": "'.$version.'"'),'BeamNG build diagnostics record their generating version');
})();
