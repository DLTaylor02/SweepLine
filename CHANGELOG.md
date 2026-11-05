# SweepLine changelog

## Unreleased

- Fix terrain connecting faces entering the tunnel roadway: apply usable-bore clearance clipping to every side/end/portal face, including its owning excavation volume. Retain exterior joins and add curved, sloped, changing-width, reverse-direction regressions that reproduce the previous bug. Supplied Tahoe (2) replay detects 222 intruding faces before and zero after the repair.


- Repair vertical cracks left between tunnel excavation floors and surrounding terrain, diagnosed from the refreshed Lake Tahoe export. Stitch excavation boundaries and portal rims, clip internal faces, preserve the usable driving bore, and add horizontal-ray seam/opening regressions alongside area-coverage checks.


- Fix see-through bridge decks when the main road uses explicit pavement: add a closed, correctly wound concrete underside shell at mapped span boundaries. Preserve existing driving collision and terrain; add closure, normals, endpoint and generated-ZIP regression coverage. Diagnosed from the supplied Dwight D. Eisenhower Highway export.


- Fix visible/collision holes beside tunnel portals: preserve lower original ground and retain a textured ground surface beneath excavated areas. Add full replacement-area and roadside coverage regressions; validate a read-only replay of the supplied Lake Tahoe export with zero gaps across 56 roadside samples.


- Extend BeamNG fixed beam/slab bridge and viaduct estimates to 2,000 m, with longer approach anchors, bounded curvature/grade/clearance and span budgets. Retain under-bridge terrain protection and short-bridge sampling; add concrete collision material and large-valley ZIP regression coverage. Suspension, arch, cable-stayed and movable structures remain refused.


- Fix continuous-road candidates across two-way/one-way split and rejoin sections. Preserve distinct carriageways, whole-path direction constraints and shared approaches; prefer the matching custom click direction and correct API/export direction metadata. Add migration 017 and transactional local candidate refresh during installer rescoring, with offline graph/export and disposable-PostGIS regression coverage.


- Add bounded single-bore BeamNG tunnel exports: approach-derived estimated floor, generic concrete interior/open portals, lighting, native terrain holes and clipped replacement overburden. Preserve ordinary shoulders and bridge behavior, report estimates, reject unsupported tunnels, and add offline geometry/ZIP regression coverage.

- Apply the primary-road native terrain shoulder method to side roads/connectors/turnarounds in a shared nearest-corridor pass. Prevent later blends and clearance checks from removing earlier side-road edge support; retain primary-road and bridge protection, paved widths, clearance targets and cut limits. Add overlapping-road regression coverage and shared terrain-pass reporting.


- Use bounded PHP CLI helper processes for BeamNG terrain classification/filtering, aerial tint filtering and terrain texture generation. Preserve serial pixel output and original elevation sampling; keep terrain mutation, database sessions and ZIP writes in the parent.
- Add automatic CPU/affinity/cgroup/memory limits and an application-specific BEAMNG_CPU_WORKERS override, with serial fallback, cancellation/failure cleanup, private phase files and export concurrency reporting.
- Add real subprocess concurrency, byte-equivalence, cancellation, failure and cleanup tests; benchmark four palette textures at 2.76x faster with four helpers on the development machine.


- Fix primary-road terrain being excavated by final side-road cleanup: protect all supporting grid corners during every branch pass, bound secondary clearance cuts and shorten/omit unsupported context stretches instead of disturbing main-road support. Report these limits and remove stale turnarounds.
- Add render-only 18 cm pavement edges to explicit road meshes; preserve top-surface collision without physical seam/shoulder walls.
- Retain the opposite white edge line at T-junctions, including reversed route orientations; interrupt both edges only for opposing entrances.


- Double eligible BeamNG curve-rounding radius/trim limits, with a 0.70 m deviation cap and enlarged-round junction/structure protection.
- Improve mapped-building appearance with deterministic siding/tint/roof variants, stock normal/specular maps, generic framed windows and entrances, and cornice/base trim. Keep mapped footprints and cap decorative detail work; record estimated-architecture limitations.
- Distinguish guardrail fetch outcome from empty mapped coverage and placement rejection in export reports.


