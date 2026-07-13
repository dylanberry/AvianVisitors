# Deployment notes

This folder collects the non-Bird Up! configuration changes made for the
Toronto Raspberry Pi 4 deployment.

## Files

- `Caddyfile.sample` — full Caddy config template. Keeps the collage public and
  password-protects only the admin menu/tools endpoints. Copy to `Caddyfile`,
  replace the placeholder hash, and edit the paths for your system.
- `birdnet.conf.example` — the BirdNET-Pi settings that differ from defaults
  (confidence, sensitivity, audio gain, coordinates placeholder).
- `analysis.py.patch` — patch for `BirdNET-Pi/scripts/utils/analysis.py` to
  apply the configurable `AUDIO_GAIN` before analysis.
- `crontab-alsa.txt` — ALSA commands to set the USB mic to 100% capture volume
  and disable AGC on every boot.

## Applying

1. Copy `Caddyfile.sample` to `/etc/caddy/Caddyfile`, replace the bcrypt hash
   placeholder with your own password, adjust the root path and any hostnames,
   then `sudo caddy reload`.
2. Apply `analysis.py.patch` in your `BirdNET-Pi` directory.
3. Merge the values from `birdnet.conf.example` into your `birdnet.conf`.
4. Add the two `@reboot` lines from `crontab-alsa.txt` to the Pi user's crontab.

## PHP-FPM permissions for corrections

The `correction.php` admin endpoint can hide detections, reidentify them (moving audio/spectrogram files), and append species to `exclude_species_list.txt`. On the live Pi, PHP-FPM runs as the `caddy` user, so that user must have write access to:

- `~/BirdNET-Pi/scripts/birds.db` — `hide` and `reidentify` update the `detections` table.
- `~/BirdSongs/Extracted/By_Date/` — `reidentify` moves `.mp3` and `.png` files into the new species directory.
- `~/BirdNET-Pi/exclude_species_list.txt` — `exclude` appends the requested species.

Verify the permissions on the Pi:

```bash
ls -l ~/BirdNET-Pi/scripts/birds.db
ls -ld ~/BirdSongs/Extracted/By_Date/
ls -l ~/BirdNET-Pi/exclude_species_list.txt
```

If the `caddy` user cannot write them, adjust group ownership and group-write:

```bash
sudo chown :caddy ~/BirdNET-Pi/scripts/birds.db
sudo chmod g+w ~/BirdNET-Pi/scripts/birds.db
sudo chown -R :caddy ~/BirdSongs/Extracted/By_Date/
sudo chmod -R g+w ~/BirdSongs/Extracted/By_Date/
sudo chown :caddy ~/BirdNET-Pi/exclude_species_list.txt
sudo chmod g+w ~/BirdNET-Pi/exclude_species_list.txt
```

## Deploying updates from this workstation

This repo is the source-of-truth copy for the Toronto Pi. After front-end or
auth changes, push the updated files to the live Pi:

- `avian/frontend/index.html`
- `avian/frontend/apt.js`
- `avian/api/correction.php`
- `avian/api/birdnet-api.php`
- `docs/deployment/Caddyfile` (gitignored, contains the real bcrypt hash)
- `avian/forwarding/caddy-auth.caddy` (if the admin path list changes)

Fast path: run `./scripts/deploy-to-pi.sh` from the repo root. It copies the
files, reloads Caddy, and verifies public endpoints return `200` and admin
endpoints return `401`.

The script requires a temporary passwordless sudoers entry on the Pi because the
workstation agent cannot prompt for the Pi sudo password. If the entry is
missing, the script prints the command to create it. On the Pi:

```bash
echo 'dylanberry ALL=(root) NOPASSWD: /usr/bin/cp /tmp/Caddyfile /etc/caddy/Caddyfile, /usr/bin/systemctl reload caddy, /usr/bin/rm -f /etc/sudoers.d/avian-deploy' \
  | sudo tee /etc/sudoers.d/avian-deploy \
  && sudo chmod 440 /etc/sudoers.d/avian-deploy \
  && sudo visudo -c
```

The script removes `/etc/sudoers.d/avian-deploy` after the Caddy reload.

When editing `apt.js`, bump the cache-busting query string in `index.html`
(`apt.js?v=r...`) so browsers load the new version. The live Caddyfile in
`docs/deployment/Caddyfile` must stay in sync with `Caddyfile.sample` except for
the real bcrypt hash and system-specific paths.
