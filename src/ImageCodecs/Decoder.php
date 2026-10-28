<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

interface Decoder
{
    public function decode(CodecRequest $request): DecodedImage;
}
