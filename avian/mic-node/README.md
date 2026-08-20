# Mic node — Pi-side receivers

Pi-side deployment for the T-SIM7080G mic node's audio paths. **Buffered
store-and-forward (dumpd, :8557) is the active path** since 2026-08-16
(node fw v1.38+). The UDP push path below (burst/live) is a fallback, and
RTSP pull is retired (`RTSP_STREAM=` empty, `birdnet_recording` inactive).

> Moved here from the node repo (`tsim7080g-node/pi/`) on 2026-08-20 — this
> repo is the source of truth for the Pi deployment. The node firmware lives
> in the sibling repo `~/code/tsim7080g-node` (dump protocol spec:
> `docs/buffered-recording-spec.md` there).

| Port | Consumer | Purpose |
|---|---|---|
| 8557/tcp | `birdnet-dumpd.py` (this dir, §3) | **active**: buffered ADPCM dumps → StreamData WAVs |
| 8555/udp | `birdnet-udp2-recording.sh` (this dir) | fallback: analysis segments → `~/BirdSongs/StreamData` |
| 8556/udp | `livestream.sh` UDP branch (`../..`/scripts/livestream.sh) | icecast live audio (only while node is in *live* mode) |

UDP push details (fallback): the node streams 48 kHz s16le mono PCM.
It only sends to 8556 in **live** mode; in **burst** mode only 8555 gets
audio (duty-cycled 20 s on / 100 s off by default).

## 1. UDP2 recording service (analysis)

```bash
sudo cp avian/mic-node/birdnet-udp2-recording.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/birdnet-udp2-recording.sh
sudo cp avian/mic-node/birdnet-udp2.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now birdnet-udp2
```

Optional `birdnet.conf` overrides:
```ini
UDP2_STREAM_PORT=8555
```

The service writes `%F-birdnet-UDP2-*.wav` into `$RECS_DIR/StreamData`
(`RECORDING_LENGTH`-second segments, same as the RTSP path), and
`birdnet_analysis` consumes them unchanged. Burst gaps are handled with a UDP
read timeout (`UDP_READ_TIMEOUT_US`, default 10 min): on a dead stream ffmpeg
exits (finalizing the open segment's WAV header so analysis consumes it
normally) and the loop respawns a fresh listener. Without the timeout a node
dying mid-segment leaves the WAV open and header-unfinalized forever -
invisible to `birdnet_analysis` (no IN_CLOSE_WRITE; open files are excluded
from its backlog) and later bursts would append to the stale segment.

## 2. Live audio (icecast) — Bird Up! hook

Already wired in this repo:

- `avian/api/live-audio.php` — `?action=start|end|status`; starts `livestream`
  on demand, toggles the node `/api/live?on=1|0`, and restores both on end.
- `avian/frontend/apt.js` — the drawer LIVE AUDIO button calls start/end.
- `scripts/livestream.sh` — added a UDP source branch driven by
  `UDP_LIVESTREAM_PORT` (default 8556) in `birdnet.conf`:
  ```ini
  UDP_LIVESTREAM_PORT=8556
  ```
  The node switches to continuous push while live, so the icecast feed is
  gap-free during a listening session. When the session ends the node returns
  to burst mode ("ends if the mic node was initially in burst mode").

### Node config on the Pi

`avian/config/mic-node.json`:
```json
{ "url": "http://192.168.86.51", "service": "livestream" }
```
(`url` = the node's LAN address; the node's `/api/live` is open on the LAN.)

### Sudoers note

`birdnet-udp2` runs as `pi`; `livestream` start/stop from PHP (caddy user)
is covered by the existing `/etc/sudoers.d/010_caddy-nopasswd` rule. If you
tighten that, add to `/etc/sudoers.d/020_avian-admin`:
```
/bin/systemctl start livestream, \
/bin/systemctl stop livestream, \
/bin/systemctl is-active livestream, \
/bin/systemctl is-active birdnet-udp2
```

## 3. Buffered dump receiver (dumpd) — PRIMARY analysis path since 2026-08-16

With node fw v1.38+ in buffered store-and-forward mode, the node does NOT
push UDP; it TCP-dumps ADPCM frames to the Pi on **:8557** every `udp_buf_int_s`
(default 300 s). `birdnet-dumpd.py` decodes them to 15 s 24 kHz WAVs named by
capture time (`%F-birdnet-UDP2-*.wav` → StreamData; `birdnet_analysis`
resamples and consumes them unchanged).

Installed 2026-08-17 as a systemd unit:

```bash
sudo cp avian/mic-node/birdnet-dumpd.py /usr/local/bin/
sudo chmod +x /usr/local/bin/birdnet-dumpd.py            # else exit 203/EXEC
sudo sed 's/<USER>/dylanberry/g' avian/mic-node/birdnet-dumpd.service | sudo tee /etc/systemd/system/birdnet-dumpd.service
                                                       # tee, not `>` (redirect runs unprivileged)
sudo pkill -f 'nohup.*birdnet-dumpd' 2>/dev/null; pkill -f birdnet-dumpd.py  # old copies hold :8557
sudo systemctl daemon-reload
sudo systemctl enable --now birdnet-dumpd
```

Watch dumps: `journalctl -u birdnet-dumpd -f` (expect a connection from
192.168.86.51 every 5 min, then `wrote ...wav` lines + `dump complete`).
3 consecutive dump failures reboot the node (wedge detector), so this service
must stay up — `Restart=always` is in the unit. When dumpd is the active path,
`RTSP_STREAM` in birdnet.conf is empty and `birdnet_recording` is inactive;
`birdnet-udp2` can stay enabled but receives nothing.

A successful dump also refreshes `StreamData/.last-dump` (heartbeat marker);
`avian/api/health.php` judges mic-node freshness from it (WAVs are consumed
by analysis ~1-2 min after landing, so the dir is routinely empty — mtime of
the newest WAV is NOT a reliable liveness signal).

## 4. Smoke test

```bash
# node side (burst mode): watch segments appear
ls -lt ~/BirdSongs/StreamData/ | head
# live toggle (Bird Up! drawer → LIVE AUDIO) — then both ports should flow:
sudo tcpdump -i any -n udp port 8555 or port 8556   # (needs sudo)
```