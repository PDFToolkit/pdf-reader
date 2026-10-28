<?php

declare(strict_types=1);

namespace PDF\Reading\Tests\ImageCodecs;

use PDF\Reading\ImageCodecs\CodecRequest;
use PDF\Reading\ImageCodecs\ComponentPlane;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\ImageCodecs\Decoder;

final class RecordingDecoder implements Decoder
{
    /** @var list<CodecRequest> */
    public array $requests = [];

    public function __construct(private readonly ?DecodedImage $image = null, private readonly ?\Throwable $failure = null)
    {
    }

    public function decode(CodecRequest $request): DecodedImage
    {
        $this->requests[] = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->image ?? new DecodedImage(8, 1, [new ComponentPlane(8, 1, 1, false, "\xa5")]);
    }
}
