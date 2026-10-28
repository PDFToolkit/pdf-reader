"""Produce external-file PDF streams with pyHanko; compare their actual data using pypdf codecs."""
from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import re
from pathlib import Path
import subprocess
import tempfile
import zlib

from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.writer import PdfFileWriter, PageObject
from pypdf import PdfReader
from pypdf.generic import EncodedStreamObject, NameObject

ROOT = Path(__file__).resolve().parents[1]


def check(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return result.stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    original = bytes(range(256)) * 7 + b'External bytes are not PDF stream ciphertext.\n'
    with tempfile.TemporaryDirectory(prefix='reader-external-') as temporary:
        directory = (args.work_dir or Path(temporary)).absolute()
        directory.mkdir(parents=True, exist_ok=True)
        reports = []
        for kind in ('string', 'dictionary', 'indirect', 'filter-chain'):
            for encrypted in (False, True):
                writer = PdfFileWriter(stream_xrefs=True)
                writer.insert_page(PageObject(writer.add_object(generic.StreamObject(stream_data=b'q Q\n')), (0, 0, 200, 200)))
                spec = generic.TextStringObject('payload.bin')
                if kind != 'string':
                    spec = generic.DictionaryObject({generic.pdf_name('/Type'): generic.pdf_name('/Filespec'),
                        generic.pdf_name('/F'): writer.add_object(spec) if kind == 'indirect' else spec})
                if kind == 'indirect':
                    spec = writer.add_object(spec)
                encoded = zlib.compress(original)
                filters = generic.pdf_name('/FlateDecode')
                parameters = generic.DictionaryObject({generic.pdf_name('/Predictor'): generic.NumberObject(1)})
                if kind == 'filter-chain':
                    encoded = base64.a85encode(encoded) + b'~>'
                    filters = generic.ArrayObject([generic.pdf_name('/ASCII85Decode'), filters])
                    parameters = generic.ArrayObject([generic.NullObject(), parameters])
                if kind == 'indirect':
                    filters = writer.add_object(filters)
                    parameters = writer.add_object(parameters)
                external = generic.StreamObject({generic.pdf_name('/F'): spec,
                    generic.pdf_name('/FFilter'): filters, generic.pdf_name('/FDecodeParms'): parameters},
                    stream_data=b'ignored local bytes')
                writer.root[generic.pdf_name('/ExternalPayload')] = writer.add_object(external)
                if encrypted:
                    writer.encrypt('owner', 'user', pdf_mac=False)
                output = io.BytesIO()
                writer.write(output)
                stem = f'{kind}-encrypted{int(encrypted)}'
                path = directory / f'{stem}.pdf'
                payload = directory / f'{stem}.bin'
                path.write_bytes(output.getvalue())
                payload.write_bytes(encoded)
                independent = PdfReader(path)
                if encrypted:
                    assert independent.decrypt('user')
                actual_stream = independent.trailer['/Root']['/ExternalPayload']
                actual_spec = actual_stream['/F']
                assert (actual_spec if isinstance(actual_spec, str) else actual_spec['/F']) == 'payload.bin'
                # Acquire the explicitly chosen file ourselves; pypdf only decodes its real FFilter chain.
                proxy = EncodedStreamObject()
                proxy._data = payload.read_bytes()
                proxy[NameObject('/Filter')] = actual_stream['/FFilter']
                proxy[NameObject('/DecodeParms')] = actual_stream['/FDecodeParms']
                expected = proxy.get_data()
                assert expected == original
                actual = json.loads(check([args.php, ROOT / 'tools/report-external-stream.php', path, payload,
                    'encrypted' if encrypted else 'plain']))
                assert actual['bytes'] == len(expected)
                assert actual['sha256'] == hashlib.sha256(expected).hexdigest()
                reference = independent.trailer['/Root'].raw_get('/ExternalPayload')
                offset = independent.xref[0][reference.idnum]
                source_bytes = path.read_bytes()
                start = source_bytes.index(b'\nstream\n', offset) + len(b'\nstream\n')
                length = int(re.search(rb'/Length\s+(\d+)', source_bytes[offset:start]).group(1))
                # The ignored local payload is retained verbatim, even if the producer encrypted it.
                assert actual['localHex'] == source_bytes[start:start + length].hex()
                assert actual['authenticated'] == encrypted
                assert actual['requests'] == 1
                check([args.qpdf, *(['--password=user'] if encrypted else []), '--suppress-recovery', '--check', path])
                reports.append({'file': path.name, 'encrypted': encrypted, 'bytes': len(expected), 'sha256': actual['sha256']})
        print(json.dumps({'cases': reports, 'independentProducer': 'pyHanko', 'independentDecoder': 'pypdf',
            'externalSource': 'explicitly supplied bytes; no PDF-selected filesystem or URL acquisition',
            'sourceIntegrity': 'external file bytes are not authenticated by the PDF source MAC'}, indent=2))


if __name__ == '__main__':
    main()
