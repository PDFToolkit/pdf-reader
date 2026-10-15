"""Authenticate independently qpdf-encrypted PDFs through the native PHP reader."""

import argparse
import hashlib
import json
import io
import os
from pathlib import Path
import re
import subprocess
import tempfile
import zlib

from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, BooleanObject, ByteStringObject, DecodedStreamObject, DictionaryObject, NameObject, NumberObject

METADATA = b'<metadata>Independent authenticated reader fixture</metadata>'
SIGNATURE = bytes(range(32))


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return result.stdout


def gcm_source(qpdf, authenticated_source, output, clear_metadata, compressed):
    """Independent AESV4 source, reusing only qpdf's password entries and test key.

    R7 uses R6 password authentication. qpdf does not write AESV4; Python's
    cryptography backend encrypts each source object with a new 96-bit IV.
    Object-stream members are deliberately NOT encrypted a second time.
    """
    source = PdfReader(authenticated_source)
    encryption = source.trailer['/Encrypt']
    encryption[NameObject('/V')] = NumberObject(6)
    encryption[NameObject('/R')] = NumberObject(7)
    encryption['/CF']['/StdCF'][NameObject('/CFM')] = NameObject('/AESV4')
    encryption[NameObject('/EncryptMetadata')] = BooleanObject(not clear_metadata)
    key_report = run([qpdf, '--password=user', '--show-encryption', '--show-encryption-key', authenticated_source])
    key_match = re.search(rb'Encryption key = ([0-9a-fA-F]{64})', key_report)
    assert key_match, key_report
    cipher = AESGCM(bytes.fromhex(key_match[1].decode()))

    def encrypt(value):
        nonce = os.urandom(12)
        return nonce + cipher.encrypt(nonce, value, None)

    def literal(value):
        return b'<' + encrypt(value).hex().encode() + b'>'

    def stream(value, entries=b''):
        return b'<< /Length ' + str(len(value)).encode() + b' ' + entries + b' >>\nstream\n' + value + b'\nendstream'

    content = b'q 1 0 0 1 0 0 cm Q\n'
    catalog = (b'<< /Type /Catalog /Pages 2 0 R /Metadata 5 0 R /FixtureSignature 6 0 R '
        b'/FixtureBinary ' + (b'<' + bytes(range(256)).hex().encode() + b'>' if compressed else literal(bytes(range(256))))
        + b'/FixtureLiteral ' + (b'<' + b'A (nested) \\ line\n\tend'.hex().encode() + b'>' if compressed else literal(b'A (nested) \\ line\n\tend')) + b' >>')
    objects = {
        2: b'<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
        3: b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R >>',
        4: stream(encrypt(zlib.compress(content)), b'/Filter /FlateDecode'),
        5: stream(METADATA if clear_metadata else encrypt(METADATA), b'/Type /Metadata /Subtype /XML'),
        6: b'<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 0 0 0] /Contents <' + SIGNATURE.hex().encode() + b'> >>',
    }
    serialized_encryption = io.BytesIO()
    encryption.write_to_stream(serialized_encryption)
    objects[7] = serialized_encryption.getvalue()
    if compressed:
        objects[8] = stream(encrypt(zlib.compress(b'1 0 ' + catalog)), b'/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode')
    else:
        objects[1] = catalog
    data = bytearray(b'%PDF-2.0\n%\xe2\xe3\xcf\xd3\n')
    offsets = {}
    for number, body in sorted(objects.items()):
        offsets[number] = len(data)
        data.extend(f'{number} 0 obj\n'.encode() + body + b'\nendobj\n')
    start = len(data)
    if compressed:
        offsets[9] = start
        records = []
        for number in range(10):
            kind, first, second = (0, 0, 65535) if number == 0 else ((2, 8, 0) if number == 1 else (1, offsets[number], 0))
            records.append(bytes([kind]) + first.to_bytes(4, 'big') + second.to_bytes(2, 'big'))
        data.extend(b'9 0 obj\n' + stream(b''.join(records), b'/Type /XRef /Size 10 /W [1 4 2] /Root 1 0 R /Encrypt 7 0 R') + b'\nendobj\n')
    else:
        data.extend(b'xref\n0 8\n0000000000 65535 f \n')
        for number in range(1, 8):
            data.extend(f'{offsets[number]:010} 00000 n \n'.encode())
        data.extend(b'trailer\n<< /Size 8 /Root 1 0 R /Encrypt 7 0 R >>\n')
    data.extend(f'startxref\n{start}\n%%EOF\n'.encode())
    output.write_bytes(data)
    return content


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-reader-authentication-') as temporary:
        directory = Path(temporary)
        writer = PdfWriter(clone_from=root / 'tests/Fixtures/pdf/classic.pdf')
        metadata = DecodedStreamObject()
        metadata.set_data(METADATA)
        metadata[NameObject('/Type')] = NameObject('/Metadata')
        metadata[NameObject('/Subtype')] = NameObject('/XML')
        writer.root_object[NameObject('/Metadata')] = writer._add_object(metadata)
        signature = DictionaryObject({NameObject('/Type'): NameObject('/Sig'),
            NameObject('/Filter'): NameObject('/Adobe.PPKLite'), NameObject('/SubFilter'): NameObject('/adbe.pkcs7.detached'),
            NameObject('/ByteRange'): ArrayObject([NumberObject(0)] * 4), NameObject('/Contents'): ByteStringObject(SIGNATURE)})
        writer.root_object[NameObject('/FixtureSignature')] = writer._add_object(signature)
        source = directory / 'source.pdf'
        writer.write(source)
        original = PdfReader(source)
        content = original.pages[0].get_contents().get_data()
        modes = [(2, ['40']), (3, ['128', '--use-aes=n']), (4, ['128', '--use-aes=n', '--force-V4']),
            (4, ['128', '--use-aes=y']), (5, ['256', '--force-R5']), (6, ['256'])]
        count = 0
        for mode_index, (revision, options) in enumerate(modes):
            for clear_metadata in ([False, True] if revision >= 4 else [False]):
                for object_streams in [False, True]:
                    path = directory / f'r{revision}-{mode_index}-{clear_metadata}-{object_streams}.pdf'
                    run([args.qpdf, '--allow-weak-crypto', '--object-streams=' + ('generate' if object_streams else 'disable'),
                        '--encrypt', 'user', 'owner', *options, *(['--cleartext-metadata'] if clear_metadata else []), '--', source, path])
                    run([args.qpdf, '--password=user', '--suppress-recovery', '--check', path])
                    for password, access in [('user', 'User'), ('owner', 'Owner')]:
                        report = json.loads(run([args.php, root / 'tools/authenticate_case.php', path, revision, password]))
                        assert report['revision'] == revision and report['access'] == access, path.name
                        assert report['binarySha256'] == hashlib.sha256(bytes(range(256))).hexdigest(), path.name
                        assert report['literal'] == 'A (nested) \\ line\n\tend', path.name
                        assert report['contentSha256'] == hashlib.sha256(content).hexdigest(), path.name
                        assert report['metadata'] == METADATA.decode(), path.name
                        assert report['signature'] == SIGNATURE.hex(), path.name
                    count += 1
        # Independent R7 encryption uses the R6 credentials with matching metadata.
        for clear_metadata in [False, True]:
            seed = directory / f'gcm-seed-{clear_metadata}.pdf'
            run([args.qpdf, '--encrypt', 'user', 'owner', '256', *(['--cleartext-metadata'] if clear_metadata else []), '--', source, seed])
            for compressed in [False, True]:
                path = directory / f'r7-{clear_metadata}-{compressed}.pdf'
                content = gcm_source(args.qpdf, seed, path, clear_metadata, compressed)
                for password, access in [('user', 'User'), ('owner', 'Owner')]:
                    report = json.loads(run([args.php, root / 'tools/authenticate_case.php', path, 7, password]))
                    assert report['revision'] == 7 and report['access'] == access
                    assert report['binarySha256'] == hashlib.sha256(bytes(range(256))).hexdigest()
                    assert report['literal'] == 'A (nested) \\ line\n\tend'
                    assert report['contentSha256'] == hashlib.sha256(content).hexdigest()
                    assert report['metadata'] == METADATA.decode() and report['signature'] == SIGNATURE.hex()
                count += 1
        print(f'{count} independently encrypted R2–R7 PDFs: both passwords, object streams, metadata and signature exemptions passed.')


if __name__ == '__main__':
    main()
