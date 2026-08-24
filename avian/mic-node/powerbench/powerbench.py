#!/usr/bin/env python3
"""powerbench — automated power-efficiency experiment controller for the
T-SIM7080G mic node.

Runs either in-cluster (k3s, namespace birdup-go; telemetry source =
Prometheus instant queries against the birdup exporter) or on sb-birdnet-pi4
(telemetry source = dumpd JSONL) — see telemetry_source in the config.
Walks an experiment schedule (experiments.json), applying each phase's node
config via the node's /api/set endpoint, pausing the phase clock while the
node is charging or below the battery floor, and tripping guards (crash /
dropped-frames) that revert the node to baseline and halt the schedule.

Node state is read-only observation (Prometheus or JSONL — nothing ever polls
the node for state, it is unreachable except during ECO dump windows).
Config changes are pushed by polling /api/set until a dump window answers.

Exposes Prometheus metrics on :9559/metrics:
  powerbench_phase_info{phase,index,config_hash} 1
  powerbench_phase_start_timestamp_seconds
  powerbench_phase_duration_seconds
  powerbench_phase_run_seconds            (battery-time accumulated this phase)
  powerbench_paused{reason} 0/1
  powerbench_halted 0/1
  powerbench_guard_tripped_total{guard}
  powerbench_apply_failures_total
  powerbench_last_transition_timestamp_seconds

Modes:
  powerbench.py run      — daemon (systemd)
  powerbench.py status   — print state
  powerbench.py pause|resume|abort|skip — control the running daemon
Stdlib only, mirroring node-telemetry-exporter.py conventions.
"""

import json
import logging
import os
import sys
import threading
import time
import urllib.parse
import urllib.request
import urllib.error
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

logging.basicConfig(level=logging.INFO,
                    format="%(asctime)s [powerbench] %(levelname)s %(message)s")
log = logging.getLogger("powerbench")

DEFAULT_STATE_DIR = os.path.expanduser("~/.powerbench")

# Known-good baseline values for keys we experiment on, used when /api/status
# does not expose the key. Values are strings: /api/set takes form fields.
DEFAULT_BASELINE = {
    "pwr_cpu_low": "160",
    "udp_buf_int_s": "300",
    "pwr_tx_dbm": "-1",
}


_MISSING = object()


def _load_json(path, default=_MISSING):
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, ValueError):
        if default is not _MISSING:
            return default
        raise


def _save_json(path, obj):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(obj, fh, indent=2)
    os.replace(tmp, path)


