<?php
(function():void{
    check(\SweepLine\CoveragePlanner::roadCount('<osm><count><tag k="ways" v="12000"/></count></osm>')===12000,'Density preflight parses actual way count');
    check(\SweepLine\CoveragePlanner::roadCount('<osm><count><tag k="ways" v="0"/></count></osm>')===0,'Empty density estimate remains valid');
    foreach(['<osm/>','<osm><remark>timeout</remark></osm>','broken','<osm><count><tag k="ways" v="-1"/></count></osm>'] as $xml)
        try{\SweepLine\CoveragePlanner::roadCount($xml);check(false,'Malformed density estimate rejected');}catch(RuntimeException $e){check(true,'Unavailable or malformed density estimates cannot masquerade as empty areas');}
})();
