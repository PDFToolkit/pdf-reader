"""Compare metadata ownership and indirect EFF types with independent encrypted PDFs."""
from __future__ import annotations

import argparse
import base64
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import zlib

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives.padding import PKCS7
from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, DecodedStreamObject, DictionaryObject, IndirectObject, NameObject, NumberObject
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.crypt import SecurityHandler
from pyhanko.pdf_utils.crypt.api import AuthStatus
from pyhanko.pdf_utils.reader import PdfFileReader

CATALOG = b'<catalog>public</catalog>'
PAGE = b'<page>private</page>'
OBJECT = b'<object>private</object>'
CLEAR = b'<other>explicit Identity</other>'
EMBEDDED = b'embedded bytes selected through indirect Type'


def run(*arguments):
    process = subprocess.run(list(map(str, arguments)), capture_output=True)
    assert process.returncode == 0, process.stderr.decode(errors='replace')
    return process.stdout


def stream(writer, data, **entries):
    result = DecodedStreamObject()
    result.set_data(data)
    result.update({NameObject('/' + key): value for key, value in entries.items()})
    return writer._add_object(result)


def encrypted_attachment(qpdf, seed, destination, compressed):
    """Use qpdf credentials and independent CBC encryption, with EFF != StmF."""
    original = PdfReader(seed)
    encryption = original.trailer['/Encrypt']
    encryption[NameObject('/StmF')] = NameObject('/Identity')
    encryption[NameObject('/StrF')] = NameObject('/Identity')
    encryption[NameObject('/EFF')] = NameObject('/StdCF')
    # Table 20 requires this standard-name entry to be ignored even when its
    # supplied value is not a usable crypt-filter dictionary.
    encryption['/CF'][NameObject('/Identity')] = NumberObject(17)
    report = run(qpdf, '--password=user', '--show-encryption', '--show-encryption-key', seed)
    match = re.search(rb'Encryption key = ([0-9a-fA-F]{64})', report)
    assert match, report
    iv = os.urandom(16)
    padder = PKCS7(128).padder()
    padded = padder.update(EMBEDDED) + padder.finalize()
    encryptor = Cipher(algorithms.AES(bytes.fromhex(match[1].decode())), modes.CBC(iv)).encryptor()
    ciphertext = iv + encryptor.update(padded) + encryptor.finalize()

    def binary_stream(data, entries=b''):
        return b'<< /Length ' + str(len(data)).encode() + b' ' + entries + b' >>\nstream\n' + data + b'\nendstream'

    catalog = (b'<< /Type /Catalog /Pages 2 0 R /FixtureEmbedded 4 0 R '
        b'/Names << /EmbeddedFiles << /Names [(fixture.bin) << /Type /Filespec /F (fixture.bin) /EF << /F 4 0 R >> >>] >> >> >>')
    serialized = io.BytesIO()
    encryption.write_to_stream(serialized)
    objects = {
        2: b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3: b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << >> >>',
        4: binary_stream(ciphertext, b'/Type 5 0 R'),
        5: b'/EmbeddedFile',
        6: serialized.getvalue(),
    }
    if compressed:
        objects[7] = binary_stream(zlib.compress(b'1 0 ' + catalog), b'/Type /ObjStm /N 1 /First 4 /Filter /FlateDecode')
    else:
        objects[1] = catalog
    data = bytearray(b'%PDF-2.0\n%\xe2\xe3\xcf\xd3\n')
    offsets = {}
    for number, body in sorted(objects.items()):
        offsets[number] = len(data)
        data.extend(f'{number} 0 obj\n'.encode() + body + b'\nendobj\n')
    start = len(data)
    if compressed:
        offsets[8] = start
        records = []
        for number in range(9):
            kind, first, second = (0, 0, 65535) if number == 0 else ((2, 7, 0) if number == 1 else (1, offsets[number], 0))
            records.append(bytes([kind]) + first.to_bytes(4, 'big') + second.to_bytes(2, 'big'))
        data.extend(b'8 0 obj\n' + binary_stream(b''.join(records), b'/Type /XRef /Size 9 /W [1 4 2] /Root 1 0 R /Encrypt 6 0 R /ID [<00112233445566778899aabbccddeeff> <00112233445566778899aabbccddeeff>]') + b'\nendobj\n')
    else:
        data.extend(b'xref\n0 7\n0000000000 65535 f \n')
        for number in range(1, 7):
            data.extend(f'{offsets[number]:010} 00000 n \n'.encode())
        data.extend(b'trailer\n<< /Size 7 /Root 1 0 R /Encrypt 6 0 R /ID [<00112233445566778899aabbccddeeff> <00112233445566778899aabbccddeeff>] >>\n')
    data.extend(f'startxref\n{start}\n%%EOF\n'.encode())
    destination.write_bytes(data)


