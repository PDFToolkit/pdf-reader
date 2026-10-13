#!/usr/bin/env python3
"""Check original nested page trees with pypdf and qpdf, independently of PHP."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

from pypdf import PdfReader
from pypdf.generic import ArrayObject, IndirectObject, NameObject


def reference_identity(reference) -> dict[str, int]:
    if not isinstance(reference, IndirectObject):
        raise ValueError("Expected an indirect page-tree or stream reference")
    return {"number": reference.idnum, "generation": reference.generation}


def validate(qpdf: str, root: Path) -> None:
    manifest = json.loads((root / "manifest.json").read_text(encoding="utf-8"))
    binary_samples = b"\x00\xff\nendstream\nendobj\nxref\nstartxref\n%%EOF\n" + bytes(range(256))
    binary_content = (
        f"q\nBI /W {len(binary_samples)} /H 1 /BPC 8 /CS /G ID\n".encode("ascii")
        + binary_samples + b"\nEI\nQ\n"
    )
    expected_contents = [
        [
            b"BT /FBranch 12 Tf 30 400 Td (Inherited page one) Tj ET\n",
            b"BT /FBranch 12 Tf 30 370 Td (Second content stream) Tj ET\n",
        ],
        [binary_content, b"q\n1 0 0 1 0 0 cm\nQ\n"],
        [],
    ]
    expected_geometry = [
        ([0, 0, 300, 500], [20, 30, 280, 470], -90),
        ([0, 0, 320, 520], [30, 40, 270, 460], 0),
        ([0, 0, 400, 600], [10, 20, 390, 580], 90),
    ]
    expected_fonts = [{"FBranch": "Courier"}, {}, {"FRoot": "Helvetica"}]
    expected_text = ["Inherited page one\nSecond content stream", "", ""]
    if manifest["page_count"] != 3 or manifest["page_tree_nodes"] != 6:
        raise ValueError("Unexpected page-tree dimensions in manifest")
    if manifest["binary_sample_bytes"] != len(binary_samples) or manifest["binary_sample_sha256"] != hashlib.sha256(binary_samples).hexdigest():
        raise ValueError("Unexpected binary inline-image sample manifest")
    for name, information in manifest["fixtures"].items():
        path = root / name
        data = path.read_bytes()
        if len(data) != information["bytes"] or hashlib.sha256(data).hexdigest() != information["sha256"]:
            raise ValueError("Fixture bytes do not match their manifest: " + name)
        subprocess.run([qpdf, "--check", str(path)], check=True, capture_output=True)
        reader = PdfReader(path, strict=True)
        if reader._startxref != information["startxref"] or reference_identity(reader.trailer.raw_get("/Root")) != information["catalog"]:
            raise ValueError("Unexpected cross-reference or catalogue identity")
        tree = information["page_tree"]
        root_reference = reader.root_object.raw_get("/Pages")
        root_node = root_reference.get_object()
        branch_reference = root_node.raw_get("/Kids")[0]
        branch_node = branch_reference.get_object()
        nested_reference = branch_node.raw_get("/Kids")[1]
        nested_node = nested_reference.get_object()
        if [reference_identity(item) for item in (root_reference, branch_reference, nested_reference)] != [tree["root"], tree["branch"], tree["nested"]]:
            raise ValueError("Unexpected nested /Pages identities")
        if [int(node["/Count"]) for node in (root_node, branch_node, nested_node)] != [3, 2, 1]:
            raise ValueError("Unexpected descendant counts")
        ordered_leaves = [
            branch_node.raw_get("/Kids")[0],
            nested_node.raw_get("/Kids")[0],
            root_node.raw_get("/Kids")[1],
        ]
        parent_references = [branch_reference, nested_reference, root_reference]
        if len(reader.pages) != 3 or len(information["pages"]) != 3:
            raise ValueError("Expected three pages in document order")
        for index, page in enumerate(reader.pages):
            expected = information["pages"][index]
            original = ordered_leaves[index].get_object()
            if str(original["/Type"]) != "/Page" or reference_identity(page.indirect_reference) != reference_identity(ordered_leaves[index]):
                raise ValueError("Unexpected leaf page identity or order")
            if reference_identity(page.indirect_reference) != expected["identifier"]:
                raise ValueError("Page identifier differs from manifest")
            parent = reference_identity(original.raw_get("/Parent"))
            if parent != reference_identity(parent_references[index]) or parent != expected["parent"]:
                raise ValueError("Page /Parent does not match its containing /Kids")
            if sorted(str(key)[1:] for key in original) != expected["own_entries"]:
                raise ValueError("Unexpected own entries; inheritance may have been flattened")
            media, crop, rotation = expected_geometry[index]
            if [int(value) for value in page.mediabox] != media or [int(value) for value in page.cropbox] != crop or int(page.rotation) != rotation:
                raise ValueError("Nearest ancestor or leaf geometry did not prevail")
            if expected["media_box"] != media or expected["crop_box"] != crop or expected["rotation"] != rotation:
                raise ValueError("Manifest geometry differs from independent expectations")
            fonts = {
                str(key)[1:]: str(font.get_object()["/BaseFont"])[1:]
                for key, font in page["/Resources"].get("/Font", {}).items()
            }
            if fonts != expected_fonts[index] or fonts != expected["resource_fonts"]:
                raise ValueError("Page resources did not inherit independently")
            if index == 1 and len(page["/Resources"]) != 0:
                raise ValueError("An explicit empty /Resources must stop inheritance")
            if page.extract_text() != expected_text[index] or expected["text"] != expected_text[index]:
                raise ValueError("Unexpected independent text extraction")
            contents = original.get("/Contents", ArrayObject()).get_object()
            if len(contents) != len(expected_contents[index]) or len(contents) != len(expected["contents"]):
                raise ValueError("Unexpected number of page content streams")
            for stream_index, stream_reference in enumerate(contents):
                stream = stream_reference.get_object()
                stream_manifest = expected["contents"][stream_index]
                filters = stream.get("/Filter", [])
                if isinstance(filters, NameObject):
                    filters = [filters]
                if reference_identity(stream_reference) != stream_manifest["identifier"]:
                    raise ValueError("Unexpected content stream reference")
                if [str(value)[1:] for value in filters] != stream_manifest["filters"]:
                    raise ValueError("Unexpected content stream encoding")
                if stream._data != bytes.fromhex(stream_manifest["encoded_hex"]):
                    raise ValueError("Encoded stream bytes differ from manifest")
                decoded = stream.get_data()
                if decoded != expected_contents[index][stream_index] or decoded != bytes.fromhex(stream_manifest["decoded_hex"]):
                    raise ValueError("Decoded stream bytes or their order changed")
        if name == "classic.pdf":
            if not isinstance(branch_node.raw_get("/MediaBox"), IndirectObject):
                raise ValueError("Expected an indirect inherited rectangle")
            if not isinstance(ordered_leaves[1].get_object().raw_get("/Contents"), IndirectObject):
                raise ValueError("Expected an indirect array of content streams")
            filters = [[stream["filters"] for stream in page["contents"]] for page in information["pages"]]
            if filters != [[[], ["FlateDecode"]], [[], ["FlateDecode"]], []]:
                raise ValueError("Classic fixture must mix encoded and unencoded streams")
        if name == "object-streams.pdf":
            xref = subprocess.check_output([qpdf, "--show-xref", str(path)], text=True)
            for page in information["pages"]:
                identity = page["identifier"]
                if not re.search(rf"(?m)^{identity['number']}/{identity['generation']}: compressed;", xref):
                    raise ValueError("Every rewritten leaf page must be in an object stream")
    print("Validated two nested page-tree fixtures, inherited geometry/resources and four ordered content streams.")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--qpdf", default="qpdf", help="qpdf executable")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1] / "tests" / "Fixtures" / "page-tree"
    validate(args.qpdf, root)


if __name__ == "__main__":
    main()
