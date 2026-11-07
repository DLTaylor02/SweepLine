<?php
declare(strict_types=1);
namespace SweepLine;

/** Export-local, allowlisted diagnostics. No environment, credentials, sessions or user accounts. */
final class BeamBuildLog
{
    private array $events=[];
    private int $dropped=0;
    private array $artifacts=[];
    private float $started;
    public function __construct(){ $this->started=microtime(true); }
    public function event(?string $message): void
    {
        if($message===null)return;
        if(count($this->events)>=2000){$this->dropped++;return;}
        $this->events[]=['utc'=>gmdate('c'),'elapsed_seconds'=>round(microtime(true)-$this->started,3),'message'=>$message];
    }
    public function artifact(string $name,string $path): void
    {
        if(!is_file($path))return;
        $this->artifacts[]=['name'=>$name,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
    }
    public function render(array $plan,array $report,array $generated,array $files): string
    {
        $inputs=array_intersect_key($plan,array_flip(['name','alternate_names','orientation','epsg','bounds','padding_miles','resolution','terrain_style','center','selection','points','sources','bridges','tunnels','carriageways','carriageway_data','building_data','guardrail_data','intersection_data','water_data']));
        $data=['sweepline_version'=>Version::number(),'build_utc'=>gmdate('c'),'duration_seconds'=>round(microtime(true)-$this->started,3),
            'coordinate_reference'=>'selection coordinates are longitude/latitude EPSG:4326; plan points and source geometry use plan EPSG in metres; generated roads use map-local metres with report elevation_origin_m.',
            'input_note'=>'Original custom clicks are recorded only when retained in the road snapshot. Older snapshots expose snapped/exported geometry only. Missing fields mean unavailable, not zero.',
            'inputs'=>$inputs,'generated_geometry'=>$generated,'analysis_and_rejections'=>$report,'events'=>$this->events,'omitted_event_count'=>$this->dropped,
            'downloaded_and_processed_input_files'=>$this->artifacts,'archive_files_before_build_log'=>$files,
            'limits'=>'Rejected-object counts/reasons are recorded where the producing subsystem supplies them; unavailable individual rejected footprints are not reconstructed. Provider names and grid requests describe requested data, not its surveyed accuracy.'];
        return Version::label()." BeamNG.drive build.log\nBuild completed; in-game validation remains separate.\nCoordinates describe this exported road and may be sensitive when sharing the archive.\n\n".json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    }
}
