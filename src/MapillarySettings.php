<?php
declare(strict_types=1);
namespace SweepLine;
final class MapillarySettings {
    public function __construct(private \PDO $db){}
    public function token():string{
        $value=$this->db->query("SELECT value FROM app_settings WHERE key='mapillary_access_token'")->fetchColumn();
        return $value===false?trim(getenv('MAPILLARY_ACCESS_TOKEN')?:''):(string)$value;
    }
    public function read():array{
        return ['configured'=>$this->token()!==''];
    }
    public static function validate(mixed $value):string{
        if(!is_string($value)||strlen($value)>4096||preg_match('/[^\x21-\x7e]/',$value))throw new \InvalidArgumentException('Enter a valid Mapillary access token without whitespace (up to 4096 characters).');
        return $value;
    }
    public function save(array $input):array{
        if(($input['clear_token']??false)===true)$token='';
        elseif(isset($input['access_token'])&&$input['access_token']!=='')$token=self::validate($input['access_token']);
        else return $this->read()+['message'=>'Token unchanged.'];
        $this->db->prepare("INSERT INTO app_settings(key,value) VALUES ('mapillary_access_token',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([$token]);
        return $this->read()+['message'=>$token===''?'Mapillary disabled.':'Mapillary token saved. It will be used by subsequent exports.'];
    }
}
