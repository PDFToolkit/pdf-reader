<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Syntax\Filters\EarlyChange;

/** ISO 32000-1, 7.4.4.2/Table 8: clear, EOD, EarlyChange and the 4096-code table. */
final readonly class LzwDecoder implements Decoder
{
    public function __construct(
        private ReaderLimits $limits = new ReaderLimits(),
        private EarlyChange $earlyChange = new EarlyChange(),
    ) {
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        $input = new LzwCodeReader($bytes);
        $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
        $dictionary = [];
        $next = 258;
        $width = 9;
        $previous = null;
        while (true) {
            $code = $input->read($width);
            if ($code === 256) {
                $dictionary = [];
                $next = 258;
                $width = 9;
                $previous = null;
                continue;
            }
            if ($code === 257) {
                return $output->bytes();
            }
            if ($code < 256) {
                $entry = chr($code);
            } elseif (isset($dictionary[$code])) {
                $entry = $dictionary[$code];
            } elseif ($code === $next && $previous !== null && $next < 4096) {
                $entry = $previous . $previous[0];
            } else {
                throw new ParseException('LZW code does not identify a defined dictionary entry.');
            }
            $output->append($entry);
            if ($previous !== null && $next < 4096) {
                $dictionary[$next++] = $previous . $entry[0];
                if ($width < 12 && $next + $this->earlyChange->value->integer() === (1 << $width)) {
                    ++$width;
                }
            }
            $previous = $entry;
        }
    }
}
