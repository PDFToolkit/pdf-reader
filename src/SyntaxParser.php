<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** ISO 32000-1, 7.2 and 7.3: bounded syntax without object or stream resolution. */
final class SyntaxParser
{
    private readonly ReadBudget $budget;

    public function __construct(
        public readonly ByteCursor $cursor,
        private readonly ReaderLimits $limits = new ReaderLimits(),
        ?ReadBudget $budget = null,
        private readonly ?SourceStringSpans $sourceStrings = null,
    ) {
        $this->budget = $budget ?? new ReadBudget($limits);
    }

    public function read(): BasicType
    {
        return $this->readValue(1);
    }

    public function unsignedInteger(): int
    {
        $this->cursor->skipWhitespaceAndComments();
        $start = $this->cursor->position();
        $token = $this->token();
        if ($token === '' || !ctype_digit($token)) {
            throw new ParseException('An unsigned integer was expected.', $start);
        }
        return $this->integerValue($token, $start);
    }

    public function keyword(string $keyword): void
    {
        if (!$this->atKeyword($keyword)) {
            throw new ParseException('The expected PDF keyword was not found.', $this->cursor->position());
        }
        $this->cursor->read(strlen($keyword));
    }

    public function atKeyword(string $keyword): bool
    {
        if ($keyword === '') {
            throw new \InvalidArgumentException('A PDF keyword cannot be empty.');
        }
        $this->cursor->skipWhitespaceAndComments();
        $candidate = $this->cursor->peek(strlen($keyword) + 1);
        return str_starts_with($candidate, $keyword)
            && (strlen($candidate) === strlen($keyword) || $this->delimiter($candidate[strlen($keyword)]));
    }

    private function readValue(int $depth): BasicType
    {
        $this->cursor->skipWhitespaceAndComments();
        if ($depth > $this->limits->maxDepth) {
            throw new ParseException('The object nesting limit was exceeded.', $this->cursor->position());
        }
        $this->budget->value();
        $first = $this->cursor->peek();
        return match ($first) {
            '/' => $this->name(),
            '(' => $this->literal($depth),
            '[' => $this->array($depth),
            '<' => $this->cursor->peek(2) === '<<' ? $this->dictionary($depth) : $this->hexadecimal(),
            '' => throw new ParseException('A PDF value was expected before the end of the byte view.', $this->cursor->position()),
            default => $this->scalar(),
        };
    }

    private function scalar(): BasicType
    {
        $start = $this->cursor->position();
        $token = $this->token();
        if ($token === 'true' || $token === 'false') {
            return new BooleanType($token === 'true');
        }
        if ($token === 'null') {
            return new NullType();
        }
        if (preg_match('/\A[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)\z/D', $token) !== 1) {
            throw new ParseException('Invalid PDF scalar syntax.', $start);
        }
        if (str_contains($token, '.')) {
            $value = (float) $token;
            if (!is_finite($value)) {
                throw new ParseException('A real number exceeds the supported numeric range.', $start);
            }
            return new NumericType($value);
        }
        $integer = $this->integerValue($token, $start);
        $after = $this->cursor->position();
        if (ctype_digit($token)) {
            $this->cursor->skipWhitespaceAndComments();
            if (ctype_digit($this->cursor->peek())) {
                $generationToken = $this->token();
                if (ctype_digit($generationToken) && $this->atKeyword('R')) {
                    $generation = $this->integerValue($generationToken, $after);
                    if ($integer === 0 || $generation > 65535) {
                        throw new ParseException('Invalid indirect-reference identifier.', $start);
                    }
                    $this->keyword('R');
                    return new ObjectReference(new ObjectIdentifier(new ObjectNumber($integer), new GenerationNumber($generation)));
                }
            }
            $this->cursor->seek($after);
        }
        return new IntegerType($integer);
    }

