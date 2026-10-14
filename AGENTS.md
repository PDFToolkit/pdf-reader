# Contributor and agent instructions

## Architecture

- Keep external PDF reading in this package and reuse the core PDF object model.
- Use explicit objects, immutable read views and document-scoped state. Avoid singletons and global registries.
- Preserve configurable resource limits, cumulative budgets and explicit errors for unsupported PDF mechanisms.
- Do not add a production dependency from the core to this reader.

## Completion and releases

- Keep commits atomic, with a clear purpose and no WIP commits in the published history.
- Release each affected package when a coherent addition, correction or documentation update has been completed and validated. Publishing the changes on `master` alone does not complete the work.
- Follow [RELEASING.md](RELEASING.md): use independent package versions, stable dependency constraints, annotated tags and GitHub release notes.
- The initial publication of the optional packages used one bootstrap exception, recorded in RELEASING.md. It is complete and must not be repeated for later releases. Module production dependencies must remain stable throughout.
- Before tagging, require successful checks for the exact candidate commit on PHP 8.3, 8.4 and 8.5, including the repository's independent PDF validations.
- Do not rewrite a published tag or edit an old release to include new changes. Publish a new version instead.
- Keep the reader's guides beside its code. Maintain shared guides, site tooling and publication in [PDFToolkit/pdftoolkit.github.io](https://github.com/PDFToolkit/pdftoolkit.github.io), serving [the documentation root](https://pdftoolkit.github.io/) and [reader entrypoint](https://pdftoolkit.github.io/modules/reader/).
- After a reader release, update the documentation site's released-source pins and provenance to its published version and exact tag commit. Build module guides and API documentation from that pinned source, verify source links, and publish the site update independently of the core package.
- Report the versions and release links when the work is complete.
- Publish and verify stable releases on Packagist. Fresh consumers must install through the default Composer repositories without VCS or path repository configuration. Keep package documentation links current in Composer metadata.

## Documentation languages

- Use English by default for public documentation and maintain complete French counterparts with reciprocal language links.
- Update both languages together, preserving API identifiers, normative source wording and executable example behaviour. Verify equivalent links and examples in both languages.
