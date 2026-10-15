"""Compare physical revision views with independent pyHanko, qpdf and actual CMS signatures."""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import logging
from pathlib import Path
import re
import subprocess
import tempfile

from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.writer import PdfFileWriter, PageObject
from pyhanko.sign import signers
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko_certvalidator import ValidationContext
from pyhanko_certvalidator.registry import SimpleCertificateStore

from generate_public_key_fixtures import identity

ROOT = Path(__file__).resolve().parents[1]
logging.getLogger('pyhanko').setLevel(logging.CRITICAL)


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return result.stdout


def hybrid(source):
    """Replace only the unsigned initial footer with its actual hybrid companion table."""
    reader = PdfFileReader(io.BytesIO(source))
    old_offset = reader.xrefs.get_startxref_for_revision(0)
    footer = list(re.finditer(rb'(?:\r?\n)startxref\s+\d+\s+%%EOF\s*\Z', source))
    assert len(footer) == 1
    prefix = source[:footer[0].start()] + b'\n'
    trailer = generic.DictionaryObject({generic.pdf_name(key): reader.trailer_view.raw_get(key)
        for key in ('/Root', '/Size', '/Info', '/ID', '/Encrypt') if key in reader.trailer_view})
    trailer[generic.pdf_name('/XRefStm')] = generic.NumberObject(old_offset)
    encoded = io.BytesIO()
    trailer.write_to_stream(encoded, None)
    return prefix + b'xref\n0 1\n0000000000 65535 f \ntrailer\n' + encoded.getvalue() + f'\nstartxref\n{len(prefix)}\n%%EOF\n'.encode()


