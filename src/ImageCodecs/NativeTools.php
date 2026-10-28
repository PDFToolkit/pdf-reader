<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Executables are application configuration, never obtained from image data. */
final readonly class NativeTools
{
    public function __construct(public string $ghostscript, public string $prlimit)
    {
        foreach ([$ghostscript, $prlimit] as $path) {
            if (!str_starts_with($path, '/') || !is_file($path) || !is_executable($path)) {
                throw new CodecUnavailable('A configured native codec executable is unavailable.');
            }
        }
    }

    public static function discover(): self
    {
        return new self(self::find('gs'), self::find('prlimit'));
    }

    private static function find(string $name): string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/bin:/bin') as $directory) {
            if (!str_starts_with($directory, '/')) {
                continue;
            }
            $candidate = $directory . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }
        throw new CodecUnavailable('The bounded native codec needs Ghostscript and prlimit on the application PATH.');
    }
}
