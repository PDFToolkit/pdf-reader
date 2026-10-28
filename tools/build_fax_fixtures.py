"""Build independent CCITT samples with Pillow/libtiff and pypdf (MIT synthetic patterns)."""
from pathlib import Path
import argparse
import base64
import hashlib
import io
import json
import random

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / 'tests/Fixtures/fax'


def refresh():
    import PIL
    from PIL import Image, TiffImagePlugin, features
    cases = []
    rng = random.Random(20261028)
    for name, width, height, pattern, compression, mixed in [
        ('mh-byte-aligned', 41, 9, 'random', 'tiff_ccitt', False),
        ('group4-checkerboard', 64, 8, 'checker', 'group4', False),
        ('group4-runs-and-pass', 256, 16, 'runs', 'group4', False),
        ('group4-random', 41, 9, 'random', 'group4', False),
        ('group4-long-makeup', 6145, 3, 'long', 'group4', False),
        ('group4-one-pixel', 1, 3, 'checker', 'group4', False),
        ('group3-one-dimensional', 64, 8, 'checker', 'group3', False),
        ('group3-mixed-dimensional', 64, 8, 'runs', 'group3', True),
    ]:
        image = Image.new('1', (width, height), 1)
        pixels = image.load()
        for y in range(height):
            for x in range(width):
                pixels[x, y] = ((x // 4 + y) % 2) if pattern == 'checker' else (int(x < 16 + y * 3 or x >= width - 20 - y) if pattern == 'runs' else (int(x < 3000 + y) if pattern == 'long' else rng.randint(0, 1)))
        buffer = io.BytesIO()
        info = TiffImagePlugin.ImageFileDirectory_v2()
        if mixed:
            info[292] = 1
        image.save(buffer, format='TIFF', compression=compression, tiffinfo=info)
        encoded = buffer.getvalue()
        decoded = Image.open(io.BytesIO(encoded))
        assert decoded.tobytes() == image.tobytes()
        offsets, counts = decoded.tag_v2[273], decoded.tag_v2[279]
        assert len(offsets) == len(counts) == 1
        data = encoded[offsets[0]:offsets[0] + counts[0]]
        params = {'K': -1 if compression == 'group4' else (2 if mixed else 0), 'Columns': width, 'Rows': height, 'EndOfBlock': compression == 'group4', 'BlackIs1': True}
        if compression == 'group3':
            params['EndOfLine'] = True
        if compression == 'tiff_ccitt':
            params['EncodedByteAlign'] = True
        (DEST / f'{name}.tiff').write_bytes(encoded)
        cases.append({'name': name, 'width': width, 'height': height, 'params': params, 'encoded': base64.b64encode(data).decode(), 'bitmap': base64.b64encode(image.tobytes()).decode(), 'encodedSha256': hashlib.sha256(data).hexdigest(), 'tiff': f'{name}.tiff'})
    group4 = dict(cases[3])
    bits = ''.join(f'{byte:08b}' for byte in base64.b64decode(group4['encoded']))
    marker = bits.rfind('000000000001000000000001')
    assert marker > 0
    bits = bits[:marker]
    data = int(bits.ljust((len(bits) + 7) // 8 * 8, '0'), 2).to_bytes((len(bits) + 7) // 8, 'big')
    group4.update(name='group4-rows-without-eofb', encoded=base64.b64encode(data).decode(), encodedSha256=hashlib.sha256(data).hexdigest(), params={**group4['params'], 'EndOfBlock': False})
    cases.append(group4)
    group3 = dict(cases[6])
    data = base64.b64decode(group3['encoded']) + int('000000000001' * 6, 2).to_bytes(9, 'big')
    group3.update(name='group3-return-to-control', encoded=base64.b64encode(data).decode(), encodedSha256=hashlib.sha256(data).hexdigest(), params={**group3['params'], 'Rows': 1, 'EndOfBlock': True})
    cases.append(group3)
    cases.append({**cases[7], 'name': 'group3-positive-k-equivalence', 'params': {**cases[7]['params'], 'K': 99}})
    cases.append({**cases[6], 'name': 'group3-optional-eol', 'params': {**cases[6]['params'], 'EndOfLine': False}})
    cases.append({**cases[0], 'name': 'mh-unknown-rows', 'params': {**cases[0]['params'], 'Rows': 0}})
    cases.append({**cases[3], 'name': 'group4-eofb-overrides-rows', 'params': {**cases[3]['params'], 'Rows': 1}})
    # Explicit table vectors are independent of the PHP decoder and its code tables.
    eol, eofb, enter1, enter2 = '000000000001', '000000000001000000000001', '000000001111', '0000001111'
    patterns = '1' + '01' + '001' + '0001' + '00001' + '00000'
    codes = '1' + '01' + '001' + '0001' + '00001' + '000001'
    def packed(bits):
        return int(bits.ljust((len(bits) + 7) // 8 * 8, '0'), 2).to_bytes((len(bits) + 7) // 8, 'big')
    for name, width, height, bits, pixels, params in [
        ('uncompressed-g3-table', 20, 1, eol + enter1 + codes + '00000010', patterns, {'K': 0, 'EndOfLine': True, 'EndOfBlock': False}),
        ('uncompressed-g4-cross-row', 7, 3, enter2 + codes + '1' + '00000010' + eofb, patterns + '1', {'K': -1, 'EndOfBlock': True}),
        ('uncompressed-g4-to-vertical', 8, 2, enter2 + '1' + '000001' + '0000000010' + '111' + eofb, '10000000' * 2, {'K': -1, 'EndOfBlock': True}),
        ('uncompressed-g3-to-black-run', 8, 1, enter1 + '00000000011' + '0011', '00011111', {'K': 0, 'EndOfBlock': False}),
        ('uncompressed-g4-to-black-run', 8, 1, enter2 + '00000000011' + '1' + eofb, '00011111', {'K': -1, 'EndOfBlock': True}),
        ('uncompressed-g4-exit-cross-row', 3, 2, enter2 + '11' + '000000000010' + eofb, '110000', {'K': -1, 'EndOfBlock': True}),
    ]:
        data = packed(bits)
        bitmap = b''.join(packed(''.join('0' if pixel == '1' else '1' for pixel in pixels[y * width:(y + 1) * width])) for y in range(height))
        cases.append({'name': name, 'width': width, 'height': height, 'params': {**params, 'Columns': width, 'Rows': height, 'BlackIs1': False}, 'encoded': base64.b64encode(data).decode(), 'bitmap': base64.b64encode(bitmap).decode(), 'encodedSha256': hashlib.sha256(data).hexdigest(), 'origin': 'ITU-T T.4 (1999) table 5 / T.6 (1988) table 4'})
    # Every exit length (0..4) and both next-run colours, in both dimensional modes.
    white_runs = {3: '1000', 4: '1011', 5: '1100', 6: '1110', 7: '1111'}
    black_runs = {3: '10', 4: '011', 5: '0011', 6: '0010', 7: '00011'}
    for k in (0, -1):
        for zeros in range(5):
            for black in (False, True):
                remaining = 7 - zeros
                pixels = '1' + '0' * zeros + ('1' if black else '0') * remaining
                exit_code = '0' * (6 + zeros) + '1' + ('1' if black else '0')
                after = '1' if k < 0 else (black_runs if black else white_runs)[remaining]
                bits = (enter2 if k < 0 else enter1) + '1' + exit_code + after + (eofb if k < 0 else '')
                data = packed(bits)
                bitmap = packed(''.join('0' if pixel == '1' else '1' for pixel in pixels))
                cases.append({'name': f'uncompressed-g{4 if k < 0 else 3}-exit-{zeros}-{int(black)}', 'width': 8, 'height': 1, 'params': {'K': k, 'Columns': 8, 'Rows': 1, 'EndOfBlock': k < 0, 'BlackIs1': False}, 'encoded': base64.b64encode(data).decode(), 'bitmap': base64.b64encode(bitmap).decode(), 'encodedSha256': hashlib.sha256(data).hexdigest(), 'origin': 'ITU-T T.4 table 5 / T.6 table 4 exhaustive exit codes'})
    manifest = {'license': 'MIT', 'encoder': f'Pillow {PIL.__version__} / libtiff {features.version("libtiff")}', 'cases': cases}
    (DEST / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')


def pdf(case, decoded=None):
    from pypdf import PdfWriter
    from pypdf.generic import DictionaryObject as D, NameObject as N, NumberObject as I, BooleanObject as B, DecodedStreamObject, EncodedStreamObject
    writer = PdfWriter()
    page = writer.add_blank_page(width=case['width'], height=case['height'])
    image = EncodedStreamObject()
    image._data = base64.b64decode(case['encoded']) if decoded is None else decoded
    image.update({N('/Type'): N('/XObject'), N('/Subtype'): N('/Image'), N('/Width'): I(case['width']), N('/Height'): I(case['height']), N('/BitsPerComponent'): I(1), N('/ColorSpace'): N('/DeviceGray'), N('/Filter'): N('/CCITTFaxDecode'), N('/DecodeParms'): D({N('/' + key): B(value) if isinstance(value, bool) else I(value) for key, value in case['params'].items()})})
    if decoded is not None:
        del image[N('/Filter')]
        del image[N('/DecodeParms')]
    page[N('/Resources')] = D({N('/XObject'): D({N('/Fax'): writer._add_object(image)})})
    contents = DecodedStreamObject()
    contents.set_data(f'q {case["width"]} 0 0 {case["height"]} 0 0 cm /Fax Do Q'.encode())
    page[N('/Contents')] = writer._add_object(contents)
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--refresh', action='store_true')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    DEST.mkdir(parents=True, exist_ok=True)
    if args.refresh:
        refresh()
    manifest = json.loads((DEST / 'manifest.json').read_text())
    for case in manifest['cases']:
        data = pdf(case)
        path = DEST / (case['name'] + '.pdf')
        if args.check:
            assert path.read_bytes() == data, path
        else:
            path.write_bytes(data)
    print(f"Verified {len(manifest['cases'])} fax PDFs" if args.check else f"Built {len(manifest['cases'])} fax PDFs")


if __name__ == '__main__':
    main()
