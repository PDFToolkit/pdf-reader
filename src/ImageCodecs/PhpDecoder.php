<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** Optional Composer adapter; the Reader's public image types have no package dependency. */
final readonly class PhpDecoder implements Decoder
{
    public function decode(CodecRequest $request): DecodedImage
    {
        if (!class_exists(\PDFToolkit\Codecs\PhpDecoder::class)) {
            throw new CodecUnavailable('No suitable native codec is available. Install the PHP fallback with: composer require pdftoolkit/pdf-codecs:^0.1');
        }
        $settings = $request->limits;
        $limits = new \PDFToolkit\Codecs\DecodeLimits(
            maxInputBytes: $request->maximumInputBytes,
            maxOutputBytes: $request->maximumOutputBytes,
            maxPixels: $settings->maxPixels,
            maxDimension: $settings->maxDimension,
            maxComponents: $settings->maxComponents,
            maxWork: $settings->maxWork,
            maxMemoryBytes: $settings->maxMemoryBytes,
            maxDepth: $request->maximumDepth,
            maxSegments: $settings->maxSegments,
            maxMetadataBytes: $settings->maxMetadataBytes,
            maxTiles: $settings->maxTiles,
        );
        try {
            $image = (new \PDFToolkit\Codecs\PhpDecoder($limits))->decode(new \PDFToolkit\Codecs\DecodeRequest(
                \PDFToolkit\Codecs\Codec::from($request->filter),
                $request->bytes,
                $request->colorTransform,
                $request->globals,
            ));
        } catch (\PDFToolkit\Codecs\ResourceLimit) {
            throw new CodecLimit('The PHP image decoder exceeded its configured resource limit.');
        } catch (\PDFToolkit\Codecs\MalformedImage) {
            throw new ParseException('The PHP image decoder rejected malformed image data.');
        } catch (\PDFToolkit\Codecs\UnsupportedFeature) {
            throw new CodecUnavailable('The installed PHP codec does not support this image profile without loss.');
        }
        $components = [];
        foreach ($image->components as $plane) {
            $components[] = new ComponentPlane(
                $plane->width,
                $plane->height,
                $plane->precision,
                $plane->signed,
                $plane->bytes,
                $plane->separationX,
                $plane->separationY,
                $plane->originX,
                $plane->originY,
                $plane->alpha->value
            );
        }
        return new DecodedImage(
            $image->width,
            $image->height,
            $components,
            new ColorInformation(
                $image->color->space,
                $image->color->enumeratedSpace,
                $image->color->iccProfile,
                $image->color->parameters,
                array_map(static fn ($specification) => new ColorSpecification($specification->method, $specification->precedence, $specification->approximation, $specification->methodData, $specification->enumeratedSpace, $specification->iccProfile, $specification->parameters), $image->color->specifications),
                $image->color->selectedSpecification
            ),
            $image->originX,
            $image->originY,
            'php',
            array_map(static fn ($definition) => new ChannelDefinition($definition->channel, $definition->type, $definition->association, $definition->outputChannel), $image->channelDefinitions),
            $image->chromaKey
        );
    }
}
