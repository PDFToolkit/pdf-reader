"""Authenticate independently qpdf-encrypted PDFs through the native PHP reader."""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, ByteStringObject, DecodedStreamObject, DictionaryObject, NameObject, NumberObject

METADATA = b'<metadata>Independent authenticated reader fixture</metadata>'
SIGNATURE = bytes(range(32))


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
        print(f'{count} independently encrypted R2/R3/R4-RC4/R4-AES/R5/R6 PDFs: both passwords, object streams, metadata and signature exemptions passed.')


if __name__ == '__main__':
    main()
