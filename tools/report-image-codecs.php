<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ImageCodecs\AutomaticDecoder;
use PDF\Reading\ImageCodecs\CodecRequest;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\ImageCodecs\GhostscriptDecoder;
use PDF\Reading\ImageCodecs\PhpDecoder;
use PDF\Reading\ImageCodecs\NativeTools;
use PDF\Reading\ImageCodecs\NativeProcess;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;

final readonly class ImageCodecReport
{
    public function run(array $arguments): array
    {
        if (($arguments[1] ?? '') === 'environment') {
            $report = ['php' => PHP_VERSION, 'phpBinary' => PHP_BINARY, 'phpCodecsAvailable' => class_exists(\PDFToolkit\Codecs\PhpDecoder::class)];
            try {
                $tools = NativeTools::discover();
                $request = new CodecRequest('DCTDecode', '', 1024, 1024, 8);
                $revision = trim((new NativeProcess($tools))->run('revision == quit', '', $request, 1024));
                if (!ctype_digit($revision)) {
                    throw new RuntimeException('Native version probe returned an invalid revision.');
                }
                $number = (int) $revision;
                $report['native'] = ['available' => true, 'ghostscript' => $tools->ghostscript, 'prlimit' => $tools->prlimit,
                    'revision' => $number, 'version' => intdiv($number, 1000) . '.' . sprintf('%02d', intdiv($number % 1000, 10)) . '.' . ($number % 10)];
            } catch (Throwable $error) {
                $report['native'] = ['available' => false, 'error' => $error::class, 'message' => $error->getMessage()];
            }
            return $report;
        }
        $backend = match ($arguments[1] ?? 'auto') {
            'auto' => new AutomaticDecoder(),
            'php' => new PhpDecoder(),
            'native' => new GhostscriptDecoder(),
            default => throw new InvalidArgumentException('Expected auto, php or native backend.'),
        };
        if (isset($arguments[2])) {
            $document = (new DocumentReader(imageDecoder: $backend,
                authentication: new StandardPasswordAuthentication(new PreparedPassword($arguments[3] ?? 'user'))))
                ->read(new FileBytes(file_get_contents($arguments[2])));
            $stream = $document->resolve($document->catalog()->get('TestImage'));
            if (!$stream instanceof EncodedStream) {
                throw new RuntimeException('Fixture TestImage must resolve to a stream.');
            }
            return $this->image($document->imageData($stream));
        }
        $directory = dirname(__DIR__) . '/tests/ImageCodecs/Fixtures/';
        $cases = json_decode(file_get_contents($directory . 'manifest.json'), true, flags: JSON_THROW_ON_ERROR)['cases'];
        foreach (['rgb.jpg', 'cmyk-progressive.jpg'] as $file) {
            $cases[] = ['file' => $file, 'filter' => 'DCTDecode'];
        }
        $report = [];
        foreach ($cases as $case) {
            try {
                $request = new CodecRequest($case['filter'], file_get_contents($directory . $case['file']), 67108864, 67108864, 64,
                    globals: isset($case['globals']) ? file_get_contents($directory . $case['globals']) : null);
                $report[$case['file']] = $this->image($backend->decode($request));
            } catch (Throwable $error) {
                $report[$case['file']] = ['error' => $error::class, 'message' => $error->getMessage()];
            }
        }
        return $report;
    }

    private function image(DecodedImage $image): array
    {
        return ['width' => $image->width, 'height' => $image->height, 'x0' => $image->originX, 'y0' => $image->originY, 'backend' => $image->backend,
            'components' => array_map(static fn ($p) => ['width' => $p->width, 'height' => $p->height, 'precision' => $p->precision, 'signed' => $p->signed,
                'dx' => $p->separationX, 'dy' => $p->separationY, 'x0' => $p->originX, 'y0' => $p->originY, 'alpha' => $p->alpha, 'bytes' => base64_encode($p->bytes)], $image->components),
            'color' => ['space' => $image->color->space, 'enumeratedSpace' => $image->color->enumeratedSpace,
                'iccProfile' => $image->color->iccProfile === null ? null : base64_encode($image->color->iccProfile), 'parameters' => $image->color->parameters,
                'selectedSpecification' => $image->color->selectedSpecification,
                'specifications' => array_map(static fn ($s) => ['method' => $s->method, 'precedence' => $s->precedence, 'approximation' => $s->approximation,
                    'methodData' => base64_encode($s->methodData), 'enumeratedSpace' => $s->enumeratedSpace,
                    'iccProfile' => $s->iccProfile === null ? null : base64_encode($s->iccProfile), 'parameters' => $s->parameters], $image->color->specifications)],
            'chromaKey' => $image->chromaKey,
            'channelDefinitions' => array_map(static fn ($d) => get_object_vars($d), $image->channelDefinitions)];
    }
}

echo json_encode((new ImageCodecReport())->run($argv), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
