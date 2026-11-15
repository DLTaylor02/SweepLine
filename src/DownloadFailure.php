<?php
declare(strict_types=1);
namespace SweepLine;
final class DownloadFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, public readonly ?string $providerDetail=null) { parent::__construct($message); }
}
