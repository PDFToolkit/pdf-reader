<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

/** An immutable scanline, with alternating white/black transition positions. */
final readonly class CcittFaxRow
{
    /** @param list<int> $changes */
    public function __construct(public int $columns, public array $changes = [])
    {
    }

    public function bytes(bool $blackIs1): string
    {
        $output = '';
        $pending = 0;
        $used = 0;
        $start = 0;
        $white = true;
        for ($index = 0; $index <= count($this->changes); ++$index) {
            $end = $this->changes[$index] ?? $this->columns;
            $length = $end - $start;
            $one = $white !== $blackIs1;
            if ($used > 0 && $length > 0) {
                $take = min(8 - $used, $length);
                $pending = ($pending << $take) | ($one ? (1 << $take) - 1 : 0);
                $used += $take;
                $length -= $take;
                if ($used === 8) {
                    $output .= chr($pending);
                    $used = $pending = 0;
                }
            }
            $whole = intdiv($length, 8);
            $output .= str_repeat($one ? "\xff" : "\0", $whole);
            $length %= 8;
            if ($length > 0) {
                $pending = $one ? (1 << $length) - 1 : 0;
                $used = $length;
            }
            $white = !$white;
            $start = $end;
        }
        if ($used > 0) {
            // Unused samples are zero; only Columns samples have colour semantics.
            $output .= chr($pending << (8 - $used));
        }
        return $output;
    }
}
