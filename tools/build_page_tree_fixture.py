#!/usr/bin/env python3
"""Reproduce original nested-page-tree fixtures using pypdf 6.20.1/qpdf 12.4.2.

The PHP library is deliberately not involved in either construction or checking.
Generated page content and dictionaries are original project material under MIT.
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
    ByteStringObject,
    DecodedStreamObject,
    DictionaryObject,
    NameObject,
    NumberObject,
)


FILE_ID = hashlib.sha256(b"PDFToolkit original nested page-tree fixture").digest()[:16]
FIRST_CONTENT = b"BT /FBranch 12 Tf 30 400 Td (Inherited page one) Tj ET\n"
SECOND_CONTENT = b"BT /FBranch 12 Tf 30 370 Td (Second content stream) Tj ET\n"
BINARY_SAMPLES = b"\x00\xff\nendstream\nendobj\nxref\nstartxref\n%%EOF\n" + bytes(range(256))
BINARY_CONTENT = (
    f"q\nBI /W {len(BINARY_SAMPLES)} /H 1 /BPC 8 /CS /G ID\n".encode("ascii")
    + BINARY_SAMPLES + b"\nEI\nQ\n"
)
FOURTH_CONTENT = b"q\n1 0 0 1 0 0 cm\nQ\n"
EXPECTED_PAGES = [
    {
        "media_box": [0, 0, 300, 500],
        "crop_box": [20, 30, 280, 470],
        "rotation": -90,
        "resource_fonts": {"FBranch": "Courier"},
        "own_entries": ["Contents", "Parent", "Type"],
        "contents_hex": [FIRST_CONTENT.hex(), SECOND_CONTENT.hex()],
        "text": "Inherited page one\nSecond content stream",
    },
    {
        "media_box": [0, 0, 320, 520],
        "crop_box": [30, 40, 270, 460],
        "rotation": 0,
        "resource_fonts": {},
        "own_entries": ["Contents", "MediaBox", "Parent", "Resources", "Rotate", "Type"],
        "contents_hex": [BINARY_CONTENT.hex(), FOURTH_CONTENT.hex()],
        "text": "",
    },
    {
        "media_box": [0, 0, 400, 600],
        "crop_box": [10, 20, 390, 580],
        "rotation": 90,
        "resource_fonts": {"FRoot": "Helvetica"},
        "own_entries": ["Parent", "Type"],
        "contents_hex": [],
        "text": "",
    },
]


def rectangle(values: list[int]) -> ArrayObject:
    return ArrayObject([NumberObject(value) for value in values])


def page_tree() -> bytes:
    writer = PdfWriter()
    writer.pdf_header = "%PDF-1.7"
    writer._ID = ArrayObject([ByteStringObject(FILE_ID), ByteStringObject(FILE_ID)])
    writer.add_metadata({
        "/Producer": "pypdf 6.20.1 original page-tree fixture generator",
        "/Title": "Nested inheritance and independent siblings",
    })
    root_ref = writer._pages
    root = root_ref.get_object()

    def resources(name: str, base_font: str):
        font = writer._add_object(DictionaryObject({
            NameObject("/Type"): NameObject("/Font"),
            NameObject("/Subtype"): NameObject("/Type1"),
            NameObject("/BaseFont"): NameObject("/" + base_font),
        }))
        return writer._add_object(DictionaryObject({
            NameObject("/Font"): DictionaryObject({NameObject("/" + name): font}),
            NameObject("/ProcSet"): ArrayObject([NameObject("/PDF"), NameObject("/Text")]),
        }))

    root_resources = resources("FRoot", "Helvetica")
    branch_resources = resources("FBranch", "Courier")
    branch_media_box = writer._add_object(rectangle([0, 0, 300, 500]))
    branch = DictionaryObject({
        NameObject("/Type"): NameObject("/Pages"),
        NameObject("/Parent"): root_ref,
        NameObject("/Count"): NumberObject(2),
        NameObject("/MediaBox"): branch_media_box,
        NameObject("/CropBox"): rectangle([20, 30, 280, 470]),
        NameObject("/Rotate"): NumberObject(-90),
        NameObject("/Resources"): branch_resources,
    })
    branch_ref = writer._add_object(branch)
    nested = DictionaryObject({
        NameObject("/Type"): NameObject("/Pages"),
        NameObject("/Parent"): branch_ref,
        NameObject("/Count"): NumberObject(1),
        NameObject("/CropBox"): rectangle([30, 40, 270, 460]),
    })
    nested_ref = writer._add_object(nested)

    def content(data: bytes, compressed: bool = False):
        stream = DecodedStreamObject()
        stream.set_data(data)
        return writer._add_object(stream.flate_encode() if compressed else stream)

    first = DictionaryObject({
        NameObject("/Type"): NameObject("/Page"),
        NameObject("/Parent"): branch_ref,
        NameObject("/Contents"): ArrayObject([content(FIRST_CONTENT), content(SECOND_CONTENT, True)]),
    })
    first_ref = writer._add_object(first)
    second_contents = writer._add_object(ArrayObject([content(BINARY_CONTENT), content(FOURTH_CONTENT, True)]))
    second = DictionaryObject({
        NameObject("/Type"): NameObject("/Page"),
        NameObject("/Parent"): nested_ref,
        NameObject("/MediaBox"): rectangle([0, 0, 320, 520]),
        NameObject("/Rotate"): NumberObject(0),
        NameObject("/Resources"): DictionaryObject(),
        NameObject("/Contents"): second_contents,
    })
    second_ref = writer._add_object(second)
    third_ref = writer._add_object(DictionaryObject({
        NameObject("/Type"): NameObject("/Page"),
        NameObject("/Parent"): root_ref,
    }))
    nested[NameObject("/Kids")] = ArrayObject([second_ref])
    branch[NameObject("/Kids")] = ArrayObject([first_ref, nested_ref])
    root.update({
        NameObject("/Kids"): ArrayObject([branch_ref, third_ref]),
        NameObject("/Count"): NumberObject(3),
        NameObject("/MediaBox"): rectangle([0, 0, 400, 600]),
        NameObject("/CropBox"): rectangle([10, 20, 390, 580]),
        NameObject("/Rotate"): NumberObject(90),
        NameObject("/Resources"): root_resources,
    })
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def identifier(reference) -> dict[str, int]:
    return {"number": reference.idnum, "generation": reference.generation}


def describe(path: Path) -> dict:
    reader = PdfReader(path, strict=True)
    root_ref = reader.root_object.raw_get("/Pages")
    root = root_ref.get_object()
    branch_ref = root.raw_get("/Kids")[0]
    nested_ref = branch_ref.get_object().raw_get("/Kids")[1]
    pages = []
    for page in reader.pages:
        raw = page.indirect_reference.get_object()
        contents = []
        for reference in raw.get("/Contents", ArrayObject()).get_object():
            stream = reference.get_object()
            filters = stream.get("/Filter", [])
            if isinstance(filters, NameObject):
                filters = [filters]
            contents.append({
                "identifier": identifier(reference),
                "filters": [str(value)[1:] for value in filters],
                "encoded_hex": stream._data.hex(),
                "decoded_hex": stream.get_data().hex(),
            })
        resources = page["/Resources"]
        pages.append({
            "identifier": identifier(page.indirect_reference),
            "parent": identifier(raw.raw_get("/Parent")),
            "own_entries": sorted(str(key)[1:] for key in raw),
            "media_box": [int(value) for value in page.mediabox],
            "crop_box": [int(value) for value in page.cropbox],
            "rotation": int(page.rotation),
            "resource_fonts": {
                str(key)[1:]: str(font.get_object()["/BaseFont"])[1:]
                for key, font in resources.get("/Font", {}).items()
            },
            "contents": contents,
            "text": page.extract_text(),
        })
    information = {
        "bytes": path.stat().st_size,
        "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "startxref": reader._startxref,
        "catalog": identifier(reader.trailer.raw_get("/Root")),
        "page_tree": {
            "root": identifier(root_ref),
            "branch": identifier(branch_ref),
            "nested": identifier(nested_ref),
        },
        "pages": pages,
    }
    if len(pages) != len(EXPECTED_PAGES):
        raise ValueError("Expected three leaf pages")
    for actual, expected in zip(pages, EXPECTED_PAGES, strict=True):
        for key in ("media_box", "crop_box", "rotation", "resource_fonts", "own_entries", "text"):
            if actual[key] != expected[key]:
                raise ValueError(f"Unexpected page {key}: {actual[key]!r}, expected {expected[key]!r}")
        if [stream["decoded_hex"] for stream in actual["contents"]] != expected["contents_hex"]:
            raise ValueError("Unexpected page content bytes or order")
    return information


def build(qpdf: str, destination: Path) -> None:
    if pypdf.__version__ != "6.20.1":
        raise RuntimeError("Rebuild with pypdf==6.20.1")
    version = subprocess.check_output([qpdf, "--version"], text=True).splitlines()[0]
    if version != "qpdf version 12.4.2":
        raise RuntimeError("Rebuild with qpdf==12.4.2")
    original = destination / "classic.pdf"
    original.write_bytes(page_tree())
    subprocess.run([
        qpdf, "--object-streams=generate", "--deterministic-id",
        str(original), str(destination / "object-streams.pdf"),
    ], check=True, capture_output=True)
    fixtures = {}
    for path in sorted(destination.glob("*.pdf")):
        subprocess.run([qpdf, "--check", str(path)], check=True, capture_output=True)
        fixtures[path.name] = describe(path)
    manifest = {
        "generators": {"pypdf": pypdf.__version__, "qpdf": "12.4.2"},
        "page_tree_nodes": 6,
        "page_count": 3,
        "binary_sample_bytes": len(BINARY_SAMPLES),
        "binary_sample_sha256": hashlib.sha256(BINARY_SAMPLES).hexdigest(),
        "fixtures": fixtures,
    }
    (destination / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--qpdf", default="qpdf", help="qpdf 12.4.2 executable")
    parser.add_argument("--check", action="store_true", help="Verify byte-for-byte reproducibility without editing fixtures")
    args = parser.parse_args()
    destination = Path(__file__).resolve().parents[1] / "tests" / "Fixtures" / "page-tree"
    with tempfile.TemporaryDirectory(prefix="pdf-reader-page-tree-") as directory:
        temporary = Path(directory)
        build(args.qpdf, temporary)
        for path in sorted(temporary.iterdir()):
            committed = destination / path.name
            if args.check:
                if not committed.is_file() or committed.read_bytes() != path.read_bytes():
                    raise RuntimeError(f"Fixture differs: {path.name}")
            else:
                destination.mkdir(parents=True, exist_ok=True)
                committed.write_bytes(path.read_bytes())
    print("Nested page-tree fixtures reproduced and validated." if args.check else "Nested page-tree fixtures built and validated.")


if __name__ == "__main__":
    main()
