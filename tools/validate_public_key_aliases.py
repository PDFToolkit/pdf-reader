#!/usr/bin/env python3
"""Independent PubSec metadata, physical reference alias and implicit EFF audit.

Uses original pyHanko CMS packets. cryptography constructs/validates encrypted
page/Form metadata; pyHanko's strict raw parser resolves the physical chains and
its unchanged security handler authenticates the terminal Encrypt dictionary.
Also appends implicit EFF streams with distinct AES keys and checks deferred
wrong-credential failures. No oracle implementation is replaced or monkeypatched.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

from asn1crypto import cms, x509 as asn1_x509
from cryptography.hazmat.primitives import padding, serialization
from cryptography.hazmat.primitives.asymmetric import padding as rsa_padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.crypt.api import AuthStatus, CryptFilterConfiguration, SecurityHandler, SecurityHandlerVersion
from pyhanko.pdf_utils.crypt.permissions import PubKeyPermissions
from pyhanko.pdf_utils.crypt.pubkey import (
    PubKeyAdbeSubFilter, PubKeyAESCryptFilter, PubKeySecurityHandler,
    RecipientEncryptionPolicy, SimpleEnvelopeKeyDecrypter,
)
from pyhanko.pdf_utils.reader import PdfFileReader

from generate_public_key_fixtures import base_writer, generate as generate_sources

ROOT = Path(__file__).resolve().parents[1]

VALUES = {
    'Metadata': b'<catalog>public PubSec metadata</catalog>',
    'FixturePageMetadata': b'<page>private PubSec metadata</page>',
    'FixtureObjectMetadata': b'<form>private PubSec metadata</form>',
}
IDENTITIES = {'Metadata': 5, 'FixturePageMetadata': 6, 'FixtureObjectMetadata': 7}


def encoded(value):
    out = io.BytesIO()
    value.write_to_stream(out, None)
    return out.getvalue()


def resolve(reader, value):
    seen = set()
    terminal = None
    while isinstance(value, generic.IndirectObject):
        terminal = value.idnum, value.generation
        assert terminal not in seen and len(seen) < 64
        seen.add(terminal)
        value = reader.get_object(value.reference, never_decrypt=True)
    return value, terminal, len(seen)


def cms_payload(packet, key):
    envelope = cms.ContentInfo.load(packet, strict=True)['content']
    recipient, = envelope['recipient_infos']
    assert recipient.chosen['key_encryption_algorithm']['algorithm'].native == 'rsaes_pkcs1v15'
    aes = key.decrypt(recipient.chosen['encrypted_key'].native, rsa_padding.PKCS1v15())
    encrypted = envelope['encrypted_content_info']
    params = encrypted['content_encryption_algorithm']
    assert params['algorithm'].native == 'aes256_cbc'
    operation = Cipher(algorithms.AES(aes), modes.CBC(params['parameters'].native)).decryptor()
    padded = operation.update(encrypted['encrypted_content'].native) + operation.finalize()
    unpadder = padding.PKCS7(128).unpadder()
    return unpadder.update(padded) + unpadder.finalize()


def object_key(key, number, generation=0):
    if len(key) == 32:
        return key
    return hashlib.md5(key + number.to_bytes(3, 'little') + generation.to_bytes(2, 'little') + b'sAlT').digest()[:16]


def encrypt(key, number, plaintext, generation=0):
    iv = os.urandom(16)
    padder = padding.PKCS7(128).padder()
    padded = padder.update(plaintext) + padder.finalize()
    cipher = Cipher(algorithms.AES(object_key(key, number, generation)), modes.CBC(iv)).encryptor()
    return iv + cipher.update(padded) + cipher.finalize()


def decrypt(key, number, ciphertext, generation=0):
    cipher = Cipher(algorithms.AES(object_key(key, number, generation)), modes.CBC(ciphertext[:16])).decryptor()
    padded = cipher.update(ciphertext[16:]) + cipher.finalize()
    unpadder = padding.PKCS7(128).unpadder()
    return unpadder.update(padded) + unpadder.finalize()


def stream(data, extra=b''):
    return b'<< /Length ' + str(len(data)).encode() + b' ' + extra + b' >>\nstream\n' + data + b'\nendstream'


def build(encryption, key):
    catalog = b'<< /Type /Catalog /Pages 2 0 R /Metadata 5 0 R /FixturePageMetadata 6 0 R /FixtureObjectMetadata 7 0 R /FixturePage 3 0 R /FixtureForm 8 0 R >>'
    objects = {
        1: catalog,
        2: b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3: b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << /XObject << /Fm 8 0 R >> >> /Contents 4 0 R /Metadata 6 0 R >>',
        4: stream(encrypt(key, 4, b'q Q\n')),
        5: stream(VALUES['Metadata'], b'/Type /Metadata /Subtype /XML'),
        6: stream(encrypt(key, 6, VALUES['FixturePageMetadata']), b'/Type /Metadata /Subtype /XML'),
        7: stream(encrypt(key, 7, VALUES['FixtureObjectMetadata']), b'/Type /Metadata /Subtype /XML'),
        8: stream(encrypt(key, 8, b'q Q\n'), b'/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Resources << >> /Metadata 7 0 R'),
        9: encoded(encryption),
    }
    data = bytearray(b'%PDF-2.0\n%\xe2\xe3\xcf\xd3\n')
    offsets = {}
    for number, body in objects.items():
        offsets[number] = len(data)
        data.extend(f'{number} 0 obj\n'.encode() + body + b'\nendobj\n')
    start = len(data)
    data.extend(b'xref\n0 10\n0000000000 65535 f \n')
    for number in range(1, 10):
        data.extend(f'{offsets[number]:010d} 00000 n \n'.encode())
    data.extend(f'trailer << /Size 10 /Root 1 0 R /Encrypt 9 0 R >>\nstartxref\n{start}\n%%EOF\n'.encode())
    return bytes(data)


def append_aliases(data):
    previous = re.search(rb'startxref\s+(\d+)\s+%%EOF\s*\Z', data)
    assert previous
    objects = {
        1: b'<< /Type /Catalog /Pages 2 0 R /Metadata 14 0 R /FixturePageMetadata 16 0 R /FixtureObjectMetadata 18 0 R /FixturePage 3 0 R /FixtureForm 8 0 R >>',
        10: b'11 0 R', 11: b'9 0 R',
        12: b'13 0 R', 13: b'1 0 R',
        14: b'15 0 R', 15: b'5 0 R',
        16: b'17 0 R', 17: b'6 0 R',
        18: b'19 0 R', 19: b'7 0 R',
    }
    result = bytearray(data)
    offsets = {}
    for number, body in objects.items():
        offsets[number] = len(result)
        result.extend(f'{number} 0 obj\n'.encode() + body + b'\nendobj\n')
    start = len(result)
    result.extend(b'xref\n')
    for number, offset in offsets.items():
        result.extend(f'{number} 1\n{offset:010d} 00000 n \n'.encode())
    result.extend(f'trailer << /Size 20 /Root 12 0 R /Encrypt 10 0 R /Prev {int(previous[1])} >>\nstartxref\n{start}\n%%EOF\n'.encode())
    return bytes(result)


def independent(data, credential, private_key, source_packets):
    reader = PdfFileReader(io.BytesIO(data), strict=True)
    encryption, encryption_id, encryption_hops = resolve(reader, reader.trailer.raw_get('/Encrypt'))
    handler = SecurityHandler.build(encryption)
    assert handler.authenticate(credential).status == AuthStatus.USER
    crypt_filter = handler.get_stream_filter()
    packets = [item.dump() for item in crypt_filter.recipients]
    assert packets == source_packets
    payload = cms_payload(packets[1], private_key)
    assert len(payload) == 24 and not crypt_filter.encrypt_metadata
    digest = 'sha256' if len(crypt_filter.shared_key) == 32 else 'sha1'
    key = hashlib.new(digest, payload[:20] + b''.join(packets) + b'\xff' * 4).digest()[:len(crypt_filter.shared_key)]
    assert key == crypt_filter.shared_key
    catalog, root_id, root_hops = resolve(reader, reader.trailer.raw_get('/Root'))
    _, metadata_id, _ = resolve(reader, catalog.raw_get('/Metadata'))
    actual, cipher_hashes, hops = {}, {}, {}
    for name, expected in VALUES.items():
        obj, terminal, count = resolve(reader, catalog.raw_get('/' + name))
        assert terminal == (IDENTITIES[name], 0)
        assert isinstance(obj, generic.StreamObject)
        raw = obj.encoded_data
        clear = raw if terminal == metadata_id else decrypt(key, terminal[0], raw)
        assert clear == expected
        assert (raw == expected) == (name == 'Metadata')
        actual[name] = base64.b64encode(clear).decode()
        cipher_hashes[name] = hashlib.sha256(raw).hexdigest()
        hops[name] = count
    page, _, _ = resolve(reader, catalog.raw_get('/FixturePage'))
    form, _, _ = resolve(reader, catalog.raw_get('/FixtureForm'))
    assert resolve(reader, page.raw_get('/Metadata'))[1] == (6, 0)
    assert resolve(reader, form.raw_get('/Metadata'))[1] == (7, 0)
    assert encryption_id == (9, 0) and root_id == (1, 0)
    actual['PageMetadata'] = actual['FixturePageMetadata']
    actual['FormMetadata'] = actual['FixtureObjectMetadata']
    actual['recipientPacketsSha256'] = [hashlib.sha256(packet).hexdigest() for packet in packets]
    return actual, {'terminalEncryption': encryption_id, 'terminalRoot': root_id,
        'terminalMetadata': metadata_id, 'encryptionHops': encryption_hops, 'rootHops': root_hops,
        'metadataHops': hops, 'sourceStreamSha256': cipher_hashes}


def generate(directory, source_directory):
    directory.mkdir(parents=True, exist_ok=True)
    for name in ('recipient.der', 'recipient-key.pem', 'attachment.der', 'attachment-key.pem', 'wrong.der', 'wrong-key.pem'):
        if (source_directory / name).resolve() != (directory / name).resolve():
            shutil.copyfile(source_directory / name, directory / name)
    for name in ('recipient', 'attachment', 'wrong'):
        (directory / (name + '-key.pem')).chmod(0o600)
    credential = SimpleEnvelopeKeyDecrypter.load(str(directory / 'recipient-key.pem'), str(directory / 'recipient.der'))
    private_key = serialization.load_pem_private_key((directory / 'recipient-key.pem').read_bytes(), None)
    records = []
    for mode in ('aes128', 'aes256'):
        source = (source_directory / f's5-{mode}-clear-metadata.pdf').read_bytes()
        original = PdfFileReader(io.BytesIO(source), strict=True)
        assert original.decrypt_pubkey(credential).status == AuthStatus.USER
        handler = original.security_handler
        encryption = original.trailer['/Encrypt']
        packets = [item.dump() for item in handler.get_stream_filter().recipients]
        base = build(encryption, handler.get_file_encryption_key())
        prior_hashes = None
        for alias in (False, True):
            data = append_aliases(base) if alias else base
            filename = f'pubsec-{mode}-metadata-' + ('aliases' if alias else 'direct') + '.pdf'
            (directory / filename).write_bytes(data)
            expected, facts = independent(data, credential, private_key, packets)
            if alias:
                assert facts['sourceStreamSha256'] == prior_hashes
                assert facts['encryptionHops'] == facts['rootHops'] == 3
                assert set(facts['metadataHops'].values()) == {3}
            else:
                prior_hashes = facts['sourceStreamSha256']
            records.append({'file': filename, 'sha256': hashlib.sha256(data).hexdigest(),
                'source_file': f's5-{mode}-clear-metadata.pdf', 'source_sha256': hashlib.sha256(source).hexdigest(),
                'expected': expected, 'facts': facts, 'aliases': alias})
    return records



def named_aes256_source(source_directory):
    """Create the AES-256 counterpart with ordinary, unchanged pyHanko filters."""
    certificates = {name: asn1_x509.Certificate.load((source_directory / (name + '.der')).read_bytes())
        for name in ('recipient', 'unmatched', 'attachment')}
    document = PubKeyAESCryptFilter(keylen=32, acts_as_default=True)
    attachment = PubKeyAESCryptFilter(keylen=32, acts_as_default=False)
    attachment.set_embedded_only()
    config = CryptFilterConfiguration({'/DocumentKey': document, '/AttachmentKey': attachment},
        default_stream_filter=generic.pdf_name('/DocumentKey'),
        default_string_filter=generic.pdf_name('/DocumentKey'),
        default_file_filter=generic.pdf_name('/AttachmentKey'))
    handler = PubKeySecurityHandler(SecurityHandlerVersion.AES256, PubKeyAdbeSubFilter.S5,
        32, crypt_filter_config=config)
    permissions = PubKeyPermissions.allow_everything() & ~PubKeyPermissions.ALLOW_ENCRYPTION_CHANGE
    policy = RecipientEncryptionPolicy()
    document.add_recipients([certificates['unmatched']], policy, permissions)
    document.add_recipients([certificates['recipient']], policy, permissions)
    attachment.add_recipients([certificates['attachment']], policy)
    writer = base_writer()
    writer.output_version = (2, 0)
    writer._assign_security_handler(handler)
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def append_implicit_attachment(source, key, plaintext):
    """Append a raw revision; all original PDF, CMS and ciphertext bytes survive."""
    reader = PdfFileReader(io.BytesIO(source), strict=True)
    number, generation = int(reader.trailer['/Size']), 0
    previous = re.search(rb'startxref\s+(\d+)\s+%%EOF\s*\Z', source)
    assert previous and '/AuthCode' not in reader.trailer
    ciphertext = encrypt(key, number, plaintext, generation)
    trailer = generic.DictionaryObject({generic.pdf_name(name): reader.trailer_view.raw_get(name)
        for name in ('/Root', '/Encrypt', '/ID')})
    trailer[generic.pdf_name('/Size')] = generic.NumberObject(number + 1)
    trailer[generic.pdf_name('/Prev')] = generic.NumberObject(int(previous[1]))
    trailer[generic.pdf_name('/FixtureImplicitAttachment')] = generic.IndirectObject(number, generation, reader)
    result = bytearray(source)
    offset = len(result)
    result.extend(f'{number} {generation} obj\n'.encode()
        + stream(ciphertext, b'/Type /EmbeddedFile') + b'\nendobj\n')
    start = len(result)
    result.extend(f'xref\n{number} 1\n{offset:010d} {generation:05d} n \ntrailer\n'.encode()
        + encoded(trailer) + f'\nstartxref\n{start}\n%%EOF\n'.encode())
    assert result[:len(source)] == source
    return bytes(result), (number, generation), ciphertext


def implicit_attachment_cases(directory, source_directory):
    credentials = {name: SimpleEnvelopeKeyDecrypter.load(str(directory / (name + '-key.pem')),
        str(directory / (name + '.der'))) for name in ('recipient', 'attachment', 'wrong')}
    private_keys = {name: serialization.load_pem_private_key((directory / (name + '-key.pem')).read_bytes(), None)
        for name in ('recipient', 'attachment')}
    plaintext = b'Implicit EFF recipient-specific attachment\x00\xff\n' + bytes(range(256))
    records = []
    for mode in ('aes128', 'aes256'):
        if mode == 'aes128':
            source = (source_directory / 's5-distinct-keys-aes128.pdf').read_bytes()
        else:
            source = named_aes256_source(source_directory)
        source_file = f'implicit-source-{mode}.pdf'
        (directory / source_file).write_bytes(source)
        original = PdfFileReader(io.BytesIO(source), strict=True)
        handler = original.security_handler
        document_filter = handler.get_stream_filter()
        attachment_filter = handler.get_embedded_file_filter()
        assert document_filter.authenticate(credentials['recipient']).status == AuthStatus.USER
        assert attachment_filter.authenticate(credentials['attachment']).status == AuthStatus.USER
        assert original.root['/FixtureLiteral'] == 'Public-key literal (test)'
        expected_length = 16 if mode == 'aes128' else 32
        derived_keys = []
        for crypt_filter, name, payload_size in [(document_filter, 'recipient', 24), (attachment_filter, 'attachment', 20)]:
            packets = [packet.dump() for packet in crypt_filter.recipients]
            payload = cms_payload(packets[1 if name == 'recipient' else 0], private_keys[name])
            assert len(payload) == payload_size
            digest = 'sha1' if expected_length == 16 else 'sha256'
            key = hashlib.new(digest, payload[:20] + b''.join(packets)).digest()[:expected_length]
            assert key == crypt_filter.shared_key and crypt_filter.encrypt_metadata
            derived_keys.append(key)
        document_key, attachment_key = derived_keys
        assert attachment_key != document_key
        data, identity, ciphertext = append_implicit_attachment(source, attachment_key, plaintext)
        independent = PdfFileReader(io.BytesIO(data), strict=True)
        fresh_handler = independent.security_handler
        assert fresh_handler.get_stream_filter().authenticate(credentials['recipient']).status == AuthStatus.USER
        fresh_attachment = fresh_handler.get_embedded_file_filter()
        assert fresh_attachment.authenticate(credentials['attachment']).status == AuthStatus.USER
        value, terminal, _ = resolve(independent, independent.trailer.raw_get('/FixtureImplicitAttachment'))
        assert terminal == identity and value['/Type'] == '/EmbeddedFile' and '/Filter' not in value
        assert value.encoded_data == ciphertext
        direct_key = object_key(attachment_key, *identity)
        assert direct_key == fresh_attachment.derive_object_key(*identity)
        assert fresh_attachment.decrypt(direct_key, ciphertext) == plaintext
        assert decrypt(attachment_key, identity[0], ciphertext, identity[1]) == plaintext
        wrong = PdfFileReader(io.BytesIO(data), strict=True)
        assert wrong.security_handler.get_stream_filter().authenticate(credentials['recipient']).status == AuthStatus.USER
        assert wrong.root['/FixtureLiteral'] == 'Public-key literal (test)'
        assert wrong.security_handler.get_embedded_file_filter().authenticate(credentials['wrong']).status == AuthStatus.FAILED
        filename = f'pubsec-{mode}-implicit-attachment.pdf'
        (directory / filename).write_bytes(data)
        records.append({
            'file': filename, 'sha256': hashlib.sha256(data).hexdigest(),
            'source_file': source_file, 'source_sha256': hashlib.sha256(source).hexdigest(),
            'expected': {'documentLiteral': 'Public-key literal (test)', 'attachment': base64.b64encode(plaintext).decode(),
                'wrongCredentialDocumentOpened': True, 'wrongCredentialAttachmentRefused': True},
            'facts': {'sourcePrefixBytes': len(source), 'sourcePrefixPreserved': data[:len(source)] == source,
                'attachmentIdentity': identity, 'distinctDocumentAndAttachmentKeys': True,
                'keyBytes': expected_length, 'explicitCryptFilter': False,
                'ciphertextSha256': hashlib.sha256(ciphertext).hexdigest()},
        })
    return records


def native_reports(php, bootstrap, directory, reporter, records):
    command = [php]
    if bootstrap:
        command += ['-d', 'auto_prepend_file=' + str(bootstrap.resolve())]
    command += [str(ROOT / 'tools' / reporter), str(directory)]
    command += [record['file'] for record in records]
    process = subprocess.run(command, cwd=ROOT, capture_output=True)
    assert process.returncode == 0, process.stdout.decode(errors='replace') + process.stderr.decode(errors='replace')
    reports = json.loads(process.stdout)
    assert len(reports) == len(records)
    for record, report in zip(records, reports):
        assert report == record['expected'], (record['file'], report, record['expected'])

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php', help='PHP executable for the native reader.')
    parser.add_argument('--bootstrap', type=Path, help='Development-only source override.')
    parser.add_argument('--work-dir', type=Path, help='Preserve generated cases and verification.json here.')
    parser.add_argument('--fixtures-dir', type=Path, help='Reuse an existing generate_public_key_fixtures.py directory.')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='reader-public-key-aliases-') as temporary:
        directory = (args.work_dir or Path(temporary)).resolve()
        directory.mkdir(parents=True, exist_ok=True)
        sources = args.fixtures_dir.resolve() if args.fixtures_dir else directory / 'sources'
        if args.fixtures_dir is None:
            generate_sources(sources)
        records = generate(directory, sources)
        implicit = implicit_attachment_cases(directory, sources)
        native_reports(args.php, args.bootstrap, directory, 'report-public-key-aliases.php', records)
        native_reports(args.php, args.bootstrap, directory, 'report-public-key-implicit-attachments.php', implicit)
        result = {
            'cases': len(records) + len(implicit),
            'metadata_alias_cases': len(records),
            'implicit_attachment_cases': len(implicit),
            'deferred_wrong_credentials_refused': len(implicit),
            'independent': 'strict pyHanko parser + unmodified PubSec handler + cryptography CMS/CBC',
            'native_reader': 'passed',
            'cases_detail': records + implicit,
        }
        (directory / 'verification.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
        summary = {key: value for key, value in result.items() if key != 'cases_detail'}
        if args.work_dir:
            summary['verification_file'] = str(directory / 'verification.json')
        print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
