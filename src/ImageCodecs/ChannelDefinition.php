<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Exact JP2 channel/type/association and the corresponding output plane. */
final readonly class ChannelDefinition
{
    public function __construct(public int $channel, public int $type, public int $association, public int $outputChannel)
    {
    }
}