def identity_aliases(source, destination):
    """Append only physical reference aliases; preserve all encrypted payload bytes."""
    original = PdfReader(source, strict=True)
    assert original.decrypt('user')
    root_ref = original.trailer.raw_get('/Root')
    encryption_ref = original.trailer.raw_get('/Encrypt')
    catalog = original.trailer['/Root']
    catalog[NameObject('/Version')] = NameObject('/2.0')
    first = int(original.trailer['/Size'])
    targets = [encryption_ref, catalog.raw_get('/Metadata'), root_ref,
        catalog.raw_get('/FixturePageMetadata'), catalog.raw_get('/FixtureObjectMetadata')]
    objects = {}
    for index, reference in enumerate(targets):
        alias = first + index * 2
        objects[alias] = f'{alias + 1} 0 R'.encode()
        objects[alias + 1] = f'{reference.idnum} {reference.generation} R'.encode()
    for key, offset in [('Metadata', 2), ('FixturePageMetadata', 6), ('FixtureObjectMetadata', 8)]:
        catalog[NameObject('/' + key)] = IndirectObject(first + offset, 0, original)
    serialized = io.BytesIO()
    catalog.write_to_stream(serialized)
    objects[root_ref.idnum] = serialized.getvalue()
    data = bytearray(source.read_bytes())
    previous = re.search(rb'startxref\s+(\d+)\s+%%EOF\s*\Z', data)
    assert previous
    offsets = {}
    for number, body in sorted(objects.items()):
        offsets[number] = len(data)
        generation = root_ref.generation if number == root_ref.idnum else 0
        data.extend(f'{number} {generation} obj\n'.encode() + body + b'\nendobj\n')
    start = len(data)
    data.extend(b'xref\n')
    for number, offset in offsets.items():
        generation = root_ref.generation if number == root_ref.idnum else 0
        data.extend(f'{number} 1\n{offset:010d} {generation:05d} n \n'.encode())
    identifiers = io.BytesIO()
    original.trailer['/ID'].write_to_stream(identifiers)
    data.extend(f'trailer << /Size {first + 10} /Root {first + 4} 0 R /Encrypt {first} 0 R /Prev {int(previous[1])} /ID '.encode()
        + identifiers.getvalue() + f' >>\nstartxref\n{start}\n%%EOF\n'.encode())
    destination.write_bytes(data)


