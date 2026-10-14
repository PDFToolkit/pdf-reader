"""Create original PDF 2.0 syntax fixtures using pypdf, not the PHP writer."""

from pathlib import Path
import hashlib
import io
import json
import subprocess

from pypdf import PdfWriter
from pypdf.generic import DictionaryObject, NameObject, TextStringObject, ByteStringObject


def main():
    root = Path(__file__).resolve().parents[1] / 'tests' / 'Fixtures' / 'pdf20'
    root.mkdir(exist_ok=True)
    records = {}
    for filename, header, catalog in [('header.pdf', '%PDF-2.0', None), ('catalog.pdf', '%PDF-1.7', '/2.0')]:
        writer = PdfWriter()
        writer.pdf_header = header
        writer.add_blank_page(width=200, height=120)
        writer.root_object[NameObject('/Lang')] = TextStringObject('fr-FR')
        writer.root_object[NameObject('/FixtureUTF8')] = ByteStringObject(b'\xef\xbb\xbfBonjour \xc3\xa9t\xc3\xa9')
        if catalog:
            writer.root_object[NameObject('/Version')] = NameObject(catalog)
        output = io.BytesIO()
        writer.write(output)
        data = output.getvalue()
        (root / filename).write_bytes(data)
        records[filename] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'header': header, 'catalogVersion': catalog}
    subprocess.run(['qpdf', '--object-streams=generate', '--force-version=2.0', str(root / 'header.pdf'), str(root / 'object-streams.pdf')], check=True)
    data = (root / 'object-streams.pdf').read_bytes()
    records['object-streams.pdf'] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'header': '%PDF-2.0', 'catalogVersion': None}
    (root / 'manifest.json').write_bytes((json.dumps({'producer': 'pypdf 6.20.1 and qpdf 12.4.2; original MIT fixtures', 'fixtures': records}, indent=2) + '\n').encode())


if __name__ == '__main__':
    main()
