#!/usr/bin/env python3
"""Verify independently generated Adobe.PubSec files with pyHanko, cryptography and PHP.

The producer is independent from PDFToolkit. CMS payloads, permission byte order,
recipient-array key derivation and MAC binding are checked before the native read.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import logging
import re
from pathlib import Path
import subprocess
import tempfile

from asn1crypto import cms
from cryptography.hazmat.primitives import padding, serialization
from cryptography.hazmat.primitives.keywrap import InvalidUnwrap
from cryptography.hazmat.primitives.asymmetric import padding as rsa_padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.crypt.api import AuthStatus, PdfMacStatus
from pyhanko.pdf_utils.crypt.pdfmac import PdfMacTokenHandler, validate_pdf_mac
from pyhanko.pdf_utils.crypt.pubkey import SimpleEnvelopeKeyDecrypter
from pyhanko.pdf_utils.reader import PdfFileReader

from generate_public_key_fixtures import BINARY, COMPRESSED, CONTENT, INCREMENTAL, generate
from validate_source_mac import verify_crypto_token

ROOT = Path(__file__).resolve().parents[1]
logging.getLogger('pyhanko.pdf_utils.reader').setLevel(logging.CRITICAL)


def decrypt_cms(packet, key):
    envelope = cms.ContentInfo.load(packet, strict=True)['content']
    recipient, = envelope['recipient_infos']
    recipient = recipient.chosen
    assert recipient['key_encryption_algorithm']['algorithm'].native == 'rsaes_pkcs1v15'
    envelope_key = key.decrypt(recipient['encrypted_key'].native, rsa_padding.PKCS1v15())
    encrypted = envelope['encrypted_content_info']
    algorithm = encrypted['content_encryption_algorithm']
    assert algorithm['algorithm'].native == 'aes256_cbc'
    decoder = Cipher(algorithms.AES(envelope_key), modes.CBC(algorithm['parameters'].native)).decryptor()
    padded = decoder.update(encrypted['encrypted_content'].native) + decoder.finalize()
    unpadder = padding.PKCS7(128).unpadder()
    return unpadder.update(padded) + unpadder.finalize()


def check_filter(handler, crypt_filter, private_keys, permissions, *, document):
    packets = [recipient.dump() for recipient in crypt_filter.recipients]
    assert len(packets) == (2 if document else 1)
    identities = ('unmatched', 'recipient') if document else ('attachment',)
    payloads = [decrypt_cms(packet, private_keys[name]) for packet, name in zip(packets, identities)]
    assert all(len(payload) == (24 if document else 20) for payload in payloads)
    assert all(payload[:20] == payloads[0][:20] for payload in payloads)
    if document:
        assert all(int.from_bytes(payload[20:], 'big') == permissions for payload in payloads)
    suffix = b'\xff' * 4 if document and not crypt_filter.encrypt_metadata else b''
    digest = 'sha256' if handler.version.value >= 5 else 'sha1'
    derived = hashlib.new(digest, payloads[0][:20] + b''.join(packets) + suffix).digest()[:crypt_filter.keylen]
    assert derived == crypt_filter.shared_key
    return derived


def check_mac(reader, data, explicit_key=None):
    if explicit_key is None:
        validate_pdf_mac(reader)
    ac = reader.trailer_view['/AuthCode']
    signature = None
    if ac['/MACLocation'] == '/Standalone':
        payload = ac.raw_get('/MAC').original_bytes
        owner = ac
    else:
        owner = ac['/SigObjRef']
        signed = cms.ContentInfo.load(owner.raw_get('/Contents', decrypt=generic.EncryptedObjAccess.RAW).original_bytes)['content']
        signer, = signed['signer_infos']
        signature = signer['signature'].native
        attribute, = [value for value in signer['unsigned_attrs'] if value['type'].dotted == '1.0.32004.1.2']
        token, = attribute['values']
        payload = token.dump()
    first, length, second, tail = map(int, owner['/ByteRange'])
    assert first == 0 and second + tail == len(data)
    handler = reader.security_handler
    verify_crypto_token(payload, data[:length] + data[second:], signature,
        explicit_key if explicit_key is not None else handler.get_file_encryption_key(), handler.get_kdf_salt())


def independent(directory, record, credentials, private_keys):
    data = (directory / record['file']).read_bytes()
    assert hashlib.sha256(data).hexdigest() == record['sha256']
    reader = PdfFileReader(io.BytesIO(data), strict=not record.get('hybrid', False))
    handler = reader.security_handler
    assert handler.get_name() == '/Adobe.PubSec'
    assert str(handler.subfilter.value).lstrip('/') == record['subFilter']
    if record.get('macDistinct'):
        assert handler.get_stream_filter().authenticate(credentials['recipient']).status == AuthStatus.USER
        default = handler.get_stream_filter('/DefaultCryptFilter')
        assert default.authenticate(credentials['recipient']).status == AuthStatus.USER
        default_key = check_filter(handler, default, private_keys, record['permissions'], document=True)
        check_mac(reader, data, explicit_key=default_key)
    elif record['named']:
        # EFF is deliberately a second identity. pyHanko's global authenticate
        # expects one identity for every default filter; use its public filter API.
        assert handler.get_stream_filter().authenticate(credentials['recipient']).status == AuthStatus.USER
        attached_filter = handler.get_embedded_file_filter()
        assert attached_filter.authenticate(credentials['attachment']).status == AuthStatus.USER
        attachment_key = check_filter(handler, attached_filter, private_keys, None, document=False)
    else:
        result = reader.decrypt_pubkey(credentials['recipient'])
        assert result.status == AuthStatus.USER
        if record['mac']:
            assert result.mac_status == PdfMacStatus.SUCCESSFUL
            check_mac(reader, data)
    document_key = check_filter(handler, handler.get_stream_filter(), private_keys, record['permissions'], document=True)
    if record.get('macDistinct'):
        assert default_key != document_key
    assert reader.root['/FixtureBinary'].original_bytes == BINARY
    assert reader.root['/FixtureLiteral'] == 'Public-key literal (test)'
    assert reader.root['/Pages']['/Kids'][0]['/Contents'].data == CONTENT
    if record['compressed']:
        assert reader.root['/FixtureCompressed']['/Value'] == COMPRESSED
    if record['incremental']:
        assert reader.root['/FixtureIncremental'] == INCREMENTAL
    if record['named']:
        assert attachment_key != document_key
        assert reader.root['/FixtureAttachment'].data == BINARY
        assert reader.root['/FixtureClear'].data == b'Explicit public-key clear stream'
        assert reader.trailer['/Encrypt']['/CF']['/AttachmentKey']['/AuthEvent'] == '/EFOpen'
    metadata = reader.root.get('/Metadata')
    return hashlib.sha256(metadata.get_object().data).hexdigest() if metadata else None


def wrong_default_key_mac(directory, record, credential):
    """A valid MAC under StmF is not proof under TS 32004 DefaultCryptFilter."""
    data = (directory / record['file']).read_bytes()
    reader = PdfFileReader(io.BytesIO(data))
    handler = reader.security_handler
    handler.get_stream_filter().authenticate(credential)
    default = handler.get_stream_filter('/DefaultCryptFilter')
    default.authenticate(credential)
    ac = reader.trailer_view['/AuthCode']
    _, first, second, _ = map(int, ac['/ByteRange'])
    # Token replacement stays inside the one excluded hex span, preserving the
    # document digest, source offsets and all original encrypted PDF payloads.
    wrong = PdfMacTokenHandler.from_key_mat(handler.get_file_encryption_key(), handler.get_kdf_salt(), 'sha256')
    token = wrong.build_pdfmac_token(document_digest=hashlib.sha256(data[:first] + data[second:]).digest(), signature_digest=None)
    replacement = b'<' + token.dump().hex().encode() + b'>'
    assert len(replacement) == second - first
    data = data[:first] + replacement + data[second:]
    control = PdfFileReader(io.BytesIO(data))
    assert control.decrypt_pubkey(credential).mac_status == PdfMacStatus.SUCCESSFUL
    try:
        check_mac(control, data, explicit_key=default.shared_key)
    except InvalidUnwrap:
        pass
    else:
        raise AssertionError('A MAC using the wrong default key was accepted')
    name = 'invalid-stmf-key-mac.pdf'
    (directory / name).write_bytes(data)
    return {'file': name}


def invoke(php, bootstrap, directory, records, identities='recipient,attachment'):
    command = [php]
    if bootstrap:
        command += ['-d', 'auto_prepend_file=' + str(bootstrap)]
    command += [str(ROOT / 'tools/report-public-key.php'), str(directory), identities]
    command += [record['file'] for record in records]
    result = subprocess.run(command, cwd=ROOT, capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--bootstrap', type=Path, help='Development-only source override.')
    parser.add_argument('--work-dir', type=Path)
    parser.add_argument('--fixtures-dir', type=Path, help='Validate an existing generated fixture directory.')
    parser.add_argument('--independent-only', action='store_true', help='Run the independent producers/oracles without PHP.')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='reader-public-key-') as temporary:
        directory = (args.fixtures_dir or args.work_dir or Path(temporary)).resolve()
        records = json.loads((directory / 'manifest.json').read_text())['files'] if args.fixtures_dir else generate(directory)
        credentials = {name: SimpleEnvelopeKeyDecrypter.load(str(directory / (name + '-key.pem')), str(directory / (name + '.der')))
            for name in ('recipient', 'attachment')}
        private_keys = {name: serialization.load_pem_private_key((directory / (name + '-key.pem')).read_bytes(), None)
            for name in ('recipient', 'unmatched', 'attachment')}
        expected_metadata = {record['file']: independent(directory, record, credentials, private_keys) for record in records}
        wrong = SimpleEnvelopeKeyDecrypter.load(str(directory / 'wrong-key.pem'), str(directory / 'wrong.der'))
        for record in records:
            reader = PdfFileReader(io.BytesIO((directory / record['file']).read_bytes()), strict=not record.get('hybrid', False))
            assert reader.decrypt_pubkey(wrong).status == AuthStatus.FAILED, record
        modified = []
        for record in records:
            if not record['mac']:
                continue
            data = (directory / record['file']).read_bytes()
            match = re.search(rb'/FixtureBinary\s*<([0-9a-fA-F]+)>', data)
            assert match, record
            position = match.start(1)
            data = data[:position] + (b'0' if data[position:position + 1] != b'0' else b'1') + data[position + 1:]
            reader = PdfFileReader(io.BytesIO(data))
            if record.get('macDistinct'):
                reader.security_handler.get_stream_filter().authenticate(credentials['recipient'])
                default = reader.security_handler.get_stream_filter('/DefaultCryptFilter')
                default.authenticate(credentials['recipient'])
                try:
                    check_mac(reader, data, explicit_key=default.shared_key)
                except (AssertionError, ValueError):
                    pass
                else:
                    raise AssertionError('Tampered explicit-key MAC was accepted')
            else:
                assert reader.decrypt_pubkey(credentials['recipient']).mac_status == PdfMacStatus.FAILED
            name = 'tampered-' + record['file']
            (directory / name).write_bytes(data)
            modified.append({'file': name})
        wrong_mac_key = [wrong_default_key_mac(directory, record, credentials['recipient']) for record in records if record.get('macDistinct')]
        if args.independent_only:
            print(json.dumps({'independent_sources': len(records), 'CMS_permissions_keys_MAC': 'passed'}, indent=2))
            return
        report = invoke(args.php, args.bootstrap, directory, records)
        assert len(report) == len(records)
        for result, record in zip(report, records):
            assert result['ok'], result
            assert result['pages'] == 1 and result['binary'] == BINARY.hex() and result['contents'] == CONTENT.decode(), result
            assert result['literal'] == 'Public-key literal (test)' and result['mac'] == record['mac'], result
            # Interpret the independently recovered CMS word using the source
            # subfilter, rather than pyHanko's undifferentiated public flag enum.
            word = record['permissions']
            if record['subFilter'] == 'adbe.pkcs7.s3':
                broad = word & 0x3c
                word &= ~0xf00
                for original, modern in ((4, 2048), (8, 1024), (16, 512), (32, 256)):
                    if broad & original:
                        word |= modern
            expected_permissions = -4 if word & 2 else (word & 0xfffffffc) - 0x100000000
            assert result['permissions'] == expected_permissions, result
            assert result['subFilter'] == record['subFilter'], result
            document_facts = [fact for fact in result['filters'].values() if fact['permissions'] is not None]
            assert document_facts and all(fact['permissions'] == record['permissions'] and fact['recipientIndex'] == 1 for fact in document_facts), result
            assert all(fact['subFilter'] == record['subFilter'] and fact['effectiveMask'] & ~3 == expected_permissions for fact in document_facts), result
            assert result['metadataSha256'] == expected_metadata[record['file']], result
            if record['compressed']:
                assert result['compressed'] == COMPRESSED, result
            if record['incremental']:
                assert result['incremental'] == INCREMENTAL, result
            if record['named']:
                assert result['attachment'] == BINARY.hex() and result['clear'] == 'Explicit public-key clear stream', result
        rejected = invoke(args.php, args.bootstrap, directory, records, identities='wrong')
        assert len(rejected) == len(records) and all(not result['ok'] for result in rejected), rejected
        modified_report = invoke(args.php, args.bootstrap, directory, modified)
        assert len(modified_report) == len(modified) and all(not result['ok'] for result in modified_report), modified_report
        wrong_key_report = invoke(args.php, args.bootstrap, directory, wrong_mac_key)
        assert len(wrong_key_report) == len(wrong_mac_key) and all(not result['ok'] for result in wrong_key_report), wrong_key_report
        print(json.dumps({'independent_sources': len(records), 'wrong_identity_refusals': len(rejected),
            's3_R2_permission_cases': sum(record['subFilter'] == 'adbe.pkcs7.s3' for record in records),
            'wrong_default_MAC_key_refusals': len(wrong_key_report),
            'tampered_MAC_refusals': len(modified_report),
            'CMS_permissions_keys_MAC': 'passed', 'native_reader': 'passed'}, indent=2))


if __name__ == '__main__':
    main()
