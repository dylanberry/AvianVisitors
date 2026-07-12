# Deployment notes

This folder collects the non-AvianVisitors configuration changes made for the
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
