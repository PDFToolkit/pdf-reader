"""Validate original fixture bytes and semantics with independent PDF readers."""

import hashlib
import json
from pathlib import Path
import subprocess

from pypdf import PdfReader


def main():
    root = Path(__file__).resolve().parents[1] / 'tests' / 'Fixtures' / 'pdf'
    manifest = json.loads((root / 'manifest.json').read_text(encoding='utf-8'))
    for name, information in manifest['fixtures'].items():
        path = root / name
        data = path.read_bytes()
        if len(data) != information['bytes'] or hashlib.sha256(data).hexdigest() != information['sha256']:
            raise ValueError('Fixture bytes do not match their manifest: ' + name)
        subprocess.run(['qpdf', '--check', str(path)], check=True, capture_output=True)
        reader = PdfReader(path, strict=True)
        if len(reader.pages) != 1 or reader.pages[0].extract_text() != 'Independent reader fixture':
            raise ValueError('Unexpected fixture page content: ' + name)
        binary = reader.root_object['/FixtureData'].get_data()
        if len(binary) != manifest['binary_stream_bytes'] or hashlib.sha256(binary).hexdigest() != manifest['binary_stream_sha256']:
            raise ValueError('Unexpected binary stream: ' + name)
    deleted = subprocess.check_output(['qpdf', '--show-object=9,0', str(root / 'deleted-object.pdf')], text=True).strip()
    stale = subprocess.check_output(['qpdf', '--show-object=9,0', str(root / 'reused-generation.pdf')], text=True).strip()
    reused = subprocess.check_output(['qpdf', '--show-object=9,1', str(root / 'reused-generation.pdf')], text=True).strip()
    if deleted != 'null' or stale != 'null' or reused != '(reused generation one)':
        raise ValueError('Independent free-object or generation check failed.')
    modern = root.parent / 'pdf20'
    for name, information in json.loads((modern / 'manifest.json').read_text(encoding='utf-8'))['fixtures'].items():
        path = modern / name
        data = path.read_bytes()
        if len(data) != information['bytes'] or hashlib.sha256(data).hexdigest() != information['sha256']:
            raise ValueError('PDF 2.0 fixture identity mismatch: ' + name)
        subprocess.run(['qpdf', '--check', str(path)], check=True, capture_output=True)
        reader = PdfReader(path, strict=True)
        if len(reader.pages) != 1 or reader.pdf_header != information['header'] or str(reader.root_object.get('/Version')) != str(information['catalogVersion']):
            raise ValueError('Unexpected PDF 2.0 file structure: ' + name)
    print('Validated five external PDF fixtures, binary streams, deletion and generation reuse.')
    print('Validated three independent PDF 2.0 header, catalog-version and object-stream fixtures.')


if __name__ == '__main__':
    main()