- Texture mapped building walls with stock residential/business/industrial/neutral materials and physical-scale UVs, keeping roofs separate. Prefer building tags over optional mapped land-use hints; preserve buildings if land-use fetching fails. Record appearance provenance and coverage limits.
- Add two-foot paved shoulders on each side of primary and mapped side roads, preserving lane widths/markings and spawn offsets. Blend native terrain to the new outer pavement edge; retain junction clipping and bridge protection.
- Refine small road direction changes using bounded quadratic corner rounds and fine angle supports. Protect sharp turns, endpoints, junctions and structure corridors; use explicit pavement for rounded roads to avoid independent engine spline overshoot.


- Add automatic OSM guardrail fetching and pavement-aware BeamNG placement with generic steel posts, closed beams and rigid capped terminals. Join mapped way breaks, retain existing bridge railings, report source/coverage/rejections and optional failures, and bound download/mesh work. Share cancellation-aware OSM retries with buildings; add offline fetch, geometry and generated-ZIP tests. No guessed roadside barriers are substituted where mapping is absent.


- Fix tire-catching intersection ledges by clipping side-road pavement out of the primary footprint and matching exact shared-edge elevations. Junction maps use an explicit primary ribbon to avoid independent native spline drift; retain sampled route/elevation/width data, native terrain shoulders, bridge protections, markings and navigation. Preserve pavement edges across paint subdivisions. Canal Road (4) geometry replay removes overlaps at 22 entrances across 50 branches. Regenerate exports; in-game collision validation remains required.


- Replace raised side-road surfaces and lower terrain decals with one welded pavement mesh per branch and connector. Preserve primary-road centreline/elevation/width, use smooth junction crossfall, and shape native side shoulders after all primary-road terrain passes; final clearance lowers contact terrain rather than lifting pavement. End lane markings at exact junction boundaries and continue side-road markings through the connector.
- Replace circular/convex building placement filters with polygon pavement/neighbor clearance, ear-clipped concave roofs, larger practical footprint limits and a 3 m foundation-variation allowance. Record rejection reasons. A live 95 m Canal Road corridor audit placed 142 of 289 footprints versus 75 with the old filters; most remaining buildings were beyond the retained 200 ft range.

- Fix intersecting roads being covered by later apron/terrain passes: anchor entrances to their original main-road junction plane, stop branches re-entering the primary pavement, and audit final side-road clearance against both terrain diagonals without moving the main road. Add connected minimap/AI graphs and conservative synthetic turnaround loops at suitable two-way stub ends; reject water, buildings, steep terrain and road conflicts.
- Keep BeamNG export progress monotonic per job and retain the reached stage when reopening it; generic or repeated status messages no longer reset the staged bar.

- Upgrade BeamNG side roads to lane-aware MeshRoad surfaces with matched entrance elevations, native terrain shoulders and clearance correction; extend the cap to 600 feet. Suppress main/side-road markings at junctions and join angled entrances with native terrain aprons, avoiding overlapping collision meshes and bridge interiors.
- Use verified East Coast beech forest/group meshes with built-in detail levels in dense eastern woodland interiors; retain individual trees at road edges and existing regional conifer choices.

- Correct likely aerial shadows before BeamNG terrain material classification and base-color blending. Use nearby lit vegetation/dry-ground evidence and muted regional fallback palettes; preserve ambiguous flat blue neighborhoods and missing imagery. Apply the corrected colors to aerial/fallback textures too, and report correction counts without changing raw scenery evidence or terrain geometry.

- Include up to 300 feet of connected side-road context from locally imported OSM shared-node junctions in BeamNG exports, using terrain decals and keeping scenery clear. Report coverage/truncation and context failures.
- Restrict Utah boulders to bare western terrain with low canopy evidence. Add a bounded tree-only pass targeting twice the initial tree count, with partial crown overlap, retained road/building clearance, and actual-count reporting.

- Display selected-road length in miles and endpoint terrain elevations in feet for discovery, custom and favorite roads. Load USGS point elevations asynchronously, cache successful samples for 90 days and unavailable samples for five minutes, and swap values with Start/Finish orientation.

- Retry temporary BeamNG building-provider failures up to three attempts with 5/10-second cancellable backoff. Retry Overpass timeout responses, avoid retrying permanent/processing failures, and report provider exhaustion separately from processing failures.

