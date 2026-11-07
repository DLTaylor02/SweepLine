# Application versions

SweepLine uses `0.N.0`, starting with `0.1.0` for the first commit. `N` is the number of commits reachable from the checked-out commit (`git rev-list --count HEAD`), including merge commits. The initial stamp is **0.120.0**, based on the 120 existing commits. This is a commit counter, not conventional semantic versioning. Every commit is considered released immediately; there is no separate unreleased period.

`VERSION` is the single runtime source. Web requests never execute Git. Every page shows the version in its header; GPX and KMZ carry it in descriptions, PDFs print it in their header/footer, and BeamNG maps include it in the menu title, preview images, installation notes, report and build log. Existing downloaded files are not rewritten.

Committing does not rewrite the tracked VERSION file: no Git commit hook is installed. The installer automatically stamps the deployed VERSION from the source checkout's complete Git history. No new package is required: if Git/history is unavailable, it retains the bundled VERSION. Shallow clones cannot establish a lifetime count and also retain that stamp; use a full checkout for accurate commit-based deployment versions.

For a release archive or local development, refresh the stamp before packaging:

```bash
php bin/version.php --write
```

Without `--write`, the command prints the version derived from the current checkout. Run it after the commit whose version you want to package. The installer recalculates after subsequent commits automatically. Source archives must include the stamped VERSION file. Branches with the same reachable commit count can share a number; the exported build diagnostics remain separate from this application version.