def validate_identity_aliases(path, expected):
    """Use unchanged strict parsing/authentication APIs, then independent CBC.

    qpdf, pypdf and pyHanko's high-level decryptors expect /Encrypt to resolve
    immediately to a dictionary. Their original-source checks below remain
    unchanged. The strict pyHanko parser does expose the legal physical chains;
    its actual Standard handler authenticates the terminal dictionary without
    rewriting source bytes or replacing parser/authentication internals.
    """
    with path.open('rb') as source:
        reader = PdfFileReader(source, strict=True)

        def resolve(value):
            seen = set()
            terminal = None
            while isinstance(value, generic.IndirectObject):
                identity = (value.idnum, value.generation)
                assert identity not in seen and len(seen) < 64
                seen.add(identity)
                terminal = identity
                value = reader.get_object(value.reference, never_decrypt=True)
            return value, terminal

        encryption, encryption_id = resolve(reader.trailer.raw_get('/Encrypt'))
        handler = SecurityHandler.build(encryption)
        assert handler.authenticate('user', reader.trailer['/ID'][0].original_bytes).status == AuthStatus.USER
        catalog, _ = resolve(reader.trailer.raw_get('/Root'))
        _, metadata_id = resolve(catalog.raw_get('/Metadata'))
        assert encryption_id is not None and metadata_id is not None
        for name in ['Metadata', 'FixturePageMetadata', 'FixtureObjectMetadata', 'FixtureClearMetadata']:
            stream_value, identity = resolve(catalog.raw_get('/' + name))
            assert isinstance(stream_value, generic.StreamObject) and identity is not None
            data = stream_value.encoded_data
            if identity != metadata_id:
                assert len(data) >= 32 and len(data) % 16 == 0
                key = handler.get_stream_filter().derive_object_key(*identity)
                decryptor = Cipher(algorithms.AES(key), modes.CBC(data[:16])).decryptor()
                padded = decryptor.update(data[16:]) + decryptor.finalize()
                unpadder = PKCS7(128).unpadder()
                data = unpadder.update(padded) + unpadder.finalize()
            filters = stream_value.get('/Filter')
            if filters is not None:
                assert filters == '/FlateDecode', filters
                data = zlib.decompress(data)
            assert base64.b64encode(data).decode() == expected[name], (path.name, name)
        return {name: base64.b64encode(encryption['/' + name].original_bytes).decode() for name in ['O', 'U']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='pdf-reader-metadata-') as temporary:
        directory = args.work_dir.resolve() if args.work_dir else Path(temporary)
        directory.mkdir(parents=True, exist_ok=True)
        writer = PdfWriter()
        page = writer.add_blank_page(width=100, height=100)
        metadata = {'Type': NameObject('/Metadata'), 'Subtype': NameObject('/XML')}
        writer.root_object[NameObject('/Metadata')] = stream(writer, CATALOG, **metadata)
        page_metadata = stream(writer, PAGE, **metadata)
        page[NameObject('/Metadata')] = page_metadata
        writer.root_object[NameObject('/FixturePage')] = page.indirect_reference
        writer.root_object[NameObject('/FixturePageMetadata')] = page_metadata
        other_metadata = stream(writer, OBJECT, **metadata)
        form = stream(writer, b'q Q', Type=NameObject('/XObject'), Subtype=NameObject('/Form'),
            BBox=ArrayObject([NumberObject(n) for n in [0, 0, 100, 100]]), Metadata=other_metadata)
        writer.root_object[NameObject('/FixtureForm')] = form
        writer.root_object[NameObject('/FixtureObjectMetadata')] = other_metadata
        # A separate stream explicitly selects Identity instead of the implicit default.
        writer.root_object[NameObject('/FixtureClearMetadata')] = stream(writer, CLEAR, **metadata,
            Filter=NameObject('/Crypt'), DecodeParms=DictionaryObject({NameObject('/Name'): NameObject('/Identity')}))
        source = directory / 'source.pdf'
        writer.write(source)
        expected = {key: base64.b64encode(value).decode() for key, value in {
            'Metadata': CATALOG, 'FixturePageMetadata': PAGE, 'FixtureObjectMetadata': OBJECT,
            'FixtureClearMetadata': CLEAR, 'PageMetadata': PAGE,
        }.items()}
        count = 0
        for revision, options in [(4, ['128', '--use-aes=y']), (6, ['256'])]:
            for compressed in [False, True]:
                path = directory / f'metadata-r{revision}-{compressed}.pdf'
                run(args.qpdf, '--object-streams=' + ('generate' if compressed else 'disable'),
                    '--encrypt', 'user', 'owner', *options, '--cleartext-metadata', '--', source, path)
                run(args.qpdf, '--password=user', '--suppress-recovery', '--check', path)
                clear = directory / 'decrypted.pdf'
                run(args.qpdf, '--password=user', '--decrypt', path, clear)
                independent = PdfReader(clear, strict=True)
                for key in ['Metadata', 'FixturePageMetadata', 'FixtureObjectMetadata', 'FixtureClearMetadata']:
                    assert base64.b64encode(independent.trailer['/Root']['/' + key].get_data()).decode() == expected[key]
                actual = json.loads(run(args.php, root / 'tools/authenticate_metadata_case.php', path, revision))
                assert actual == expected, (path.name, actual)
                count += 1
                aliases = directory / f'identity-aliases-r{revision}-{compressed}.pdf'
                identity_aliases(path, aliases)
                credentials = validate_identity_aliases(aliases, expected)
                actual_aliases = json.loads(run(args.php, root / 'tools/authenticate_metadata_case.php', aliases, revision, '--credentials'))
                assert actual_aliases == {**expected, 'EncryptionO': credentials['O'], 'EncryptionU': credentials['U']}
                count += 1
                if revision == 4 and not compressed:
                    # V4 fixes the key at 128 bits; global Length is not its default.
                    # Rename only that key, preserving every source offset and cipher byte.
                    reader = PdfReader(path)
                    offset = reader.xref[0][reader.trailer['/Encrypt'].indirect_reference.idnum]
                    data = path.read_bytes()
                    end = data.index(b'endobj', offset)
                    body, replacements = re.subn(rb'/Length(?=\s+128\b)', b'/Unused', data[offset:end])
                    assert replacements == 1 and len(body) == end - offset
                    absent = directory / 'r4-without-global-length.pdf'
                    absent.write_bytes(data[:offset] + body + data[end:])
                    # qpdf 12.4.2 requires global Length even for V4, contrary
                    # to table 20. The original encrypted source above was
                    # independently checked; only that same-length key changes.
                    assert json.loads(run(args.php, root / 'tools/authenticate_metadata_case.php', absent, 4)) == expected
                    count += 1
        seed = directory / 'seed.pdf'
        run(args.qpdf, '--encrypt', 'user', 'owner', '256', '--', source, seed)
        for compressed in [False, True]:
            path = directory / f'eff-{compressed}.pdf'
            encrypted_attachment(args.qpdf, seed, path, compressed)
            run(args.qpdf, '--password=user', '--suppress-recovery', '--check', path)
            actual = json.loads(run(args.php, root / 'tools/authenticate_metadata_case.php', path, 6))
            assert actual == {'FixtureEmbedded': base64.b64encode(EMBEDDED).decode()}, actual
            # qpdf 12.4.2 does not decrypt EFF when StmF is Identity, even with
            # direct Type. It supplies/checks credentials and structure here;
            # cryptography independently supplies the exact encrypted payload.
            count += 1
        print(f'{count} cases passed: original qpdf/pypdf checks plus 4 strict-parser/Standard-handler/cryptography alias cases; terminal encryption credentials, catalog metadata, encrypted page/object metadata and compressed catalogs.')


if __name__ == '__main__':
    main()
