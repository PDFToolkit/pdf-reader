"""Compare native hybrid initial/incremental/linearized reads with pypdf and qpdf."""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

from pypdf import PdfReader


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return result.stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    # Composer locks this generator to an already published stable core tag.
    generator = root / 'vendor/roukmoute/pdf/tools/fixtures/hybrid_references.php'
    with tempfile.TemporaryDirectory(prefix='pdf-reader-hybrid-') as temporary:
        directory = Path(temporary)
        # A dependency has no nested vendor directory; only adapt the bootstrap.
        original = generator.read_text()
        bootstrap = "require dirname(__DIR__, 2) . '/vendor/autoload.php';"
        assert original.count(bootstrap) == 1
        autoload = (root / 'vendor/autoload.php').as_posix().replace("'", "\\'")
        adapted = directory / 'stable-core-fixture.php'
        adapted.write_text(original.replace(bootstrap, "require '" + autoload + "';"))
        run([args.php, adapted, directory])
        cases = json.loads((directory / 'manifest.json').read_text())
        for case in cases:
            path = directory / case['file']
            run([args.qpdf, '--suppress-recovery', '--check', path])
            independent = PdfReader(path, strict=True)
            catalog = independent.trailer['/Root']
            expected = {'pages': [hashlib.sha256(page.get_contents().get_data()).hexdigest() for page in independent.pages],
                'outline': str(catalog['/Outlines']['/First']['/Title']),
                'piece': int(catalog['/PieceInfo']['/Private']) if '/PieceInfo' in catalog else None}
            actual = json.loads(run([args.php, root / 'tools/inspect_hybrid.php', path]))
            assert actual == expected, (case['file'], actual, expected)
        print(f'{len(cases)} hybrid initial/incremental/linearized documents agree with pypdf and qpdf.')


if __name__ == '__main__':
    main()
