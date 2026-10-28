# Physical revision fixtures

English | [Français](README.fr.md)

`trailing-xref.pdf` is the deterministic unsigned output of
`trailing_objects_initial()` in [the independent oracle](../../../tools/validate_revision_history.py).
Its Python byte producer uses no PHP implementation. The catalog/pages precede
the xref stream; three ordinary indexed objects follow it, including a stream
whose indirect length is stored after that stream. Fake footer/endobj text stays
inside the length-delimited payload.

The oracle compares the committed bytes with that producer, checks them with
qpdf and pyHanko, then makes a genuine CMS signature and subsequent update using
unmodified pyHanko APIs. Ephemeral certificate keys and signed variants are
generated only in the caller's validation directory; they are not committed.

`linearized-table.pdf` and `linearized-stream.pdf` are independently rewritten
from `../pdf/classic.pdf` by qpdf 12.4.2 with `--static-id --linearize` and explicit
object-stream policies. `linearized-manifest.json` retains source/output hashes.
Run `python tools/build_linearized_fixtures.py --check` from the repository root
to reproduce their exact bytes. Native tests exercise grouping, later increments,
malformed parameters and shared budgets. The history oracle additionally produces
clear/encrypted linearized files, then signs and updates them independently.