class Controller:
    def __init__(self, cfg, experiments, state_dir):
        self.cfg = cfg
        self.phases = experiments["phases"]
        self.state_dir = state_dir
        self.state_path = os.path.join(state_dir, "state.json")
        self.control_path = os.path.join(state_dir, "control.json")
        self.lock = threading.Lock()
        self.state = _load_json(self.state_path, default=None) or self._fresh_state()
        self.guard_trips = {}            # guard -> count (process lifetime)
        self.apply_failures = 0

    # ------------------------------------------------------------------ state

    def _fresh_state(self):
        return {
            "phase_index": 0,
            "phase_start_epoch": None,     # wall clock when phase began
            "phase_run_s": 0.0,            # battery-time accumulated this phase
            "paused_reason": None,         # None | "charging" | "low_batt" | "manual"
            "halted": False,
            "applied": {},                 # keys currently pushed to the node
            "baseline": {},                # captured before first mutation
            "phase_start_restart_count": None,
            "phase_start_dropped_frames": None,
            "guard_tripped": None,
            "last_transition_epoch": None,
            "last_tick_epoch": None,
            "reports": {},                 # phase name -> analysis report dict
        }

    def save(self):
        _save_json(self.state_path, self.state)

    @property
    def phase(self):
        if self.state["phase_index"] >= len(self.phases):
            return None
        return self.phases[self.state["phase_index"]]

    # --------------------------------------------------------------- node I/O

    def _node_request(self, path, data=None, timeout=10):
        url = self.cfg["node_url"].rstrip("/") + path
        body = None
        headers = {"X-ESP32MIC-CSRF": "1"}
        if data is not None:
            body = "&".join("%s=%s" % (k, v) for k, v in data.items()).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(url, data=body, headers=headers)
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.read().decode("utf-8", "replace")

    def node_reachable_status(self):
        try:
            return json.loads(self._node_request("/api/status", timeout=8))
        except Exception:
            return None

    def apply_config(self, kv, deadline_s):
        """Push keys via /api/set, retrying across ECO dump windows until the
        deadline. Returns True on full success."""
        deadline = time.time() + deadline_s
        pending = dict(kv)
        while pending and time.time() < deadline:
            for key in list(pending):
                try:
                    self._node_request("/api/set", data={"key": key, "value": pending[key]})
                    log.info("applied %s=%s", key, pending[key])
                    del pending[key]
                except Exception as exc:
                    log.info("apply %s pending (node in radio-off window?): %s", key, exc)
                    time.sleep(2)
            if pending:
                time.sleep(30)
        if pending:
            self.apply_failures += 1
            log.error("apply timed out, unapplied: %s", sorted(pending))
            return False
        return True

    # -------------------------------------------------------------- telemetry

    def telemetry_snapshot(self):
        """Newest node telemetry as a dict, from the configured source:
        'prometheus' (in-cluster; instant queries against the birdup exporter
        metrics) or 'jsonl' (on-Pi; tails the dumpd day files). Returns None
        when nothing fresh is available."""
        if self.cfg.get("telemetry_source", "jsonl") == "prometheus":
            try:
                return self._snapshot_prometheus()
            except Exception as exc:
                log.warning("prometheus snapshot failed: %s", exc)
                return None
        return self.latest_record()

    def _prom_query(self, query):
        url = (self.cfg["prometheus_url"].rstrip("/") +
               "/api/v1/query?query=" + urllib.parse.quote(query))
        with urllib.request.urlopen(url, timeout=10) as resp:
            data = json.loads(resp.read().decode("utf-8", "replace"))
        result = data.get("data", {}).get("result", [])
        return result[0] if result else None

    def _prom_value(self, query):
        res = self._prom_query(query)
        try:
            return float(res["value"][1])
        except (TypeError, KeyError, IndexError, ValueError):
            return None

    def _snapshot_prometheus(self):
        ts = self._prom_value("birdnode_telemetry_last_record_timestamp_seconds")
        if ts is None:
            return None
        snap = {
            "ts": ts,
            "batt_vbus": self._prom_value("birdnode_battery_vbus"),
            "batt_chg": self._prom_value("birdnode_battery_charging"),
            "batt_pct": self._prom_value("birdnode_battery_soc_percent"),
            "restart_count": self._prom_value("birdnode_restart_count"),
            "dropped_frames": self._prom_value("birdnode_dropped_frames_total"),
        }
        info = self._prom_query("birdnode_info")
        labels = (info or {}).get("metric", {})
        snap["boot_reason"] = labels.get("boot_reason", "")
        snap["prev_reboot"] = labels.get("prev_reboot", "")
        return snap

    # ------------------------------------------------------------- analysis

    def _prom_range(self, query, start, end, step=300):
        """query_range -> [(ts, value), ...] for the first result series."""
        url = (self.cfg["prometheus_url"].rstrip("/") +
               "/api/v1/query_range?query=" + urllib.parse.quote(query) +
               "&start=%.0f&end=%.0f&step=%d" % (start, end, step))
        with urllib.request.urlopen(url, timeout=30) as resp:
            data = json.loads(resp.read().decode("utf-8", "replace"))
        result = data.get("data", {}).get("result", [])
        if not result:
            return []
        pts = []
        for ts, val in result[0].get("values", []):
            try:
                pts.append((float(ts), float(val)))
            except (TypeError, ValueError):
                pass
        return pts

    @staticmethod
    def _lin_slope_per_hour(pts):
        """Least-squares slope of (epoch, value) points, per hour."""
        n = len(pts)
        if n < 4:
            return None
        sx = sum(p[0] for p in pts); sy = sum(p[1] for p in pts)
        sxx = sum(p[0] * p[0] for p in pts); sxy = sum(p[0] * p[1] for p in pts)
        denom = n * sxx - sx * sx
        if denom == 0:
            return None
        return (n * sxy - sx * sy) / denom * 3600.0

    def analyze_phase(self, idx, start, end, run_s, completed_by):
        """Compute the per-phase report over [start, end] from Prometheus and
        store it in state. Skipped silently for jsonl telemetry source or
        phases with <1 battery-hour."""
        if self.cfg.get("telemetry_source", "jsonl") != "prometheus":
            return
        phase = self.phases[idx]
        name = phase["name"]
        if run_s < 3600:
            log.info("phase %s: %.0f battery-s, too short to analyze", name, run_s)
            return
        try:
            mv_pts = self._prom_range(
                "birdnode_battery_millivolts and (birdnode_battery_vbus == 0)", start, end)
            pct_pts = self._prom_range(
                "birdnode_battery_soc_percent and (birdnode_battery_vbus == 0)", start, end)
            dur = max(1, int(end - start))
            def inst(q):
                v = self._prom_value_at(q, end)
                return v
            report = {
                "phase": name,
                "config": phase.get("set", {}),
                "start": start, "end": end, "completed_by": completed_by,
                "battery_hours": round(run_s / 3600.0, 2),
                "slope_mv_per_hour": self._lin_slope_per_hour(mv_pts),
                "slope_pct_per_hour": self._lin_slope_per_hour(pct_pts),
                "discharge_samples": len(mv_pts),
                "duty_cycle": inst("avg_over_time((birdnode_last_dump_duration_seconds / birdnode_dump_interval_seconds)[%ds:60])" % dur),
                "dropped_frames": inst("increase(birdnode_dropped_frames_total[%ds])" % dur),
                "restarts": inst("increase(birdnode_restart_count[%ds])" % dur),
                "rssi_dbm_avg": inst("avg_over_time(birdnode_rssi_dbm[%ds:60])" % dur),
            }
            with self.lock:
                self.state.setdefault("reports", {})[name] = report
                self.save()
            log.info("phase %s report: %s", name, report)
            self._report_notify(report)
        except Exception:
            log.exception("phase %s analysis failed", name)

    def _prom_value_at(self, query, at):
        url = (self.cfg["prometheus_url"].rstrip("/") +
               "/api/v1/query?query=" + urllib.parse.quote(query) +
               "&time=%.0f" % at)
        with urllib.request.urlopen(url, timeout=15) as resp:
            data = json.loads(resp.read().decode("utf-8", "replace"))
        result = data.get("data", {}).get("result", [])
        if not result:
            return None
        try:
            return float(result[0]["value"][1])
        except (TypeError, KeyError, IndexError, ValueError):
            return None

    def _report_notify(self, report):
        name = report["phase"]
        mv = report.get("slope_mv_per_hour")
        baseline = self.state.get("reports", {}).get("baseline")
        lines = [
            "battery time: %.1f h (%s)" % (report["battery_hours"], report["completed_by"]),
            "discharge slope: %s mV/h | %s %%/h (%d discharge samples)" % (
                ("%.1f" % mv) if mv is not None else "n/a",
                ("%.2f" % report["slope_pct_per_hour"]) if report.get("slope_pct_per_hour") is not None else "n/a",
                report["discharge_samples"]),
            "duty cycle: %s | drops: %s | restarts: %s | rssi: %s dBm" % (
                ("%.1f%%" % (report["duty_cycle"] * 100)) if report.get("duty_cycle") is not None else "n/a",
                ("%.0f" % report["dropped_frames"]) if report.get("dropped_frames") is not None else "n/a",
                ("%.0f" % report["restarts"]) if report.get("restarts") is not None else "n/a",
                ("%.0f" % report["rssi_dbm_avg"]) if report.get("rssi_dbm_avg") is not None else "n/a"),
        ]
        verdict = None
        if mv is not None and baseline and baseline.get("slope_mv_per_hour") and name != "baseline":
            base_mv = baseline["slope_mv_per_hour"]
            if base_mv < 0 and mv < 0:
                delta = (abs(mv) / abs(base_mv) - 1.0) * 100.0
                verdict = "vs baseline: %+.0f%% discharge rate" % delta
                lines.append(verdict)
        body = "\n".join(lines)
        self._notify("powerbench: phase %s %s" % (name, report["completed_by"]), body)
        self._annotate("phase %s %s: %s" % (name, report["completed_by"],
                                            verdict or "report ready"),
                       ["powerbench", name, "report"])

    def latest_record(self):
        """Newest JSONL record across today+yesterday (UTC rollover)."""
        import datetime
        now_utc = datetime.datetime.now(datetime.timezone.utc)
        paths = []
        for delta in (1, 0):
            day = (now_utc - datetime.timedelta(days=delta)).strftime("%Y-%m-%d")
            paths.append(os.path.join(self.cfg["jsonl_dir"],
                                      "node-telemetry-%s.jsonl" % day))
        for path in reversed(paths):
            try:
                with open(path, "rb") as fh:
                    fh.seek(0, os.SEEK_END)
                    size = fh.tell()
                    fh.seek(max(0, size - 65536))
                    tail = fh.read().decode("utf-8", "replace")
                for line in reversed(tail.strip().splitlines()):
                    try:
                        return json.loads(line)
                    except ValueError:
                        continue
            except OSError:
                continue
        return None

    # ------------------------------------------------------------ transitions

    def _capture_baseline(self, keys):
        st = self.node_reachable_status()
        for key in keys:
            if key in self.state["baseline"]:
                continue
            val = None
            if st is not None:
                val = st.get(key)
            if val is None:
                val = DEFAULT_BASELINE.get(key)
                log.warning("baseline for %s not readable from node; using default %s", key, val)
            self.state["baseline"][key] = str(val)

    def _notify(self, title, body, priority="3"):
        token = os.environ.get("NTFY_TOKEN")
        if not token:
            token_path = os.path.join(self.state_dir, "ntfy-token")
            try:
                with open(token_path, "r", encoding="utf-8") as fh:
                    token = fh.read().strip()
            except OSError:
                log.info("no ntfy token (env or %s); skipping notification", token_path)
                return
        url = "%s/%s" % (self.cfg["ntfy_url"].rstrip("/"), self.cfg["ntfy_topic"])
        req = urllib.request.Request(url, data=body.encode(), headers={
            "Authorization": "Bearer " + token,
            "Title": title, "Priority": priority, "Tags": "bird,microchip"})
        try:
            urllib.request.urlopen(req, timeout=10).read()
        except Exception as exc:
            log.warning("ntfy post failed: %s", exc)

    def _annotate(self, text, tags):
        token = os.environ.get("GRAFANA_TOKEN")
        if not token:
            token_path = os.path.join(self.state_dir, "grafana-token")
            try:
                with open(token_path, "r", encoding="utf-8") as fh:
                    token = fh.read().strip()
            except OSError:
                token = None
        user = os.environ.get("GRAFANA_USER") or self.cfg.get("grafana_user")
        password = os.environ.get("GRAFANA_PASSWORD") or self.cfg.get("grafana_password")
        if not token and not (user and password):
            return
        payload = json.dumps({"text": text, "tags": tags}).encode()
        headers = {"Content-Type": "application/json"}
        if token:
            headers["Authorization"] = "Bearer " + token
        else:
            import base64
            headers["Authorization"] = "Basic " + base64.b64encode(
                ("%s:%s" % (user, password)).encode()).decode()
        req = urllib.request.Request(self.cfg["grafana_url"].rstrip("/") + "/api/annotations",
                                     data=payload, headers=headers)
        try:
            urllib.request.urlopen(req, timeout=10).read()
        except Exception as exc:
            log.warning("grafana annotation failed: %s", exc)

    def _enter_phase(self, idx):
        """Apply phase idx's config and reset per-phase accounting. Analyzes
        the outgoing phase first (report -> state/metrics + ntfy + Grafana)."""
        st = self.state
        prev_idx = st["phase_index"]
        if idx != prev_idx and prev_idx < len(self.phases) and st.get("phase_start_epoch"):
            self.analyze_phase(prev_idx, st["phase_start_epoch"], time.time(),
                               st.get("phase_run_s", 0.0), "complete")
        if idx >= len(self.phases):
            # Schedule complete: mark done; tick() applies baseline + notifies.
            st["phase_index"] = idx
            st["last_transition_epoch"] = time.time()
            self.save()
            return
        phase = self.phases[idx]
        name = phase["name"]
        log.info("entering phase %d (%s)", idx, name)
        if phase.get("set"):
            self._capture_baseline(phase["set"].keys())
        ok = self.apply_config(phase.get("set", {}), self.cfg["apply_timeout_s"])
        st = self.state
        rec = self.telemetry_snapshot() or {}
        st.update({
            "phase_index": idx,
            "phase_start_epoch": time.time(),
            "phase_run_s": 0.0,
            "paused_reason": None,
            "applied": dict(phase.get("set", {})),
            "phase_start_restart_count": rec.get("restart_count"),
            "phase_start_dropped_frames": rec.get("dropped_frames"),
            "guard_tripped": None,
            "last_transition_epoch": time.time(),
        })
        self.save()
        if not ok:
            self._abort("apply timeout entering phase %s" % name)
            return
        self._notify("powerbench: phase %s" % name,
                     "Entered phase %d (%s), set=%s" % (idx, name, phase.get("set", {})))
        self._annotate("powerbench phase: %s" % name, ["powerbench", name])

    def _abort(self, reason):
        """Revert to baseline and halt the schedule. Analyzes the interrupted
        phase first (partial data still informs the guard decision)."""
        log.error("ABORT: %s", reason)
        st = self.state
        idx = st["phase_index"]
        if idx < len(self.phases) and st.get("phase_start_epoch"):
            self.analyze_phase(idx, st["phase_start_epoch"], time.time(),
                               st.get("phase_run_s", 0.0), "aborted")
        self.apply_config(self.state["baseline"], self.cfg["apply_timeout_s"])
        st = self.state
        st["halted"] = True
        st["applied"] = {}
        self.save()
        self._notify("powerbench ABORT", reason + " — node reverted to baseline, schedule halted.", "5")
        self._annotate("powerbench ABORT: " + reason, ["powerbench", "abort"])

    def _trip_guard(self, guard, detail):
        self.guard_trips[guard] = self.guard_trips.get(guard, 0) + 1
        self.state["guard_tripped"] = guard
        self.save()
        self._abort("guard '%s' tripped: %s" % (guard, detail))

    # ------------------------------------------------------------------- tick

    def tick(self):
        st = self.state
        now = time.time()
        last = st.get("last_tick_epoch") or now
        st["last_tick_epoch"] = now

        self._consume_command()
        if st["halted"]:
            self.save()
            return
        if st["phase_index"] >= len(self.phases):
            # Schedule complete: ensure baseline applied once, then idle.
            if st["applied"]:
                self._enter_baseline_idle()
            self.save()
            return

        phase = self.phase
        rec = self.telemetry_snapshot()

        # Pause accounting: only battery-discharge time counts toward a phase.
        pause = None
        if st["paused_reason"] == "manual":
            pause = "manual"
        elif rec is None or (time.time() - float(rec.get("ts", 0))) > 900:
            # No fresh telemetry: node state unknown, do not accrue battery time.
            pause = "no_telemetry"
        elif rec.get("batt_vbus") == 1 or rec.get("batt_chg") == 1:
            pause = "charging"
        elif rec.get("batt_pct") is not None and 0 <= rec.get("batt_pct", 0) < self.cfg["battery_floor_pct"]:
            pause = "low_batt"
        if pause != st["paused_reason"]:
            log.info("pause state: %s -> %s", st["paused_reason"], pause)
            st["paused_reason"] = pause
        if pause is None:
            st["phase_run_s"] += max(0.0, now - last)

        # Guards.
        if rec is not None and not st["halted"]:
            guards = phase.get("guards", [])
            if "crash" in guards:
                rc0 = st.get("phase_start_restart_count")
                rc = rec.get("restart_count")
                boot = str(rec.get("boot_reason", ""))
                prev = str(rec.get("prev_reboot", ""))
                if rc0 is not None and rc is not None and rc > rc0 and (
                        "interrupt_wdt" in boot or "interrupt_wdt" in prev):
                    self._trip_guard("crash", "restart_count %s->%s boot_reason=%s prev_reboot=%s"
                                     % (rc0, rc, boot, prev))
                    return
            if "drops" in guards:
                d0 = st.get("phase_start_dropped_frames")
                d = rec.get("dropped_frames")
                if d0 is not None and d is not None and d - d0 > self.cfg["drop_guard_frames"]:
                    self._trip_guard("drops", "dropped_frames +%d (limit %d)"
                                     % (d - d0, self.cfg["drop_guard_frames"]))
                    return

        # Phase expiry.
        duration_s = phase["duration_h"] * 3600.0
        if st["phase_run_s"] >= duration_s:
            log.info("phase %s complete (%.1f battery-hours)", phase["name"], st["phase_run_s"] / 3600.0)
            self._enter_phase(st["phase_index"] + 1)
            return

        self.save()

    def _enter_baseline_idle(self):
        log.info("schedule complete; reverting to baseline and idling")
        self.apply_config(self.state["baseline"], self.cfg["apply_timeout_s"])
        st = self.state
        st["applied"] = {}
        st["last_transition_epoch"] = time.time()
        self.save()
        self._notify("powerbench: schedule complete",
                     "All phases done; node reverted to baseline.")
        self._annotate("powerbench: schedule complete", ["powerbench", "done"])

    # --------------------------------------------------------------- control

    def _consume_command(self):
        cmd = _load_json(self.control_path, default=None)
        if not cmd:
            return
        try:
            os.remove(self.control_path)
        except OSError:
            pass
        name = cmd.get("command")
        log.info("control command: %s", name)
        if name == "pause":
            self.state["paused_reason"] = "manual"
        elif name == "resume":
            self.state["paused_reason"] = None
        elif name == "abort":
            self._abort("manual abort")
        elif name == "skip":
            if self.phase is not None:
                self._enter_phase(self.state["phase_index"] + 1)

    # --------------------------------------------------------------- metrics

    def render_metrics(self):
        with self.lock:
            st = dict(self.state)
            trips = dict(self.guard_trips)
            fails = self.apply_failures
        phase = self.phase
        name = phase["name"] if phase else "done"
        idx = st["phase_index"]
        import hashlib
        cfg_hash = hashlib.sha1(json.dumps(
            phase.get("set", {}) if phase else {}, sort_keys=True).encode()).hexdigest()[:8]
        out = []
        out.append("# HELP powerbench_phase_info Current experiment phase.")
        out.append("# TYPE powerbench_phase_info gauge")
        out.append('powerbench_phase_info{phase="%s",index="%d",config_hash="%s"} 1'
                   % (name, idx, cfg_hash))
        def g(n, h, v):
            if v is None:
                return
            out.append("# HELP %s %s" % (n, h))
            out.append("# TYPE %s gauge" % n)
            out.append("%s %s" % (n, v))
        g("powerbench_phase_start_timestamp_seconds", "Wall clock when the current phase began.",
          st.get("phase_start_epoch"))
        g("powerbench_phase_duration_seconds", "Battery-time budget of the current phase.",
          (phase["duration_h"] * 3600) if phase else 0)
        g("powerbench_phase_run_seconds", "Battery-discharge time accumulated in the current phase.",
          round(st.get("phase_run_s", 0), 1))
        paused = st.get("paused_reason")
        out.append("# HELP powerbench_paused Phase clock paused (1) with reason label.")
        out.append("# TYPE powerbench_paused gauge")
        for reason in ("charging", "low_batt", "manual", "no_telemetry"):
            out.append('powerbench_paused{reason="%s"} %d' % (reason, 1 if paused == reason else 0))
        g("powerbench_halted", "Schedule halted (abort or complete).",
          1 if st.get("halted") or phase is None else 0)
        g("powerbench_last_transition_timestamp_seconds", "Wall clock of the last phase transition.",
          st.get("last_transition_epoch"))
        out.append("# HELP powerbench_guard_tripped_total Guard trips by guard name.")
        out.append("# TYPE powerbench_guard_tripped_total counter")
        for guard, count in trips.items():
            out.append('powerbench_guard_tripped_total{guard="%s"} %d' % (guard, count))
        out.append("# HELP powerbench_apply_failures_total Config apply timeouts.")
        out.append("# TYPE powerbench_apply_failures_total counter")
        out.append("powerbench_apply_failures_total %d" % fails)
        # Per-phase analysis reports (computed at phase exit).
        report_metrics = [
            ("slope_mv_per_hour", "powerbench_phase_report_slope_mv_per_hour",
             "Battery discharge slope during the phase (mV/h, discharge-only samples, negative = draining)."),
            ("slope_pct_per_hour", "powerbench_phase_report_slope_pct_per_hour",
             "Battery discharge slope during the phase (SOC %/h)."),
            ("duty_cycle", "powerbench_phase_report_duty_cycle",
             "Radio duty cycle (dump window / interval) during the phase."),
            ("dropped_frames", "powerbench_phase_report_dropped_frames",
             "Dropped audio frames during the phase."),
            ("restarts", "powerbench_phase_report_restarts",
             "Node reboots during the phase."),
            ("rssi_dbm_avg", "powerbench_phase_report_rssi_dbm_avg",
             "Mean WiFi RSSI during the phase (confound check)."),
            ("battery_hours", "powerbench_phase_report_battery_hours",
             "Battery-discharge hours the phase accumulated."),
        ]
        reports = st.get("reports", {})
        for key, metric, help_ in report_metrics:
            out.append("# HELP %s %s" % (metric, help_))
            out.append("# TYPE %s gauge" % metric)
            for phase_name, rep in sorted(reports.items()):
                val = rep.get(key)
                if val is None:
                    continue
                out.append('%s{phase="%s"} %s' % (metric, phase_name, val))
        return "\n".join(out) + "\n"

    # ------------------------------------------------------------------- run

    def run(self):
        port = self.cfg["metrics_port"]
        ctrl = self

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                if self.path.rstrip("/") in ("", "/metrics"):
                    body = ctrl.render_metrics().encode()
                    self.send_response(200)
                    self.send_header("Content-Type", "text/plain; version=0.0.4")
                    self.send_header("Content-Length", str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)
                else:
                    self.send_response(404)
                    self.end_headers()

            def log_message(self, *args):
                pass

        server = ThreadingHTTPServer(("0.0.0.0", port), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        log.info("metrics on :%d/metrics", port)

        # Boot: if never transitioned, enter phase 0.
        if self.state["phase_start_epoch"] is None and not self.state["halted"]:
            self._enter_phase(0)

        tick_s = self.cfg["tick_s"]
        while True:
            try:
                self.tick()
            except Exception:
                log.exception("tick failed")
            time.sleep(tick_s)


def _write_control(state_dir, command):
    path = os.path.join(state_dir, "control.json")
    if os.path.exists(path):
        pending = _load_json(path, default={})
        print("refusing: command '%s' is still pending (daemon consumes one command per tick)"
              % pending.get("command", "?"))
        return 1
    _save_json(path, {"command": command, "ts": time.time()})
    print("queued command: %s (daemon applies within one tick)" % command)
    return 0


def main(argv):
    if len(argv) < 2 or argv[1] not in ("run", "status", "pause", "resume", "abort", "skip"):
        print(__doc__)
        return 2
    mode = argv[1]
    cfg_dir = os.environ.get("POWERBENCH_CONF_DIR",
                             os.path.join(os.path.dirname(os.path.abspath(__file__))))
    cfg = _load_json(os.path.join(cfg_dir, "powerbench.json"))
    experiments = _load_json(os.path.join(cfg_dir, "experiments.json"))
    state_dir = cfg.get("state_dir", DEFAULT_STATE_DIR)
    os.makedirs(state_dir, exist_ok=True)

    if mode in ("pause", "resume", "abort", "skip"):
        return _write_control(state_dir, mode)
    if mode == "status":
        st = _load_json(os.path.join(state_dir, "state.json"), default={})
        phase_idx = st.get("phase_index", 0)
        name = (experiments["phases"][phase_idx]["name"]
                if phase_idx < len(experiments["phases"]) else "done")
        run_h = st.get("phase_run_s", 0) / 3600.0
        dur_h = experiments["phases"][phase_idx]["duration_h"] if phase_idx < len(experiments["phases"]) else 0
        print("phase:      %d (%s)" % (phase_idx, name))
        print("progress:   %.1f / %.1f battery-hours" % (run_h, dur_h))
        print("paused:     %s" % (st.get("paused_reason") or "no"))
        print("halted:     %s" % bool(st.get("halted")))
        print("applied:    %s" % (st.get("applied") or "{}"))
        print("baseline:   %s" % (st.get("baseline") or "{}"))
        print("guard trip: %s" % (st.get("guard_tripped") or "none"))
        return 0

    Controller(cfg, experiments, state_dir).run()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
