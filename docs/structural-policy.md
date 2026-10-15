# Explicit structural policy

English | [Français](fr/structural-policy.md)

`new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20)` enables targeted
ISO 32000-2 EC3 structural checks. `StructuralPolicy::Compatible` is the default
and preserves historical reading tolerance. Selecting `Pdf20` applies these
checks to the supplied source, even when its header declares an older version;
it does not silently upgrade that source or establish complete PDF conformance.

The policy follows ordinary reads, authenticated graphs, object streams and
physical revision views. `requiringSourceIntegrity()` preserves it; structural
validation and the configured authentication/MAC policy remain separate concerns.
Direct `SyntaxParser` and structural-reader users may supply the same policy.

EC3 issue379 (physical source pages 48 and 1020) requires canonical decimal
object/generation identifiers. In `Pdf20`, object headers, indirect references
and object-stream member numbers reject leading zeros; a generation may be the
single digit `0`. Generic numeric objects and fixed-width traditional xref fields
keep their existing syntax. A leading plus sign is not an object identifier.

The policy adds no I/O, repair, PKI trust or modification authority. All existing
resource limits still apply. See [physical history](revision-history.md).

EC3 issue114 (physical page 77) extends the ObjStm rule beyond `Length` to every
dictionary value. `Pdf20` rejects any such value that references a compressed
object. Direct values and references to ordinary objects remain permitted.
This rule concerns each entry's value; it does not introduce xref-stream-style
recursive directness for elements inside an otherwise direct array or dictionary.
