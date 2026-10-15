<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslRecipientDecrypter;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!isset($argv) || count($argv) !== 4) {
    fwrite(STDERR, "Usage: php examples/certificate.php document.pdf certificate.der private-key.pem\n");
    exit(1);
}
$load = static function (string $path): string {
    $bytes = file_get_contents($path);
    return $bytes === false ? throw new RuntimeException('Cannot read the application-selected input.') : $bytes;
};
// This CLI application selects every input. Nothing is loaded from a path in the PDF.
$identity = new OpenSslIdentity(
    new CertificateChain(new X509Certificate(new HexadecimalString($load($argv[2])))),
    $load($argv[3]),
);
$authentication = new PublicKeyAuthentication(
    new OpenSslRecipientDecrypter($identity),
    PublicKeyPermissionByteOrder::MostSignificantFirst,
);
$document = (new DocumentReader(authentication: $authentication))->read(new FileBytes($load($argv[1])));
echo json_encode([
    'pages' => count((new PageTreeReader($document))->read()),
    'effectivePermissions' => $document->permissions()?->effectiveMask(),
    'mac' => $document->mac()?->value,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
