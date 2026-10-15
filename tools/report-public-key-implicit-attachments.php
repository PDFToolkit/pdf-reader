<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Reading\Security\PublicKeyAuthenticationFailed;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslRecipientDecrypter;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

require dirname(__DIR__) . '/vendor/autoload.php';
$directory = $argv[1] ?? throw new InvalidArgumentException('A fixture directory is required.');
$identities = [];
foreach (['recipient', 'attachment', 'wrong'] as $name) {
    $certificate = file_get_contents($directory . '/' . $name . '.der');
    $privateKey = file_get_contents($directory . '/' . $name . '-key.pem');
    if ($certificate === false || $privateKey === false) {
        throw new RuntimeException('Cannot read synthetic fixture credentials.');
    }
    $identities[$name] = new OpenSslRecipientDecrypter(new OpenSslIdentity(
        new CertificateChain(new X509Certificate(new HexadecimalString($certificate))),
        $privateKey,
    ));
}
$reports = [];
foreach (array_slice($argv, 2) as $filename) {
    $bytes = file_get_contents($directory . '/' . $filename);
    if ($bytes === false) {
        throw new RuntimeException('Cannot read source fixture.');
    }
    $source = new FileBytes($bytes);
    $reader = new DocumentReader(authentication: new PublicKeyAuthentication(
        [$identities['recipient'], $identities['attachment']],
        PublicKeyPermissionByteOrder::MostSignificantFirst,
    ));
    $document = $reader->read($source);
    $literal = $document->catalog()->get('FixtureLiteral');
    $reference = $document->trailer()->get('FixtureImplicitAttachment');
    $attachment = $reference === null ? null : $document->resolve($reference);
    if (!$literal instanceof StringType || !$attachment instanceof EncodedStream
        || $attachment->dictionary()->get('Filter') !== null) {
        throw new RuntimeException('The implicit attachment fixture has an unexpected structure.');
    }
    $report = ['documentLiteral' => $literal->value, 'attachment' => base64_encode($document->streamData($attachment))];
    $wrongReader = new DocumentReader(authentication: new PublicKeyAuthentication(
        [$identities['recipient'], $identities['wrong']],
        PublicKeyPermissionByteOrder::MostSignificantFirst,
    ));
    $wrongDocument = $wrongReader->read($source);
    $wrongLiteral = $wrongDocument->catalog()->get('FixtureLiteral');
    if (!$wrongLiteral instanceof StringType || $wrongLiteral->value !== $literal->value) {
        throw new RuntimeException('An unavailable attachment credential prevented normal document access.');
    }
    $report['wrongCredentialDocumentOpened'] = true;
    $wrongReference = $wrongDocument->trailer()->get('FixtureImplicitAttachment');
    if ($wrongReference === null) {
        throw new RuntimeException('The implicit attachment reference is missing.');
    }
    try {
        $wrongDocument->resolve($wrongReference);
        throw new RuntimeException('An incorrect attachment credential exposed plaintext.');
    } catch (PublicKeyAuthenticationFailed $error) {
        $report['wrongCredentialAttachmentRefused'] = true;
    }
    $reports[] = $report;
}
echo json_encode($reports, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
