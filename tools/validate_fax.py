"""Independent fax checks: libtiff input pixels, Reader PDF path, qpdf, Poppler, normative extension vectors."""
import argparse
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

from PIL import Image, features
from pypdf import PdfReader
from build_fax_fixtures import pdf

ROOT = Path(__file__).resolve().parents[1]
FIXTURES = ROOT / 'tests/Fixtures/fax'


def run(command):
    result = subprocess.run([str(value) for value in command], cwd=ROOT, capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise AssertionError(f'{command}: {result.returncode}\n{result.stdout}\n{result.stderr}')
    return result


def pixels(path, case, scale=1):
    with Image.open(path) as image:
        assert image.size == (case['width'] * scale, case['height'] * scale)
        if scale == 1:
            return image.convert('1').tobytes()
        source = image.convert('L')
        output = Image.new('1', (case['width'], case['height']))
        output.putdata([255 if source.getpixel((x * scale + scale // 2, y * scale + scale // 2)) >= 128 else 0 for y in range(case['height']) for x in range(case['width'])])
        return output.tobytes()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    cases = json.loads((FIXTURES / 'manifest.json').read_text())['cases']
    report = {'cases': [], 'extensionEvidence': 'ITU-T T.4 (1999) table 5; T.6 (1988) table 4 and 2.3.1. Explicit expected bitmaps; Poppler output validation is not an independent uncompressed decoder.', 'libtiff': features.version('libtiff')}
    native = json.loads(run([args.php, ROOT / 'tools/report-fax.php']).stdout)
    (ROOT / '_build').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='fax-', dir=ROOT / '_build') as temporary:
        directory = Path(temporary)
        for case in cases:
            name = case['name']
            expected = base64.b64decode(case['bitmap'])
            actual = base64.b64decode(native[name]['bytes'])
            encoded = base64.b64decode(case['encoded'])
            assert actual == expected, f'{name}: native decoded pixels'
            assert hashlib.sha256(encoded).hexdigest() == case['encodedSha256']
            source = FIXTURES / f'{name}.pdf'
            assert source.read_bytes() == pdf(case), f'{name}: source reproducibility'
            image = PdfReader(source, strict=True).pages[0]['/Resources']['/XObject']['/Fax']
            assert image._data == encoded, f'{name}: input stream binding'
            run([args.qpdf, '--check', source])
            item = {'name': name, 'inputSha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'decodedSha256': hashlib.sha256(actual).hexdigest(), 'nativePixels': True, 'qpdf': True}
            if 'tiff' in case:
                assert pixels(FIXTURES / case['tiff'], case) == expected, f'{name}: Pillow/libtiff source pixels'
                rendered = directory / (name + '-compressed')
                result = run([args.pdftoppm, '-r', '720', '-gray', '-singlefile', '-png', source, rendered])
                assert not result.stderr.strip(), f'{name}: Poppler input warning {result.stderr}'
                assert pixels(rendered.with_suffix('.png'), case, 10) == expected, f'{name}: Poppler original fax pixels'
                item.update(libtiffPixels=True, compressedPopplerPixels=True)
            else:
                item.update(normativeVector=True, independentUncompressedDecoder=False)
            decoded = directory / (name + '-decoded.pdf')
            decoded.write_bytes(pdf(case, actual))
            run([args.qpdf, '--check', decoded])
            rendered = directory / (name + '-decoded')
            result = run([args.pdftoppm, '-r', '720', '-gray', '-singlefile', '-png', decoded, rendered])
            assert not result.stderr.strip(), f'{name}: Poppler output warning {result.stderr}'
            assert pixels(rendered.with_suffix('.png'), case, 10) == expected, f'{name}: Poppler decoded output pixels'
            item['decodedOutputPopplerPixels'] = True
            report['cases'].append(item)
    report.update(ok=True, caseCount=len(cases), independentCompressedCases=sum('tiff' in case for case in cases), normativeExtensionCases=sum('tiff' not in case for case in cases))
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, indent=2) + '\n')
    print(f"Validated {len(cases)} fax cases ({report['independentCompressedCases']} independent compressed / {report['normativeExtensionCases']} normative extension)")


if __name__ == '__main__':
    main()
