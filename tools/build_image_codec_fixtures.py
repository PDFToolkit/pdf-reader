"""Build synthetic PDF globals fixtures with pypdf and qpdf; no user files or credentials."""
from pathlib import Path
import argparse
import hashlib
import json
import subprocess
from pypdf import PdfWriter
from pypdf.generic import DictionaryObject, EncodedStreamObject, DecodedStreamObject, NameObject, NumberObject

class GlobalFixtureBuilder:
    def __init__(self, directory, qpdf):
        self.directory, self.qpdf = directory, qpdf

    def build(self):
        directory = self.directory
        case = next(c for c in json.loads((directory / 'manifest.json').read_text())['cases'] if c.get('name') == 'text-globals')
        image_bytes = (directory / case['file']).read_bytes()
        globals_bytes = (directory / case['globals']).read_bytes()
        writer = PdfWriter()
        page = writer.add_blank_page(width=case['width'], height=case['height'])
        globals_stream = DecodedStreamObject()
        globals_stream.set_data(globals_bytes)
        reference = writer._add_object(globals_stream.flate_encode())
        image = EncodedStreamObject()
        image._data = image_bytes
        image.update({NameObject('/Type'): NameObject('/XObject'), NameObject('/Subtype'): NameObject('/Image'),
                      NameObject('/Width'): NumberObject(case['width']), NameObject('/Height'): NumberObject(case['height']),
                      NameObject('/BitsPerComponent'): NumberObject(1), NameObject('/ColorSpace'): NameObject('/DeviceGray'),
                      NameObject('/Filter'): NameObject('/JBIG2Decode'),
                      NameObject('/DecodeParms'): DictionaryObject({NameObject('/JBIG2Globals'): reference})})
        ref = writer._add_object(image)
        writer.root_object[NameObject('/TestImage')] = ref
        page[NameObject('/Resources')] = DictionaryObject({NameObject('/XObject'): DictionaryObject({NameObject('/Im'):ref})})
        content=DecodedStreamObject(); content.set_data(f'q {case["width"]} 0 0 {case["height"]} 0 0 cm /Im Do Q'.encode())
        page[NameObject('/Contents')] = writer._add_object(content)
        writer.write(directory / 'globals.pdf')
        subprocess.run([self.qpdf, '--encrypt', 'user', 'owner', '256', '--', str(directory / 'globals.pdf'), str(directory / 'globals-encrypted.pdf')], check=True)
        files = {name:hashlib.sha256((directory / name).read_bytes()).hexdigest() for name in ('globals.pdf','globals-encrypted.pdf')}
        result = {'generator': 'pypdf clear PDF; qpdf AES-256 R6; random encryption salt/IV, regenerate hashes with fixtures',
                  'encodedSha256': case['sha256'], 'globalsSha256': case['globalsSha256'],
                  'samplesSha256': case['samplesSha256'], 'files': files, 'testPasswords': ['user', 'owner']}
        (directory / 'globals-pdf.json').write_text(json.dumps(result, indent=2)+'\n')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--qpdf', default='qpdf')
    args = parser.parse_args()
    GlobalFixtureBuilder(Path(__file__).resolve().parents[1] / 'tests/ImageCodecs/Fixtures', args.qpdf).build()
