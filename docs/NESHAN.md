# Neshan integration — RADO

RADO uses Neshan as the primary map provider for Baneh.

## Client map key

The passenger app reads the map display key at build time:

```text
NESHAN_MAP_KEY
```

Do not commit a real key. GitHub Actions expects it as a repository secret.

## Backend service key

Search, reverse-geocoding and routing are designed to pass through the RADO backend so the service key is not bundled in the APK:

```text
NESHAN_SERVICE_API_KEY
NESHAN_API_BASE_URL=https://api.neshan.org
```

Planned RADO endpoints:

```text
GET /api/v1/maps/reverse?lat=...&lng=...
GET /api/v1/maps/search?term=...&lat=...&lng=...
GET /api/v1/maps/route?origin=lat,lng&destination=lat,lng
```

The mobile client talks only to these RADO endpoints for service calls.

## Baneh default viewport

```text
lat: 35.9968
lng: 45.8853
zoom: 15
```

## First APK behavior

If `NESHAN_MAP_KEY` is available during build, the real Neshan map is displayed with POIs, traffic and the current-location control.

If `RADO_API_BASE_URL` is not configured yet, the app remains usable as a UI preview. Pickup and destination can still be selected and a straight-line preview distance/ETA is shown. Once the backend map proxy is enabled, the same UI automatically switches to real reverse-geocoding and route metrics.
