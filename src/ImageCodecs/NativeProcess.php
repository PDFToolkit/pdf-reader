<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** Fixed PostScript filter program, binary stdin, bounded pipes and an empty GS environment. */
final readonly class NativeProcess
{
    public function __construct(private NativeTools $tools)
    {
    }

    public function run(string $program, string $input, CodecRequest $request, int $maximumOutput): string
    {
        if (!function_exists('proc_open')) {
            throw new CodecUnavailable('Native image decoding requires proc_open.');
        }
        $limits = $request->limits;
        $arguments = [$this->tools->prlimit, '--as=' . $limits->nativeMemoryBytes, '--cpu=' . $limits->nativeCpuSeconds,
            '--fsize=0', '--nofile=64', '--', $this->tools->ghostscript, '-q', '-dNODISPLAY', '-dBATCH', '-dSAFER', '-c', $program];
        $process = @proc_open(
            $arguments,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            '/',
            ['PATH' => '/usr/bin:/bin', 'HOME' => '/nonexistent', 'LANG' => 'C', 'LC_ALL' => 'C'],
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new CodecUnavailable('The bounded native image process could not be started.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $start = hrtime(true);
        $position = 0;
        $output = $diagnostics = '';
        $exit = null;
        try {
            while (true) {
                if (hrtime(true) - $start > $limits->nativeWallMilliseconds * 1000000) {
                    throw new CodecLimit('The native image decoder exceeded its elapsed-time limit.');
                }
                $status = proc_get_status($process);
                $read = [];
                foreach ([1, 2] as $index) {
                    if (isset($pipes[$index]) && !feof($pipes[$index])) {
                        $read[] = $pipes[$index];
                    }
                }
                $write = isset($pipes[0]) ? [$pipes[0]] : [];
                $except = [];
                if ($read !== [] || $write !== []) {
                    if (@stream_select($read, $write, $except, 0, 20000) === false) {
                        throw new ParseException('Native image process pipe selection failed.');
                    }
                }
                if ($write !== []) {
                    $written = @fwrite($pipes[0], substr($input, $position, 8192));
                    if ($written === false) {
                        // Drain diagnostics before classifying an early exit:
                        // a missing compiled filter closes stdin immediately.
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    } else {
                        $position += $written;
                        if ($position === strlen($input)) {
                            fclose($pipes[0]);
                            unset($pipes[0]);
                        }
                    }
                }
                foreach ($read as $pipe) {
                    $slice = fread($pipe, 8192);
                    if ($slice === false) {
                        throw new ParseException('Native image decoder output could not be read.');
                    }
                    if ($pipe === $pipes[1]) {
                        if (strlen($slice) > $maximumOutput - strlen($output)) {
                            throw new CodecLimit('Native image output exceeds its byte limit.');
                        }
                        $output .= $slice;
                    } else {
                        if (strlen($slice) > $limits->maxDiagnosticsBytes - strlen($diagnostics)) {
                            throw new CodecLimit('Native image diagnostics exceed their byte limit.');
                        }
                        $diagnostics .= $slice;
                    }
                }
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    if ($status['signaled']) {
                        throw new CodecLimit('The native image decoder was terminated by a resource limit or signal.');
                    }
                    if (feof($pipes[1]) && feof($pipes[2])) {
                        break;
                    }
                }
            }
            if ($diagnostics === 'PDFTOOLKIT_CODEC_UNAVAILABLE') {
                throw new CodecUnavailable('The installed Ghostscript lacks the required image filter.');
            }
            if ($request->filter === 'JPXDecode') {
                // These fixed OpenJPEG notices describe unchanged raw components.
                // Any additional warning or repair remains a decoding failure.
                $diagnostics = str_replace([
                    "openjpeg warning: unspec CS. 1 component so assuming gray.\n",
                    "openjpeg warning: unspec CS. 3 components. Assuming data RGB.\n",
                    "openjpeg warning: unspec CS. 4 components so assuming CMYK.\n",
                ], '', $diagnostics);
            }
            if ($exit !== 0 || $diagnostics !== '') {
                $lower = strtolower($diagnostics);
                if (str_contains($diagnostics, 'VMerror') || str_contains($output, '/VMerror')
                    || str_contains($lower, 'out of memory') || str_contains($lower, 'cannot allocate memory')
                    || str_contains($lower, 'failed to map segment')) {
                    throw new CodecLimit('The native image decoder exhausted its memory limit.');
                }
                if (in_array($exit, [126, 127], true)) {
                    throw new CodecUnavailable('The configured native image executable or its runtime libraries are unavailable.');
                }
                throw new ParseException('The native image decoder rejected the image or reported damaged data.');
            }
            if ($position !== strlen($input)) {
                throw new ParseException('The native image decoder did not consume the supplied input.');
            }
            return $output;
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
