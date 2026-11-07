<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** One shutdown hook for the long-lived CLI worker, never one closure per job. */
final class BeamFailure
{
    private static ?PDO $db=null;
    private static ?array $active=null;
    private static string $reserve='';
    public static function install(PDO $db): void
    {
        if(self::$db!==null)return;self::$db=$db;self::$reserve=str_repeat(' ',262144);
        register_shutdown_function(static function():void{
            $error=error_get_last();
            if(!self::$active||!$error||!in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
            self::$reserve='';$active=self::$active;self::$active=null;
            $message=self::message((string)$error['message'],$active['stage']);
            error_log('SweepLine BeamNG '.$active['id'].': '.$message);
            try{
                if(self::$db->inTransaction())self::$db->rollBack();
                $q=self::$db->prepare("UPDATE beamng_exports SET status=CASE WHEN cancel_requested THEN 'cancelled' ELSE 'failed' END,progress='Export worker stopped',error=CASE WHEN cancel_requested THEN NULL ELSE ? END,payload='{}'::jsonb,updated_at=now() WHERE id=? AND status='running'");
                $q->execute([$message,$active['id']]);
            }catch(\Throwable $e){error_log('SweepLine could not persist the fatal export status; inspect the worker journal.');}
        });
    }
    public static function begin(string $id): void {self::$active=['id'=>$id,'stage'=>'Preparing map'];}
    public static function stage(string $message): void
    {
        if(self::$active)self::$active['stage']=substr(str_replace(["\r","\n"],' ',$message),0,240);
    }
    public static function finish(): void {self::$active=null;}
    public static function message(string $error,string $stage): string
    {
        $stage=substr(str_replace(["\r","\n"],' ',$stage),0,240);
        return str_contains($error,'Allowed memory size')?
            'Export exceeded the worker PHP memory limit during: '.$stage.'. Check the worker service journal for details.':
            'Export worker stopped with a PHP fatal error during: '.$stage.'. Check the worker service journal for details.';
    }
}
