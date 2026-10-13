#!/usr/bin/env python3
"""Build small external PDF fixtures, independently from the PHP writer.

Requires pypdf==6.20.1 and qpdf==12.4.2. The PHP tests read committed files
and do not require either tool. All generated content is original MIT material.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile

import pypdf
from pypdf import PdfReader, PdfWriter
from pypdf.generic import (
    ArrayObject,
    BooleanObject,
    ByteStringObject,
    DecodedStreamObject,
    DictionaryObject,
    FloatObject,
    IndirectObject,
    NameObject,
    NullObject,
    NumberObject,
    TextStringObject,
)


LITERAL = "A (nested) \\ line\n\tend"
CONTENT = b"BT /F1 14 Tf 20 120 Td (Independent reader fixture) Tj ET\n"
BINARY = b"\x00\xff\nendstream\nendobj\nxref\nstartxref\n%%EOF\n" + bytes(range(256))
FILE_ID = hashlib.sha256(b"roukmoute/pdf external reader fixtures").digest()[:16]


class IndirectLengthStream(DecodedStreamObject):
    """Preserve an indirect /Length, which pypdf's ordinary writer normalizes."""

    def write_to_stream(self, stream, encryption_key=None):
        if encryption_key is not None:
            raise ValueError("Fixture streams are not encrypted")
        DictionaryObject.write_to_stream(self, stream)
        stream.write(b"\nstream\n" + self._data + b"\nendstream")


def serialized(value) -> bytes:
    stream = io.BytesIO()
    value.write_to_stream(stream)
    return stream.getvalue()


def classic() -> tuple[bytes, dict]:
    writer = PdfWriter()
    writer.pdf_header = "%PDF-1.7"
    writer._ID = ArrayObject([ByteStringObject(FILE_ID), ByteStringObject(FILE_ID)])
    writer.add_metadata({"/Producer": "pypdf 6.20.1 fixture generator", "/Title": "Original revision"})
    page = writer.add_blank_page(width=240, height=160)
    font = DictionaryObject({
        NameObject("/Type"): NameObject("/Font"),
        NameObject("/Subtype"): NameObject("/Type1"),
        NameObject("/BaseFont"): NameObject("/Helvetica"),
    })
    font_ref = writer._add_object(font)
    page[NameObject("/Resources")] = DictionaryObject({
        NameObject("/Font"): DictionaryObject({NameObject("/F1"): font_ref}),
    })
    contents = DecodedStreamObject()
    contents.set_data(CONTENT)
    contents_ref = writer._add_object(contents)
    page[NameObject("/Contents")] = contents_ref
    length_ref = writer._add_object(NumberObject(len(BINARY)))
    data = IndirectLengthStream()
    data.set_data(BINARY)
    data[NameObject("/Length")] = length_ref
    data[NameObject("/Type")] = NameObject("/FixtureData")
    data_ref = writer._add_object(data)
    transient_ref = writer._add_object(TextStringObject("remove me"))
    writer.root_object.update({
        NameObject("/FixtureLiteral"): TextStringObject(LITERAL),
        NameObject("/FixtureBinary"): ByteStringObject(bytes(range(256))),
        NameObject("/FixtureName"): NameObject("/A name/#()"),
        NameObject("/FixtureArray"): ArrayObject([
            NullObject(), BooleanObject(True), BooleanObject(False),
            NumberObject(-23), FloatObject("1.25"),
            DictionaryObject({NameObject("/Nested"): TextStringObject("value")}),
        ]),
        NameObject("/FixtureData"): data_ref,
        NameObject("/FixtureTransient"): transient_ref,
    })
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue(), {
        "catalog": writer.root_object.indirect_reference.idnum,
        "page": page.indirect_reference.idnum,
        "font": font_ref.idnum,
        "contents": contents_ref.idnum,
        "length": length_ref.idnum,
        "binary_stream": data_ref.idnum,
        "transient": transient_ref.idnum,
        "info": writer._info.indirect_reference.idnum,
    }


def appended(original: bytes, entries: dict[int, tuple[int, int, str]], objects: list[tuple[int, int, object]]) -> bytes:
    """Append one independently calculated, traditional cross-reference revision."""
    reader = PdfReader(io.BytesIO(original), strict=True)
    output = io.BytesIO()
    output.write(original)
    if not original.endswith(b"\n"):
        output.write(b"\n")
    for number, generation, value in objects:
        entries[number] = (output.tell(), generation, "n")
        output.write(f"{number} {generation} obj\n".encode("ascii"))
        output.write(serialized(value) + b"\nendobj\n")
    xref_offset = output.tell()
    output.write(b"xref\n")
    for number, (first, generation, kind) in sorted(entries.items()):
        output.write(f"{number} 1\n{first:010d} {generation:05d} {kind} \n".encode("ascii"))
    trailer = DictionaryObject({
        key: reader.trailer.raw_get(key)
        for key in reader.trailer
        if key not in ("/Prev", "/XRefStm")
    })
    trailer[NameObject("/Prev")] = NumberObject(reader._startxref)
    trailer[NameObject("/ID")] = ArrayObject([
        ByteStringObject(FILE_ID),
        ByteStringObject(hashlib.sha256(output.getvalue()).digest()[:16]),
    ])
    output.write(b"trailer\n" + serialized(trailer))
    output.write(f"\nstartxref\n{xref_offset}\n%%EOF\n".encode("ascii"))
    return output.getvalue()


