<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Optional bounded native filter adapter. No PDF interpreter or rendering device is used. */
final readonly class GhostscriptDecoder implements Decoder
{
    public function __construct(private ?NativeTools $tools = null)
    {
    }

    public function decode(CodecRequest $request): DecodedImage
    {
        $tools = $this->tools ?? NativeTools::discover();
        $description = match ($request->filter) {
            'DCTDecode' => (new JpegHeader())->read($request),
            'JPXDecode' => (new JpxHeader())->read($request),
            'JBIG2Decode' => (new Jbig2Header())->read($request),
            default => throw new CodecUnavailable('Unknown native image codec.'),
        };
        $parameters = $description->colorTransform === null ? '' : '/ColorTransform ' . $description->colorTransform;
        $input = $request->bytes;
        $prefix = '/' . $request->filter . ' /Filter resourcestatus { pop pop } '
            . '{ (%stderr) (w) file (PDFTOOLKIT_CODEC_UNAVAILABLE) writestring quit } ifelse '
            . '(%stdin) (r) file /f exch def ';
        if ($request->filter === 'JBIG2Decode' && $request->globals !== null && $request->globals !== '') {
            // The public GS filter takes an embedded segment stream, not PDF's
            // JBIG2Globals parameter. Validated page-zero segments precede the
            // page segments in that same stream; their numbers remain intact.
            $input = $request->globals . $input;
        }
        $program = $prefix . 'f << ' . $parameters . ' >> /' . $request->filter . ' filter /s exch def '
            . '(%stdout) (w) file /o exch def /b 8192 string def '
            . '{ s b readstring { o exch writestring } { o exch writestring exit } ifelse } loop o flushfile quit';
        $bytes = (new NativeProcess($tools))->run($program, $input, $request, $request->maximumOutputBytes);
        return $description->planes($bytes);
    }
}
