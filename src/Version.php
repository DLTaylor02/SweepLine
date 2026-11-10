<?php
declare(strict_types=1);
namespace SweepLine;

/** Manually maintained release stamp, shared by pages and exports. */
final class Version
{
    public static function valid(string $value): bool
    {
        return preg_match('/^0\.[1-9][0-9]*\.(0|[1-9][0-9]*)$/D',$value)===1;
    }
    public static function number(): string
    {
        static $version;
        if ($version===null) {
            $value=trim((string)file_get_contents(SWEEPLINE_ROOT.'/VERSION'));
            if (!self::valid($value)) throw new \RuntimeException('Invalid application version.');
            $version=$value;
        }
        return $version;
    }
    public static function label(): string { return 'SweepLine v'.self::number(); }
}
