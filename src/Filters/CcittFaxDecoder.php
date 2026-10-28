<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;

/** ISO 32000-2 7.4.6: native MH/MR/MMR samples with explicit bounded damage recovery. */
final readonly class CcittFaxDecoder implements Decoder
{
    public function __construct(
        private ReaderLimits $limits = new ReaderLimits(),
        private CcittFaxParameters $parameters = new CcittFaxParameters(),
        private CcittFaxLimits $workLimits = new CcittFaxLimits(),
    ) {
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        $settings = $this->parameters;
        $rowBytes = $settings->rowBytes();
        if ($rowBytes > $this->limits->maxDecodedStreamBytes || strlen($bytes) > $this->limits->maxFileBytes) {
            throw new ParseException('CCITT input or scanline exceeds the configured byte limit.');
        }
        $bits = new CcittFaxBits($bytes, $this->workLimits);
        $reader = new CcittFaxRowReader($this->workLimits);
        $reference = new CcittFaxRow($settings->columns);
        $uncompressed = new CcittFaxUncompressed();
        $previousDamaged = false;
        $damaged = 0;
        $output = '';
        $synchronized = false;
        for ($row = 0; ; ++$row) {
            if (!$settings->endOfBlock && (($settings->rows > 0 && $row === $settings->rows) || !$uncompressed->active() && $bits->exhausted())) {
                return $output;
            }
            if (!$uncompressed->active() && !$synchronized && $settings->encodedByteAlign) {
                $bits->align();
            }
            if (!$uncompressed->active() && $settings->k < 0 && $bits->peek(24) === 0x001001) {
                $bits->read(24);
                $bits->finish();
                return $output;
            }
            $synchronized = false;
            $rowStart = $bits->position();
            $isDamaged = false;
            try {
                $oneDimensional = $settings->k === 0;
                if ($uncompressed->active()) {
                    // T.6 concatenates the last and first pixels of adjacent rows.
                } elseif ($bits->hasEol()) {
                    $bits->eol();
                    $tag = $settings->k > 0 ? $bits->read() : 1;
                    if ($settings->k >= 0 && $bits->hasEol()) {
                        if ($tag !== 1) {
                            throw new CcittFaxRowException('CCITT RTC requires one-dimensional tag bits.');
                        }
                        for ($marker = 1; $marker < 6; ++$marker) {
                            $bits->eol();
                            if ($settings->k > 0 && $bits->read() !== 1) {
                                throw new CcittFaxRowException('CCITT RTC requires one-dimensional tag bits.');
                            }
                        }
                        $bits->finish();
                        return $output;
                    }
                    $oneDimensional = $settings->k === 0 || $settings->k > 0 && $tag === 1;
                } elseif ($settings->endOfLine) {
                    throw new CcittFaxRowException('CCITT EndOfLine requires a marker before each row.');
                } elseif ($settings->k > 0) {
                    $oneDimensional = $bits->read() === 1;
                }
                $rowStart = $bits->position();
                if ($row === 0 && $settings->k > 0 && !$oneDimensional) {
                    throw new CcittFaxRowException('CCITT mixed coding requires a one-dimensional first row.');
                }
                $next = $reader->read($bits, $settings->columns, $reference, $oneDimensional, $budget, $uncompressed);
                $uncompressed->boundary($bits);
                if ($settings->k >= 0 && $uncompressed->active()) {
                    throw new CcittFaxRowException('T.4 uncompressed data must exit before the line synchronization boundary.');
                }
            } catch (CcittFaxRowException $error) {
                if (!$settings->endOfLine || $settings->k < 0 || $damaged >= $settings->damagedRowsBeforeError) {
                    throw $error;
                }
                $bits->recover($rowStart);
                $synchronized = true;
                $uncompressed = new CcittFaxUncompressed();
                $next = $previousDamaged ? new CcittFaxRow($settings->columns) : $reference;
                ++$damaged;
                $isDamaged = true;
            }
            if ($row >= $this->workLimits->maxRows || $rowBytes > $this->limits->maxDecodedStreamBytes - strlen($output)) {
                throw new ParseException('CCITT exceeds its decoded byte or row limit.');
            }
            $budget->decoded($rowBytes);
            $budget->retained($rowBytes);
            $output .= $next->bytes($settings->blackIs1);
            $reference = $next;
            $previousDamaged = $isDamaged;
        }
    }
}
