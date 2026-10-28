<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\ByteCursor;
use PDF\Reading\Filters\CcittFaxDecoder;
use PDF\Reading\Filters\CcittFaxLimits;
use PDF\Reading\Filters\CcittFaxParameters;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingCcittFaxPrefixTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/fax/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testIndependentAndNormativeCasesHaveAnExactBoundary(array $case): void
    {
        $encoded = base64_decode($case['encoded'], true);
        self::assertIsString($encoded);
        $cursor = new ByteCursor('before' . $encoded . ' EI q Q after', 6);
        if (!($case['params']['EndOfBlock'] ?? true) && ($case['params']['Rows'] ?? 0) === 0) {
            $this->expectExceptionMessage('EndOfBlock or a positive Rows');
        }
        $result = (new CcittFaxDecoder(parameters: $this->parameters($case['params'])))->decodePrefix($cursor, new ReadBudget(new ReaderLimits()));
        self::assertSame(base64_decode($case['bitmap'], true), $result->samples);
        self::assertSame(strlen($encoded), $result->encodedBytes);
        self::assertSame(6 + strlen($encoded), $cursor->position());
        self::assertSame(' EI q Q after', $cursor->read($cursor->remaining()));
        self::assertGreaterThan(0, $result->bitReads);
        self::assertGreaterThan(0, $result->rows);
    }

    public function testPrefixDoesNotInterpretPdfSeparatorsAndWholeStreamRemainsStrict(): void
    {
        $encoded = $this->bits('1' . '000000000001000000000001');
        $decoder = new CcittFaxDecoder(parameters: new CcittFaxParameters(k: -1, columns: 8));
        foreach (['EI', ' EI q Q', "\0\0 EI"] as $following) {
            $cursor = new ByteCursor($encoded . $following);
            self::assertSame("\xff", $decoder->decodePrefix($cursor, new ReadBudget(new ReaderLimits()))->samples);
            self::assertSame($following, $cursor->read($cursor->remaining()));
        }
        $this->expectException(ParseException::class);
        $decoder->decode($encoded . ' EI', new ReadBudget(new ReaderLimits()));
    }

    public function testEiBytesInsideUncompressedPixelsDoNotBecomeABoundary(): void
    {
        // T.6 entry, six literal black pixels, then literal patterns whose encoded bytes spell EI.
        $pixels = '1111110100010101001001';
        $encoded = $this->bits('0000001111' . $pixels . '00000010' . '000000000001000000000001');
        self::assertStringContainsString('EI', $encoded);
        $cursor = new ByteCursor($encoded . ' EI q Q');
        $result = (new CcittFaxDecoder(parameters: new CcittFaxParameters(k: -1, columns: 22)))
            ->decodePrefix($cursor, new ReadBudget(new ReaderLimits()));
        self::assertSame($this->bits(strtr($pixels, '01', '10')), $result->samples);
        self::assertSame(strlen($encoded), $result->encodedBytes);
        self::assertSame(' EI q Q', $cursor->read($cursor->remaining()));
    }

    public function testRowsTerminatedStreamsDoNotAcceptUnconsumedWholeStreamData(): void
    {
        $decoder = new CcittFaxDecoder(parameters: new CcittFaxParameters(k: -1, columns: 8, rows: 2, endOfBlock: false));
        $cursor = new ByteCursor("\xc0 EI q Q");
        $result = $decoder->decodePrefix($cursor, new ReadBudget(new ReaderLimits()));
        self::assertSame("\xff\xff", $result->samples);
        self::assertSame(1, $result->encodedBytes);
        $this->expectException(ParseException::class);
        $decoder->decode("\xc0 EI", new ReadBudget(new ReaderLimits()));
    }

    public function testAnUnboundedPrefixDoesNotGuessAnEiEndpoint(): void
    {
        $this->expectExceptionMessage('EndOfBlock or a positive Rows');
        (new CcittFaxDecoder(parameters: new CcittFaxParameters(endOfBlock: false)))
            ->decodePrefix(new ByteCursor("\0 EI"), new ReadBudget(new ReaderLimits()));
    }

    public function testPrefixPaddingAndBitWorkRemainStrict(): void
    {
        $decoder = new CcittFaxDecoder(parameters: new CcittFaxParameters(k: -1, columns: 8, rows: 1, endOfBlock: false));
        $this->expectExceptionMessage('zero padding');
        $decoder->decodePrefix(new ByteCursor("\xff EI"), new ReadBudget(new ReaderLimits()));
    }

    public function testPrefixLookaheadConsumesWorkWithoutAdvancingTheCaller(): void
    {
        $decoder = new CcittFaxDecoder(parameters: new CcittFaxParameters(k: -1, columns: 8), workLimits: new CcittFaxLimits(maxBitReads: 1));
        $cursor = new ByteCursor($this->bits('1' . '000000000001000000000001') . ' EI');
        try {
            $decoder->decodePrefix($cursor, new ReadBudget(new ReaderLimits()));
            self::fail('The bounded lookahead did not reject.');
        } catch (ParseException $error) {
            self::assertStringContainsString('bit-work budget', $error->getMessage());
            self::assertSame(0, $cursor->position());
        }
    }

    /** @param array<string, int|bool> $values */
    private function parameters(array $values): CcittFaxParameters
    {
        $named = [];
        foreach ($values as $key => $value) {
            $named[lcfirst($key)] = $value;
        }
        return new CcittFaxParameters(...$named);
    }

    private function bits(string $bits): string
    {
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0');
        $output = '';
        for ($offset = 0; $offset < strlen($bits); $offset += 8) {
            $output .= chr(bindec(substr($bits, $offset, 8)));
        }
        return $output;
    }
}
