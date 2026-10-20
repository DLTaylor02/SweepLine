# Optional developer data tools

Normal installations do not need these commands. The installer queues ZIP preparation and searches prepare road coverage for the user's chosen ZIP code automatically. No default locality or road region is configured. SuperAdmin management exposes progress, errors, and retries.

The retained tools support debugging and controlled test datasets:

- `php bin/import-zips.php /path/to/US.txt`: ingest an authentic GeoNames postal file.
- `php bin/import.php <region-slug> 'Region name' /path/to/region.osm <west,south,east,north>`: queue a local genuine extract for explicitly chosen bounds.
- `bash bin/prepare-extract.sh /path/to/regional.osm.pbf <west,south,east,north> /path/to/output.osm`: use Osmium complete-way clipping/filtering and XML conversion.
- `php bin/status.php`: inspect explicit import jobs; ordinary automatic tasks are visible in the web UI.

Replace angle-bracket placeholders before running a command. [Geofabrik](https://download.geofabrik.de/) supplies regional PBF extracts. Select coverage for your intended region and merge same-snapshot neighboring extracts when needed. Declare only coverage genuinely provided by the source. Do not import full states into the bounded PHP graph stage: the limits are 1M nodes, 30k normalized ways, and 10k nodes per way.

Run local tools under the application Linux account on a deployed server to retain correct permissions. No shell command, arbitrary URL, or file upload is accepted from ordinary web search input. Geographic coordinates and postal records in automated tests are explicitly synthetic fixtures, not application defaults.
