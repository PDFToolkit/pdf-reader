"""Compare native reader output with qpdf for real filtered PDF streams."""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-reader-filters-') as temporary:
        fixture = subprocess.run([args.php, str(root / 'tools/filter_cases.php'), temporary], capture_output=True)
        assert fixture.returncode == 0, fixture.stdout.decode(errors='replace') + fixture.stderr.decode(errors='replace')
        cases = json.loads((Path(temporary) / 'manifest.json').read_text())
        for case in cases:
            path = Path(temporary) / case['file']
            checked = subprocess.run([args.qpdf, '--suppress-recovery', '--check', str(path)], capture_output=True)
            assert checked.returncode == 0, case['file'] + ': ' + checked.stdout.decode(errors='replace') + checked.stderr.decode(errors='replace')
            decoded = subprocess.check_output([args.qpdf, f'--show-object={case["object"]}', '--filtered-stream-data', str(path)])
            assert len(decoded) == case['decodedBytes'], case['file']
            assert hashlib.sha256(decoded).hexdigest() == case['decodedSha256'], case['file']
        print(f'{len(cases)} independent qpdf LZW/EarlyChange/reset and 1/2/4/8/16-bit TIFF/PNG stream comparisons passed.')


if __name__ == '__main__':
    main()
