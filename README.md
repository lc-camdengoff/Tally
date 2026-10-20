# Tally

Timecode markers for a shoot. Sync the phone to the camera's timecode (camera
OCR, audio LTC, a manual tap, or time of day), then tap colored buttons to log
moments. Export the markers as a Resolve EDL, CSV or text list.

The whole app is `index.html`: no build step. Everything is saved in the
browser's `localStorage` on the device.

## Files `index.html` expects next to it

These aren't in the repo yet. The app runs without them but loses what each one
adds:

| Path | Without it |
| --- | --- |
| `manifest.webmanifest`, `icons/icon-180.png`, `icons/icon-192.png` | No proper Home Screen icon or app name |
| `fonts/archivo-*.woff2`, `fonts/martian-mono-*.woff2` | Falls back to system fonts |
| `sw.js` | No offline copy. Settings will keep saying "Saving a copy to this phone" |
| `vendor/tesseract/`, `vendor/tesseract-core/`, `vendor/lang/eng.traineddata.gz` | Camera sync loads its text reader from the jsDelivr CDN, so it needs a connection |

Camera and microphone access need the page served over HTTPS (or `localhost`).

## Deploying

Pushing to `main` runs `.github/workflows/deploy.yml`, which uploads the app
over FTPS to `filmmaking/tally/`, live at
<https://creativemedia.church/filmmaking/tally/>. It needs the `FTP_USERNAME`
and `FTP_PASSWORD` repository secrets, the same FTP account the other
filmmaking tools use (rooted at `public_html/filmmaking/`). It can also be run
by hand from the Actions tab.
