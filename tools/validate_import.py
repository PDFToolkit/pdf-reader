#!/usr/bin/env python3
"""Compare explicit native imports against independent pypdf/qpdf and certificate producers."""
from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
import zlib

from pypdf import PdfReader
from pyhanko.pdf_utils.crypt.pubkey import SimpleEnvelopeKeyDecrypter
from pyhanko.pdf_utils.reader import PdfFileReader
from generate_public_key_fixtures import CONTENT, identity, ordinary_writer

ROOT = Path(__file__).resolve().parents[1]


def run(command):
    result = subprocess.run(list(map(str, command)), cwd=ROOT, capture_output=True)
    if result.returncode:
        raise AssertionError(result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace'))
    return result.stdout


def stream(data, entries=b''):
    return b'<< /Length ' + str(len(data)).encode() + b' ' + entries + b' >>\nstream\n' + data + b'\nendstream'


def physical(objects):
    data = bytearray(b'%PDF-1.7\n')
    offsets = {}
    for number, value in sorted(objects.items()):
        offsets[number] = len(data)
        data.extend(f'{number} 0 obj\n'.encode() + value + b'\nendobj\n')
    xref = len(data)
    data.extend(f'xref\n0 {max(objects) + 1}\n0000000000 65535 f \n'.encode())
    for number in range(1, max(objects) + 1):
        data.extend(f'{offsets[number]:010d} 00000 n \n'.encode())
    data.extend(f'trailer << /Size {max(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode())
    return bytes(data)


def validate(args, directory):
    records = []
    content = b'q 1 0 0 1 2 3 cm Q\n'
    encoded = zlib.compress(content).hex().encode() + b'>'
    source = directory / 'cycles-source.pdf'
    source.write_bytes(physical({
        1: b'<< /Type /Catalog /Pages 2 0 R /First 4 0 R /Alias 4 0 R /Cycle 5 0 R /Metadata 6 0 R >>',
        2: b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3: b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources <<>> /Contents 4 0 R >>',
        4: stream(encoded, b'/Filter [/ASCIIHexDecode /FlateDecode] /DecodeParms [null << /Predictor 1 >>] /Self 4 0 R'),
        5: b'[5 0 R 1 0 R]',
        6: stream(zlib.compress(b'<x>independent UTF-8</x>'), b'/Type /Metadata /Subtype /XML /Filter /FlateDecode'),
    }))
    compressed = directory / 'compressed-source.pdf'
    run([args.qpdf, '--object-streams=generate', source, compressed])
    inputs = [(path, 'root', 'none', content, PdfReader(path).pages[0]['/Contents']._data, [], True) for path in [source, compressed]]
    for file, security in [('qpdf-r2-40-metadata-encrypted.pdf', 'legacy'), ('qpdf-r4-128-metadata-encrypted.pdf', 'legacy'), ('qpdf-r5-metadata-encrypted.pdf', 'modern')]:
        path = ROOT / 'tests/Fixtures/encryption' / file
        independent = PdfReader(path, password='owner')
        inputs.append((path, 'pages', security, independent.pages[0].get_contents().get_data(), independent.pages[0]['/Contents']._data, [], False))
    certs = {name: identity(directory, name) for name in ['unmatched', 'recipient']}
    writer, _ = ordinary_writer('s5-aes256', certs)
    certificate_source = directory / 'certificate-source.pdf'
    with certificate_source.open('wb') as output:
        writer.write(output)
    independent = PdfFileReader(io.BytesIO(certificate_source.read_bytes()))
    independent.decrypt_pubkey(SimpleEnvelopeKeyDecrypter.load(str(directory / 'recipient-key.pem'), str(directory / 'recipient.der')))
    certificate_content = independent.root['/Pages']['/Kids'][0]['/Contents']
    assert certificate_content.data == CONTENT
    certificate_encoded = certificate_content.encoded_data
    inputs.append((certificate_source, 'root', 'certificate', CONTENT, certificate_encoded, [directory / 'recipient.der', directory / 'recipient-key.pem'], False))
    for ordinal, (path, selection, security, expected, source_encoded, credentials, cyclic) in enumerate(inputs):
        for mode in ['preserve', 'decode']:
            output = directory / f'import-{ordinal}-{mode}.pdf'
            command = [args.php]
            if args.bootstrap:
                command += ['-d', 'auto_prepend_file=' + str(args.bootstrap.resolve())]
            command += [ROOT / 'tools/import_graph_case.php', path, output, mode, selection, security, *credentials]
            record = json.loads(run(command))
            run([args.qpdf, '--suppress-recovery', '--check', output])
            reader = PdfReader(output, strict=True)
            assert not reader.is_encrypted
            assert '/Encrypt' not in reader.trailer and '/AuthCode' not in reader.trailer
            assert reader.pages[0].get_contents().get_data() == expected
            native_stream = reader.pages[0]['/Contents']
            expected_encoded = expected if mode == 'decode' else source_encoded
            assert native_stream._data == expected_encoded
            assert record['sourceUnchanged'] and record['sourceSha256'] == hashlib.sha256(path.read_bytes()).hexdigest()
            if security != 'none':
                assert record['sourceStreams'][0]['encodedSha256'] != record['sourceStreams'][0]['originalSha256']
            if cyclic:
                root = reader.trailer['/Root']
                assert root.raw_get('/First') == root.raw_get('/Alias')
                assert root['/First'].raw_get('/Self') == root.raw_get('/First')
                cycle = root['/Cycle']
                assert cycle[0] == root.raw_get('/Cycle') and cycle[1] == reader.trailer.raw_get('/Root')
                assert root['/Metadata'].get_data() == b'<x>independent UTF-8</x>'
                assert '/Filter' not in root['/Metadata']
            record.update(case=ordinal, mode=mode, authentication=security)
            records.append(record)
    return {'passed': True, 'pdfs': len(records), 'cases': records}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--bootstrap', type=Path, help='Development-only core override.')
    parser.add_argument('--work-dir', type=Path)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    if args.work_dir:
        args.work_dir.mkdir(parents=True, exist_ok=True)
        report = validate(args, args.work_dir.resolve())
    else:
        with tempfile.TemporaryDirectory(prefix='reader-import-') as temporary:
            report = validate(args, Path(temporary))
    result = json.dumps(report, indent=2) + '\n'
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(result)
    print(result, end='')


if __name__ == '__main__':
    main()
