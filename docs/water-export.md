# Water in BeamNG exports

[BeamNG export reference](beamng-export.md) | [Documentation index](README.md)

New exports automatically discover water across the entire exported square. No operator imports, API keys, new PHP extensions, schema changes or UI settings are required.

## Sources and cache

The baseline is [USGS 3DHP/NHD waterbody polygons and flowlines](https://hydro.nationalmap.gov/arcgis/rest/services/3DHP_all/MapServer). OpenStreetMap water polygons supplement missing bodies; duplicate polygons are removed in PostGIS. Queries intersect the projected square, so a lake enclosing the map is included even if its shoreline lies outside it. OSM area lookup also attempts enclosing features.

Requests run in the background export worker. Validated responses use the existing private seven-day BeamNG resource cache and cleanup schedule. Transient failures retry three times, with cancellation checked during downloads and waits. Each response is capped at 16 MiB. Failed or malformed responses are not cached as successful data.

Limits bound processing (without increasing the worker PHP memory limit): 128 USGS bodies, 256 flowlines, 128 OSM bodies, 100,000 incoming coordinate vertices, 20,000 normalized vertices, 4,096 vertices per feature, 640 normalized parts and 1,024 native water patches. Reaching a limit produces a warning rather than an unbounded job. Extremely large or complex water bodies may be omitted.

## Rendering and estimates

PostGIS repairs and clips polygons while retaining island holes. Permanent lakes, ponds, reservoirs and mapped river/canal areas become native BeamNG v0.40 River objects. A flowline alone does not establish a river's width; no arbitrary ribbon is invented for streams without mapped banks. Explicitly seasonal/intermittent OSM water is omitted.

Inscribed grid cells are coalesced into native rectangular patches. Shoreline cell size adapts from roughly 1-4 metres up to 32 metres to fit the existing 1,024-object budget; it is recorded per feature together with covered area. Dense shoreline vertices no longer directly determine object count. Narrow channels and shoreline slivers can still be omitted at coarser resolutions. This avoids a single rectangular water plane flooding land or islands. Lake levels use low interior DEM samples. Rivers use mapped USGS flowlines and a terrain-derived downhill profile when available; inconsistent slopes or unsupported geometry are omitted. Water colors come from interior aerial samples. Core game water textures are referenced without copying proprietary assets into the archive.

Water level, depth and bed shape are estimates. Lake shaping lowers a nominal 1.5-metre shallow bed by at most three metres, fading at shorelines; river-bank shaping is described below. Raster work is bounded and road/tunnel buffers are protected. Native water physics are enabled, but no measured current or bathymetry is claimed. Rivers without a usable profile require sufficiently flat terrain. Source Z coordinates are not used without verified vertical datum information.

Water conflicting with ordinary pavement or tunnel geometry, or lacking bridge-deck clearance, is skipped with a diagnostic. Exported road elevations are not changed by the water pass. Mapped water also excludes roadside scenery/buildings while dry islands remain available. Existing bridge, tunnel, median and shoulder processing remains responsible for road geometry.

## Diagnostics and verification

The export progress identifies water downloads and preparation. Provider outages are optional-data warnings; generation can continue with remaining providers or no added water, without fabricating replacement lakes.

Look for `water_data` in `build.log` and the approximation report: provider availability, cache timestamps/hashes, source counts, duplicates, rejected features, estimated profiles, bed edits and processing limits are recorded. Input water JSON files are hashed in the build log. Coordinate provenance is location-specific to the requested export; no locality-specific rules are built into the application.

Offline tests cover island preservation, shore strips, OSM relation assembly, bridge/tunnel/road protection, river profiles, retries/cancellation, scenery exclusions and complete archive output. The disposable PostGIS integration suite covers clipping, projection, deduplication and the combined fetch workflow. Actual in-game appearance and physics still require BeamNG validation.

Attribution: USGS 3DHP/NHD and OpenStreetMap contributors ([ODbL](https://www.openstreetmap.org/copyright)). Existing imagery/elevation attribution remains in each export.

## Rivers and bank contact (0.129.0)

River/canal profiles now include official USGS waterbody-connector flowlines (feature type 5), prioritized before ordinary river/canal lines in the bounded query. These mapped connectors provide geometry inside river waterbody polygons; a line alone still cannot establish water width. The query change creates a new resource-cache key automatically, so old responses excluding connectors are not reused for this request.

Where mapped profiles are usable, native River ribbons follow perpendicular intersections with the mapped banks instead of a rectangular shoreline grid. Width is constrained by polygon boundaries and island holes. Separate mapped components remain separate; chords across dry land are rejected. Lakes retain their established polygon-grid rendering. Flat rivers with no usable mapped line retain the explicitly reported grid fallback.

Elevation fitting samples more closely along each mapped component, filters isolated local DEM spikes over a bounded chainage window, and fits downhill profiles. Local drops receive bounded downhill slope fitting, allowing grades up to 20% with at most 12 m additional correction after the initial robust/downhill fit. This replaces whole-component rejection at 5%; genuinely unsupported components are still omitted independently. These thresholds describe estimated simulation profiles, not measured channel grades. Surface elevation, channel bed and shoreline remain estimates, not surveyed water engineering.

River terrain blends to about 5 cm above the estimated waterline at the bank, with a submerged nominal 3 m river bed (as of 0.130.0) and an outer blend spanning at least 1.5 terrain cells. Per-vertex changes are limited to 12 m downward and 3 m upward. Ordinary road/tunnel support buffers remain protected, and only areas near supported ribbons are shaped. Lake bed shaping is unchanged.

Reports/build.log identify each feature's surface method, native object names, profile paths, rejected profile-component count and bank-blend distance. Rejected whole features now include their source IDs and reasons. Inspect these fields when a river is missing or only partially represented.

USGS documents [waterbody connectors in its flowline layer](https://hydro.nationalmap.gov/arcgis/rest/services/3DHP_all/MapServer/50). Shared source limits can still truncate coverage; native spline surfaces and bank contact require fresh in-game validation.
