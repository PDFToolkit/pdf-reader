"""Independent TS 32004 source-MAC interoperability, without PKI trust or network access.

pyHanko creates the PDFs and validates their MACs. cryptography independently
checks HKDF, AES key wrapping, HMAC and the exact source/signature digests.
The PHP reader must accept these files and reject the adversarial variants.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import logging
import os
from pathlib import Path
import re
import subprocess
import struct
import tempfile
from datetime import datetime, timezone, timedelta

from asn1crypto import cms, keys, x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, hmac, keywrap, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives.padding import PKCS7
from pypdf import PdfReader
from pypdf.generic import ByteStringObject, NameObject, NumberObject
from pyhanko.pdf_utils import generic, misc
from pyhanko.pdf_utils.crypt.api import AuthStatus, PdfMacStatus
from pyhanko.pdf_utils.crypt.pdfmac import PdfMacTokenHandler, add_standalone_mac, validate_pdf_mac
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.writer import PdfFileWriter, PageObject
from pyhanko.sign import signers
from pyhanko.sign.timestamps import DummyTimeStamper
from pyhanko_certvalidator.registry import SimpleCertificateStore

ROOT = Path(__file__).resolve().parents[1]
logging.getLogger('pyhanko.pdf_utils.reader').setLevel(logging.CRITICAL)
DIGESTS = ('sha256', 'sha384', 'sha512', 'sha3_256', 'sha3_384', 'sha3_512')
CONTENT = b'q 1 0 0 1 0 0 cm Q\n'


def writer(streams=False, compressed=False, use_gcm=False):
    result = PdfFileWriter(stream_xrefs=streams)
    content = result.add_object(generic.StreamObject(stream_data=CONTENT))
    result.insert_page(PageObject(content, (0, 0, 200, 200)))
    result.root[generic.pdf_name('/FixtureBinary')] = generic.ByteStringObject(bytes(range(256)))
    if compressed:
        container = result.prepare_object_stream()
        reference = result.add_object(generic.DictionaryObject({generic.pdf_name('/Value'): generic.TextStringObject('compressed authenticated value')}), obj_stream=container)
        result.root[generic.pdf_name('/FixtureCompressed')] = reference
    result.encrypt('owner', 'user', pdf_mac=True, use_gcm=use_gcm)
    return result


def signing_identity():
    private = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(x509.NameOID.COMMON_NAME, 'PDFToolkit untrusted MAC fixture')])
    start = datetime(2026, 1, 1, tzinfo=timezone.utc)
    certificate = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
        .public_key(private.public_key()).serial_number(x509.random_serial_number())
        .not_valid_before(start).not_valid_after(start + timedelta(days=3650))
        .sign(private, hashes.SHA256()))
    return signers.SimpleSigner(
        signing_cert=asn1_x509.Certificate.load(certificate.public_bytes(serialization.Encoding.DER)),
        signing_key=keys.PrivateKeyInfo.load(private.private_bytes(serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())),
        cert_registry=SimpleCertificateStore(),
    )


def historical_r5():
    """Independent R5 credentials from qpdf; cryptography emits the new MAC policy.

    pyHanko intentionally does not authenticate R5, so this producer preserves
    the checked-in qpdf credential entries and uses pypdf to recover their key.
    No security-handler or oracle implementation is monkeypatched.
    """
    seed = PdfReader(ROOT / 'tests/Fixtures/encryption/qpdf-r5-metadata-encrypted.pdf', strict=True)
    assert seed.decrypt('user') == 1
    file_key = seed._encryption._key
    salt = os.urandom(32)
    encryption = seed.trailer['/Encrypt']
    encryption[NameObject('/P')] = NumberObject(-4100)
    flags = struct.pack('<i', -4100) + b'\xff' * 4 + b'Tadb' + os.urandom(4)
    operation = Cipher(algorithms.AES(file_key), modes.ECB()).encryptor()
    encryption[NameObject('/Perms')] = ByteStringObject(operation.update(flags) + operation.finalize())
    encryption[NameObject('/KDFSalt')] = ByteStringObject(salt)
    encoded = io.BytesIO()
    encryption.write_to_stream(encoded)

    def encrypt(value):
        iv = os.urandom(16)
        padding = PKCS7(128).padder()
        padded = padding.update(value) + padding.finalize()
        operation = Cipher(algorithms.AES(file_key), modes.CBC(iv)).encryptor()
        return iv + operation.update(padded) + operation.finalize()

    content = encrypt(CONTENT)
    objects = {
        1: b'<< /Type /Catalog /Pages 2 0 R /FixtureBinary <' + encrypt(bytes(range(256))).hex().encode() + b'> >>',
        2: b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3: b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R >>',
        4: b'<< /Length ' + str(len(content)).encode() + b' >>\nstream\n' + content + b'\nendstream',
        5: encoded.getvalue(),
    }
    data = bytearray(b'%PDF-2.0\n%\xe2\xe3\xcf\xd3\n')
    offsets = {}
    for number, value in objects.items():
        offsets[number] = len(data)
        data.extend(f'{number} 0 obj\n'.encode() + value + b'\nendobj\n')
    xref = len(data)
    data.extend(b'xref\n0 6\n0000000000 65535 f \n')
    for number in objects:
        data.extend(f'{offsets[number]:010d} 00000 n \n'.encode())
    handler = PdfMacTokenHandler.from_key_mat(file_key, salt, 'sha256')
    token_size = handler.determine_token_size(False)
    data.extend(b'trailer\n<< /Size 6 /Root 1 0 R /Encrypt 5 0 R /ID [<00112233445566778899aabbccddeeff><00112233445566778899aabbccddeeff>] /AuthCode << /MACLocation /Standalone /MAC ')
    first = len(data)
    data.extend(b'<' + b'0' * (token_size * 2) + b'>')
    second = len(data)
    data.extend(b' /ByteRange [0000000000 0000000000 0000000000 0000000000] >> >>\n')
    data.extend(f'startxref\n{xref}\n%%EOF\n'.encode())
    replacement = f'[0000000000 {first:010d} {second:010d} {len(data) - second:010d}]'.encode()
    data = data.replace(b'[0000000000 0000000000 0000000000 0000000000]', replacement)
    token = handler.build_pdfmac_token(document_digest=hashlib.sha256(data[:first] + data[second:]).digest(), signature_digest=None).dump()
    assert len(token) == token_size
    data[first:second] = b'<' + token.hex().encode() + b'>'
    return bytes(data)


def generate(directory):
    directory.mkdir(parents=True, exist_ok=True)
    files = []
    for digest in DIGESTS:
        output = io.BytesIO()
        add_standalone_mac(writer(), md_algorithm=digest, output=output)
        files.append((f'pyhanko-standalone-{digest}.pdf', output.getvalue(), 'Standalone'))
    for compressed in (False, True):
        output = io.BytesIO()
        add_standalone_mac(writer(True, compressed), output=output)
        files.append((f'pyhanko-stream-{int(compressed)}.pdf', output.getvalue(), 'Standalone'))
    for streams in (False, True):
        output = io.BytesIO()
        add_standalone_mac(writer(streams, compressed=streams, use_gcm=True), output=output)
        files.append((f'pyhanko-r7-{int(streams)}.pdf', output.getvalue(), 'Standalone'))
    initial = files[0][1]
    incremental = IncrementalPdfFileWriter(io.BytesIO(initial), strict=True)
    incremental.encrypt('owner')
    incremental.root[generic.pdf_name('/FixtureIncremental')] = incremental.add_object(generic.TextStringObject('latest authenticated revision'))
    incremental.update_root()
    output = io.BytesIO()
    incremental.write(output)
    files.append(('pyhanko-incremental.pdf', output.getvalue(), 'Standalone'))
    signer = signing_identity()
    for streams in (False, True):
        output = io.BytesIO()
        signers.PdfSigner(signers.PdfSignatureMetadata(field_name='approval', md_algorithm='sha256'), signer=signer).sign_pdf(writer(streams), output=output, bytes_reserved=16384)
        files.append((f'pyhanko-attached-{int(streams)}.pdf', output.getvalue(), 'AttachedToSig'))
    timestamped = IncrementalPdfFileWriter(io.BytesIO(initial), strict=True)
    timestamped.encrypt('owner')
    timestamped.root[generic.pdf_name('/FixtureTimestamp')] = timestamped.add_object(generic.NumberObject(1))
    timestamped.update_root()
    output = io.BytesIO()
    tsa = DummyTimeStamper(signer.signing_cert, signer.signing_key, fixed_dt=datetime(2026, 10, 15, tzinfo=timezone.utc))
    signers.PdfTimeStamper(tsa, field_name='timestamp').timestamp_pdf(timestamped, 'sha256', output=output, bytes_reserved=16384)
    files.append(('pyhanko-timestamp.pdf', output.getvalue(), 'AttachedToSig'))
    files.append(('qpdf-cryptography-r5.pdf', historical_r5(), 'Standalone'))
    records = []
    for filename, data, location in files:
        (directory / filename).write_bytes(data)
        records.append({'file': filename, 'sha256': hashlib.sha256(data).hexdigest(), 'location': location})
    manifest = {'generator': 'pyHanko 0.37.0 / cryptography 50.0.2', 'notice': 'Synthetic public user/owner passwords; ephemeral signing key discarded; certificates are not trusted.', 'files': records}
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    return records


def authenticated(data):
    reader = PdfFileReader(io.BytesIO(data), strict=True)
    result = reader.decrypt('user')
    assert result.status == AuthStatus.USER and result.mac_status == PdfMacStatus.SUCCESSFUL, result
    validate_pdf_mac(reader)
    return reader


def crypto_oracle(data):
    reader = authenticated(data)
    ac = reader.trailer_view['/AuthCode']
    signature = None
    if ac['/MACLocation'] == '/Standalone':
        payload = ac.raw_get('/MAC').original_bytes
        range_owner = ac
    else:
        range_owner = ac['/SigObjRef']
        signed = cms.ContentInfo.load(range_owner.raw_get('/Contents', decrypt=generic.EncryptedObjAccess.RAW).original_bytes)['content']
        signer, = signed['signer_infos']
        signature = signer['signature'].native
        attribute, = [value for value in signer['unsigned_attrs'] if value['type'].dotted == '1.0.32004.1.2']
        token, = attribute['values']
        payload = token.dump()
    first, length, second, tail = map(int, range_owner['/ByteRange'])
    assert first == 0 and second + tail == len(data)
    verify_crypto_token(payload, data[:length] + data[second:], signature, reader.security_handler.get_file_encryption_key(), reader.security_handler.get_kdf_salt())
    return reader


def verify_crypto_token(payload, covered, signature, file_key, salt):
    envelope = cms.ContentInfo.load(payload, strict=True)['content']
    raw = envelope['encap_content_info']['content'].contents
    integrity = envelope['encap_content_info']['content'].parsed
    digest_name = envelope['digest_algorithm']['algorithm'].native
    assert integrity['data_digest'].native == hashlib.new(digest_name, covered).digest()
    if signature is not None:
        assert integrity['signature_digest'].native == hashlib.new(digest_name, signature).digest()
    kek = HKDF(algorithm=hashes.SHA256(), length=32, salt=salt, info=b'PDFMAC').derive(file_key)
    recipient, = envelope['recipient_infos']
    wrapped = recipient.chosen['encrypted_key'].native
    mac_key = keywrap.aes_key_unwrap(kek, wrapped)
    attributes = envelope['auth_attrs'].dump()
    authenticator = hmac.HMAC(mac_key, hashes.SHA256())
    authenticator.update(b'\x31' + attributes[1:])
    authenticator.verify(envelope['mac'].native)
    claims = {value['type'].native: value['values'][0].native for value in envelope['auth_attrs']}
    assert claims['message_digest'] == hashlib.new(digest_name, raw).digest()
    PdfMacTokenHandler.for_validation(file_encryption_key=file_key, kdf_salt=salt, auth_data=envelope).validate_pdfmac_token_cms(
        auth_data=envelope, document_digest=hashlib.new(digest_name, covered).digest(),
        signature_digest=None if signature is None else hashlib.new(digest_name, signature).digest())


def r5_oracle(data):
    reader = PdfReader(io.BytesIO(data), strict=True)
    assert reader.decrypt('user') == 1
    encryption = reader.trailer['/Encrypt']
    assert encryption['/R'] == 5 and encryption['/P'] == -4100
    ac = reader.trailer['/AuthCode']
    first, length, second, tail = map(int, ac['/ByteRange'])
    assert first == 0 and second + tail == len(data)
    verify_crypto_token(ac['/MAC'].original_bytes, data[:length] + data[second:], None, reader._encryption._key, encryption['/KDFSalt'].original_bytes)
    assert reader.pages[0].get_contents().get_data() == CONTENT


def invoke(php, bootstrap, paths):
    command = [php]
    if bootstrap:
        command += ['-d', f'auto_prepend_file={bootstrap}']
    command += [str(ROOT / 'tools/source_mac_report.php'), *map(str, paths)]
    process = subprocess.run(command, cwd=ROOT, capture_output=True)
    assert process.returncode == 0, process.stdout.decode(errors='replace') + process.stderr.decode(errors='replace')
    return json.loads(process.stdout)


def standalone_variant(source, build, *, whitespace=False, enlarged_gap=False, odd_nibble=False, padding=False):
    """Issue an authentic MAC over deliberately invalid PDF/CMS framing or semantics.

    These test producers know the fixture key. Recomputing the HMAC prevents
    malformed-input tests from passing merely because their hash became stale.
    """
    reader = authenticated(source)
    ac = reader.trailer_view['/AuthCode']
    _, first, second, _ = map(int, ac['/ByteRange'])
    handler = PdfMacTokenHandler.from_key_mat(reader.security_handler.get_file_encryption_key(), reader.security_handler.get_kdf_salt(), 'sha256')
    token_size = len(build(handler, bytes(32)).dump())

    def literal(token):
        encoded = token.hex().encode()
        if whitespace:
            encoded = encoded[:4] + b' ' + encoded[4:]
        if odd_nibble:
            assert encoded[-1:] == b'0'
            encoded = encoded[:-1]
        if padding:
            encoded += b'00'
        return b'<' + encoded + b'>'

    placeholder = literal(bytes(token_size))
    gap = placeholder + (b'\n' if enlarged_gap else b'')
    output = source[:first] + gap + source[second:]
    end = first + len(gap)
    values = f'/ByteRange [0 {first} {end} {len(output) - end}]'.encode()

    def range_value(match):
        assert len(values) <= len(match[0])
        return values.ljust(len(match[0]), b' ')

    output, count = re.subn(rb'/ByteRange\s*\[[^\]]+\][ \t]*', range_value, output)
    assert count == 1
    covered = output[:first] + output[end:]
    token = build(handler, hashlib.sha256(covered).digest()).dump()
    assert len(token) == token_size
    output = output[:first] + literal(token) + output[first + len(placeholder):]
    return output


def edit_attributes(handler, digest, mutation):
    token = handler.build_pdfmac_token(document_digest=digest, signature_digest=None)
    envelope = token['content']
    mutation(envelope)
    recipient, = envelope['recipient_infos']
    key = keywrap.aes_key_unwrap(handler.mac_kek, recipient.chosen['encrypted_key'].native)
    attrs = envelope['auth_attrs'].dump()
    authenticator = hmac.HMAC(key, hashes.SHA256())
    authenticator.update(b'\x31' + attrs[1:])
    envelope['mac'] = authenticator.finalize()
    return token


def attached_variant(source, mutation):
    reader = authenticated(source)
    signature = reader.trailer_view['/AuthCode']['/SigObjRef']
    _, first, second, _ = map(int, signature['/ByteRange'])
    token = cms.ContentInfo.load(signature.raw_get('/Contents', decrypt=generic.EncryptedObjAccess.RAW).original_bytes)
    signer, = token['content']['signer_infos']
    mutation(reader, signer)
    payload = token.dump().hex().encode()
    allocated = second - first - 2
    assert len(payload) <= allocated
    return source[:first] + b'<' + payload.ljust(allocated, b'0') + b'>' + source[second:]


def inherited_mac_revision(source):
    """A syntactically complete new revision cannot inherit an old MAC as proof."""
    reader = authenticated(source)
    trailer = reader.trailer_view
    root = trailer.raw_get('/Root')
    encryption = trailer.raw_get('/Encrypt')
    previous = int(re.findall(rb'startxref\s+([0-9]+)', source)[-1])
    ids = io.BytesIO()
    trailer['/ID'].write_to_stream(ids, None)
    offset = len(source) + 1
    appendix = (f'\nxref\n0 1\n0000000000 65535 f \ntrailer\n<< /Size {int(trailer["/Size"])} '
        f'/Root {root.idnum} {root.generation} R /Encrypt {encryption.idnum} {encryption.generation} R '
        f'/Prev {previous} /ID ').encode() + ids.getvalue() + f' >>\nstartxref\n{offset}\n%%EOF\n'.encode()
    return source + appendix


def adversarial(directory):
    source = (directory / 'pyhanko-standalone-sha256.pdf').read_bytes()

    def good(handler, digest):
        return handler.build_pdfmac_token(document_digest=digest, signature_digest=None)

    def final_zero(handler, digest):
        for _ in range(1024):
            token = good(handler, digest)
            if token.dump()[-1] & 15 == 0:
                return token
        raise AssertionError('Could not produce the requested public test MAC nibble')

    def duplicate(envelope):
        attrs = envelope['auth_attrs']
        attrs.append(next(attr for attr in attrs if attr['type'].native == 'content_type'))

    def missing(envelope):
        envelope['auth_attrs'] = cms.CMSAttributes([attr for attr in envelope['auth_attrs'] if attr['type'].native != 'content_type'])

    def mismatched(envelope):
        attribute, = [attr for attr in envelope['auth_attrs'] if attr['type'].native == 'cms_algorithm_protection']
        attribute['values'][0]['digest_algorithm']['algorithm'] = 'sha384'

    def recipients(handler, digest):
        token = good(handler, digest)
        token['content']['recipient_infos'].append(token['content']['recipient_infos'][0])
        return token

    variants = {
        'gap-excludes-extra-byte': standalone_variant(source, good, enlarged_gap=True),
        'hex-whitespace': standalone_variant(source, good, whitespace=True),
        'hex-odd-final-nibble': standalone_variant(source, final_zero, odd_nibble=True),
        'standalone-cms-padding': standalone_variant(source, good, padding=True),
        'wrong-document-binding': standalone_variant(source, lambda handler, digest: good(handler, bytes(32))),
        'duplicate-content-type': standalone_variant(source, lambda handler, digest: edit_attributes(handler, digest, duplicate)),
        'missing-content-type': standalone_variant(source, lambda handler, digest: edit_attributes(handler, digest, missing)),
        'mismatched-algorithm-protection': standalone_variant(source, lambda handler, digest: edit_attributes(handler, digest, mismatched)),
        'two-recipients': standalone_variant(source, recipients),
    }
    signed = (directory / 'pyhanko-attached-0.pdf').read_bytes()

    def remove(reader, signer):
        signer['unsigned_attrs'] = cms.CMSAttributes([])

    def duplicate_attached(reader, signer):
        signer['unsigned_attrs'].append(signer['unsigned_attrs'][0])

    def signature_changed(reader, signer):
        signature = signer['signature'].native
        signer['signature'] = bytes([signature[0] ^ 1]) + signature[1:]

    variants['attached-missing-mac'] = attached_variant(signed, remove)
    variants['attached-duplicate-mac'] = attached_variant(signed, duplicate_attached)
    variants['attached-wrong-signature-binding'] = attached_variant(signed, signature_changed)
    variants['inherited-standalone-mac'] = inherited_mac_revision(source)
    variants['inherited-attached-mac'] = inherited_mac_revision(signed)
    paths = []
    for name, data in variants.items():
        # The independent validator must reject each negative fixture too.
        failed = False
        try:
            reader = PdfFileReader(io.BytesIO(data), strict=True)
            result = reader.decrypt('user')
            if result.mac_status == PdfMacStatus.FAILED:
                failed = True
            else:
                validate_pdf_mac(reader)
        except (ValueError, misc.PdfReadError):
            failed = True
        assert failed, f'Independent PDF MAC validator accepted {name}'
        path = directory / f'invalid-{name}.pdf'
        path.write_bytes(data)
        paths.append(path)
    return paths


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--bootstrap', help='Development-only source override, never a runtime dependency.')
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory() as temporary:
        directory = args.work_dir.resolve() if args.work_dir else Path(temporary)
        records = generate(directory)
        paths = []
        for record in records:
            path = directory / record['file']
            if record['file'] == 'qpdf-cryptography-r5.pdf':
                r5_oracle(path.read_bytes())
            else:
                crypto_oracle(path.read_bytes())
            if not record['file'].startswith('pyhanko-r7-'):
                # qpdf 12.4.2 does not implement AESV4; pyHanko and cryptography
                # remain the unchanged, independent R7 cryptographic oracles.
                process = subprocess.run([args.qpdf, '--password=user', '--check', str(path)], capture_output=True)
                assert process.returncode == 0, process.stderr.decode(errors='replace')
            paths.append(path)
        report = invoke(args.php, args.bootstrap, paths)
        assert len(report) == len(records)
        for result, record in zip(report, records):
            assert result['ok'] and result['mac'] == record['location'] and result['pages'] == 1, result
            assert result['binary'] == bytes(range(256)).hex() and result['contents'] == CONTENT.decode(), result
            if record['file'] in ('pyhanko-stream-1.pdf', 'pyhanko-r7-1.pdf'):
                assert result['compressed'] == 'compressed authenticated value', result
        negative = adversarial(directory)
        rejected = invoke(args.php, args.bootstrap, negative)
        assert len(rejected) == len(negative) and all(not result['ok'] for result in rejected), rejected
        print(json.dumps({'independent_sources': len(records), 'adversarial_sources_refused': len(negative), 'both_mac_modes': True, 'digest_algorithms': list(DIGESTS), 'cryptography_and_pyhanko': 'passed', 'native_reader': 'passed'}, indent=2))


if __name__ == '__main__':
    main()
