<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeReader;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Reading\Security\PublicKeyReadPermissions;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslRecipientDecrypter;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

require dirname(__DIR__) . '/vendor/autoload.php';
$directory = $argv[1] ?? throw new InvalidArgumentException('A fixture directory is required.');
$identityNames = explode(',', $argv[2] ?? 'recipient,attachment');
$decrypters = array_map(static function (string $name) use ($directory): OpenSslRecipientDecrypter {
    $certificate = file_get_contents($directory . '/' . $name . '.der');
    $privateKey = file_get_contents($directory . '/' . $name . '-key.pem');
    if ($certificate === false || $privateKey === false) {
        throw new RuntimeException('Cannot read synthetic fixture credentials.');
    }
    return new OpenSslRecipientDecrypter(new OpenSslIdentity(
        new CertificateChain(new X509Certificate(new HexadecimalString($certificate))), $privateKey,
    ));
}, $identityNames);
$report = [];
foreach (array_slice($argv, 3) as $filename) {
    try {
        $bytes = file_get_contents($directory . '/' . $filename);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read source fixture.');
        }
        $reader = new DocumentReader(authentication: new PublicKeyAuthentication($decrypters, PublicKeyPermissionByteOrder::MostSignificantFirst));
        $document = $reader->read(new FileBytes($bytes));
        $pages = (new PageTreeReader($document))->read();
        $content = '';
        foreach ($pages as $page) {
            foreach ((new PageContentsReader($document))->read($page) as $stream) {
                $content .= $document->streamData($stream);
            }
        }
        $catalog = $document->catalog();
        $resolve = static function (string $name) use ($catalog, $document): mixed {
            $value = $catalog->get($name);
            return $value === null ? null : $document->resolve($value);
        };
        $binary = $resolve('FixtureBinary');
        $literal = $resolve('FixtureLiteral');
        $compressed = $resolve('FixtureCompressed');
        $compressed = $compressed instanceof DictionaryType ? $compressed->get('Value') : null;
        $incremental = $resolve('FixtureIncremental');
        $attachment = $resolve('FixtureAttachment');
        $clear = $resolve('FixtureClear');
        $metadata = $resolve('Metadata');
        $permissions = $document->permissions();
        if (!$permissions instanceof PublicKeyReadPermissions) {
            throw new RuntimeException('Public-key authentication did not produce public-key permissions.');
        }
        $filters = [];
        foreach ($permissions->filters as $name => $access) {
            $filters[$name] = ['permissions' => $access->permissions?->unsigned,
                'owner' => $access->permissions?->ownerAccess(), 'recipientIndex' => $access->recipientIndex];
        }
        $report[] = ['file' => $filename, 'ok' => true, 'mac' => $document->mac()?->value,
            'pages' => count($pages), 'permissions' => $permissions->effectiveMask(), 'filters' => $filters,
            'binary' => $binary instanceof StringType ? bin2hex($binary->value) : null,
            'literal' => $literal instanceof StringType ? $literal->value : null, 'contents' => $content,
            'compressed' => $compressed instanceof StringType ? $compressed->value : null,
            'incremental' => $incremental instanceof StringType ? $incremental->value : null,
            'attachment' => $attachment instanceof EncodedStream ? bin2hex($document->streamData($attachment)) : null,
            'clear' => $clear instanceof EncodedStream ? $document->streamData($clear) : null,
            'metadataSha256' => $metadata instanceof EncodedStream ? hash('sha256', $document->streamData($metadata)) : null];
    } catch (Throwable $error) {
        $report[] = ['file' => $filename, 'ok' => false, 'error' => $error::class, 'message' => $error->getMessage()];
    }
}
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
