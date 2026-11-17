# Terrain contact regression data

`bridge-underpass-contact.json` is a translated, anonymized numeric failure snapshot. It contains local terrain samples and road/structure geometry, with road names, OSM IDs, absolute map coordinates, selection metadata and account data removed. It is test input only; production behavior uses geometric relationships without identifying a location.

Geometry derives from OpenStreetMap contributors ([attribution and ODbL](https://www.openstreetmap.org/copyright)); terrain originates from the export's USGS elevation source. No imagery or game assets are included.

`tunnel-overpass-contact.json` is a translated, anonymized local terrain/road snapshot from a user-supplied failed export. It retains measured relative geometry and global chainage, without road names, OSM IDs, absolute coordinates or selection metadata. OSM-derived road geometry © OpenStreetMap contributors (ODbL); elevation data from USGS. It tests two estimated bores beneath a crossing street; it is not a full-map or surveyed-elevation fixture.
