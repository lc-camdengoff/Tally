# Tally

Timecode markers for a shoot. Sync the phone to the camera's timecode (camera
OCR, audio LTC, a manual tap, or time of day), then tap colored buttons to log
moments. Export the markers as a Resolve EDL, CSV or text list.

The whole app is `index.html`: no build step. Its look is the filmmaking tools'
shared one: it links `../style/brand.css` (filmmaking-home's `style/brand.css`)
and maps its own names (`--bg`, `--panel`, `--text`…) onto that file's tokens at
the top of its `<style>`, so a change there changes Tally with every other tool.
Only `--tally`, the record-lamp red, is Tally's own. The nav bar across the top
is the same one as every tool's (`../auth/nav.css`): the app draws it from
`api.php?action=nav` and keeps the last answer, so it's there offline too. Everything is saved in the
browser's `localStorage` on the device, and also to the person's filmmaking
account when they're signed in (see *Saving to the account*).

## Offline

`sw.js` keeps a copy of everything on the phone the first time Tally is opened
with a connection, so after that it opens and camera sync works with no signal.
It keeps a copy of the shared look too (`brand.css`, its fonts, `nav.css`),
refreshed in the background each time, since filmmaking-home changes those
without Tally knowing. Settings → Offline says when the copy is ready. Adding Tally to the Home Screen
(Share → Add to Home Screen) makes it open like an app.

The page is fetched fresh whenever there's a connection, so a deploy reaches
people the next time they open it. The other files are only re-downloaded when
`VERSION` in `sw.js` changes: bump it whenever you add, remove or replace one of
the files in its `PRECACHE` list.

| Path | What it is |
| --- | --- |
| `vendor/tesseract/`, `vendor/tesseract-core/` | tesseract.js 5.1.1 and its core, the text reader camera sync uses (Apache 2.0) |
| `vendor/lang/eng.traineddata.gz` | its English model, `@tesseract.js-data/eng` 1.0.0, `4.0.0_best_int` |
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

- **Saved sessions** (`saved.php`, the Saved link on the home screen and in
  Settings) lists everything a person has saved, by folder, with each session's
  Resolve EDL, CSV and text list to download (`file.php`). It needs only a
  browser and the sign-in, so an editor can get the EDL on any computer. Its
  files are built in PHP (`lib.php`) and match the app's byte for byte:
  `node tests/export-parity.mjs` checks that, and runs before every deploy.
- `api.php` is what the app talks to: `me`, `changes` and `save`, JSON in and
  out. It uses the shared sign-in in `../auth/` (filmmaking-home) and its
  database, and creates its table, `tally_items` (`schema.sql`), on first use.
  `lib.php` and `schema.sql` are closed to the web by `.htaccess`.
- Every session and folder carries `updated`, set on the device whenever it
  changes, and the later change wins. A deletion is saved too, so it reaches
  the other devices. A session someone is logging markers into is never
  deleted from under them; it goes back up instead.
- **No connection on set is the normal case.** Every change is saved on the
  phone the moment it's made; nothing waits on the network. What hasn't
  reached the account yet is worked out from each item's `updated` against the
  last one saved, not kept in a queue, so there's nothing to lose if the app is
  closed mid-save. Tally tries again when it opens, when it comes back to the
  front, when the phone gets a connection, and every minute while it's open.
  A Home Screen web app can't run in the background on iPhone, so a phone that
  gets Wi-Fi while Tally is closed backs up the next time it's opened. Any
  connection will do, mobile data included: it's a few KB, and a web page
  can't tell Wi-Fi from mobile data on iPhone anyway.
- Each session in the list says **Backed up** or **On this phone**, and a line
  above the list says how many are waiting and why: offline, or signed out
  (sign-ins last 30 days, so a long stretch offline can outlast one), with a
  Sign in link. Nothing is lost either way; it goes up once signed in.
- The manifest's `scope` is the tools folder (`../`), not just `tally/`, so on
  an iPhone the sign-in pages open inside the Home Screen app. Outside its
  scope iOS would show them in a separate browser view, and the sign-in
  wouldn't reach the app.
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
