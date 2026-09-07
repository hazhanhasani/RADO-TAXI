# RADO Update Architecture

Production update flow:

`GitHub Release -> rado-taxi.sbs (cPanel mirror/updater) -> Passenger & Driver apps`

## Rules

- Mobile applications never query GitHub for updates.
- Passenger app checks `https://rado-taxi.sbs/api/app-updates/passenger`.
- Driver app checks `https://rado-taxi.sbs/api/app-updates/driver`.
- APK download URLs returned by the API are hosted on `rado-taxi.sbs`.
- cPanel checks GitHub releases from `hazhanhasani/RADO-TAXI` through cron and mirrors approved release assets.
- Android release builds use one permanent RADO signing key. Losing this key makes in-place updates impossible.
- cPanel updater preserves `.env`, runtime storage and user-generated data.

## cPanel cron

Recommended interval: every 5 minutes.

```cron
*/5 * * * * /usr/local/bin/php /home/CPANEL_USER/rado/bin/rado-update.php >/dev/null 2>&1
```

Adjust PHP and home paths to the hosting account.

## Release assets

A production release should publish:

- `RADO-Passenger-vX.Y.Z.apk`
- `RADO-Driver-vX.Y.Z.apk`
- `RADO-cPanel-vX.Y.Z.zip`
- `RADO-release.json`
- `SHA256SUMS.txt`

`RADO-release.json` contains app version codes, mandatory-update flags and minimum supported versions. cPanel downloads the release metadata and assets, verifies SHA-256 where available, then publishes app-update manifests locally.
