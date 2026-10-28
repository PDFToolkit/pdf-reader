"""Reader image backends: frozen independent planes, live libjpeg/Poppler, filtered/encrypted globals."""
from __future__ import annotations
import argparse
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
from PIL import Image, features
from pypdf import PdfReader, PdfWriter
from pypdf.generic import DecodedStreamObject, EncodedStreamObject, DictionaryObject, NameObject, NumberObject

ROOT = Path(__file__).resolve().parents[1]
FIXTURES = ROOT / 'tests/ImageCodecs/Fixtures'

class ImageCodecOracle:
    def __init__(self, args):
        self.args = args
        self.php = [args.php]
        if args.bootstrap:
            self.php += ['-d', 'auto_prepend_file=' + str(args.bootstrap.resolve())]
        self.php += [str(ROOT / 'tools/report-image-codecs.php')]

    def run_command(self, args):
        result = subprocess.run([str(a) for a in args], cwd=ROOT, capture_output=True, text=True, timeout=180)
        assert result.returncode == 0, (args, result.returncode, result.stdout, result.stderr)
        return result

    def decode(self, mode, *args):
        return json.loads(self.run_command(self.php + [mode, *args]).stdout)

    def samples(self, component):
        raw = base64.b64decode(component['bytes'], validate=True)
        width, height, precision = (component[k] for k in ('width', 'height', 'precision'))
        stride = (width * precision + 7) // 8
        assert len(raw) == stride * height
        values = []
        for y in range(height):
            for x in range(width):
                position = y * stride * 8 + x * precision
                value = 0
                for bit in range(position, position + precision):
                    value = (value << 1) | ((raw[bit // 8] >> (7 - bit % 8)) & 1)
                if component['signed'] and value & (1 << (precision - 1)):
                    value -= 1 << precision
                values.append(value)
        return values

    def check(self, case, actual):
        assert 'error' not in actual, (case['file'], actual)
        if case['filter'] == 'JBIG2Decode':
            assert [actual['width'], actual['height']] == [case['width'], case['height']]
            assert len(actual['components']) == 1
            plane = actual['components'][0]
            assert (plane['precision'], plane['signed'], plane['alpha']) == (1, False, 'none')
            assert hashlib.sha256(base64.b64decode(plane['bytes'])).hexdigest() == case['samplesSha256']
        else:
            reference_path = FIXTURES / case['expected']
            assert hashlib.sha256(reference_path.read_bytes()).hexdigest() == case['expectedSha256']
            reference = json.loads(reference_path.read_text())
            assert all(actual[k] == reference[k] for k in ('width', 'height', 'x0', 'y0'))
            assert len(actual['components']) == len(reference['components'])
            for plane, expected in zip(actual['components'], reference['components'], strict=True):
                assert all(plane[k] == expected[k] for k in ('width', 'height', 'precision', 'signed', 'dx', 'dy', 'x0', 'y0'))
                assert plane['alpha'] == expected.get('alpha', 'none')
                assert self.samples(plane) == expected['samples'], case['file']

    def image_pdf(self, path, data, width, height, components, filter_name=None):
        writer = PdfWriter()
        page = writer.add_blank_page(width=width, height=height)
        image = EncodedStreamObject() if filter_name else DecodedStreamObject()
        image._data = data
        image.update({NameObject('/Type'): NameObject('/XObject'), NameObject('/Subtype'): NameObject('/Image'),
                      NameObject('/Width'): NumberObject(width), NameObject('/Height'): NumberObject(height),
                      NameObject('/BitsPerComponent'): NumberObject(8),
                      NameObject('/ColorSpace'): NameObject({1:'/DeviceGray',3:'/DeviceRGB',4:'/DeviceCMYK'}[components])})
        if filter_name:
            image[NameObject('/Filter')] = NameObject('/'+filter_name)
        ref = writer._add_object(image)
        writer.root_object[NameObject('/TestImage')] = ref
        page[NameObject('/Resources')] = DictionaryObject({NameObject('/XObject'): DictionaryObject({NameObject('/Im'):ref})})
        content = DecodedStreamObject(); content.set_data(f'q {width} 0 0 {height} 0 0 cm /Im Do Q'.encode())
        page[NameObject('/Contents')] = writer._add_object(content)
        writer.write(path)

    def extract(self, path, prefix, password=None):
        args = [self.args.pdfimages]
        if password: args += ['-upw', password]
        self.run_command(args + ['-png', path, prefix])
        paths = list(prefix.parent.glob(prefix.name+'-*.png'))
        assert len(paths) == 1, paths
        with Image.open(paths[0]) as image: return list(image.convert('RGB').get_flattened_data())

    def run(self):
        environment=self.decode('environment')
        assert environment['phpCodecsAvailable'], environment
        if self.args.require_native: assert environment['native']['available'], environment
        manifest = json.loads((FIXTURES/'manifest.json').read_text())
        for name, sha in manifest['auxiliaryFiles'].items():
            assert hashlib.sha256((FIXTURES/name).read_bytes()).hexdigest() == sha
        decoded = {mode: self.decode(mode) for mode in ('auto','php','native')}
        checks = []
        for case in manifest['cases']:
            assert hashlib.sha256((FIXTURES/case['file']).read_bytes()).hexdigest() == case['sha256']
            if 'globals' in case:
                assert hashlib.sha256((FIXTURES/case['globals']).read_bytes()).hexdigest() == case['globalsSha256']
            for mode in ('auto','php'): self.check(case, decoded[mode][case['file']])
            for metadata in ('color','channelDefinitions','chromaKey'):
                assert decoded['auto'][case['file']][metadata] == decoded['php'][case['file']][metadata], (case['file'],metadata)
            native = decoded['native'][case['file']]
            native_qualified = case['native'] and environment['native']['available'] and environment['native'].get('revision', 0) >= case.get('nativeMinimumRevision', 0)
            if native_qualified and self.args.require_native:
                self.check(case, native)
                assert decoded['auto'][case['file']]['backend'] == 'ghostscript'
            elif not native_qualified:
                assert native.get('error') == 'PDF\\Reading\\ImageCodecs\\CodecUnavailable', native
                assert decoded['auto'][case['file']]['backend'] == 'php'
            checks.append({'file':case['file'],'sha256':case['sha256'],'backend':decoded['auto'][case['file']]['backend'],
                           'planes':'exact','nativeProfile':case['native'],'nativeQualified':native_qualified,'nativeMinimumRevision':case.get('nativeMinimumRevision',0),'evidence':case.get('oracle','Explicit JBIG2 bitmap and external corpus qualification')})
        (ROOT/'_build').mkdir(exist_ok=True)
        live = []
        with tempfile.TemporaryDirectory(prefix='image-codecs-', dir=ROOT/'_build') as temporary:
            directory = Path(temporary)
            for name in ('rgb.jpg','cmyk-progressive.jpg'):
                source = FIXTURES/name
                with Image.open(source) as image:
                    expected = image.tobytes()
                    mode = image.mode
                # Pillow presents conventional CMYK. PDF DCT returns encoded
                # Adobe CMYK component values without this display inversion.
                if mode == 'CMYK': expected = bytes(255-v for v in expected)
                for backend in ('auto','php'):
                    actual = decoded[backend][name]; assert 'error' not in actual, actual
                    planes = [base64.b64decode(p['bytes']) for p in actual['components']]
                    count = len(planes); pixels = bytes(planes[c][i] for i in range(actual['width']*actual['height']) for c in range(count))
                    assert len(pixels) == len(expected)
                    maximum = max(abs(a-b) for a,b in zip(pixels,expected,strict=True))
                    assert maximum <= (3 if count == 3 else 1), (name,backend,maximum)
                    encoded = directory/(name+backend+'-encoded.pdf'); plain = directory/(name+backend+'-decoded.pdf')
                    self.image_pdf(encoded,source.read_bytes(),actual['width'],actual['height'],count,'DCTDecode')
                    self.image_pdf(plain,pixels,actual['width'],actual['height'],count)
                    for pdf in (encoded,plain): self.run_command([self.args.qpdf,'--check',pdf])
                    a=self.extract(encoded,encoded.with_suffix('')); b=self.extract(plain,plain.with_suffix(''))
                    delta=max(abs(x-y) for left,right in zip(a,b,strict=True) for x,y in zip(left,right,strict=True))
                    assert delta <= 3, (name,backend,'Poppler',delta)
                    live.append({'file':name,'backend':backend,'libjpegMaxDifference':maximum,'popplerMaxDifference':delta,'qpdf':True})
            evidence = json.loads((FIXTURES/'globals-pdf.json').read_text())
            for name, sha in evidence['files'].items():
                path=FIXTURES/name; assert hashlib.sha256(path.read_bytes()).hexdigest()==sha
                password='user' if 'encrypted' in name else None
                args=[self.args.qpdf]+(['--password=user'] if password else [])+['--check',path]
                self.run_command(args)
                pdf=PdfReader(path,strict=True,password=password)
                image=pdf.root_object['/TestImage']; globals_stream=image['/DecodeParms']['/JBIG2Globals']
                assert hashlib.sha256(image._data).hexdigest()==evidence['encodedSha256']
                assert hashlib.sha256(globals_stream.get_data()).hexdigest()==evidence['globalsSha256']
                for backend in ('auto','php'):
                    actual=self.decode(backend,str(path),'user')
                    assert hashlib.sha256(base64.b64decode(actual['components'][0]['bytes'])).hexdigest()==evidence['samplesSha256']
                    pixels=self.extract(path,directory/(name+backend+'-globals'),password)
                    expected=self.samples(actual['components'][0])
                    assert pixels == [(v*255, v*255, v*255) for v in expected], (name,backend,'Poppler globals')
                    live.append({'file':name,'backend':backend,'pypdfDecryptionAndGlobals':True,'qpdf':True,'exactSamples':True,'popplerExact':True})
        return {'status':'pass','profileCases':len(checks),'nativeRequired':self.args.require_native,'environment':environment,'checks':checks,'liveChecks':live,
                'versions':{'Pillow':Image.__version__,'libjpeg':features.version_codec('jpg'),'Poppler':self.run_command([self.args.pdfimages,'-v']).stderr.splitlines()[0],
                            'qpdf':self.run_command([self.args.qpdf,'--version']).stdout.splitlines()[0]},
                'limits':['Finite corpus, not a universal conformance claim.','JPEG full-resolution samples use explicit IDCT/colour rounding tolerances.','JPEG 2000 reference planes were generated independently by OpenJPEG; high-precision/metadata vectors retain their declared evidence category.','JBIG2 unsupported native profiles are checked against explicit independent or normative bitmaps; this does not validate libjbig2dec for those profiles.']}

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php',default='php'); parser.add_argument('--bootstrap',type=Path)
    parser.add_argument('--qpdf',default='qpdf'); parser.add_argument('--pdfimages',default='pdfimages')
    parser.add_argument('--require-native',action='store_true'); parser.add_argument('--report',type=Path,required=True)
    args=parser.parse_args()
    try: report=ImageCodecOracle(args).run()
    except Exception as error:
        report={'status':'fail','error':f'{type(error).__name__}: {error}'}
        args.report.parent.mkdir(parents=True,exist_ok=True);args.report.write_text(json.dumps(report,indent=2)+'\n');raise
    args.report.parent.mkdir(parents=True,exist_ok=True);args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({'status':report['status'],'profileCases':report['profileCases'],'liveChecks':len(report['liveChecks'])}))

if __name__=='__main__':main()
