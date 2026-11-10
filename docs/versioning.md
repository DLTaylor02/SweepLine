# Application versions

SweepLine uses a manually maintained `VERSION` file. Every commit is a release. Before committing a patch, increment the middle component once (for example, `0.123.0` to `0.124.0`) and include VERSION in that commit. Versions are no longer calculated from Git history. Explicit patch releases may increment the last component instead, for example `0.147.0` → `0.147.1` → `0.147.2`. The next middle-component release resets the patch to zero, such as `0.148.0`. Both forms are accepted by the shared application and installer version validator.

The initial numbering began at `0.1.0` and was caught up to the project's existing commits. Read the current release stamp from `VERSION`. Future release numbers are maintained explicitly, including for documentation-only commits.

The installer copies and validates the bundled VERSION without changing it. Full clones, shallow clones and source archives therefore deploy the same version. No Git access, commit hook or version-generation step is required on the server.

To print the bundled version:

```bash
php bin/version.php
```

Every page shows the version in its header. GPX and KMZ carry it in descriptions, PDFs print it in their header/footer, and BeamNG maps include it in menu titles, preview images, installation notes, reports and build logs. Existing downloaded files retain the version that generated them.

Keep VERSION, release notes and source code together when packaging or committing changes. Rerun `sudo ./setup.sh` from the updated source to deploy the new version.