    private function integerValue(string $token, int $offset): int
    {
        $negative = str_starts_with($token, '-');
        $digits = ltrim(ltrim($token, '+-'), '0');
        $bound = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($bound) || (strlen($digits) === strlen($bound) && strcmp($digits, $bound) > 0)) {
            throw new ParseException('An integer exceeds the supported numeric range.', $offset);
        }
        return (int) $token;
    }

    private function name(): NameType
    {
        $start = $this->cursor->position();
        $this->cursor->read(1);
        $value = '';
        while (!$this->cursor->eof() && !$this->delimiter($this->cursor->peek())) {
            $byte = $this->cursor->read(1);
            if ($byte === '#') {
                $hex = $this->cursor->peek(2);
                if (strlen($hex) !== 2 || !ctype_xdigit($hex)) {
                    throw new ParseException('A name escape must contain two hexadecimal digits.', $this->cursor->position());
                }
                $this->cursor->read(2);
                $byte = chr((int) hexdec($hex));
            }
            if ($byte === "\0") {
                throw new ParseException('PDF names cannot contain a null byte.', $start);
            }
            $this->appendRetained($value, $byte);
            $this->tokenLimit($start);
        }
        return new NameType($value);
    }

    private function literal(int $depth): LiteralString
    {
        $start = $this->cursor->position();
        $this->cursor->read(1);
        $nesting = 1;
        $value = '';
        while (!$this->cursor->eof()) {
            $byte = $this->cursor->read(1);
            if ($byte === '\\') {
                if ($this->cursor->eof()) {
                    break;
                }
                $escaped = $this->cursor->read(1);
                if ($escaped === "\r" || $escaped === "\n") {
                    if ($escaped === "\r" && $this->cursor->peek() === "\n") {
                        $this->cursor->read(1);
                    }
                } elseif ($escaped >= '0' && $escaped <= '7') {
                    $octal = $escaped;
                    for ($i = 0; $i < 2; ++$i) {
                        $next = $this->cursor->peek();
                        if ($next === '' || $next < '0' || $next > '7') {
                            break;
                        }
                        $octal .= $this->cursor->read(1);
                    }
                    $this->appendRetained($value, chr(((int) octdec($octal)) & 255));
                } else {
                    $this->appendRetained($value, match ($escaped) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                        default => $escaped,
                    });
                }
            } elseif ($byte === '(') {
                ++$nesting;
                if ($nesting > $this->limits->maxDepth - $depth + 1) {
                    throw new ParseException('The literal-string nesting limit was exceeded.', $this->cursor->position());
                }
                $this->appendRetained($value, '(');
            } elseif ($byte === ')') {
                --$nesting;
                $this->tokenLimit($start);
                if ($nesting === 0) {
                    return new LiteralString($value);
                }
                $this->appendRetained($value, ')');
            } elseif ($byte === "\r") {
                if ($this->cursor->peek() === "\n") {
                    $this->cursor->read(1);
                }
                $this->appendRetained($value, "\n");
            } else {
                $this->appendRetained($value, $byte);
            }
            $this->tokenLimit($start);
        }
        throw new ParseException('An unterminated literal string was found.', $start);
    }

    private function hexadecimal(): HexadecimalString
    {
        $start = $this->cursor->position();
        $this->cursor->read(1);
        $digits = '';
        while (!$this->cursor->eof()) {
            $byte = $this->cursor->read(1);
            $this->tokenLimit($start);
            if ($byte === '>') {
                if (strlen($digits) % 2 !== 0) {
                    $digits .= '0';
                }
                $this->budget->retained(intdiv(strlen($digits), 2));
                $decoded = hex2bin($digits);
                if ($decoded === false) {
                    throw new ParseException('Invalid hexadecimal string.', $start);
                }
                return new HexadecimalString($decoded);
            }
            if (str_contains("\0\t\n\f\r ", $byte)) {
                continue;
            }
            if (!ctype_xdigit($byte)) {
                throw new ParseException('Invalid hexadecimal string digit.', $this->cursor->position() - 1);
            }
            $digits .= $byte;
        }
        throw new ParseException('An unterminated hexadecimal string was found.', $start);
    }

    private function array(int $depth): ArrayType
    {
        $this->cursor->read(1);
        $items = [];
        while (true) {
            $this->cursor->skipWhitespaceAndComments();
            if ($this->cursor->peek() === ']') {
                $this->cursor->read(1);
                return new ArrayType(...$items);
            }
            if (count($items) >= $this->limits->maxContainerEntries) {
                throw new ParseException('The array-entry limit was exceeded.', $this->cursor->position());
            }
            $items[] = $this->readValue($depth + 1);
        }
    }

    private function dictionary(int $depth): DictionaryType
    {
        $this->cursor->read(2);
        $entries = [];
        $keys = [];
        while (true) {
            $this->cursor->skipWhitespaceAndComments();
            if ($this->cursor->peek(2) === '>>') {
                $this->cursor->read(2);
                return new DictionaryType(...$entries);
            }
            if (count($entries) >= $this->limits->maxContainerEntries) {
                throw new ParseException('The dictionary-entry limit was exceeded.', $this->cursor->position());
            }
            if ($this->cursor->peek() !== '/') {
                throw new ParseException('A dictionary key must be a name.', $this->cursor->position());
            }
            $this->budget->value();
            $key = $this->name();
            if (isset($keys[$key->value])) {
                throw new ParseException('Duplicate dictionary keys are not accepted.', $this->cursor->position());
            }
            $keys[$key->value] = true;
            $this->cursor->skipWhitespaceAndComments();
            $start = $this->cursor->position();
            $value = $this->readValue($depth + 1);
            // These security strings need their actual token, not a textual search that
            // could accidentally select a duplicate in a stream or an older revision.
            if ($value instanceof StringType && in_array($key->value, ['MAC', 'Contents', 'KDFSalt'], true)) {
                $this->sourceStrings?->record($value, $start, $this->cursor->position());
            }
            $entries[] = new Entry($key, $value);
        }
    }

    private function token(): string
    {
        $start = $this->cursor->position();
        $value = '';
        while (!$this->cursor->eof() && !$this->delimiter($this->cursor->peek())) {
            $value .= $this->cursor->read(1);
            $this->tokenLimit($start);
        }
        return $value;
    }

    private function tokenLimit(int $start): void
    {
        if ($this->cursor->position() - $start > $this->limits->maxTokenBytes) {
            throw new ParseException('The token-byte limit was exceeded.', $start);
        }
    }

    private function appendRetained(string &$value, string $bytes): void
    {
        $this->budget->retained(strlen($bytes));
        $value .= $bytes;
    }

    private function delimiter(string $byte): bool
    {
        // EC3 issue365: braces delimit Type 4 calculator procedures, not general PDF objects.
        return $byte !== '' && str_contains("\0\t\n\f\r ()<>[]/%", $byte);
    }
}
