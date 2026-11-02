<?php
declare(strict_types=1);
namespace SweepLine;
final class DownloadFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable) { parent::__construct($message); }
}