- Soften BeamNG stock terrain color toward exposure-matched aerial hue/chroma at a 50% blend, retaining shadow/snow/water guards. Reduce aerial fallback saturation from 1.5 to 1.25; preserve terrain geometry, classification and building placement.

- Remove the idle ZIP-search hint from all Discovery tabs; show feedback only when there is activity or a status message.

- Join the selected Discovery tab to a bordered content panel, retain shared progress inside that frame, and move the driving-profile selector into ZIP search.

- Split the Discovery sidebar into ZIP search, Custom road and Your Discoveries tabs with keyboard navigation. Open discoveries automatically after completed searches and place road diagnostics above results; retain shared profile and preparation progress.

- Reuse the staged discovery progress bar for custom-road preparation, including live area counts, download/analysis stages, completion and cancellation cleanup.

- Show persistent custom-road rejection reasons over the map and in the sidebar, with a reset picker for retry. Expand custom download padding to 25% of endpoint distance (1-5 miles per side) to accommodate winding roads.

- Prevent artificial ridges beside simple bridges: exclude deck intervals from both native terrain-blending passes and widen bridge protection to the full shoulder influence band plus a grid guard. Preserve the landward approach correction and ordinary shoulder output. Add a regression that reproduces the old ridge and checks unchanged valley terrain.

- Correct native terrain clipping through pavement just beyond bridge abutments, where the broad bridge mask previously blocked shoulder shaping. Add a landing-only shaping/contact pass that preserves deck-interior vertices and leaves ordinary-road processing unchanged. Validate offline against the supplied Frenchman Lake export; report bridge_approach_correction.

- Fix terrain clipping undoing native shoulder shaping on grades. Correct interpolated contact heights rather than flattening every touched vertex to the lowest road sample; cover bilinear and both terrain triangle diagonals. ZIP (24) showed a roughly 25 cm median road-edge gap; add a graded-grid regression at its actual 3.52 m spacing.

- Remove all separate BeamNG shoulder/roadbed collision objects and their mesh generator. Shape native terrain to a 5 cm pavement-clearance target, then smoothly blend into the original terrain over a grid-aware shoulder range. Preserve bridge masks and terrain materials; retain the protected road-clipping pass and add raising/lowering/falloff/exclusion regressions.

- Replace solid BeamNG shoulder/roadbed MeshRoad ribbons with upward-facing top-only TSStatic collision meshes, removing side walls, undersides and end caps that can catch tires. Preserve terrain-matched ground types, bridge exclusions and buried outer edges; share rail coordinates across material boundaries.

- Reduce new custom-road preparation to a tight padded rectangle with a small import halo instead of radius-search half-degree tiles. Reuse existing full coverage and stable extract keys, preserve bounded halos on dense-area splits, and support splitting long narrow rectangles. Radius-search planning is unchanged.

- Avoid region-cancellation/status 503s caused by cleanup waiting for active import row locks. Defer running import cleanup, skip locked rows during cleanup, and retain checkpoint cancellation and immediate area-key reuse. Log SQLSTATE and code location for unexpected API errors without SQL or parameter values; add a two-connection lock regression.

- Assign BeamNG shoulder/roadbed materials from each side's underlying terrain category instead of forcing DIRT everywhere; match stock palette colors and split strips when surface types change. Extend outer shoulders by 0.75 m and embed edges 0.15 m into final terrain to hide seams, preserving bridge exclusions.

- Add dirt/gravel shoulder ribbons joining road edges to final shaped terrain, widening from 1.5 to 6 meters when needed, with a supporting surface 5 cm below pavement. Target 5 cm terrain clearance and apply bridge protection to both shaping/overlap correction and all shoulder/roadbed meshes. Preserve road elevation sampling.

- Keep lane and opposing-traffic markings in BeamNG pavement transition sections instead of substituting an edge-only texture. Space dashed dividers at one 3-meter dash and 9-meter gap per 12-meter repeat; preserve existing width tapers.

- Jump to search progress when a search starts, scrolling only the left panel on desktop and the page on mobile. Background polls do not reposition the view.

- Show search preparation tiles on the map with light red queued fills, pulsing yellow active fills and green ready fills, plus stronger borders and a legend. Use real task bounds including split tiles, clear overlays on completion/new searches/sign-out, and restore them after map recovery.

