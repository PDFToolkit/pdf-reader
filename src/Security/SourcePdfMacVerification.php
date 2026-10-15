<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Document\Signatures\SignatureValue;
use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Security\FileEncryptionKey;
use PDF\Security\PdfMacSignatureAttachment;
use PDF\Security\PdfMacTokenVerifier;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Native TS 32004 source authentication, before decryption; no PKI or network operations. */
final readonly class SourcePdfMacVerification
{
    public function __construct(private int $maximumTokenBytes = 1048576)
    {
        if ($maximumTokenBytes < 1) {
            throw new \InvalidArgumentException('The source PDF MAC token limit must be positive.');
        }
    }

    public function verify(ReadDocument $raw, DictionaryType $encryption, FileEncryptionKey $key, int $permissions): ?PdfMacLocation
    {
        $salt = (new SecurityDictionaryValues($raw, $encryption))->directValue('KDFSalt');
        $inherited = (new SecurityDictionaryValues($raw, $raw->trailer()))->directValue('AuthCode');
        if ($salt === null && $inherited === null && ($permissions & 4096) !== 0) {
            return null;
        }
        $revision = $raw->sourceRevision();
        $authCode = $revision->trailer->get('AuthCode');
        if (!$salt instanceof StringType || strlen($salt->value) !== 32 || !$authCode instanceof DictionaryType) {
            throw new ParseException('PDF MAC requires a direct 32-byte KDFSalt and a direct AuthCode in the latest physical trailer.');
        }
        $location = $authCode->get('MACLocation');
        if (!$location instanceof NameType) {
            throw new ParseException('A PDF MAC location must be a direct name.');
        }
        $selected = PdfMacLocation::tryFrom($location->value)
            ?? throw new UnsupportedPdfFeature('The source PDF MAC location is unsupported.');
        $verifier = new PdfMacTokenVerifier($key, $salt->value, $this->maximumTokenBytes);
        $coverage = new PdfMacCoverage($revision);
        try {
            if ($selected === PdfMacLocation::Standalone) {
                if ((new SecurityDictionaryValues($raw, $authCode))->directValue('SigObjRef') !== null) {
                    throw new ParseException('A standalone PDF MAC cannot contain SigObjRef.');
                }
                $token = $authCode->get('MAC');
                if (!$token instanceof HexadecimalString || strlen($token->value) > $this->maximumTokenBytes) {
                    throw new ParseException('A standalone PDF MAC requires direct hexadecimal bytes within the configured CMS limit.');
                }
                $covered = $coverage->coveredBytes($authCode, 'MAC', true);
                $verifier->verify($token->value, $covered);
            } else {
                $fields = new SecurityDictionaryValues($raw, $authCode);
                if ($fields->directValue('ByteRange') !== null || $fields->directValue('MAC') !== null) {
                    throw new ParseException('A signature-attached PDF MAC cannot contain its own ByteRange or MAC.');
                }
                $reference = $authCode->get('SigObjRef');
                $signature = $reference instanceof ObjectReference ? $raw->resolve($reference) : null;
                if (!$signature instanceof DictionaryType) {
                    throw new ParseException('A signature-attached PDF MAC requires an indirect signature dictionary.');
                }
                $type = (new SecurityDictionaryValues($raw, $signature))->directValue('Type');
                if ($type !== null && (!$type instanceof NameType || !in_array($type->value, ['Sig', 'DocTimeStamp'], true))) {
                    throw new ParseException('SigObjRef must identify a signature or document timestamp.');
                }
                $contents = $signature->get('Contents');
                if (!$contents instanceof HexadecimalString) {
                    throw new ParseException('The selected source signature requires direct hexadecimal Contents.');
                }
                $cms = new SignatureValue($this->unpadded($contents->value));
                $covered = $coverage->coveredBytes($signature, 'Contents', false);
                (new PdfMacSignatureAttachment())->verify($cms, $covered, $verifier);
            }
        } catch (\InvalidArgumentException|\UnexpectedValueException|\OverflowException|\LengthException $error) {
            throw new ParseException('The source PDF MAC is malformed, unsupported or fails exact file authentication.');
        }
        return $selected;
    }

    /** Consume one bounded definite-length CMS object, permitting only PDF signature zero padding. */
    private function unpadded(string $bytes): string
    {
        $size = strlen($bytes);
        if ($size < 2 || $size > $this->maximumTokenBytes || $bytes[0] !== "\x30") {
            throw new ParseException('The source signature CMS envelope is missing or exceeds its configured byte limit.');
        }
        $position = 2;
        $length = ord($bytes[1]);
        if (($length & 128) !== 0) {
            $count = $length & 127;
            if ($count === 0 || $count > PHP_INT_SIZE || $count > $size - $position || $bytes[$position] === "\0") {
                throw new ParseException('The source signature CMS length must be definite and minimally encoded.');
            }
            $length = 0;
            for ($index = 0; $index < $count; ++$index) {
                $byte = ord($bytes[$position++]);
                if ($length > intdiv(PHP_INT_MAX - $byte, 256)) {
                    throw new ParseException('The source signature CMS length exceeds native bounds.');
                }
                $length = $length * 256 + $byte;
            }
            if ($length < 128) {
                throw new ParseException('The source signature CMS length is not minimally encoded.');
            }
        }
        if ($length > $size - $position || trim(substr($bytes, $position + $length), "\0") !== '') {
            throw new ParseException('The source signature Contents has truncated CMS data or nonzero trailing bytes.');
        }
        return substr($bytes, 0, $position + $length);
    }
}
