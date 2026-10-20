# Tally

Timecode markers for a shoot. Sync the phone to the camera's timecode (camera
OCR, audio LTC, a manual tap, or time of day), then tap colored buttons to log
moments. Export the markers as a Resolve EDL, CSV or text list.

The whole app is `index.html`: no build step. Everything is saved in the
browser's `localStorage` on the device, and also to the person's filmmaking
account when they're signed in (see *Saving to the account*).

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

## Saving to the account

Signed in to their filmmaking account (Settings → Filmmaking account → Sign
in), a person's sessions and folders also save to the shared database, so
they're safe if the phone is lost and show up on their other devices: log on a
phone, then open Tally on the edit machine to export the EDL. Signed out or
offline the app works exactly as before. The phone's copy is the one it runs on,
and the account catches up when there's a connection.

- `api.php` is the only server code: `me`, `changes` and `save`, JSON in and
  out. It uses the shared sign-in in `../auth/` (filmmaking-home) and its
  database, and creates its table, `tally_items` (`schema.sql`), on first use.
  `lib.php` and `schema.sql` are closed to the web by `.htaccess`.
- Every session and folder carries `updated`, set on the device whenever it
  changes, and the later change wins. A deletion is saved too, so it reaches
  the other devices. A session someone is logging markers into is never
  deleted from under them; it goes back up instead.
- Sessions made while signed out are saved to whoever signs in next on that
  phone. Sessions already saved to one account are never sent to another.
- Access is the `tally` role in filmmaking-home's `auth/tools.php`: someone set
  to No access on Team admin can still use the app, but it doesn't save to their
  account.
- Button layouts and settings stay on each device. A folder's buttons travel
  with the folder.

## Deploying

Pushing to `main` runs `.github/workflows/deploy.yml`, which uploads the app
over FTPS to `filmmaking/tally/`, live at
<https://creativemedia.church/filmmaking/tally/>. It needs the `FTP_USERNAME`
and `FTP_PASSWORD` repository secrets, the same FTP account the other
filmmaking tools use (rooted at `public_html/filmmaking/`). It can also be run
by hand from the Actions tab.
