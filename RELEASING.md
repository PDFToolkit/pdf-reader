# Releasing pdftoolkit/pdf-reader

This repository contains the external PDF reader. Its versions are independent of the other PDFToolkit packages.

## When to release

A completed, validated addition, correction or documentation update must have a published version in each affected package. Group related atomic commits into a coherent release. Do not publish a release automatically for every push, and do not release unchanged packages just to synchronize version numbers.

Use annotated Git tags named `vX.Y.Z` and GitHub releases. Composer derives the package version from its tag; do not add a `version` field to `composer.json`.

## Version selection

| Change | Version increment |
| --- | --- |
| First public version of a package | Start at `0.1.0` |
| New feature or incompatible public API change before 1.0 | Increment the minor version, reset the patch to zero |
| Compatible correction, dependency maintenance or documentation update before 1.0 | Increment the patch version |
| Incompatible public API change from 1.0 onward | Increment the major version |
| Compatible feature from 1.0 onward | Increment the minor version |
| Compatible correction or documentation update from 1.0 onward | Increment the patch version |

Describe incompatible changes and the required migration in the changelog and release notes. Changing the minimum PHP version is a compatibility change.

## Dependencies and publication order

- Released packages must use stable version constraints for PDFToolkit dependencies. Do not publish runtime requirements on `dev-master`, local path repositories or unpublished commits.
- Keep the core free of production dependencies on the reader, image converters and font converters. Its development dependencies may use those packages for examples and cross-package validation.
- Publish a changed dependency before packages that require its new API. Check the actual dependency graph, including development dependencies, before choosing a publication order.
- For later releases, when a core change and a module change depend on each other for development checks, validate them together locally first. Publish the dependency with development dependencies on compatible, already released modules, then publish the changed modules. Update the dedicated documentation site's released-source pins after publishing the changed packages.
- Update lock files using the declared repositories. Outside the initial bootstrap exception below, their PDFToolkit source references must identify real commits from the selected published tags.
- Verify installation in a fresh consumer project with stable package versions. Test both the affected module and the core alone; installing the core must not install optional modules.
- Shared guides, API generation, site tooling and publication live in [PDFToolkit/pdftoolkit.github.io](https://github.com/PDFToolkit/pdftoolkit.github.io), published at [the documentation root](https://pdftoolkit.github.io/). Module guides remain beside their code and are rendered at [the reader entrypoint](https://pdftoolkit.github.io/modules/reader/).
- After a module release, update the site's released-source pins and provenance with the published version and selected tag's exact commit. Rebuild module guides and API documentation from those pinned sources, verify that source links identify the same commit, and publish the site update independently of package releases.

### Historical initial optional-package bootstrap

This sequence is complete and records the first publication, when the core also owned the shared site. Current documentation publication belongs to the dedicated repository described above.

The first release of the extracted optional packages creates a publication cycle: their production code needs the new core API, while the core's examples and documentation use those modules as development dependencies. For this initial publication only, the following sequence is allowed:

1. Publish core `0.2.0` from a validated commit whose development dependencies lock the tested module snapshots. These temporary branch requirements belong exclusively to `require-dev`; the core still has no production dependency on any module. Use `COMPOSER_ROOT_VERSION=dev-master` for this bootstrap's development checks.
2. Publish each optional package as `0.1.0`, with a stable production requirement on `roukmoute/pdf:^0.2`. Its lock file must reference the published core tag. Keep normal stable minimum stability.
3. Replace the core's temporary module requirements and lock entries with their published `0.1.0` versions, rebuild the shared documentation from those tagged commits, and publish core `0.2.1`. Use the core's `0.2.x-dev` branch alias and `COMPOSER_ROOT_VERSION=0.2.x-dev` for its development checks, so the module requirement `^0.2` also accepts the core checkout.

The bootstrap is complete only after the stabilization patch and all affected package checks, stable installation checks and documentation verification succeed. Do not stop with the temporary development dependencies. This exception does not permit unstable production dependencies, skipped validation, moving a tag, or repeating the bootstrap for later feature releases.

## Prepare and validate

1. Inspect local changes, existing remote tags and GitHub releases. Choose the next unused version based on the previous published version and the complete set of unreleased changes.
2. Update `CHANGELOG.md` with a dated section for the chosen version. Record additions, corrections, compatibility changes, minimum PHP requirements and material limitations. Leave an empty unreleased section for future work.
3. Make coherent commits for the implementation, validations and release preparation. Ensure the working tree is clean before selecting the candidate commit.
4. Run `composer validate --strict`, `composer install`, `composer check-platform-reqs`, `composer audit --locked` and `composer check`. Run the independent fixture or generated-PDF checks in `.github/workflows/quality.yml`. For core changes, also verify specification generation and the documentation build where relevant.
5. Push the candidate commit. Require the Quality jobs on PHP 8.3, 8.4 and 8.5 to succeed for that exact commit, and require every other applicable repository check to succeed. An older successful run is not sufficient.
6. Verify that the staged release notes describe the actual candidate, use its dependency versions, and link to its changelog and relevant documentation.

## Publish and verify

Select `RELEASE_TAG`, `RELEASE_COMMIT` and `RELEASE_NOTES_FILE` explicitly. The commit must be the full SHA validated above, and the notes file must contain the prepared release notes. With those values set, the publication commands are:

```sh
git tag -a "$RELEASE_TAG" "$RELEASE_COMMIT" -m "Release $RELEASE_TAG"
git push origin "$RELEASE_TAG"
gh release create "$RELEASE_TAG" --verify-tag --title "$RELEASE_TAG" --notes-file "$RELEASE_NOTES_FILE"
```

Before creating the tag, check that the name is absent both locally and remotely. After publishing:

1. Compare `git rev-parse "$RELEASE_TAG^{commit}"` and the remote peeled tag reference with `RELEASE_COMMIT`. Both must identify the validated commit.
2. Confirm the GitHub release is published, has the correct tag and notes, and is not a draft or prerelease unless a prerelease was deliberately selected.
3. Verify checks triggered by the tag, package installation from that version, and any documentation deployment affected by the release.
4. Report each affected package's version and GitHub release URL.

Never force-update, delete and recreate, or move an existing published tag. Never append new changes to an old release. Correct a published package with a new version. Do not publish unrelated changes while resolving a release problem.