- Add BeamNG-style staged progress to Discovery searches for coverage, ZIP location, downloading, analysis and results. Keep actual area counts and accessible area progress, reflect repeated stages across areas, and mark cached/completed searches ready without estimating time or percentages.

- Add a two-point custom-road picker in Discovery with automatic shared coverage preparation, nearest-road snapping and strict single-candidate connectivity validation. Clip and rescore the selected stretch, preserve endpoint order, and reuse private Favorites plus GPX/KMZ/PDF/BeamNG exports through owner-scoped snapshots. Add migration 015, input/UI tests and PostGIS integration coverage.

- Strengthen satellite/palette terrain blending: raise exposure influence and chroma variation while retaining vegetation green separation, conservative invalid-color fallback and an 85% palette brightness floor. Close-up detail strengths remain unchanged.

- Add a nine-segment BeamNG preparation bar showing queued, elevation, imagery, preparation, terrain analysis, road shaping, materials, scenery and packaging stages from worker status. Distinguish current/completed stages, retain detailed progress/errors, and fill the bar only when the download is ready.

- Blend filtered aerial colors into stock terrain base palettes with bounded exposure/chroma, vegetation-green protection and conservative fallback for black/snow/water-like samples. Reuse a north-up tint field, preserve close-up PBR settings and report cancellable worker progress; add color-bound and orientation regressions.

- Place BeamNG START/FINISH arches 100 feet ahead of each spawn along its inward driving direction, following bends, grade and road width; keep vehicle spawns unchanged and clamp short stretches at their endpoint.

- Preserve vegetation color up close: limit vegetation color-detail tint to 0.02, soften its normal/AO detail, and disable detail-height blending for stock terrain so category borders do not change with viewing distance. Add worst-case near-detail color regressions.

- Double BeamNG level draw distance from 8 km to 16 km and halve fog density to keep distant terrain clearer. New exports include the updated visibility settings.

- Fix Discovery initialization failing in browsers with ResizeObserver after the compact road-panel update; correctly resolve the map and card elements and cover the browser observer path in frontend tests.

- Correct BeamNG stock terrain rendering: use explicit category base colors with stock PBR detail instead of treating neutral detail maps as standalone base colors. Preserve 1024-square detail resolution; add shader-formula, exported-pixel and installed-texture color checks. Export (16) confirmed vegetation assignments existed despite the gray/brown appearance.

- Compact the selected-road card with inline Favorite/Swap controls, smaller export buttons, nonduplicated alternate names and expandable score details. Frame selected roads beside the measured card (above it on narrow maps), including Favorites and panel/viewport size changes.

- Reduced BeamNG road-to-terrain corridor clearance from 15 cm to 10 cm, retaining the elevation sampling method and terrain-overlap correction.

- Corrected steep and muted forest terrain being classified as stone/rock. Replaced stony forest ground with grass blades, and changed dry soil and exposed-rock stock materials after inspecting export (15).

- Added distinct sand and stone terrain classes and broadened mineral recognition for pale/shaded slopes while retaining uncertain-water/snow/pavement and mapped-roof fallback.
- Replaced moss-heavy forest floor, grass and dark soil textures with alternate stock PBR sets; verified all replacement channels. Removed the web terrain-style dropdown and always requests Stock materials, ignoring obsolete Aerial preferences.

- Removed the 256-square stock terrain classification cap and nearest-neighbor enlargement that produced large material blocks. Classification now uses full imagery/terrain resolution with one-pixel neighborhood sampling and an isolated-speckle filter that preserves thin features. Added a 512-square pixel-boundary regression test and resolution/spacing reporting.

- Fixed BeamNG menu names displaying Unicode escape text such as u2014. Map titles use a plain hyphen before SweepLine, and level JSON writes non-ASCII road names directly as UTF-8 rather than Unicode escape sequences.

- Added experimental Stock materials/Aerial imagery BeamNG export modes. Stock mode classifies terrain into grass, forest floor, dry soil, gravel and rock with neighborhood/slope/elevation heuristics, speckle filtering and aerial fallback for uncertain areas.
- Referenced 25 verified shared stock PBR textures without redistributing game assets. Added style-scoped jobs/UI state, required-asset diagnostics, classification reports and stock-package/frontend regression tests.

