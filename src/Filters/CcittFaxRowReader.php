<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\UnsupportedPdfFeature;

/** T.4/T.6 Huffman, pass, horizontal and vertical scanline decoding. */
final readonly class CcittFaxRowReader
{
    public function __construct(private CcittFaxLimits $limits, private CcittFaxCodes $codes = new CcittFaxCodes())
    {
    }

    public function read(CcittFaxBits $bits, int $columns, CcittFaxRow $reference, bool $oneDimensional, ReadBudget $budget, CcittFaxUncompressed $uncompressed): CcittFaxRow
    {
        $position = 0;
        $white = true;
        $initial = true;
        $referenceIndex = 0;
        $changes = [];
        while ($position < $columns) {
            if ($uncompressed->active()) {
                $pixel = $uncompressed->pixel($bits);
                $nextWhite = $pixel ?? $uncompressed->exitWhite();
                if ($white !== $nextWhite) {
                    $this->change($changes, $position, $budget);
                    $white = $nextWhite;
                }
                if ($pixel !== null) {
                    ++$position;
                    $initial = false;
                }
                continue;
            }
            if ($oneDimensional && $bits->peek(12) === 15) {
                $bits->read(12);
                $uncompressed->enter();
                continue;
            }
            if ($oneDimensional) {
                $position += $this->run($bits, $white, $columns - $position);
                $this->change($changes, $position, $budget);
                $white = !$white;
                continue;
            }
            while (isset($reference->changes[$referenceIndex]) && ($reference->changes[$referenceIndex] < $position || !$initial && $reference->changes[$referenceIndex] === $position)) {
                ++$referenceIndex;
            }
            $bIndex = $referenceIndex + ($referenceIndex % 2 === ($white ? 0 : 1) ? 0 : 1);
            $b1 = $reference->changes[$bIndex] ?? $columns;
            $b2 = $reference->changes[$bIndex + 1] ?? $columns;
            $mode = $this->mode($bits);
            if ($mode === 'uncompressed') {
                $uncompressed->enter();
                continue;
            }
            if ($mode === 'pass') {
                if ($b2 <= $position) {
                    throw new CcittFaxRowException('CCITT pass mode must advance its coding position.');
                }
                $position = $b2;
            } elseif ($mode === 'horizontal') {
                $position += $this->run($bits, $white, $columns - $position);
                $this->change($changes, $position, $budget);
                $position += $this->run($bits, !$white, $columns - $position);
                $this->change($changes, $position, $budget);
            } else {
                $next = $b1 + $mode;
                if ($next < $position || $next > $columns) {
                    throw new CcittFaxRowException('CCITT vertical mode lies outside its coding row.');
                }
                $position = $next;
                $this->change($changes, $position, $budget);
                $white = !$white;
            }
            $initial = false;
        }
        return new CcittFaxRow($columns, $changes);
    }

    private function run(CcittFaxBits $bits, bool $white, int $maximum): int
    {
        $table = $white ? $this->codes->white : $this->codes->black;
        $length = 0;
        do {
            $code = 'b';
            $run = null;
            for ($width = 1; $width <= 13; ++$width) {
                $code .= (string) $bits->read();
                if (isset($table[$code])) {
                    $run = $table[$code];
                    break;
                }
            }
            if ($run === null) {
                throw new CcittFaxRowException('CCITT contains an invalid Huffman run code.');
            }
            if ($run > $maximum - $length) {
                throw new CcittFaxRowException('CCITT run lengths exceed the declared Columns.');
            }
            $length += $run;
        } while ($run >= 64);
        return $length;
    }

    /** @return int|'pass'|'horizontal'|'uncompressed' */
    private function mode(CcittFaxBits $bits): int|string
    {
        $codes = ['1' => 0, '011' => 1, '010' => -1, '000011' => 2, '000010' => -2, '0000011' => 3, '0000010' => -3, '0001' => 'pass', '001' => 'horizontal'];
        $code = '';
        for ($width = 1; $width <= 7; ++$width) {
            $code .= (string) $bits->read();
            if (isset($codes[$code])) {
                return $codes[$code];
            }
        }
        if ($code === '0000001') {
            if ($bits->read(3) === 7) {
                return 'uncompressed';
            }
            throw new UnsupportedPdfFeature('CCITT contains an undefined extension selector.');
        }
        throw new CcittFaxRowException('CCITT contains an invalid two-dimensional mode.');
    }

    /** @param list<int> $changes */
    private function change(array &$changes, int $position, ReadBudget $budget): void
    {
        $last = array_key_last($changes);
        if ($last !== null && $changes[$last] === $position) {
            array_pop($changes);
            return;
        }
        if (count($changes) >= $this->limits->maxRowTransitions) {
            throw new ParseException('CCITT exceeds its scanline transition budget.');
        }
        $budget->retained(32);
        $changes[] = $position;
    }
}
