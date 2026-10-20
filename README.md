# Tally

Timecode markers for a shoot. Sync the phone to the camera's timecode (camera
OCR, audio LTC, a manual tap, or time of day), then tap colored buttons to log
moments. Export the markers as a Resolve EDL, CSV or text list.

The whole app is `index.html`: no build step. Everything is saved in the
browser's `localStorage` on the device.

## Offline

`sw.js` keeps a copy of everything on the phone the first time Tally is opened
with a connection, so after that it opens and camera sync works with no signal.
Settings → Offline says when that copy is ready. Adding Tally to the Home Screen
(Share → Add to Home Screen) makes it open like an app.

The page is fetched fresh whenever there's a connection, so a deploy reaches
people the next time they open it. The other files are only re-downloaded when
`VERSION` in `sw.js` changes: bump it whenever you add, remove or replace one of
the files in its `PRECACHE` list.

| Path | What it is |
| --- | --- |
| `vendor/tesseract/`, `vendor/tesseract-core/` | tesseract.js 5.1.1 and its core, the text reader camera sync uses (Apache 2.0) |
| `vendor/lang/eng.traineddata.gz` | its English model, `@tesseract.js-data/eng` 1.0.0, `4.0.0_best_int` |
| `fonts/` | Archivo and Martian Mono from Fontsource (SIL Open Font License) |
| `icons/`, `manifest.webmanifest` | Home Screen icon and name |

If camera sync can't load the local text reader, it falls back to jsDelivr.

Camera and microphone access need the page served over HTTPS (or `localhost`).

## Deploying

Pushing to `main` runs `.github/workflows/deploy.yml`, which uploads the app
over FTPS to `filmmaking/tally/`, live at
<https://creativemedia.church/filmmaking/tally/>. It needs the `FTP_USERNAME`
and `FTP_PASSWORD` repository secrets, the same FTP account the other
filmmaking tools use (rooted at `public_html/filmmaking/`). It can also be run
by hand from the Actions tab.