- Fixed BeamNG startup time defaults by authoring startTime alongside time at 7 AM, using TimeOfDay version 2. The supplied ZIP had time set correctly but omitted the separate startup field. Expanded the clock regression check.

- Added clickable primary/alternate road names with server-validated personal name selection across Favorites and every export format. Existing Favorites save name and endpoint-orientation preferences through an owner-only CSRF-protected endpoint; direct Favorite exports default to the saved direction. Export titles/filenames and BeamNG job state reflect the selection.

- Added labeled discovery Start/Finish map pins and a swap control. Selected orientation carries through GPX, KMZ, PDF and BeamNG exports, including Favorites, with server validation, direction-scoped job state, per-user/road browser preferences and mapped one-way warnings. Added endpoint/order and frontend regression coverage.

- Added general mountain vegetation inference from regional elevation and neighboring raw aerial colors. Muted/shaded canopy can now receive trees, montane settings favor stock pines, and bare terrain/high elevations suppress tree placement. Added inferred-biome and actual-asset reporting plus cross-region tests; no road-specific rules.

- Changed BeamNG export default time to fixed 7 AM using the engine''s noon-based time scale.

- Increased terrain saturation from 1.2 to 1.5 and reduced the road-to-terrain recess from 30 cm to 15 cm. Preserved original elevation sampling, corridor shaping and the post-shaping terrain-overlap removal pass.

- Replaced remaining procedural scatter rocks with stock Utah boulders through native Forest instances. The supplied latest Mount Rose export had 3,245 low-poly generated rocks despite its 5,700 plants already using stock models; the matching game log confirmed vegetation material mappings were fixed. Added package checks excluding all generated rock/tree/bush placeholders.

- Fixed incomplete stock vegetation material loading: exports now load each used map''s parent tree material library before species libraries, including leaves_strong/leaves_thin. Confirmed missing leaves_strong mappings in the local game log for the supplied affected export. Expanded stock verification to inspect each DAE material mapping rather than only checking file existence.

- Restored buildings from fresh mapped OSM footprints with conservative size, slope, roadside and overlap checks; added generic footprint extrusions and provider-availability warnings.
- Added 100-foot pavement-width tapers on both sides of lane changes, edge-only transition markings, a 20% terrain saturation boost and a bounded terrain-overlap lowering pass.
- Added stock-vegetation asset diagnostics without procedural fallback. Close-up appearance still requires an affected ZIP/in-game investigation. Added offline tests and disposable PostGIS building-query coverage.

- Expanded native BeamNG vegetation to a seven-asset stock catalog across Utah, West Coast USA, East Coast USA and Johnson Valley. Added regional/elevation-based variants, asset-aware footprints, per-export dependency reporting and deduplicated material loading. Added optional read-only installed-asset verification.

- Set BeamNG level visibility to 8 km with lighter fog; replaced generated trees/bushes with references to installed Utah juniper assets via native Forest placements and stock materials. Enlarged placement footprints for those assets without redistributing game art.
- Reduced road-to-terrain recess from 60 cm to 30 cm while retaining 1024 elevation sampling and corridor shaping. Disabled false-positive buildings inferred solely from gray aerial colors; mapped footprint support is still pending.

- Concentrated BeamNG scenery within 200 feet of road edges, using full object footprints to avoid pavement and nearby road sections without a broad exclusion zone. Added bushes, a bounded road-length density budget, object spacing checks and per-type/rejection reporting.

- Added bounded simple-bridge BeamNG exports with approach-derived estimated decks, protected under-bridge terrain, generic piers and collision guardrails, and visible approximation warnings. Structure rejection now checks actual selected-road overlap; tunnels and unsupported bridges retain specific rejection reasons. Added overlap and synthetic-valley package tests.

- Made BeamNG rejection and background failure reasons prominent in the export row with accessible alerts. Error messages persist per account/road for the browser session and survive reopening the road; retry clears the previous failure.

- Restored original 1024 elevation downloads and road-height sampling for future BeamNG exports, independently of 2048 aerial imagery/terrain output. Terrain is bilinearly upsampled from that source; reports expose both resolutions. Existing ZIPs are untouched.

