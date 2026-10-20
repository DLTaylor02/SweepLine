# Architectural decisions

## 1. Preserve the deployment contract

Adapt the supplied installer rather than replace it. Keep its configuration, helper functions, project hooks, rsync exclusions, non-login user, FPM socket, NGINX template, service checks, and systemd hardening. Extend it with resource ownership checks, version-specific PHP/PostGIS package selection, migrations, HTTP health verification, and NGINX configuration rollback. Database provisioning is deliberately limited to the local default cluster so an environment override cannot silently target a different cluster. Existing marked deployments use their deployed credentials.

## 2. Bounded local ingestion

Automatic Overpass jobs return bounded complete-way XML for ingestion through PHP XMLReader. Optional developer PBF imports use Osmium to produce the same XML format. This avoids a persistent ingestion runtime and allows a small Overpass development extract using the identical importer. Nodes persist per import; relevant ways are normalized and indexed. The graph stage is bounded and memory-resident, rather than pretending to scale to the entire country. Extend regions now; move graph partitioning/preprocessing into SQL or a dedicated offline pipeline if scale requires it.

References: [Osmium cat](https://docs.osmcode.org/osmium/latest/osmium-cat.html), [Geofabrik regional extracts](https://download.geofabrik.de/). Automatic cells derive coverage from their fixed request bounds; optional local imports declare coverage explicitly; source hashes and activation timestamps are recorded. No external calls occur for geographic searches.

## 3. Atomic dataset visibility

Import jobs own every node, segment, connectivity row, candidate, and score. Only `regions.active_job_id` makes a completed job visible. Validate the extract and generated candidates before activation; verified empty automatic extracts may activate empty coverage. Keep previous jobs for rollback/diagnosis. A session advisory lock permits one importer, while queued jobs are claimed under row locks. A dead worker's running jobs are failed, not resumed from partially committed batches. This favors correctness over resumability. Automatic data tasks retry or subdivide interrupted/failed work, refresh stale searched coverage, and prune nonactive automatic import attempts after a week. SuperAdmin can retry terminal failures in the web UI.

## 4. Continuous stretches rather than OSM way results

Split ways at shared nodes. Extend each edge at both ends when exactly one matching identity/class/direction continuation turns less than 55 degrees. Prefer `ref` over name, using case-normalized strings. Stop at ambiguity; do not invent a turn through a fork. A visited-edge set prevents cycles and double assignment. Chains target a maximum of about 40 km, allow a final edge up to 50 km, and retain long single ways intact. Short candidates under 400 m are discarded. OSM way boundaries with only two distinct neighbors do not increase intersection counts. Actual junctions penalize continuity but do not automatically imply stops. Identity changes and unresolved directional transitions break candidates.

## 5. Geometry and quality

Haversine lengths and local bearings support uniformly resampled geometry (30 m). The qualifying curve radius band is 40–600 m; sharp changes >=55 degrees are excluded. Curve quality blends weighted curvature, sustained sample runs, and length. Mapping jitter and urban right-angle grids cannot create arbitrary scores from dense points. This heuristic needs calibration against real enthusiast judgments; it is not a vehicle-dynamics model. Database radius queries use spheroidal PostGIS geography, with a matching GiST index: [ST_DWithin documentation](https://postgis.net/docs/ST_DWithin.html).

Dimensions are normalized 0–1; weights are centralized, sum to 1, and yield 0–100 scores. Known missing attributes receive conservative values. A separate completeness value reflects attribute availability, and a modest score factor avoids missing attributes winning by default. Completeness has a structural base for valid topology and is not a statistical confidence interval. Mandatory control counts include unconditional traffic signals along an unshared segment and explicit all-way stops; shared/directional signals, ordinary stops, and barriers remain uncertain. A side-approach node is counted only if that node actually lies on the candidate. Signal counts mean potential interruptions, not a claim the signal is always red. Missing controls can never be detected from OSM alone.

## 6. Elevation deferred honestly

Gain/loss/max-grade fields are nullable, omitted from profile weights, and displayed as unavailable. A future preprocessing stage can sample USGS 3DEP DEM tiles at 20–30 m spacing, interpolate heights in a compatible vertical datum, smooth noise before computing grade/gain/loss, and store source/version/sample spacing. This avoids querying elevation APIs per search or misleading users with noisy OSM `ele` tags. Recompute cached scores only after validating a real elevation source.

## 7. Bounded read API and map

Web requests never import or score roads. Radius is capped at 150 miles, results at 100, geometries simplified for map transport, request bodies limited, and SQL/web execution time bounded. Whole candidate metrics are returned for roads intersecting the radius, rather than misleadingly claiming the whole road lies inside it. Coverage compares the search circle with the union of declared active polygons. MapLibre uses a replaceable raster basemap; all dynamic road labels use DOM text rather than HTML insertion.

## 8. Installation bootstrap and web-managed data

The entry point is `sudo ./setup`; all system provisioning remains in the established `setup.sh` template. The default port prompt uses 1279 for a fresh install and preserves an existing port on upgrades. The non-login Linux user is a service identity, independent of application accounts.

Migration 002 adds hashed-password users, a one-time setup-token hash, login attempt counters, settings and a persistent data queue. Initial setup serializes account creation under a table lock and requires the code printed in the installation terminal. Session IDs regenerate on login; cookies are HttpOnly/SameSite, mutations require CSRF, and account changes invalidate sessions via version counters. Only SuperAdmin/member capabilities are defined pending permission design.

The installer queues GeoNames initialization. Searches queue absent/stale half-degree OSM cells, with smaller cells for dense areas and a short continuity halo. Precomputed canonical geometry keys deduplicate identical whole-way candidates across cells without dropping roads whose midpoint lies beyond the requested area. Cross-cell fragmented candidates still need future stitching. Bounding boxes plan acquisition; search distance remains PostGIS geography. Valid empty OSM data is coverage rather than an endless import failure.

The PHP worker downloads and extracts ZIP coordinates, fetches OSM, and delegates local analysis to the established importer. Cached data, retry backoff, restart recovery/subdivision, provider quotas and resource guards bound shared-host work. Failed refreshes retain active datasets. The web UI polls preparation status and exposes failed-job retries to SuperAdmin. Normal operation needs no command-line imports; those tools remain optional developer diagnostics. Source availability still constrains cold searches, so the UI shows real progress/errors rather than invented results.
