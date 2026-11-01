<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class PasswordPolicy
{
    public const DEFAULTS=['minimum_length'=>12,'strength'=>'standard','screen_compromised'=>true];
    public const WARNING='Password saved. Compromised-password screening was temporarily unavailable, so this password could not be checked.';
    public function __construct(private PDO $db,private ?PwnedPasswords $screening=null) {}
    public function read(): array
    {
        $q=$this->db->query("SELECT value FROM app_settings WHERE key='password_policy'");$value=$q->fetchColumn();
        return $value===false?self::DEFAULTS:self::settings(json_decode($value,true,512,JSON_THROW_ON_ERROR));
    }
    public static function settings(array $input): array
    {
        $minimum=Validation::number($input,'minimum_length',12,1,72);
        if (floor($minimum)!==$minimum || !is_string($input['strength']??null) || !in_array($input['strength'],['basic','standard','strong'],true) || !is_bool($input['screen_compromised']??null)) {
            throw new \InvalidArgumentException('Choose a whole minimum length from 1 to 72, a valid strength, and a screening checkbox.');
        }
        return ['minimum_length'=>(int)$minimum,'strength'=>$input['strength'],'screen_compromised'=>$input['screen_compromised']];
    }
    public function save(array $input): array
    {
        $settings=self::settings($input);
        $this->db->prepare("INSERT INTO app_settings(key,value) VALUES ('password_policy',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([json_encode($settings,JSON_THROW_ON_ERROR)]);
        return $settings;
    }
    public static function format(mixed $password): string
    {
        if (!is_string($password) || $password==='' || strlen($password)>72 || str_contains($password,"\0") || !preg_match('//u',$password)) {
            throw new \InvalidArgumentException('Password must contain 1 to 72 UTF-8 bytes and no null characters.');
        }
        return $password;
    }
    public static function requirements(mixed $password,array $policy,bool $exempt=false): string
    {
        $password=self::format($password);
        if ($exempt) {return $password;}
        $count=preg_match_all('/./us',$password);
        $types=(int)(bool)preg_match('/[A-Z]/',$password)+(int)(bool)preg_match('/[a-z]/',$password)+
            (int)(bool)preg_match('/[0-9]/',$password)+(int)(bool)preg_match('/[^A-Za-z0-9]/u',$password);
        $needed=['basic'=>0,'standard'=>3,'strong'=>4][$policy['strength']];
        if ($count<$policy['minimum_length'] || $types<$needed) {
            throw new \InvalidArgumentException('Password must have at least '.$policy['minimum_length'].' characters and '.($needed?$needed.' of 4 character types (uppercase, lowercase, number, symbol).':'no additional character-type requirement.'));
        }
        return $password;
    }
    /** Warning is informational, never a claim that an unchecked password is unsafe. */
    public function validate(mixed $password,string $role): ?string
    {
        $policy=$this->read();self::requirements($password,$policy,$role==='superadmin');
        if ($role==='superadmin' || !$policy['screen_compromised']) {return null;}
        $found=($this->screening??new PwnedPasswords())->compromised($password);
        if ($found===true) {throw new \InvalidArgumentException('This password appears in known breaches. Choose a different password.');}
        return $found===null?self::WARNING:null;
    }
}

