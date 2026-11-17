<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamTerrainConflict extends BeamInterchangeLimit
{
    public function __construct(string $message,public readonly array $diagnostic)
    {
        parent::__construct($message);
    }
}
