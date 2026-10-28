"""Reproduce qpdf 12.4.2 linearized physical histories without the PDFToolkit writer."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    version = subprocess.check_output([args.qpdf, '--version'], text=True).splitlines()[0]
    assert version == 'qpdf version 12.4.2', version
    source = root / 'tests/Fixtures/pdf/classic.pdf'
    target = root / 'tests/Fixtures/revision-history'
    records = []
    with tempfile.TemporaryDirectory(prefix='linearized-fixtures-') as directory:
        for kind, mode in [('table', 'disable'), ('stream', 'generate')]:
            name = 'linearized-' + kind + '.pdf'
            path = Path(directory) / name
            subprocess.run([args.qpdf, '--static-id', '--linearize', '--object-streams=' + mode,
                str(source), str(path)], check=True, capture_output=True)
            data = path.read_bytes()
            if args.check:
                assert (target / name).read_bytes() == data, name
            else:
                (target / name).write_bytes(data)
            records.append({'file': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(),
                'objectStreams': mode, 'physicalRevisions': 1})
    metadata = {'generator': version, 'source': 'tests/Fixtures/pdf/classic.pdf',
        'sourceSha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'staticId': True, 'cases': records}
    encoded = (json.dumps(metadata, indent=2) + '\n').encode()
    if args.check:
        assert (target / 'linearized-manifest.json').read_bytes() == encoded
    else:
        (target / 'linearized-manifest.json').write_bytes(encoded)
    print('Verified two independent linearized fixtures' if args.check else 'Built two independent linearized fixtures')


if __name__ == '__main__':
    main()