def trailing_objects_initial():
    """An independent ISO 7.5.8.1 layout: actual objects after its xref stream."""
    before = [b'<< /Type /Catalog /Pages 2 0 R /HistoryMarker (initial) /HistoryTail 4 0 R >>',
        b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> >>']
    payload = b'Fake startxref 0 %%EOF endobj markers are only stream data.\n'
    after = [b'<< /Stream 5 0 R /Value (tail object) >>',
        b'<< /Length 6 0 R >>\nstream\n' + payload + b'endstream', str(len(payload)).encode()]
    prefix = b'%PDF-1.7\n%\xe2\xe3\xcf\xd3\n'
    offsets = {}
    for number, value in enumerate(before, 1):
        offsets[number] = len(prefix)
        prefix += f'{number} 0 obj\n'.encode() + value + b'\nendobj\n'
    offsets[7] = len(prefix)

    def xref():
        entries = b'\x00\x00\x00\x00\x00\xff\xff' + b''.join(
            b'\x01' + offsets.get(number, 0).to_bytes(4, 'big') + b'\x00\x00' for number in range(1, 8))
        return b'7 0 obj\n<< /Type /XRef /Size 8 /Root 1 0 R /W [1 4 2] /Length 56 >>\nstream\n' + entries + b'\nendstream\nendobj\n'

    tail = b''
    for number, value in enumerate(after, 4):
        offsets[number] = len(prefix) + len(xref()) + len(tail)
        tail += f'{number} 0 obj\n'.encode() + value + b'\nendobj\n'
    return prefix + xref() + tail + f'startxref\n{offsets[7]}\n%%EOF\n'.encode()


def produce(directory):
    certificate, private_key = identity(directory, 'history')
    signer = signers.SimpleSigner(signing_cert=certificate, signing_key=private_key, cert_registry=SimpleCertificateStore())
    records = []
    for kind in ('table', 'stream', 'hybrid', 'stream-tail'):
        for encrypted in (False, True):
            if kind == 'stream-tail' and encrypted:
                continue
            writer = PdfFileWriter(stream_xrefs=kind != 'table')
            writer.root[generic.pdf_name('/HistoryMarker')] = generic.TextStringObject('initial')
            if kind != 'table':
                container = writer.prepare_object_stream()
                writer.root[generic.pdf_name('/HistoryCompressed')] = writer.add_object(
                    generic.DictionaryObject({generic.pdf_name('/Value'): generic.TextStringObject('compressed historical object')}),
                    obj_stream=container)
            content = generic.StreamObject(stream_data=b'q Q\n% fake startxref 0 %%EOF and endobj remain stream bytes\n')
            writer.insert_page(PageObject(writer.add_object(content), (0, 0, 200, 200)))
            if encrypted:
                writer.encrypt('owner', 'user', pdf_mac=False)
            output = io.BytesIO()
            writer.write(output)
            initial = output.getvalue()
            if kind == 'hybrid':
                initial = hybrid(initial)
            elif kind == 'stream-tail':
                initial = trailing_objects_initial()
                assert initial == (ROOT / 'tests/Fixtures/revision-history/trailing-xref.pdf').read_bytes()
            update = IncrementalPdfFileWriter(io.BytesIO(initial), strict=kind != 'hybrid')
            if encrypted:
                update.encrypt('owner')
            output = io.BytesIO()
            signers.PdfSigner(signers.PdfSignatureMetadata(field_name='approval', md_algorithm='sha256'),
                signer=signer).sign_pdf(update, output=output, bytes_reserved=16384)
            signed = output.getvalue()
            update = IncrementalPdfFileWriter(io.BytesIO(signed), strict=kind != 'hybrid')
            if encrypted:
                update.encrypt('owner')
            update.root[generic.pdf_name('/HistoryMarker')] = generic.TextStringObject('latest')
            update.update_root()
            update.set_info(generic.DictionaryObject({
                generic.pdf_name('/Title'): generic.TextStringObject('Final physical revision')}))
            output = io.BytesIO()
            update.write(output)
            final = output.getvalue()
            assert final.startswith(signed) and signed.startswith(initial)
            path = directory / f'{kind}-encrypted{int(encrypted)}.pdf'
            path.write_bytes(final)
            records.append({'path': path, 'encrypted': encrypted, 'hybrid': kind == 'hybrid',
                'compressed': kind in ('stream', 'hybrid'),
                'prefixes': [initial, signed, final]})
    return records


def validate(record, args):
    path = record['path']
    source = path.read_bytes()
    php = [args.php]
    if args.bootstrap:
        php += ['-d', 'auto_prepend_file=' + str(args.bootstrap.resolve())]
    actual = json.loads(run([*php, ROOT / 'tools/report-revision-history.php', path,
        'password' if record.get('encrypted') else 'plain']))
    assert actual['sourceSha256'] == hashlib.sha256(source).hexdigest()
    expected = PdfFileReader(io.BytesIO(source), strict=not record.get('hybrid', False))
    if record.get('encrypted'):
        expected.decrypt('user')
    assert len(actual['revisions']) == expected.xrefs.total_revisions
    signatures = 0
    for revision in actual['revisions']:
        ordinal = revision['ordinal']
        assert not revision['rawAuthenticated'] and revision['rawMac'] is None
        assert revision['xref'] == expected.xrefs.get_startxref_for_revision(ordinal)
        assert source[revision['footer']:revision['footer'] + 9] == b'startxref'
        assert source[revision['eof']:revision['minimumEnd']] == b'%%EOF'
        assert all(byte in b'\x00\x09\x0a\x0c\x0d\x20' for byte in source[revision['minimumEnd']:revision['maximumEnd']])
        prefix = source[:revision['end']]
        assert revision['sourceSha256'] == hashlib.sha256(prefix).hexdigest()
        if 'prefixes' in record:
            assert prefix == record['prefixes'][ordinal]
            assert revision['marker'] == ('latest' if ordinal == 2 else 'initial')
            assert revision['compressed'] == ('compressed historical object' if record['compressed'] else None)
        independent = PdfFileReader(io.BytesIO(prefix), strict=not record.get('hybrid', False))
        if record.get('encrypted'):
            independent.decrypt('user')
        assert independent.xrefs.total_revisions == ordinal + 1
        for item in revision['objects']:
            if item['span'] is not None:
                start, end = item['span']
                assert prefix[start:end].startswith(f'{item["number"]} {item["generation"]} obj'.encode())
                assert prefix[start:end].endswith(b'endobj')
                reference = generic.Reference(item['number'], item['generation'], independent)
                independent.get_object(reference)
            else:
                assert item['kind'] != 'Uncompressed'
        for signature in revision['signatures']:
            span = signature['span']
            assert span is not None
            start, end = span
            token = prefix[start:end]
            assert token.startswith(b'<') and token.endswith(b'>')
            content = bytes.fromhex(token[1:-1].decode())
            assert hashlib.sha256(content).hexdigest() == signature['contentsSha256']
            first, length, second, tail = signature['range']
            assert first == 0 and length == start and second == end
            matching = [candidate for candidate in actual['revisions']
                if candidate['minimumEnd'] <= second + tail <= candidate['maximumEnd']]
            assert len(matching) == 1
        if 'prefixes' in record and ordinal >= 1:
            embedded, = independent.embedded_signatures
            status = validate_pdf_signature(embedded,
                signer_validation_context=ValidationContext(trust_roots=[], allow_fetching=False), skip_diff=True)
            assert status.intact and status.valid and not status.trusted
            signatures += 1
        temporary = path.parent / (path.stem + f'-revision-{ordinal}.pdf')
        temporary.write_bytes(prefix)
        password = ['--password=user'] if record.get('encrypted') else []
        run([args.qpdf, *password, '--suppress-recovery', '--check', temporary])
    return {'file': path.name, 'revisions': len(actual['revisions']), 'mathematicalSignatures': signatures,
        'encrypted': record.get('encrypted', False), 'hybrid': record.get('hybrid', False)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--bootstrap', type=Path)
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='reader-revision-history-') as temporary:
        directory = (args.work_dir or Path(temporary)).resolve()
        directory.mkdir(parents=True, exist_ok=True)
        records = produce(directory)
        for filename in ('classic', 'object-streams', 'incremental', 'deleted-object', 'reused-generation'):
            path = directory / (filename + '.pdf')
            path.write_bytes((ROOT / 'tests/Fixtures/pdf' / path.name).read_bytes())
            records.append({'path': path})
        reports = [validate(record, args) for record in records]
        print(json.dumps({'sources': reports, 'prefixes': sum(item['revisions'] for item in reports),
            'signatureVerifications': sum(item['mathematicalSignatures'] for item in reports),
            'certificateTrust': 'not asserted; offline validation with no trust roots',
            'modificationAuthorization': 'not asserted'}, indent=2))


if __name__ == '__main__':
    main()
