#!/usr/bin/env python3
"""Generate independent, ephemeral Adobe.PubSec reader fixtures with pyHanko.

No PDFToolkit writer or crypto code participates. Keys/certificates below are
synthetic test identities, regenerated per run and never trusted certificates.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import re
from datetime import datetime, timedelta, timezone
from pathlib import Path

from asn1crypto import keys, x509 as asn1_x509
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils.crypt.api import CryptFilterConfiguration, SecurityHandlerVersion
from pyhanko.pdf_utils.crypt.permissions import PubKeyPermissions
from pyhanko.pdf_utils.crypt.pdfmac import PdfMacTokenHandler, StandalonePdfMac
from pyhanko.pdf_utils.crypt.pubkey import (
    PubKeyAdbeSubFilter, PubKeyAESCryptFilter, PubKeyRC4CryptFilter,
    PubKeySecurityHandler, RecipientEncryptionPolicy, SimpleEnvelopeKeyDecrypter,
)
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.writer import PdfFileWriter, PageObject
from pyhanko.sign import signers
from pyhanko_certvalidator.registry import SimpleCertificateStore

CONTENT = b'q 1 0 0 1 0 0 cm Q\n'
BINARY = bytes(range(256))
COMPRESSED = 'compressed public-key value'
INCREMENTAL = 'latest public-key revision'
POLICY = RecipientEncryptionPolicy()


def identity(directory, name):
    private = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(x509.NameOID.COMMON_NAME, 'PDFToolkit untrusted ' + name)])
    start = datetime(2026, 1, 1, tzinfo=timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
        .public_key(private.public_key()).serial_number(x509.random_serial_number())
        .not_valid_before(start).not_valid_after(start + timedelta(days=3650))
        .add_extension(x509.KeyUsage(True, False, True, False, False, False, False, None, None), critical=True)
        .sign(private, hashes.SHA256()))
    pem = private.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())
    der = cert.public_bytes(serialization.Encoding.DER)
    (directory / (name + '-key.pem')).write_bytes(pem)
    (directory / (name + '.der')).write_bytes(der)
    return asn1_x509.Certificate.load(der), keys.PrivateKeyInfo.load(private.private_bytes(
        serialization.Encoding.DER, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))


def base_writer(compressed=False):
    writer = PdfFileWriter(stream_xrefs=compressed)
    content = generic.StreamObject(stream_data=CONTENT)
    content.compress()
    writer.insert_page(PageObject(writer.add_object(content), (0, 0, 200, 200)))
    writer.root[generic.pdf_name('/FixtureBinary')] = generic.ByteStringObject(BINARY)
    writer.root[generic.pdf_name('/FixtureLiteral')] = generic.TextStringObject('Public-key literal (test)')
    if compressed:
        container = writer.prepare_object_stream()
        writer.root[generic.pdf_name('/FixtureCompressed')] = writer.add_object(
            generic.DictionaryObject({generic.pdf_name('/Value'): generic.TextStringObject(COMPRESSED)}), obj_stream=container)
    return writer


def ordinary_writer(mode, certs, owner=False, clear_metadata=False, compressed=False, mac=False, permissions=None):
    versions = {'s3-rc4': 1, 's4-rc4': 2, 's5-rc4': 4, 's5-aes128': 4, 's5-aes256': 5, 's5-gcm': 6}
    version = SecurityHandlerVersion(versions[mode])
    perms = (PubKeyPermissions.ALLOW_PRINTING | PubKeyPermissions.ALLOW_ASSISTIVE_TECHNOLOGY
        | PubKeyPermissions.TOLERATE_MISSING_PDF_MAC)
    if permissions is not None:
        perms = permissions | PubKeyPermissions.TOLERATE_MISSING_PDF_MAC
    if owner:
        perms |= PubKeyPermissions.ALLOW_ENCRYPTION_CHANGE
    if mac:
        perms &= ~PubKeyPermissions.TOLERATE_MISSING_PDF_MAC
    handler = PubKeySecurityHandler.build_from_certs([certs['unmatched'][0]],
        version=version, keylen_bytes=5 if mode == 's3-rc4' else 16,
        use_aes=mode != 's5-rc4', use_crypt_filters=mode.startswith('s5'),
        perms=perms, encrypt_metadata=not clear_metadata, pdf_mac=mac)
    # Every fixture requires skipping an unmatched first CMS recipient packet.
    handler.add_recipients([certs['recipient'][0]], perms=perms)
    if mode == 's3-rc4':
        handler.subfilter = PubKeyAdbeSubFilter.S3
    writer = base_writer(compressed)
    writer.output_version = (2, 0) if versions[mode] >= 5 else (1, 7)
    # pyHanko has no public attachment API for a pre-built custom handler.
    # This assigns its ordinary unmodified implementation, not a crypto override.
    writer._assign_security_handler(handler)
    return writer, perms.as_uint32()


def named_writer(certs, aes=True):
    constructor = PubKeyAESCryptFilter if aes else PubKeyRC4CryptFilter
    document = constructor(keylen=16, acts_as_default=True)
    attachment = constructor(keylen=16, acts_as_default=False)
    attachment.set_embedded_only()
    config = CryptFilterConfiguration({'/DocumentKey': document, '/AttachmentKey': attachment},
        default_stream_filter=generic.pdf_name('/DocumentKey'), default_string_filter=generic.pdf_name('/DocumentKey'),
        default_file_filter=generic.pdf_name('/AttachmentKey'))
    handler = PubKeySecurityHandler(SecurityHandlerVersion.RC4_OR_AES128, PubKeyAdbeSubFilter.S5,
        16, crypt_filter_config=config)
    perms = PubKeyPermissions.allow_everything() & ~PubKeyPermissions.ALLOW_ENCRYPTION_CHANGE
    document.add_recipients([certs['unmatched'][0]], POLICY, perms)
    document.add_recipients([certs['recipient'][0]], POLICY, perms)
    attachment.add_recipients([certs['attachment'][0]], POLICY)
    writer = base_writer()
    writer.output_version = (1, 7)
    writer._assign_security_handler(handler)
    stream = generic.StreamObject({generic.pdf_name('/Type'): generic.pdf_name('/EmbeddedFile')}, stream_data=BINARY)
    stream.compress()
    stream.add_crypt_filter(generic.pdf_name('/AttachmentKey'), handler=handler)
    writer.root[generic.pdf_name('/FixtureAttachment')] = writer.add_object(stream)
    clear = generic.StreamObject(stream_data=b'Explicit public-key clear stream')
    clear.add_crypt_filter(handler=handler)
    writer.root[generic.pdf_name('/FixtureClear')] = writer.add_object(clear)
    return writer, perms.as_uint32()


def distinct_mac_writer(certs):
    """A real DefaultCryptFilter key independent from the StmF/StrF key.

    pyHanko's global MAC accessor selects StmF. Use its unmodified low-level
    MAC/CMS components with the explicit TS 32004 DefaultCryptFilter key.
    """
    document = PubKeyAESCryptFilter(keylen=32, acts_as_default=True)
    mac_filter = PubKeyAESCryptFilter(keylen=32, acts_as_default=True)
    config = CryptFilterConfiguration({'/DocumentKey': document, '/DefaultCryptFilter': mac_filter},
        default_stream_filter=generic.pdf_name('/DocumentKey'), default_string_filter=generic.pdf_name('/DocumentKey'))
    handler = PubKeySecurityHandler(SecurityHandlerVersion.AES256, PubKeyAdbeSubFilter.S5,
        32, crypt_filter_config=config, kdf_salt=os.urandom(32))
    perms = (PubKeyPermissions.ALLOW_PRINTING | PubKeyPermissions.ALLOW_ASSISTIVE_TECHNOLOGY)
    for crypt_filter in (document, mac_filter):
        crypt_filter.add_recipients([certs['unmatched'][0]], POLICY, perms)
        crypt_filter.add_recipients([certs['recipient'][0]], POLICY, perms)
    writer = base_writer(compressed=True)
    writer.output_version = (2, 0)
    writer._assign_security_handler(handler)
    assert document.shared_key != mac_filter.shared_key
    return writer, perms.as_uint32(), mac_filter.shared_key


def write_distinct_mac(writer, output, key):
    handler = PdfMacTokenHandler.from_key_mat(key, writer.security_handler.get_kdf_salt(), 'sha256')
    mac = StandalonePdfMac(bytes_reserved=2 * handler.determine_token_size(False))
    writer.set_custom_trailer_entry(generic.pdf_name('/AuthCode'), mac)
    prepared = mac.fill(writer, md_algorithm='sha256', output=output)
    digest, stream = next(prepared)
    token = handler.build_pdfmac_token(document_digest=digest.document_digest, signature_digest=None)
    digest.fill_with_cms(stream, cms_data=token)


def hybrid_revision(source):
    """Add a classic trailer referencing pyHanko's unchanged xref stream.

    This only changes xref framing; all encrypted PDF and CMS bytes retain
    pyHanko's output. Hybrid files are read with pyHanko's explicit non-strict
    mode, since that library intentionally rejects hybrid references in strict mode.
    """
    reader = PdfFileReader(io.BytesIO(source))
    xref = int(re.findall(rb'startxref\s+(\d+)', source)[-1])
    trailer = generic.DictionaryObject({generic.pdf_name(key): reader.trailer_view.raw_get(key)
        for key in ('/Root', '/Encrypt', '/ID', '/Size')})
    trailer[generic.pdf_name('/XRefStm')] = generic.NumberObject(xref)
    encoded = io.BytesIO()
    trailer.write_to_stream(encoded, None)
    start = len(source) + 1
    return (source + b'\nxref\n0 1\n0000000000 65535 f \ntrailer\n' + encoded.getvalue()
        + f'\nstartxref\n{start}\n%%EOF\n'.encode())


def generate(directory):
    directory.mkdir(parents=True, exist_ok=True)
    certs = {name: identity(directory, name) for name in ('recipient', 'unmatched', 'attachment', 'wrong')}
    records = []

    def save(name, writer, permissions, *, compressed=False, named=False, mac=None, incremental=False, mac_key=None):
        output = io.BytesIO()
        if mac_key is not None:
            write_distinct_mac(writer, output, mac_key)
        elif mac == 'AttachedToSig':
            cert, key = certs['recipient']
            signer = signers.SimpleSigner(signing_cert=cert, signing_key=key, cert_registry=SimpleCertificateStore())
            signers.PdfSigner(signers.PdfSignatureMetadata(field_name='approval', md_algorithm='sha256'),
                signer=signer).sign_pdf(writer, output=output, bytes_reserved=16384)
        else:
            writer.write(output)
        data = output.getvalue()
        filename = name + '.pdf'
        (directory / filename).write_bytes(data)
        records.append({'file': filename, 'sha256': hashlib.sha256(data).hexdigest(),
            'subFilter': str(writer.security_handler.subfilter.value).lstrip('/'),
            'permissions': permissions, 'compressed': compressed, 'named': named,
            'mac': mac, 'incremental': incremental, 'macDistinct': mac_key is not None})
        return data

    for mode in ('s3-rc4', 's4-rc4', 's5-rc4', 's5-aes128', 's5-aes256', 's5-gcm'):
        for owner in (False, True):
            for compressed in (False, True):
                writer, perms = ordinary_writer(mode, certs, owner=owner, compressed=compressed)
                data = save(f'{mode}-owner{int(owner)}-objstm{int(compressed)}', writer, perms, compressed=compressed)
                if mode == 's5-aes128' and owner and compressed:
                    hybrid = hybrid_revision(data)
                    (directory / 's5-aes128-hybrid.pdf').write_bytes(hybrid)
                    records.append({**records[-1], 'file': 's5-aes128-hybrid.pdf',
                        'sha256': hashlib.sha256(hybrid).hexdigest(), 'hybrid': True})
                    credential = SimpleEnvelopeKeyDecrypter.load(str(directory / 'recipient-key.pem'), str(directory / 'recipient.der'))
                    updated = IncrementalPdfFileWriter(io.BytesIO(data), strict=True)
                    updated.encrypt_pubkey(credential)
                    updated.root[generic.pdf_name('/FixtureIncremental')] = updated.add_object(generic.TextStringObject(INCREMENTAL))
                    updated.update_root()
                    save('s5-aes128-incremental', updated, perms, compressed=True, incremental=True)
        if mode.startswith('s5'):
            writer, perms = ordinary_writer(mode, certs, clear_metadata=True)
            save(mode + '-clear-metadata', writer, perms)
    # Independent CMS payloads distinguish the four broad R2 rights from source
    # bits 9–12, which have no authority under the S3 subfilter.
    for broad in (0, 4, 8, 16, 32):
        for reserved in (0, 0xf00):
            writer, perms = ordinary_writer('s3-rc4', certs,
                permissions=PubKeyPermissions.from_uint(broad | reserved))
            save(f's3-r2-right-{broad}-reserved-{reserved}', writer, perms)
    for mode in ('s5-aes256', 's5-gcm'):
        for location in ('Standalone', 'AttachedToSig'):
            writer, perms = ordinary_writer(mode, certs, compressed=True, mac=True)
            save(mode + '-mac-' + location, writer, perms, compressed=True, mac=location)
    writer, perms, mac_key = distinct_mac_writer(certs)
    save('s5-distinct-default-mac-key', writer, perms, compressed=True, mac='Standalone', mac_key=mac_key)
    for aes in (False, True):
        writer, perms = named_writer(certs, aes)
        save('s5-distinct-keys-' + ('aes128' if aes else 'rc4'), writer, perms, named=True)
    manifest = {'generator': 'pyHanko 0.37.0 / cryptography 50.0.2',
        'notice': 'Ephemeral synthetic test keys; no certificate trust or signature validation claim.',
        'permissionByteOrder': 'N', 'files': records}
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    return records


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    args = parser.parse_args()
    print(f'Generated {len(generate(args.directory))} independent public-key fixtures.')
