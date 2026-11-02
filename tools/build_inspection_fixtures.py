#!/usr/bin/env python3
"""Reproduce inspection fixtures using physical PDF syntax and pinned qpdf."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile


class InspectionFixtureBuilder:
    """Small independent physical writer: no PDFToolkit code or generated output."""

    @staticmethod
    def stream(data: bytes, entries: bytes = b"") -> bytes:
        return b"<< /Length " + str(len(data)).encode() + b" " + entries + b" >>\nstream\n" + data + b"\nendstream"

    @staticmethod
    def file(objects: dict[int, bytes], generations: dict[int, int] | None = None) -> bytes:
        generations = generations or {}
        result = bytearray(b"%PDF-2.0\n%\xe2\xe3\xcf\xd3\n")
        offsets = {}
        for number, value in sorted(objects.items()):
            offsets[number] = len(result)
            result.extend(f"{number} {generations.get(number, 0)} obj\n".encode() + value + b"\nendobj\n")
        startxref = len(result)
        size = max(objects) + 1
        result.extend(f"xref\n0 {size}\n0000000000 65535 f \n".encode())
        for number in range(1, size):
            result.extend((f"{offsets[number]:010} {generations.get(number, 0):05} n \n" if number in offsets else "0000000000 00000 f \n").encode())
        result.extend(f"trailer << /Size {size} /Root 1 0 R >>\nstartxref\n{startxref}\n%%EOF\n".encode())
        return bytes(result)

    def tagged_objects(self) -> dict[int, bytes]:
        return {
            1: b"<< /Type /Catalog /Pages 2 0 R /MarkInfo << /Marked true >> /StructTreeRoot 9 0 R /AF [30 0 R] /Names << /EmbeddedFiles 34 0 R >> /OpenAction << /S /JavaScript /JS (throw 'inspection-must-not-execute') >> >>",
            2: b"<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 200 200] /Resources <<>> >>",
            3: b"<< /Type /Page /Parent 2 0 R /StructParents 0 /Contents 5 0 R /Annots [8 0 R] /AF [31 0 R] >>",
            4: b"<< /Type /Page /Parent 2 0 R /StructParents 1 /Contents 6 0 R >>",
            5: self.stream(b"/Custom << /MCID 0 >> BDC\n0 0 10 10 re f\nEMC\n/Special << /MCID 2 >> BDC\nEMC\n/Special << /MCID 3 >> BDC\nEMC\n"),
            6: self.stream(b"/Custom << /MCID 1 >> BDC\n0 0 20 20 re f\nEMC\n"),
            7: b"<< /Type /MCR /Pg 4 0 R /Stm 6 0 R /MCID 1 >>",
            8: b"<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] /StructParent 2 /P 3 0 R /A << /S /URI /URI (https://127.0.0.1:9/inspection-must-not-fetch) >> >>",
            9: b"<< /Type /StructTreeRoot /K [10 7 R] /RoleMap << /Custom /P >> /Namespaces [20 0 R 21 0 R 22 0 R] /ParentTree 23 0 R /ParentTreeNextKey 3 /ClassMap << /Emphasis << /O /Layout /Color [1 0 0] >> >> >>",
            10: b"<< /Type /StructElem /S /Document /P 9 0 R /Pg 3 0 R /K 19 0 R /ID (document) /Lang (fr-FR) /AF [32 0 R] >>",
            11: b"<< /Type /StructElem /S /Custom /P 10 7 R /Pg 3 0 R /K [0 7 0 R << /Type /OBJR /Pg 3 0 R /Obj 8 0 R >>] /ID <00ff8090> /Alt (<img src=x onerror=alert(1)>) /ActualText <feff0042006f006e006a006f00750072> /A 24 0 R /C /Emphasis /LargeInteger 9007199254740993 /NegativeInteger -9007199254740993 /Escaped#20Key /Application#2FPDF /NullValue null /Ratio 1.25 >>",
            12: b"<< /Type /StructElem /S /Special /NS 20 0 R /P 10 7 R /K 2 /Lang <efbbbf6672> >>",
            13: b"<< /Type /StructElem /S /Special /NS 21 0 R /P 10 7 R /K 3 /Alt <fffe4142> >>",
            19: b"[11 0 R 12 0 R 13 0 R]",
            20: b"<< /Type /Namespace /NS (https://example.invalid/same-namespace) /RoleMapNS << /Special [/P 22 0 R] >> >>",
            21: b"<< /Type /Namespace /NS (https://example.invalid/same-namespace) /RoleMapNS << /Special [/Span 22 0 R] >> >>",
            22: b"<< /Type /Namespace /NS (http://iso.org/pdf2/ssn) >>",
            23: b"<< /Nums [0 [11 0 R null 12 0 R 13 0 R] 1 [null 11 0 R] 2 11 0 R] >>",
            24: b"<< /O /Layout /Placement /Block /Color [0.25 0.5 0.75] /PrivateBytes <00ff0a0d> >>",
            30: b"<< /Type /Filespec /F (catalog.txt) /UF <feff0063006100740061006c006f006700750065002e007400780074> /AFRelationship /Supplement /EF << /F 40 0 R >> >>",
            31: b"<< /Type /Filespec /F (page.bin) /AFRelationship /Data /EF << /F 41 0 R >> >>",
            32: b"<< /Type /Filespec /F (<script>alert(1)</script>.txt) /AFRelationship /Source /EF << /F 42 0 R >> >>",
            33: b"<< /Type /Filespec /F (named.txt) /AFRelationship /Unspecified /EF << /F 43 0 R >> >>",
            34: b"<< /Kids [35 0 R] >>",
            35: b"<< /Limits [(named) (named)] /Names [(named) 33 0 R] >>",
            40: self.stream(b"CATALOG_PAYLOAD_MUST_NOT_APPEAR_IN_JSON", b"/Type /EmbeddedFile /Subtype /text#2Fplain"),
            41: self.stream(b"UNKNOWN_FILTER_PAYLOAD_MUST_NOT_BE_DECODED", b"/Type /EmbeddedFile /Filter /InspectionUnknownDecode /F (https://127.0.0.1:9/stream-must-not-fetch)"),
            42: self.stream(b"<script>throw 'embedded-file-must-not-execute'</script>", b"/Type /EmbeddedFile /Subtype /text#2Fplain"),
            43: self.stream(b"NAMED_PAYLOAD_MUST_NOT_APPEAR_IN_JSON", b"/Type /EmbeddedFile"),
        }

    def build(self, output: Path, qpdf: str) -> dict:
        version = subprocess.check_output([qpdf, "--version"], text=True).splitlines()[0]
        if version != "qpdf version 12.4.2":
            raise ValueError("Reproduction requires qpdf 12.4.2")
        objects = self.tagged_objects()
        (output / "classic.pdf").write_bytes(self.file(objects, {10: 7}))
        subprocess.run([qpdf, "--static-id", "--object-streams=generate", "--stream-data=preserve", str(output / "classic.pdf"), str(output / "object-streams.pdf")], check=True, capture_output=True)
        untagged = {key: value for key, value in objects.items() if key in {2, 3, 4, 5, 6, 8, 31, 41}}
        untagged[1] = b"<< /Type /Catalog /Pages 2 0 R >>"
        (output / "untagged.pdf").write_bytes(self.file(untagged))
        malformed = dict(objects)
        malformed[19] = b"[11 0 R 10 7 R 11 0 R 99 0 R 10 0 R 18 0 R]"
        (output / "cycles-and-missing.pdf").write_bytes(self.file(malformed, {10: 7}))
        return {
            "schemaVersion": 1,
            "generator": "Independent physical syntax in tools/build_inspection_fixtures.py",
            "rewriter": version,
            "fixtures": {
                path.name: {"bytes": len(path.read_bytes()), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
                for path in sorted(output.glob("*.pdf"))
            },
        }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1] / "tests/Fixtures/inspection"
    root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="inspection-fixtures-") as directory:
        output = Path(directory)
        manifest = InspectionFixtureBuilder().build(output, args.qpdf)
        (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
        for path in sorted(output.iterdir()):
            if args.check:
                if (root / path.name).read_bytes() != path.read_bytes():
                    raise ValueError("Fixture is not reproducible: " + path.name)
            else:
                (root / path.name).write_bytes(path.read_bytes())
    print("Verified four independent inspection fixtures." if args.check else "Built four independent inspection fixtures.")


if __name__ == "__main__":
    main()
