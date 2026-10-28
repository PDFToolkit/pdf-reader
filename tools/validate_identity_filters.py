"""Compare clear PDF Crypt/Identity streams with qpdf, independently produced by pypdf."""
import argparse
import base64
import io
import json
from pathlib import Path
import subprocess
import tempfile
import zlib

from pypdf import PdfWriter
from pypdf.generic import ArrayObject, DictionaryObject, EncodedStreamObject, NameObject, NullObject

ROOT = Path(__file__).resolve().parents[1]


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return result.stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    original = bytes(range(256)) * 5
    with tempfile.TemporaryDirectory(prefix='reader-identity-') as temporary:
        directory = (args.work_dir or Path(temporary)).absolute()
        directory.mkdir(parents=True, exist_ok=True)
        cases = []
        for kind in ('default', 'null', 'explicit', 'chain'):
            writer = PdfWriter()
            writer.add_blank_page(100, 100)
            stream = EncodedStreamObject()
            stream._data = zlib.compress(original) if kind == 'chain' else original
            stream[NameObject('/Filter')] = NameObject('/Crypt')
            parameters = DictionaryObject({NameObject('/Type'): NameObject('/CryptFilterDecodeParms'),
                NameObject('/Name'): NameObject('/Identity')})
            if kind == 'null':
                stream[NameObject('/DecodeParms')] = NullObject()
            elif kind == 'explicit':
                stream[NameObject('/DecodeParms')] = parameters
            elif kind == 'chain':
                stream[NameObject('/Filter')] = ArrayObject([NameObject('/Crypt'), NameObject('/FlateDecode')])
                stream[NameObject('/DecodeParms')] = ArrayObject([parameters, NullObject()])
            reference = writer._add_object(stream)
            writer.root_object[NameObject('/Payload')] = reference
            output = io.BytesIO()
            writer.write(output)
            path = directory / f'identity-{kind}.pdf'
            path.write_bytes(output.getvalue())
            run([args.qpdf, '--suppress-recovery', '--check', path])
            expected = run([args.qpdf, f'--show-object={reference.idnum}', '--filtered-stream-data', path])
            assert expected == original
            actual = json.loads(run([args.php, ROOT / 'tools/report-stream.php', path]))
            assert base64.b64decode(actual['decoded']) == expected
            assert base64.b64decode(actual['encoded']) == stream._data
            assert not actual['authenticated']
            cases.append({'file': path.name, 'bytes': len(expected)})
        print(json.dumps({'cases': cases, 'producer': 'pypdf', 'independentDecoder': 'qpdf',
            'authenticated': False, 'source': 'ISO 32000-2 Tables 14 and 26'}, indent=2))


if __name__ == '__main__':
    main()