def build(qpdf: str, destination: Path) -> None:
    if pypdf.__version__ != "6.20.1":
        raise RuntimeError("Rebuild with pypdf==6.20.1")
    version = subprocess.check_output([qpdf, "--version"], text=True).splitlines()[0]
    if version != "qpdf version 12.4.2":
        raise RuntimeError("Rebuild with qpdf==12.4.2")
    original, identifiers = classic()
    classic_path = destination / "classic.pdf"
    classic_path.write_bytes(original)
    subprocess.run([
        qpdf, "--object-streams=generate", "--deterministic-id",
        str(classic_path), str(destination / "object-streams.pdf"),
    ], check=True, capture_output=True)
    info = DictionaryObject({
        NameObject("/Producer"): TextStringObject("pypdf 6.20.1 fixture generator"),
        NameObject("/Title"): TextStringObject("Updated revision"),
    })
    incremental = appended(original, {0: (0, 65535, "f")}, [(identifiers["info"], 0, info)])
    (destination / "incremental.pdf").write_bytes(incremental)
    deleted = appended(original, {
        0: (identifiers["transient"], 65535, "f"),
        identifiers["transient"]: (0, 1, "f"),
    }, [])
    (destination / "deleted-object.pdf").write_bytes(deleted)
    reused = appended(deleted, {0: (0, 65535, "f")}, [
        (identifiers["transient"], 1, TextStringObject("reused generation one")),
    ])
    (destination / "reused-generation.pdf").write_bytes(reused)
    fixtures = {}
    for path in sorted(destination.glob("*.pdf")):
        subprocess.run([qpdf, "--check", str(path)], check=True, capture_output=True)
        reader = PdfReader(path, strict=True)
        assert len(reader.pages) == 1
        assert reader.pages[0].extract_text() == "Independent reader fixture"
        catalog = reader.root_object
        assert str(catalog["/FixtureLiteral"]) == LITERAL
        assert bytes(catalog["/FixtureBinary"].original_bytes) == bytes(range(256))
        assert str(catalog["/FixtureName"]) == "/A name/#()"
        assert catalog["/FixtureData"].get_data() == BINARY
        fixtures[path.name] = {
            "bytes": path.stat().st_size,
            "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
            "startxref": reader._startxref,
            "catalog": reader.trailer.raw_get("/Root").idnum,
            "binary_stream": catalog.raw_get("/FixtureData").idnum,
            "transient": catalog.raw_get("/FixtureTransient").idnum,
        }
    assert incremental.startswith(original) and deleted.startswith(original) and reused.startswith(deleted)
    assert PdfReader(io.BytesIO(incremental), strict=True).metadata.title == "Updated revision"
    deleted_value = subprocess.check_output([
        qpdf, f"--show-object={identifiers['transient']}", str(destination / "deleted-object.pdf"),
    ], text=True).strip()
    assert deleted_value == "null"
    reused_reader = PdfReader(io.BytesIO(reused), strict=True)
    assert str(reused_reader.get_object(IndirectObject(identifiers["transient"], 1, reused_reader))) == "reused generation one"
    reused_value = subprocess.check_output([
        qpdf, f"--show-object={identifiers['transient']},1", str(destination / "reused-generation.pdf"),
    ], text=True).strip()
    assert reused_value == "(reused generation one)"
    stale_value = subprocess.check_output([
        qpdf, f"--show-object={identifiers['transient']},0", str(destination / "reused-generation.pdf"),
    ], text=True).strip()
    assert stale_value == "null"
    manifest = {
        "generators": {"pypdf": pypdf.__version__, "qpdf": "12.4.2"},
        "classic_identifiers": identifiers,
        "binary_stream_bytes": len(BINARY),
        "binary_stream_sha256": hashlib.sha256(BINARY).hexdigest(),
        "page_content_hex": CONTENT.hex(),
        "literal_value_hex": LITERAL.encode("ascii").hex(),
        "fixtures": fixtures,
    }
    (destination / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--qpdf", default="qpdf", help="qpdf 12.4.2 executable")
    parser.add_argument("--check", action="store_true", help="Check reproducibility without changing committed fixtures")
    args = parser.parse_args()
    destination = Path(__file__).resolve().parents[1] / "tests" / "Fixtures" / "pdf"
    with tempfile.TemporaryDirectory(prefix="pdf-reader-fixtures-") as directory:
        temporary = Path(directory)
        build(args.qpdf, temporary)
        for path in sorted(temporary.iterdir()):
            committed = destination / path.name
            if args.check:
                if not committed.is_file() or path.read_bytes() != committed.read_bytes():
                    raise RuntimeError(f"Fixture differs: {path.name}")
            else:
                destination.mkdir(parents=True, exist_ok=True)
                committed.write_bytes(path.read_bytes())
    print("External PDF fixtures reproduced and validated." if args.check else "External PDF fixtures built and validated.")


if __name__ == "__main__":
    main()
