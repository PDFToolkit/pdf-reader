<?php

declare(strict_types=1);

namespace PDF\Reading;

/** A verified /Encrypt trailer entry, distinguishable from unrelated unsupported codecs. */
final class EncryptedPdfException extends UnsupportedPdfFeature
{
    public function __construct(int $offset)
    {
        parent::__construct('Encrypted PDF files are not supported by this reader.', $offset);
    }
}
