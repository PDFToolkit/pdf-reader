<?php

declare(strict_types=1);

namespace PDF\Reading\Tests\Support;

use PDF\Reading\ExternalStreamRequest;
use PDF\Reading\ExternalStreamSource;
use PDF\Reading\FileBytes;

final class ExternalSourceProbe implements ExternalStreamSource
{
    /** @var list<ExternalStreamRequest> */
    public array $requests = [];

    public function __construct(private readonly string $bytes)
    {
    }

    public function read(ExternalStreamRequest $request): FileBytes
    {
        $this->requests[] = $request;
        return new FileBytes($this->bytes);
    }
}