- Restored the original BeamNG road elevation handling: 25 cm above smoothed sampled elevation and terrain recessed 60 cm below the road surface. Retained texture, spawn and arch fixes.

- Rotated both BeamNG vehicle spawns 180 degrees, preserving lane positions, grade alignment and arch orientation. Increased road clearance from 15 cm to 25 cm to reduce remaining terrain clipping.

- Fixed BeamNG terrain base texture scaling to use grid units rather than world meters, matching the installed 0.40 shader. Added asymmetric image-landmark sampling checks and increased road clearance to 15 cm.

- Removed completed BeamNG export reuse: new generations always fetch fresh raster sources. Only active jobs resume across road selection; source rasters remain deleted immediately after processing, while finished ZIPs retain their 30-minute download window.

- Moved BeamNG generation into the export button row; it becomes an orange Download link when ready. Removed UI padding/resolution options and defaulted terrain to 2048. Raised road clearance to 8 cm and made Finish always face toward Start for simulation.

- Corrected BeamNG spawn and arch rotation serialization to contiguous basis-axis columns, with diagonal-road and arch-crossing checks. Increased road clearance from 1 cm to 3 cm and invalidated older cached exports.

- Added generated START/FINISH arches at BeamNG spawn locations, with two-sided raised lettering, supports outside the roadway and six-meter overhead clearance. New exports bypass older cached packages.

- Removed the 0.6 m terrain recess beneath exported BeamNG roads. Road tops now use a 1 cm anti-flicker clearance above the shaped corridor; added serialized-terrain clearance checks and invalidated reuse of older export ZIPs.

- Added BeamNG minimap navigation, labeled SweepLine/road previews and separate Start/End thumbnails. Added PHP GD provisioning, explicit natural-color imagery and daylight settings, plus lane-centered, grade-aligned endpoint spawns. Versioned export reuse so previous ZIPs are regenerated.

- BeamNG raster downloads now retry transient provider/network failures up to three times with cancellable backoff and visible source-specific progress/errors. Permanent failures are not retried; missing data is never replaced with synthetic terrain.

- Fixed repeat installations rejecting SweepLine''s own cleanup cron entry. Added ownership markers, exact legacy-entry recognition and cron upgrade safety tests.

- Fixed BeamNG export queue creation selecting the text-projection PostGIS overload by explicitly typing the target EPSG as an integer. Added a native-PDO/PostGIS integration regression check.

- Added experimental background BeamNG.drive 0.40 map exports: real-scale square terrain, USGS elevation/NAIP imagery, mapped or estimated lanes, generated scenery, two endpoint spawns, private expiring ZIP downloads and Manage cancellation. Added GDAL installation, queue migration, temporary-file cleanup and offline package tests. In-game and live raster pipeline verification remain pending.

- Consolidated physically overlapping, unbranched candidate stretches with legal direction checks, unique-edge geometry, recalculated metrics/filtering and a bounded local analysis cache.
- Added alternate_names from contributing roads and OSM aliases across discovery, details, Favorites/thumbnails and GPX/KMZ/PDF exports, with migration backfill for existing data and validated composite export/save identities.

- Recreated the MapLibre canvas after returning from PDF preview to recover Firefox blank-map rendering, preserving serialized map sources/layers, selected-road filter, camera and padding. Normal tab switches retain lightweight repaint recovery.

- Added coalesced map resize/repaint recovery when returning from PDF preview tabs, regaining visibility/focus, or restoring the WebGL context, retaining current results, selection and camera without a new search.

- Opened road and Favorite PDF exports inline in a new tab, preserving road-based Save filenames. PDFs are generated in memory without server-side file retention; GPX/KMZ remain downloads.

- Named GPX, KMZ and PDF downloads after the exported road followed by the sweepline tag, including Favorites exports.

- Replaced one-hour idle/twelve-hour absolute logouts with a seven-day session lifetime configurable by SuperAdmin in Manage (1–30 days), persistent cookies with fixed sign-in deadlines, and application-specific session storage retention.

- Reorganized History into aligned rows/columns with the ZIP locality and state as the primary search label, local-data enrichment for existing entries, and preserved map-opening/delete actions.

- Reorganized Users into an accessible five-column account table, with aligned role/access/password controls, retained password meters, a responsive creation form and horizontal scrolling on small screens.

