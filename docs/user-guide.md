# Discovery and road tools

[Documentation index](README.md) | [Project overview](../README.md)

This reference retains implementation notes in their original order. Later corrections supersede earlier behavior. BeamNG source downloads are now cached for seven days.

## Search and automatic data preparation

No city, ZIP code, or road region is preselected. The map opens with a global overview; your chosen ZIP code determines the search center and required road coverage.

1. Enter a US ZIP code, radius, and driving profile.
2. Optionally specify a road **longer than** a minimum and/or a **maximum road length**.
3. Submit the search. Cached coverage returns ranked roads immediately.
4. If data is missing, SweepLine queues the required areas and shows a spinner, completed-area progress bar, and current preparation message beside the search controls. A matching indicator appears over the map. Available roads are labeled as partial results while preparation continues; area progress is not a time estimate. The worker downloads and analyzes those areas; the page updates automatically when they are ready.

The worker initializes US ZIP coordinates from GeoNames automatically, including on a new installation. A search requiring unavailable ZIP data waits for preparation. A ZIP absent from a completed dataset produces a clear not-found message.

Missing road coverage is acquired from OpenStreetMap through bounded Overpass downloads. Half-degree areas are reused between searches; dense areas automatically split down to one-eighth degree when extract limits are exceeded. Downloads and analysis run in the dedicated worker, **never inside the HTTP search request**. Requests for the same area share one task rather than downloading again. Verified empty extracts are valid coverage and do not cause endless imports.

ZIP data refreshes approximately every 30 days. Road areas refresh when searched after 30 days. Existing active data remains in place until its replacement succeeds, and available results are shown during preparation. Temporary network/provider failures retry automatically with backoff, up to four attempts; persistent failures appear in the UI with a SuperAdmin retry action. No fabricated data is substituted. The operator does not need shell commands to import or retry routine data preparation.

