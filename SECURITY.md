# Security policy

English | [Français](SECURITY.fr.md)

The reader accepts external documents. Current controls and limits are
explained in [docs/security.md](docs/security.md). They must be supplemented
by the application's access controls, quotas and budgets.

## Supported versions

The `0.1.x` series is supported and receives security fixes. PHP 8.3 or newer
is required; PHP 8.3, 8.4 and 8.5 are checked in CI. Use a maintained PHP version
with its updates.

## Report a vulnerability

Use [GitHub private reporting](https://github.com/PDFToolkit/pdf-reader/security/advisories/new)
when available. Do not publish an exploitable payload, confidential document
or credentials in a public issue.

If private reporting is unavailable, open an issue requesting a confidential
channel without revealing exploitation details.

A useful report includes:

- The reader and `roukmoute/pdf` revisions used.
- The PHP version, relevant extensions and configured `ReaderLimits`.
- The affected surface, observed behaviour and expected impact.
- A minimal reproduction, preferably a synthetic PDF without personal data.

Explicitly unsupported mechanisms raise `UnsupportedPdfFeature`. An expected
budget overrun raises `ParseException`. Bypassing these checks, unbounded resource
consumption or confusion in object resolution may be a security issue and
should be reported.

The model and writer have their own
[security policy](https://github.com/PDFToolkit/pdf/blob/master/SECURITY.md).