- Fixed malformed dollar quoting in the password-policy migration that prevented installer upgrades; added a migration delimiter regression check.

- Added persistent SuperAdmin password policies, shared server-side enforcement, padded HIBP range screening with informational fail-open warnings, themed requirements meters, and self-service password changes with session rotation.
- Added explicit required-password-change badges/controls and page/API restrictions without logging flagged users out. Preserved the single exempt SuperAdmin and CLI recovery. Password/account mutations now use CSRF-protected POST.

- Added regression coverage for the single installer-created SuperAdmin: duplicate database records and API creation/promotion/demotion are rejected.

- Added Admin/User permissions with role management for SuperAdmin, server-enforced separation of Users versus Manage, protected privileged accounts, and owner-only Favorites for all roles.

- Renamed Jobs to Manage and account management to Users. Added a SuperAdmin database storage budget control with immediate worker adoption and automatic requeue of budget-blocked tasks.

- Fixed dense-city imports stalling at the subdivision cutoff: smaller bounded areas now use proportional overlap, and searches automatically requeue earlier size-limit failures while preserving active data.

- Changed the KMZ path style to #FF00D5 with line width 8.

- Added explicit extremely tight bend/hairpin callouts to rally-note PDFs and other road-note exports, based on local estimated radius rather than turn angle alone.

- Included very tight turns in curvature metrics, normalized curvature-quality filtering to 1–10 with an explanatory hint, and changed sustained-curve input to feet (default 150 ft, 45.72 m). Existing active metrics are recalculated locally during installation; saved filters retain compatible units.

- Normalized curve density to 1–10 in filters/results/Favorites, added curvature-quality and sustained-curve filters, and retained legacy filter compatibility.
- Added paved/unknown/unpaved classification with migration backfill and three surface policies; the default admits unknown surfaces and excludes known unpaved sections.

- Exposed Twisty minimum curve density as an advanced filter (default 7; fractional values 0–53.6448), shared by search, diagnosis, history, resumed searches and favorites.
- Clarified pavement diagnostics to distinguish missing surface tags from surface values outside the currently recognized paved list.

- Added a missing-road diagnostic that inspects local imported ways and continuous candidates, exposes exact filter/curvature rejection reasons and source tags, and reports incomplete coverage without changing search rules.

- Kept loading spinners animated when reduced motion is reported, including remote-desktop browser sessions, by removing the spinner-specific animation override.

- Added configurable minimum winding-road eligibility before Twisty result ranking, increased curvature weight to 70%, and refreshed cached scores during installation. Motorways remain eligible; Open Highways and missing-road/continuity behavior are unchanged.

- Added labeled geographic street basemaps to Favorites thumbnails, using authenticated lazy-loaded SVGs with saved/local OSM context, highlighted routes, endpoint markers and attribution.

- Redesigned printable PDFs with an overview page, metric cards, bold typography, direction icons, paginated bend-note tables, attribution/page numbers, and a labeled local OSM street basemap with north arrow and scale. New favorites preserve surrounding-map context.

- Removed “approximately” from individual bend notes and restored the coordinate formatter required by PDF exports, fixing a missing-method error that caused HTTP 503.

- Fixed inflated export instruction mileage caused by repeated full OSM-way geometries. GPX/KMZ/PDF now use the single track for total distance and rally-style bend notes with cumulative/interval mileage, left/right descriptions, and approximate angles; existing favorite exports benefit automatically.

- Added account-private Favorites with heart toggles, geometry thumbnails and road metrics, direct map reopening, and durable road/search snapshots that survive imports and support existing export formats.

- Added selected-road GPX tracks, KMZ with KML geometry, and downloadable printable PDFs with overview sketches and ordered road-section directions, preserving mapped one-way direction and OSM attribution.

- Added map picking beside the ZIP input, backed by indexed local geographic ZIP-center lookup, distance/approximation messaging, cancellation, and unavailable-data handling.

- Set the minimum/default mapped-stop plus uncertain-control limit to 1; reopening legacy zero-limit searches uses the new minimum.

- Consolidated installation on `sudo ./setup.sh`, removed the redundant `setup` wrapper, and updated documentation and CI checks.

- Restored the latest search and live preparation progress when returning to discovery or reloading, with account-scoped tab storage, fresh polling, history-link precedence, and sign-out cleanup.