A first search covering a large uncached radius can take many minutes or longer. Provider availability, rate limits, data density, bandwidth, CPU and storage determine preparation time. A self-hosted application cannot make an unavailable upstream source respond; errors are surfaced in the UI instead of reporting incomplete downloads as success. For a high-volume deployment, an administrator can configure a dedicated HTTPS Overpass endpoint rather than relying on the public service. See the [provider's resource policy](https://dev.overpass-api.de/overpass-doc/en/preface/commons.html).

## Profiles and filters

- **Twisty Roads:** sustained curves, meaningful length, and fewer intersections/controls.
- **Open Highways:** straightness, continuous stretches, suitable posted limits, and fewer interruptions, for lawful driving.
- Radius: whole miles from 1–50, entered directly (default 5). Search distance uses indexed PostGIS geography, not a longitude/latitude bounding box.
- Optional minimum length: 0–30 miles; roads must be strictly longer.
- Optional maximum length: a positive value up to 150 miles; the upper bound is inclusive and must exceed the minimum.
- Blank bounds impose no user-specified length limit. Importer candidate-size limits still apply.
- Stops: 0–100 mapped mandatory plus uncertain controls.
- Paved-only: enabled by default; unknown surfaces do not qualify.

Candidates intersect the search radius; length and score describe the **whole continuous road stretch**, including portions outside the radius. SweepLine discovers roads rather than generating navigable routes.

## Themes

The header provides Adaptive, Light and Dark themes. **Adaptive is the default**, follows the device's color scheme, and responds to changes while the page is open. Preferences are saved in browser storage and shared across tabs. Map styling and controls follow the theme.

## Pages and previous searches

The header links share the same button styling. **Users** (`/users`) is a dedicated SuperAdmin user-management page; all worker status, ZIP sync status, preparation progress, errors, counts, cancellation and retry controls live on **Manage** (`/manage`). **History** (`/history`) is available to every signed-in user and shows only their latest 100 searches across browsers. Searches made before this feature was installed cannot be recovered. Click a search title or **View results** to open the map and automatically retrieve current results with the saved filters. Reopening does not add a history entry. **Delete search** permanently removes that entry from your account; it does not cancel jobs or remove road data. History stores search parameters and timestamps, not road-result snapshots. The radius defaults to 5 whole miles (maximum 50).

`GET /api/search-history` returns the signed-in user's recent searches. The browser sends `record_history: true` for an initial `POST /api/search`, and false for background progress polling. Identical submissions within ten seconds are deduplicated. Search parameters include location information and remain in the application database, scoped to their account; older entries are removed automatically as the 100-entry limit is reached.

## Pick a ZIP on the map

Use **Pick on map** beside the ZIP input, then click a location. SweepLine uses the local ZIP dataset to find the nearest US ZIP center within 25 miles and fills the input. The UI shows its place/state and distance from the click. This is a nearest-center approximation, not a ZIP boundary lookup: the search still centers on the selected ZIP coordinate. Outside coverage, or while ZIP initialization is incomplete, a clear message appears and manual ZIP entry remains available. This feature makes no external reverse-geocoding requests. Authenticated endpoint: `GET /api/zips/nearest?lon=-100&lat=40` (422 invalid coordinates, 404 no nearby ZIP, 202 initial dataset preparing).

## Export a selected road

Select a road result, then choose **GPX**, **KMZ**, or **Printable PDF** in its detail panel. Exports use the full stored candidate geometry, not the simplified search-map line. GPX is a GPX 1.1 track with start/end waypoints. KMZ contains a KML road line, start/end points and road-shape notes. PDF is a downloadable, printable US Letter document with a labeled local OSM street map, start/end coordinates, distance/score/control metric cards, and geometry-based bend notes. Long instruction lists paginate automatically. Exports require a signed-in session and currently active road data.

Notes follow the exported track geometry, independently of OSM way splits: cumulative mileage, distance since the previous note, left/right bend descriptions, and estimated heading change rounded to 5 degrees. Geometry is sampled every 15 m and headings smoothed over roughly 60 m; bends below 15 degrees are omitted. Consecutive bends in opposite directions remain separate. Total mileage is calculated once from the exported track, avoiding repeated full-way lengths. These are approximate road-shape notes, not surveyed rally pace notes or junction turn instructions. They are not turn-by-turn navigation, a route from your current location, or a guarantee of access or complete mapped controls. One-way chains are oriented to the mapped legal direction; inconsistent mapped directions are refused. PDFs use locally imported OSM roads as a vector street basemap, with street labels, a north arrow and scale bar. They do not fetch external map tiles or require an image/PDF service. The overview page is followed by paginated bend-note tables with vector direction icons, bold mileage/angles and alternating rows. Helvetica regular, bold and italic fonts provide hierarchy; text uses basic Latin transliteration where available. GPX/KML preserve UTF-8 names. Elevation is not fabricated. Track imports into other apps may be displayed without navigational instructions. Road data attribution is included.

Download API: `GET /api/roads/{id}/export?format=gpx|kmz|pdf&profile=twisty|open`. Responses are authenticated attachments; unsupported formats return 422 and inactive/missing roads return 404. No additional packages or persistent services are required.

## Favorite roads

Open a road result and select **♡ Add to favorites**. The heart changes to **♥ Favorited** and can be clicked again to remove it. The dedicated **Favorites** page (`/favorites`) shows saved roads with local SVG geometry thumbnails, profile score, length, curve density, mapped/uncertain controls, speed where known, and data completeness. Each account has its own favorites (up to 200); identical geometry/profile pairs are deduplicated.

Click a card to open that selected road directly in the discovery map, highlighted and zoomed, with the original profile, saved metrics, score breakdown, search filters and export controls. Favorites store server-generated snapshots independent of transient import/candidate IDs, so reimports and removal of old data do not erase saved roads. The view clearly labels saved metrics; it is not a live road-condition report. Saved GPX/KMZ/PDF exports continue to work from the snapshot. Removing a favorite removes its saved snapshot only, not road data or background work. Account deletion cascades to its favorites.

API: `GET /api/favorites`, `POST /api/favorites` with `road_id`, `profile`, and original `search` filters; `GET /api/favorites/{id}`; `DELETE /api/favorites/{id}`; `GET /api/favorites/{id}/export?format=gpx|kmz|pdf`. All require authentication, mutations require CSRF, and every lookup/deletion is scoped to the current account. Migration 008 adds persistent favorites; rerun the installer when upgrading.

PDF map context is bounded to 1,200 nearby active OSM road segments and labeled if that limit is reached. It includes the roads already imported locally, not terrain, buildings, water polygons or live traffic. Missing surrounding data is explicitly labeled rather than replaced with a fictitious map. New favorites save map context with their road snapshot; older favorites use currently available local roads. Coordinates and the selected route are always projected together in Web Mercator. Both overview and notes include attribution and page numbers.

Favorite thumbnails use the saved local OSM street basemap with surrounding roads/labels, a blue route overlay, start/end markers and attribution. Images load lazily through the account-protected SVG endpoint `GET /api/favorites/{id}/thumbnail`. Older favorites lacking saved map context use currently imported surrounding roads. Missing local context is labeled explicitly; no external tile requests are made.

## Twisty Roads qualification

Twisty Roads now requires a curvature metric of at least 0.12, a user-selected minimum curve density (default 7 qualifying 30 m samples per mile), and a sustained curve run of at least 120 m. The other initial thresholds live in `config/profiles.php`; they are not counts of individual corners. Candidates missing these metrics are excluded. Qualification runs before result ranking/limits, so length, pavement and low interruptions cannot admit an otherwise straight road. Curvature supplies 70% of the Twisty score. Motorways remain eligible if their geometry qualifies. Open Highways retains its existing rules and weights. The road connectivity/import/surface/stop filters are unchanged.

The installer refreshes existing cached scores automatically using the centralized profile configuration; no OSM reimport is needed. After manually editing profile weights, run `sudo -u sweepline php /var/www/sweepline/bin/rescore.php` (adjust the deployment directory/account if customized). Saved Favorites keep their historical scores. The initial thresholds require field validation against actual searches; they are not calibrated specifically to any named road.

## Investigating a missing road

Open **Missing a road? Check why** below the search form, enter a road name or number, and select **Check this road**. It uses the ZIP, radius and filters currently shown in the form. The report shows imported OSM source tags, candidate lengths and mapped controls, and every failing length/surface/stop/curvature requirement. It can find a candidate by names/references on its member ways even when its displayed name differs. Unknown pavement is distinguished from confirmed pavement; missing local source ways are not automatically declared missing from OSM. Try a road name as well as a number when OSM route references may differ.

This is a read-only check of active local data. It does not relax filters, fetch external data, change scores, or start imports. Results are capped at 100 source ways and 100 candidates, and coverage/truncation are labeled. API: authenticated, CSRF-protected `POST /api/search-diagnostics` with the normal search parameters plus a `road` string (2–100 characters). Use the report to distinguish short fragments, classification/tag differences, strict filters and incomplete imports before tuning the algorithm.

Advanced filters expose **Minimum curve density**, default 7, accepting any finite number from 0 through 53.6448 (the theoretical maximum with 30 m sampling). This measures qualifying sampled curve points per mile, not the number of separate bends. It overrides the Twisty curve-density gate only; curvature quality and sustained-curve requirements still apply. Open Highways ignores this field. API parameter: `min_curve_density`. History, resumed searches and favorites preserve the chosen value; older saved searches use 7. Diagnostics use exactly the selected threshold.

Known-paved filtering requires every candidate piece to have one of the currently recognized explicit surface tags: `asphalt`, `paved`, `concrete` or `concrete:plates`. A single missing tag excludes the whole candidate. Missing tags mean unknown data, not proof of an unpaved road. Other surface values (including some physically paved finishes not yet recognized by the importer) also fail this narrow list. The missing-road report now distinguishes missing tags from values outside the accepted list. Disabling Known paved includes unknown surfaces **and** explicitly unpaved roads; the policy has not otherwise changed.

## Normalized advanced filters and surface policy

Curve density is displayed and filtered on a linear 1–10 scale: `1 + 9 × raw_samples_per_mile / 53.6448`. A score of 1 is zero qualifying samples and 10 the theoretical maximum. The new default is 2.2/10, approximately the previous raw threshold of 7; it is not a default of 7/10. Decimal thresholds are accepted. API field: `min_curve_density_score` (1–10). Legacy `min_curve_density` still accepts raw units and is converted when loading old searches. Results and Favorites display normalized values.

Advanced Twisty filters also include `min_curvature_quality` (0–1, default 0.12) and `min_sustained_curve_m` (0–50,000 meters, default 120). Open Highways ignores all three geometry gates. History, resumed searches and Favorites retain them.

`surface_filter` supports `exclude_unpaved` (default: allow unknown, reject any explicitly unpaved piece), `known_paved` (all pieces must have recognized paved tags), or `any`. Missing/unrecognized tags remain unknown; any explicitly gravel/dirt/unpaved section makes the full candidate unpaved. Paved recognition now includes chipseal, paving stones and stone paving. Legacy `paved_only: true/false` maps to known_paved/any. Migration 009 backfills existing candidates without downloading OSM again. Surface confidence remains separate from the inclusion policy.

## Tight turns and imperial curve-length controls

Twisty geometry metrics now include tight turns: the former 40 m minimum radius and 55-degree sample-angle exclusion have been removed. The existing maximum qualifying radius remains 600 m. Thirty-meter resampling and road-class/continuity rules still apply. The installer recalculates affected active road metrics and scores from stored geometry in batches; no OSM download is required. Existing Favorites remain historical snapshots.

Curvature quality uses `min_curvature_quality_score` on a linear 1–10 scale (`1 + 9 × raw quality`), default 2.08 (the former 0.12). A hint explains the scale. Sustained curve length is entered in feet via `min_sustained_curve_ft`, default **150 ft**, equivalent to 45.72 meters. Legacy raw-quality and meter-based API fields/saved searches are converted without changing their meaning. Radius/length remain miles, speed mph, and notes/map scales miles or feet; internal geographic calculations and standard export coordinates retain their required units.

Rally-note exports explicitly label extremely tight bends and hairpins when the smoothed mapped geometry estimates a local radius of 131 feet or less and at least 45 degrees of total turn. Broad sweeping hairpins retain the normal hairpin label. These estimates depend on OSM geometry detail.

## Overlap consolidation and alternate road names

Discovery consolidates candidates sharing actual mapped edges, including contained sections and partially overlapping stretches from neighboring data tiles. The union must remain one connected, unbranched line with compatible legal travel direction. Crossing at an intersection, matching names, or proximity alone do not combine roads; parallel carriageways and forks remain separate. Coordinate identities are compared at seven decimal places, matching normal OSM precision. Revised or differently sampled geometry without shared edges is conservatively kept separate.

The longest contributing candidate supplies the primary name; `alternate_names` preserves other candidate names, route references, and OSM `alt_name`, `official_name`, `loc_name`, `short_name`, `old_name`, and `name:en` values. Names never determine physical overlap. Results, road details, Favorites and their accessible thumbnails display aliases. GPX/KMZ descriptions and PDF roadbooks include them as well.

Merged length and curvature come from the unique union geometry. Mapped stops and intersections are recalculated using unique OSM nodes and local connectivity rather than summing overlapping inventories. Surface and speed completeness remain conservative, elevation is unavailable until supported, and all requested filters are reapplied to the merged road before ranking. This can exclude a combined road when its complete length/stops exceed the selected limits.

The API's `member_ids` field identifies contributing candidates. Detail/export links carry a bounded, validated `members` list; Favorites submit `member_ids` and save an authoritative server-generated combined snapshot. All IDs must still belong to active data, form one valid overlapping stretch, and include the selected road ID. Clients cannot supply geometry, aliases or scores. Favorites remain private to their owner. Contained combinations use the same normalized geometry identity as the full source road to preserve favorite matching.

Search processing is bounded to 600 scored source candidates, 200,000 geometry points and 50 members per combination; responses indicate truncation where the candidate/result cap applies. A geometry resource-limit error asks for a smaller radius rather than silently substituting data. Normal ranked output remains capped at 100. Shared results cache merged analysis in PostgreSQL for seven days, retaining at most 500 combinations; keys include profile weights and current member data. Active-source validation occurs before cached details are used. Map context and other private user state are not cached there.

Migration `013_road_aliases.sql` backfills names for existing imports and saved Favorite snapshots and creates the bounded combination cache. Run `sudo ./setup.sh`; existing coverage does not need downloading again. Saved snapshots retain their original geometry, while new searches/Favorites use consolidated geometry. Missing-road diagnostics still inspect raw source candidates to explain underlying OSM tagging and filtering.

## Start/Finish selection and export direction

Selecting a road displays labeled Start and Finish pins. Swap Start / Finish exchanges them and updates GPX, KMZ, PDF rally notes and BeamNG generation, including private Favorite exports. The choice is retained per account/road in browser session storage. Closing the detail panel, signing out or starting a new search clears visible pins; Firefox PDF-map recovery recreates the selected pins. Existing generated BeamNG jobs are not rewritten when you swap: a different direction creates a separate request/job.

The default follows the exporter's mapped travel orientation. The optional export orientation parameter is forward or reverse; invalid values are rejected server-side. GPX/KMZ point order and endpoint labels, PDF notes/turn direction and BeamNG track/spawns follow the selected order. Choosing a reverse track does not change real-world access restrictions; the UI warns when it opposes mapped one-way travel. This is an export orientation choice, not navigation permission.

## Preferred primary road name

Click a primary/alternate name in the selected road details to make it primary. Choices are limited server-side to the road''s known names; the former primary remains an alternate. The selected name appears in the road details/results and is used by GPX/KMZ/PDF filenames and titles, BeamNG map titles/previews and new Favorite snapshots. This is a personal display/export preference; shared imported names are unchanged.

For an already-saved Favorite, selecting a name or swapping Start/Finish saves owner-only preferences through CSRF-protected POST /api/favorites/{id}/preferences. Favorite snapshots store the preferred name/aliases and preferred_orientation, so Favorite lists and direct exports retain them. Explicit export parameters can select another known name or forward/reverse orientation for that request. Browser-session choices are scoped per account/road; every export button sends the current choices. BeamNG job state is scoped by both name and direction.

BeamNG startup time now explicitly sets both TimeOfDay.time and TimeOfDay.startTime to 19/24 (7 AM on the noon-based clock), with version 2 and play=false. Authoring only time can leave the engine startup default active. Regenerate after upgrading; the supplied diagnostic ZIP remains untouched. In-game startup should be rechecked after this change.

## Custom roads from two map points

In Discovery, select **Choose two points**, then click the intended start and finish on the map. Select the driving profile first. Click **Cancel selection** to stop. Clicks snap within 50 meters (about 165 feet); points must be at least 100 feet and at most 50 miles apart. Missing local road coverage is queued automatically using a small padded custom extract, with preparation status and automatic polling. Cancelling the picker stops its polling, but does not cancel shared geographic imports needed by other users.

Both endpoints must lie on the same **single imported continuous road candidate**, near the nearest eligible road at each click. No turn-by-turn routing or joining different roads is performed. Separate carriageways, self-intersecting/loop candidates, gaps and candidates split by import or continuity boundaries are rejected conservatively. A real-world road that spans separate candidates may therefore require choosing a shorter stretch. Currently this uses the same supported road classes as discovery, not residential/service ways. These limits prevent an apparently connected line at a crossing from being accepted as a driving route.

The server clips the geometry between the snapped points and recalculates length, curvature, mapped controls and the selected profile score. Search eligibility filters do not suppress a deliberately selected road. New endpoints are not invented stops. Surface and speed attributes remain conservative candidate-level values; missing data remains uncertain. The clicked order sets Start/Finish orientation, which can be swapped normally. The normal card offers score details, preferred mapped names, private favorites, GPX, KMZ, PDF and BeamNG export. Existing BeamNG size/structure/coverage restrictions still apply.

Custom selections are private, server-owned snapshots, with at most 100 per user and a seven-day access lifetime. Old selections are purged when that user creates another selection. Save a favorite to retain a durable independent snapshot; favorite/export requests never accept client geometry or scores. The nearest ZIP is stored only as descriptive search context; it does not define or clip the custom road.

Upgrade with `sudo ./setup.sh` to apply migration `015_custom_roads.sql`. No new packages or configuration are required. Authenticated APIs: CSRF-protected `POST /api/custom-roads` with `start:{lon,lat}`, `finish:{lon,lat}`, and `profile`; `GET /api/custom-roads/{id}`; `GET /api/custom-roads/{id}/export?format=gpx|kmz|pdf`. Favorites and BeamNG requests accept the owner-scoped `custom_id`. Database integration tests cover clipped geometry, score recalculation, excluded controls, invalid cross-road selection, owner isolation and durable favorites after selection expiry.

Discovery search progress uses the same segmented style as BeamNG: Coverage, ZIP location, Download areas, Analyze roads, Results. The current stage is orange and prior stages blue; cached or ready data completes the bar. Completed-area counts remain below it, with an accessible native area-progress element. Stages follow the reported preparation message and can repeat across different areas; they are not percentages or remaining-time estimates. Errors keep their message while hiding the active bar. Returning to an ongoing search restores the indicator through the existing search polling.

During regional preparation, Discovery highlights the actual required tile bounds: red for queued/retry or unavailable areas, pulsing yellow for running tasks, and green for completed areas. Light fills and stronger outlines keep roads visible; the legend explains the colors. Smaller tiles created for dense areas appear individually. These show whole import tiles, which can extend beyond the search radius. Overlays disappear when the search is ready or a new search starts. Status polygons expose only bounds and state, not provider URLs or task payloads. The yellow pulse uses map paint updates and resumes after returning to a hidden tab; no extra geographic requests are made. Custom two-point preparation uses the same overlays.

BeamNG lane-transition sections now use the same lane-count/direction-specific markings as ordinary sections; the previous edge-only transition material is removed. Pavement still tapers over 100 feet on each side of a mapped width change. Divider positions follow the current section's lane layout; individual lane-drop/merge markings are generic and can change at the section boundary, not surveyed lane-transition engineering. Dashed same-direction dividers now repeat as a 3-meter (about 10-foot) dash followed by a 9-meter (about 30-foot) gap. Solid center/edge lines are unchanged. Regenerate exports to apply the new road textures.

## Divided-road continuity

Discovery and custom selection can follow a named/referenced road from two-way pavement into a one-way carriageway and back again. The graph tracks allowed travel direction across the entire stretch. Opposite carriageways retain separate geometries and may share the two-way approaches; no line is invented across a median. Existing identity, highway-class, shared-node and geometric-continuity checks remain. Disconnected roads and ambiguous continuations can still end a candidate.

When both custom endpoints are on shared approaches, selection prefers the carriageway matching the click order. Road direction metadata considers every member, including one-way sections after a two-way start, so default exports retain the legal mapped direction. Explicit start/finish reversal remains available as before.

Updating with `sudo ./setup.sh` applies migration 017 and automatically rebuilds outdated active candidates from locally retained OSM nodes/segments during the existing rescore step. It does not download the coverage again or need the original extract file. Each region's replacement is transactional: failure retains its previous candidates and analysis version. Subsequent updates skip current versions. This can make the first upgrade take longer, with progress in the installer terminal. Derived road IDs can change; rerun open searches after upgrading. Saved favorites/history/custom snapshots retain their original content.

The standalone maintenance equivalent is `php bin/rescore.php` after database migrations, using the deployed application account and environment. It is not required separately when using the installer. Offline graph/export tests cover splits, rejoins, reversed OSM geometry, input order, conflicting one-way sections, forks and gaps. Additional disposable-PostGIS tests cover import, discovery, custom selection in both directions, repeatable refresh and rollback.

Large bridge estimates retain the mapped horizontal road shape and existing pavement/shoulder widths. Generic concrete barriers and intermediate supports do not reproduce actual pier positions, foundation depths, navigation clearances or bridge architecture. No artificial arch/camber is inferred from length alone. Terrain beneath the bridge stays protected from shoulder filling. Large spans still require fresh-export and in-game validation; offline tests exercise a 700-metre synthetic valley bridge and unsupported structural/length/approach cases.

Tunnel terrain repair: excavation now retains original ground already below the road. When terrain inside the open bore/approaches must be cut, its clipped surface is lowered to 5 cm below pavement instead of being deleted. UVs and collision remain on the replacement terrain patch; the hillside above the ceiling stays intact. This closes visible and physical holes alongside the road and portal tapers. It does not add separate collision shoulder objects or change ordinary-road shoulders. Regenerate exports to apply the repair; existing ZIPs are untouched.

Bridge undersides: bridges on explicit pavement now include a closed, outward-facing concrete shell beneath the road, with bottom, side and end faces. It follows the exact pavement cross-sections and mapped span endpoints, adds 0.5 m (about 1.64 ft) of generic visible deck depth, and casts shadows. This is visual geometry only: pavement collision, supports, barriers, road elevations and native terrain remain unchanged. Native MeshRoad decks already have underside geometry and do not receive a duplicate shell. Regenerate exports to apply the fix.

Tunnel vertical seam repair: retaining excavation floors alone did not join their raised boundary to the original hillside. The replacement terrain patch now includes inward-facing side/end faces along excavation boundaries and narrow portal rims. Faces hidden inside adjoining excavation volumes are removed; the usable bore is subtracted from portal rims so they cannot close the driving entrance. The ceiling above the tunnel and lower original ground are retained. Ray-based tests inspect vertical joins and open passages in addition to projected surface-area tests. This adds faces to the existing tunnel terrain patch, not standalone roadside collision shoulder objects. Fresh generation and in-game validation remain required.

Tunnel roadway-clearance repair: the previous terrain connection pass protected the driving bore only for faces classified as portal rims. Side/end faces could remain across a curved or sloped roadway. All connecting faces now undergo the same usable-bore subtraction, including their own excavation segment as well as nearby segments. Exterior terrain connections remain. Expanded regressions exercise curved, graded, changing-width roads in both orientations and detect the previous rule's intrusive faces. A read-only replay of the supplied Tahoe (2) export changed the detected intruding connecting triangles from 222 to zero; in-game verification is still required.

Tunnel bend clearance: excavation retains every original pavement heading/grade vertex alongside its fine samples. Adjacent sections overlap by a bounded amount derived from their heading change and width, closing the wedges left by independent square-ended strips. The same volumes protect the usable bore when clipping connecting faces. Straight sections retain zero bend overlap. This change is confined to tunnel replacement geometry; native hillside elevations and ordinary-road shoulders remain unchanged.

tests/tunnel-clearance.php generates curved, sloped, changing-width roads at multiple orientations and checks all replacement ground/wall faces against their pavement triangles, including vertex, edge-midpoint and centroid samples with a 5 mm detection tolerance. Terrain and geometry are generated entirely by the tests. These offline checks supplement in-game validation.


## Preparation problems and incomplete results

Discovery shows available roads while missing areas prepare. The status distinguishes waiting for the background worker, active processing and scheduled automatic retries. Failed areas remain visible as an attention notice even when other areas are still processing; those results are explicitly incomplete.

A running area with no reported progress for at least 15 minutes is described as possibly slow or stalled. This is a warning, not proof that the worker has stopped, and does not cancel or restart work. A SuperAdmin can inspect the job in Manage. Once work resumes, active searches continue checking status; if all preparation had failed and polling stopped, run the search again after the job is retried.

Request, rendering, map-update and history-restoration errors appear in both the sidebar and a persistent map alert. The alert includes the reason and clears on a successful retry. Unreadable server responses retain their HTTP status for diagnosis.

## Actual saved search results (0.138.0)

**View results** in History loads the last result snapshot received for that search: road geometry, order, scores, metrics, center and coverage state. It does not call discovery, rerun analysis or enqueue data preparation. **Run fresh search** explicitly runs the saved filters against current coverage.

During an active search, polling updates its original history entry as results arrive. If you leave before completion, its last stored results can be partial; History labels that snapshot incomplete rather than silently completing it later. Opening a snapshot does not poll for new roads.

Entries created before result snapshots were introduced retain their filters but cannot reconstruct their original results. Opening one explains this and lets you choose a fresh search. History remains private to its owner and retains at most 100 entries. Each snapshot is bounded to 16 MiB; deleting the entry also deletes its snapshot.

Road selection displays the saved geometry and metrics. Existing export/favorite actions still require the original road's source data to remain in active coverage; a subsequent regional replacement can make those actions unavailable. Favorites retain their own independent road snapshots.

Installation/update applies additive migration `019_search_result_snapshots.sql`. Existing history is preserved with null snapshot fields.

Identical searches reuse your existing History entry regardless of age. Comparison uses normalized filters, so omitted defaults and explicitly entered defaults count as the same search. A fresh run refreshes that entry's saved results and snapshot time; its original creation date remains. Different users and different filters remain separate. Previously duplicated entries are preserved until you delete them or normal retention removes them.
