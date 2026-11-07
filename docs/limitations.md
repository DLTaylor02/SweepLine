# Limitations, attribution and licensing

[Documentation index](README.md) | [Project overview](../README.md)

## Known limitations

OSM can omit stops, surfaces, speed limits and access restrictions. Check actual access, closures and conditions before driving. No turn-by-turn navigation, live traffic, elevation source or closure guarantees are implemented. Unnamed/name-changing roads and complex junctions can fragment candidates. Automatic cells have a halo to help continuity, but cross-cell stitching and overlapping fragments still need improvement. Large searches can require many downloads; public provider quotas prevent an instant nationwide first search. No whole-US road extract is downloaded during installation.

Road data is © [OpenStreetMap contributors](https://www.openstreetmap.org/copyright), under ODbL; ZIP coordinates are from [GeoNames](https://download.geonames.org/export/zip/) under its attribution license. Basemap requests follow the [OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/). Replace the raster source in `public/assets/app.js` for higher volume. MapLibre/fonts/tiles still require external network access; local road caching does not make the basemap offline.

## License

Application code is [MIT licensed](../LICENSE), copyright 2026 SweepLine contributors. External datasets, map tiles, fonts, and MapLibre retain their respective licenses.

