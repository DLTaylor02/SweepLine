<?php
declare(strict_types=1);
namespace SweepLine;

/** Stamped at deployment; no Git process or repository access in web requests. */
final class Version
{
    public static function number(): string
    {
        static $version;
        if ($version===null) {
            $value=trim((string)file_get_contents(SWEEPLINE_ROOT.'/VERSION'));
            if (!preg_match('/^0\.[1-9][0-9]*\.0$/D',$value)) throw new \RuntimeException('Invalid application version.');
            $version=$value;
        }
        return $version;
    }
    public static function label(): string { return 'SweepLine v'.self::number(); }
    public static function fromCommitCount(int $count): string
    {
        if ($count<1) throw new \InvalidArgumentException('Commit count must be positive.');
        return '0.'.$count.'.0';
    }
}