- Defaulted the mapped-stop plus uncertain-control limit to zero in the search form and API.

- Added per-entry search history deletion with ownership and CSRF checks. Saved search links now automatically open current map results after sign-in without duplicating history.

- Corrected cancellation to remove only the current attempt: later searches can queue a fresh job. Removed prior suppression markers via migration 007 and freed area keys while cancelled work stops safely.

- Cancelled jobs now disappear from queue listings/counts and are physically removed once stopped. Added upgrade/runtime cleanup for previously cancelled tasks and inactive imports, with separate suppression keys to prevent automatic recreation.

- Added a responsive, accessible Jobs storage pie chart showing SweepLine database size, other used space, and free space across mounted local persistent filesystems, with explicit scope and unavailable-data handling.

- Styled Jobs/Manage/History links consistently, made Manage a dedicated users-only page, and consolidated preparation controls on Jobs.
- Defaulted radius to 5 miles and added private, persistent previous searches with filter restoration, bounded retention, and polling deduplication.

- Replaced the radius dropdown with a whole-mile numeric input (1–50 miles, default 50), enforced by the API.

- Added a SuperAdmin Jobs page with queue counts, progress, cancellation of queued/running automatic tasks and manual imports, and explicit retry of cancelled preparation tasks. Cancellation persists across searches and preserves active data.

- Added visible search/preparation spinners, completed-area progress, and partial-result messaging with automatic updates; versioned frontend assets to refresh cached files after deployment.

- Removed the login username hint and switched light/dark interface accents, tinted surfaces, and road/radius colors from green to blue.

- Added an operator-only `reset-superadmin` command with hidden password confirmation, account reactivation, and invalidation of existing SuperAdmin sessions.

- Moved SuperAdmin creation into the installer terminal: hidden password and confirmation only when no administrator exists, fixed username `superadmin`, and no required email.
- Removed browser account claiming/setup codes; added username login, optional account emails, and a non-destructive migration preserving existing passwords.
- Added terminal prompt, existing-account preservation, and username-only login regression coverage.

- Fixed installation failing at Linux service-account creation because `useradd` received two login arguments; added account-creation and ownership-safeguard regression checks.

- Removed the locality-specific download utility and documentation; replaced the initial regional map view and ZIP placeholder with a global overview and neutral input hint.

- Fixed maximum-length SQL quoting and deduplicated exact road geometry across overlapping coverage cells.

- Added the 1279 default-port prompt and installation-driven initialization.
- Added installer-provisioned SuperAdmin accounts, web sessions/CSRF, user management, and session invalidation.
- Added automatic ZIP initialization/refresh and cached, search-driven OSM preparation with progress polling, retries, dense-area subdivision, and resource guards.
- Added SuperAdmin worker/task monitoring and retries; updated automated tests and install-and-use documentation.

- Added an optional maximum road length, inclusive upper-bound filtering, range validation, and regression tests.

- Added the MIT license and updated Composer licensing metadata.
- Made the road-length threshold visible and optional; blank searches have no user-specified minimum and supplied thresholds use a strictly longer-than comparison.
- Added adaptive (default), light, and dark themes with saved preferences, live device-theme updates, and matching map styling.

## 0.1.0 — 2026-10-19

- Implemented local ZIP/radius discovery with two configurable driving profiles, cached explanations, and geographic coverage reporting.
- Added bounded OSM XML/PBF preparation, transactional dataset activation, connectivity, continuous road candidates, uniformly sampled curvature, conservative control analysis, and profile scoring.
- Added versioned PostGIS schema/migrations and a hardened PHP import worker.
- Built responsive MapLibre discovery UI with radius overlays, ranked roads, selection, score breakdowns, loading/errors, and coverage notices.
- Adapted the original Debian installer with dedicated database/PostGIS, FPM/NGINX/worker isolation, ownership safeguards, persistent-data preservation, and health verification.
- Added unit/integration/HTTP test suites and Linux CI; documented environment verification limits, data licensing, limitations, operations, and elevation extension path.

## Project naming

- Adopted SweepLine across the frontend, application namespace, deployment resources, documentation, and tests.

- Set the default application listening port to 1279 and aligned deployment examples.
