<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Native first, PHP second; malformed inputs and resource failures never trigger fallback. */
final readonly class AutomaticDecoder implements Decoder
{
    public function __construct(private Decoder $native = new GhostscriptDecoder(), private Decoder $php = new PhpDecoder())
    {
    }

    public function decode(CodecRequest $request): DecodedImage
    {
        try {
            return $this->native->decode($request);
        } catch (CodecUnavailable) {
            return $this->php->decode($request);
        }
    }
}
