<?php

declare(strict_types=1);

use PDF\Reading\ByteCursor;
use PDF\Reading\SyntaxParser;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringType;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
if (!is_file($autoload)) {
    throw new RuntimeException('Install the Composer dependencies before running this example.');
}
require $autoload;

// PDF literal strings contain byte escapes; no text encoding is guessed.
$value = (new SyntaxParser(new ByteCursor('<< /Label (A\053B) >>')))->read();
if (!$value instanceof DictionaryType || !$value->get('Label') instanceof StringType) {
    throw new RuntimeException('The parsed value does not contain the expected byte string.');
}
printf("Parsed label bytes: %s\n", bin2hex($value->get('Label')->value));
