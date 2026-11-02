#!/usr/bin/env python3
"""Compare actual Reader inspection JSON with pypdf source objects and qpdf."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

import pypdf
from pypdf import PdfReader
from pypdf.generic import (
    ArrayObject, BooleanObject, ByteStringObject, DictionaryObject, FloatObject,
    IndirectObject, NameObject, NullObject, NumberObject, StreamObject, TextStringObject,
)


def reference(value) -> dict | None:
    if not isinstance(value, IndirectObject):
        return None
    return {"number": str(value.idnum), "generation": value.generation}


def raw(dictionary, key, default=None):
    return dictionary.raw_get(key) if key in dictionary else default


def dereference(value):
    return value.get_object() if isinstance(value, IndirectObject) else value


def original_bytes(value) -> bytes:
    if isinstance(value, ByteStringObject):
        return bytes(value)
    if isinstance(value, TextStringObject):
        return value.original_bytes
    raise TypeError("Expected a PDF byte or text string")


def source_value(value):
    """Normalize independently parsed values, preserving references and stream opacity."""
    if isinstance(value, IndirectObject):
        return {"type": "reference", "reference": reference(value)}
    if isinstance(value, StreamObject):
        return {"type": "stream", "dictionary": source_stream_dictionary(value), "encodedByteLength": len(value._data)}
    if isinstance(value, DictionaryObject):
        return source_dictionary(value)
    if isinstance(value, ArrayObject):
        return {"type": "array", "items": [source_value(item) for item in value]}
    if isinstance(value, NameObject):
        return {"type": "name", "hex": str(value)[1:].encode("utf-8").hex()}
    if isinstance(value, (TextStringObject, ByteStringObject)):
        return {"type": "string", "hex": original_bytes(value).hex()}
    if isinstance(value, BooleanObject):
        return {"type": "boolean", "value": value.value}
    if isinstance(value, NullObject):
        return {"type": "null"}
    if isinstance(value, FloatObject):
        return {"type": "real", "value": float(value)}
    if isinstance(value, NumberObject):
        return {"type": "integer", "value": str(value)}
    raise TypeError("Unexpected pypdf object: " + type(value).__name__)


def source_dictionary(value):
    return {"type": "dictionary", "entries": {str(key)[1:].encode("utf-8").hex(): source_value(item) for key, item in value.items()}}


def source_stream_dictionary(value):
    # pypdf removes /Length while reading streams. These fixtures deliberately use
    # direct, exact lengths, which can be checked against the preserved raw data.
    dictionary = source_dictionary(value)
    dictionary["entries"][b"Length".hex()] = {"type": "integer", "value": str(len(value._data))}
    return dictionary


def inspected_value(value):
    kind = value["type"]
    if value.get("truncated") or kind == "truncated":
        raise ValueError("Ordinary fixture unexpectedly truncated")
    if kind == "dictionary":
        entries = value["entries"]
        keys = [entry["key"]["hex"].lower() for entry in entries]
        if len(keys) != len(set(keys)):
            raise ValueError("Inspection has duplicate dictionary keys")
        return {"type": kind, "entries": {entry["key"]["hex"].lower(): inspected_value(entry["value"]) for entry in entries}}
    if kind == "array":
        return {"type": kind, "items": [inspected_value(item) for item in value["items"]]}
    if kind in {"name", "string"}:
        if len(bytes.fromhex(value["hex"])) != value["byteLength"]:
            raise ValueError("Inspection string byte length changed")
        return {"type": kind, "hex": value["hex"].lower()}
    if kind == "reference":
        identity = value["reference"]
        if not isinstance(identity["number"], str):
            raise ValueError("Reference number must remain a decimal string")
        return {"type": kind, "reference": identity}
    if kind == "stream":
        return {"type": kind, "dictionary": inspected_value(value["dictionary"]), "encodedByteLength": value["encodedByteLength"]}
    if kind == "null":
        return {"type": kind}
    if kind == "integer" and not isinstance(value["value"], str):
        raise ValueError("PDF integer must remain a decimal string")
    return {"type": kind, "value": float(value["value"]) if kind == "real" else value["value"]}


class InspectionOracle:
    def __init__(self, root: Path, php: str, qpdf: str):
        self.root = root
        self.php = php
        self.qpdf = qpdf
        self.checks = 0

    def equal(self, actual, expected, label: str):
        self.checks += 1
        if actual != expected:
            raise ValueError(f"{label}\nActual: {actual!r}\nExpected: {expected!r}")

    def inspect(self, path: Path, profile: str = "complete") -> tuple[dict, str]:
        original = hashlib.sha256(path.read_bytes()).hexdigest()
        output = subprocess.check_output([self.php, str(self.root / "tools/export_inspection.php"), str(path), profile], text=True, timeout=30, cwd=self.root)
        self.equal(hashlib.sha256(path.read_bytes()).hexdigest(), original, "Read-only inspection changed the PDF")
        result = json.loads(output)
        self.equal(result["schemaVersion"], 1, "Inspection schema")
        return result, output

    def compare_value(self, actual, source, label):
        self.equal(inspected_value(actual), source_value(source), label)

    def pages(self, value):
        node = dereference(value)
        if node["/Type"] == "/Page":
            return [(value, node)]
        return [page for child in node["/Kids"] for page in self.pages(child)]

    def structure(self, value, inherited_page=None):
        resolved = dereference(value)
        if isinstance(resolved, ArrayObject):
            return [node for item in resolved for node in self.structure(item, inherited_page)]
        if isinstance(resolved, NumberObject):
            return [{"kind": "mcid", "reference": reference(value), "role": None, "page": inherited_page, "mcid": str(resolved), "objectReference": None, "properties": resolved, "children": []}]
        if not isinstance(resolved, DictionaryObject):
            raise ValueError("Unexpected fixture /K type")
        page = raw(resolved, "/Pg", inherited_page)
        kind = {"/MCR": "mcr", "/OBJR": "objr"}.get(str(resolved.get("/Type")), "element")
        return [{
            "kind": kind, "reference": reference(value), "role": raw(resolved, "/S"),
            "page": page, "inherited": "/Pg" not in resolved,
            "mcid": str(resolved["/MCID"]) if "/MCID" in resolved else None,
            "objectReference": reference(raw(resolved, "/Obj")), "properties": resolved,
            "namespace": raw(resolved, "/NS"),
            "children": self.structure(raw(resolved, "/K"), page) if "/K" in resolved else [],
        }]

    def compare_node(self, actual_id, expected, snapshot, by_id, page_numbers, parent_id):
        node = by_id[actual_id]
        for key in ("kind", "reference", "mcid", "objectReference"):
            self.equal(node[key], expected[key], "Structure node " + key)
        self.equal(node["parentId"], parent_id, "Original logical parent order")
        self.compare_value(node["properties"], expected["properties"], "Original structure properties")
        if expected["role"] is None:
            self.equal(node["role"], None, "Content references have no invented role")
        else:
            self.compare_value(node["role"], expected["role"], "Original structure role")
        identity = reference(expected["page"])
        if identity is None:
            self.equal(node["page"], None, "Unspecified structure page")
        else:
            self.equal(node["page"]["reference"], identity, "Original/inherited page reference")
            self.equal(node["page"]["number"], page_numbers[(identity["number"], identity["generation"])], "One-based page lookup")
            self.equal(node["page"]["inherited"], expected.get("inherited", True), "Inherited page marker")
        namespace = expected.get("namespace")
        if namespace is not None:
            self.equal(node["namespace"]["reference"], reference(namespace), "Namespace retains physical identity")
            self.equal(node["namespace"]["uri"], str(dereference(namespace)["/NS"]), "Namespace URI preview")
        self.equal(len(node["children"]), len(expected["children"]), "Ordered child count")
        for child_id, child in zip(node["children"], expected["children"]):
            self.compare_node(child_id, child, snapshot, by_id, page_numbers, actual_id)

    def compare_associations(self, snapshot, reader, pages, expected_nodes):
        expected = []
        for owner, dictionary in [("catalog", reader.root_object)] + [("page", page) for _, page in pages]:
            expected.extend((owner, item) for item in raw(dictionary, "/AF", []))

        def visit(nodes):
            for node in nodes:
                value = node["properties"]
                if isinstance(value, DictionaryObject):
                    expected.extend(("structure", item) for item in raw(value, "/AF", []))
                visit(node["children"])

        visit(expected_nodes)
        if "/Names" in reader.root_object:
            names = reader.root_object["/Names"]["/EmbeddedFiles"]["/Kids"][0].get_object()["/Names"]
            expected.extend(("embedded-files", names[index]) for index in range(1, len(names), 2))
        self.equal(len(snapshot["associatedFiles"]), len(expected), "AF and EmbeddedFiles associations all retained")
        expected_by_reference = {(item.idnum, item.generation): (owner, item) for owner, item in expected}
        for association in snapshot["associatedFiles"]:
            identity = association["reference"]
            owner, filespec_ref = expected_by_reference[(int(identity["number"]), identity["generation"])]
            filespec = filespec_ref.get_object()
            self.equal(association["owner"]["kind"], owner, "Association owner")
            self.equal(association["filename"], str(filespec.get("/UF", filespec["/F"])), "Filename preview")
            self.equal(association["relationship"], str(filespec["/AFRelationship"])[1:], "Association relationship")
            self.compare_value(association["properties"], filespec, "Original filespec properties")
            self.equal(len(association["embeddedStreams"]), len(filespec["/EF"]), "Embedded stream metadata count")
            for stream in association["embeddedStreams"]:
                identity = stream["reference"]
                original = IndirectObject(int(identity["number"]), identity["generation"], reader).get_object()
                self.equal(stream["encodedByteLength"], len(original._data), "Undecoded stream byte length")
                self.equal(inspected_value(stream["properties"]), source_stream_dictionary(original), "Undecoded embedded stream properties")

    def validate_source(self, path: Path):
        snapshot, encoded = self.inspect(path)
        reader = PdfReader(path, strict=True)
        self.equal(snapshot["truncated"], False, "Ordinary fixture is complete")
        self.equal(snapshot["document"]["version"], reader.pdf_header[5:], "PDF header version")
        self.equal(snapshot["document"]["catalogReference"], reference(reader.trailer.raw_get("/Root")), "Catalog identity")
        self.compare_value(snapshot["document"]["catalog"], reader.root_object, "Original catalog properties")
        pages = self.pages(reader.root_object.raw_get("/Pages"))
        self.equal(len(snapshot["pages"]), len(pages), "Original page count")
        page_numbers = {}
        for index, ((page_ref, page), actual) in enumerate(zip(pages, snapshot["pages"]), 1):
            self.equal(actual["number"], index, "Page document order")
            self.equal(actual["reference"], reference(page_ref), "Page physical identity")
            self.compare_value(actual["properties"], page, "Page dictionary before inherited values are flattened")
            page_numbers[(str(page_ref.idnum), page_ref.generation)] = index
        tagged = "/StructTreeRoot" in reader.root_object
        self.equal(snapshot["document"]["tagged"], tagged, "Tagged/untagged state")
        expected_nodes = []
        if tagged:
            structure_ref = reader.root_object.raw_get("/StructTreeRoot")
            structure = structure_ref.get_object()
            self.equal(snapshot["document"]["structureRootReference"], reference(structure_ref), "Structure tree physical identity")
            self.compare_value(snapshot["document"]["structureRoot"], structure, "Original StructTreeRoot properties")
            expected_nodes = self.structure(raw(structure, "/K"))
            by_id = {node["id"]: node for node in snapshot["nodes"]}
            self.equal(len(by_id), len(snapshot["nodes"]), "Unique structure occurrence IDs")
            self.equal(len(snapshot["roots"]), len(expected_nodes), "Structure root count")
            for node_id, expected in zip(snapshot["roots"], expected_nodes):
                self.compare_node(node_id, expected, snapshot, by_id, page_numbers, None)
            namespaces = [node["namespace"] for node in snapshot["nodes"] if node.get("namespace")]
            self.equal(len(set(item["identity"] for item in namespaces)), 2, "Same-URI namespaces remain distinct")
            self.equal(len(set(item["uri"] for item in namespaces)), 1, "Fixture has intentionally equal namespace URIs")
            expected_mappings = [("structure-root", key, value) for key, value in structure["/RoleMap"].items()]
            for namespace in structure["/Namespaces"]:
                namespace_object = namespace.get_object()
                expected_mappings.extend((f"obj-{namespace.idnum}-{namespace.generation}", key, value) for key, value in namespace_object.get("/RoleMapNS", {}).items())
            actual_mappings = [(item["owner"], inspected_value(item["source"]), inspected_value(item["target"])) for item in snapshot["roleMappings"]]
            self.equal(actual_mappings, [(owner, source_value(key), source_value(value)) for owner, key, value in expected_mappings], "Original RoleMap and namespace RoleMapNS values")
        else:
            self.equal(snapshot["roots"], [], "Untagged documents still inspect without invented tags")
            self.equal(snapshot["nodes"], [], "Untagged documents have no structure nodes")
        self.compare_associations(snapshot, reader, pages, expected_nodes)
        for item in snapshot["objects"]:
            if item["status"] == "available":
                identity = item["reference"]
                original = IndirectObject(int(identity["number"]), identity["generation"], reader).get_object()
                self.compare_value(item["value"], original, "Original referenced object properties")
        for forbidden in ["CATALOG_PAYLOAD_MUST_NOT_APPEAR_IN_JSON", "UNKNOWN_FILTER_PAYLOAD_MUST_NOT_BE_DECODED", "embedded-file-must-not-execute", "NAMED_PAYLOAD_MUST_NOT_APPEAR_IN_JSON"]:
            self.equal(forbidden in encoded, False, "Embedded stream payload is never exposed")
        self.equal(snapshot["diagnostics"], [], "Valid fixture requires no diagnostics")

    def validate_adversarial(self, directory: Path):
        malformed, _ = self.inspect(directory / "cycles-and-missing.pdf")
        kinds = {node["kind"] for node in malformed["nodes"]}
        self.equal("cycle" in kinds, True, "Ancestor cycle is explicitly represented")
        self.equal("repeated" in kinds, True, "Repeated node is distinguished from an ancestor cycle")
        missing = {item["reference"]["number"] + ":" + str(item["reference"]["generation"]) for item in malformed["objects"] if item["status"] == "missing"}
        self.equal({"99:0", "18:0", "10:0"}.issubset(missing), True, "Missing, free and wrong-generation references are not PDF null")
        tiny, encoded = self.inspect(directory / "classic.pdf", "tiny")
        self.equal(tiny["truncated"], True, "Cumulative budget exhaustion is explicit")
        self.equal(len(tiny["nodes"]) <= 2, True, "Node budget")
        self.equal(len(tiny["objects"]) <= 2, True, "Object budget")
        self.equal(len(tiny["diagnostics"]) <= 3, True, "Diagnostic budget")
        self.equal(len(encoded) < 30000, True, "Small-budget result remains bounded")
        for profile, diagnostic in [("nodes", "limit-nodes"), ("objects", "limit-objects"), ("values", "limit-values"), ("bytes", "limit-bytes"), ("strings", "limit-string"), ("associations", "limit-associations"), ("pages", "pages-unavailable"), ("depth", "limit-depth")]:
            limited, _ = self.inspect(directory / "classic.pdf", profile)
            self.equal(limited["truncated"], True, "Independent limit is explicit: " + profile)
            self.equal(diagnostic in {item["code"] for item in limited["diagnostics"]}, True, "Specific exhausted budget: " + profile)
            if profile in {"nodes", "objects"}:
                self.equal(len(limited[profile]), 2, "Only the allowed rows are retained: " + profile)
            elif profile == "associations":
                self.equal(len(limited["associatedFiles"]), 1, "Only the allowed association is retained")
            elif profile == "strings":
                def inspect_bytes(value):
                    if isinstance(value, dict):
                        if value.get("type") in {"string", "name"}:
                            self.equal(len(bytes.fromhex(value["hex"])) <= 8, True, "Every byte string preview is bounded")
                        for item in value.values():
                            inspect_bytes(item)
                    elif isinstance(value, list):
                        for item in value:
                            inspect_bytes(item)
                inspect_bytes(limited)
        limited, _ = self.inspect(directory / "cycles-and-missing.pdf", "diagnostics")
        self.equal(limited["truncated"], True, "Suppressed diagnostics mark the snapshot partial")
        self.equal(len(limited["diagnostics"]), 2, "Diagnostic count is independently bounded")

    def validate(self):
        directory = self.root / "tests/Fixtures/inspection"
        manifest = json.loads((directory / "manifest.json").read_text())
        for name, metadata in manifest["fixtures"].items():
            path = directory / name
            self.equal(len(path.read_bytes()), metadata["bytes"], "Fixture byte count: " + name)
            self.equal(hashlib.sha256(path.read_bytes()).hexdigest(), metadata["sha256"], "Fixture SHA-256: " + name)
            if name != "cycles-and-missing.pdf":
                subprocess.run([self.qpdf, "--check", str(path)], check=True, capture_output=True, timeout=30)
                self.validate_source(path)
        xref = subprocess.check_output([self.qpdf, "--show-xref", str(directory / "object-streams.pdf")], text=True)
        self.equal(bool(re.search(r"(?m)^\d+/0: compressed;", xref)), True, "Independent rewrite contains compressed objects")
        self.validate_adversarial(directory)
        return {"complete": True, "checks": self.checks, "php": subprocess.check_output([self.php, "--version"], text=True).splitlines()[0], "pypdf": pypdf.__version__, "qpdf": subprocess.check_output([self.qpdf, "--version"], text=True).splitlines()[0], "fixtures": manifest["fixtures"]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php")
    parser.add_argument("--qpdf", default="qpdf")
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    result = InspectionOracle(Path(__file__).resolve().parents[1], args.php, args.qpdf).validate()
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(f"Validated {result['checks']} independent source-structure, reference, byte, opaque-stream and bounded-inspection checks.")


if __name__ == "__main__":
    main()
