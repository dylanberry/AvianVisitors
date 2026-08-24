#!/usr/bin/env python3
"""powerbench digest — scheduled experiment summary to ntfy.

Runs as a CronJob (in-cluster). Reads the controller's phase report metrics
(computed at each phase exit) plus current-phase progress from Prometheus,
formats a table, posts it to the ntfy topic. Stdlib only.

Env:
  PROMETHEUS_URL  (default http://kube-prometheus-stack-prometheus.monitoring.svc.cluster.local:9090)
  NTFY_URL        (default http://ntfy.ntfy.svc.cluster.local)
  NTFY_TOPIC      (default bird-up)
  NTFY_TOKEN      (required; from the powerbench-auth secret)
"""

import json
import os
import urllib.parse
import urllib.request

PROM = os.environ.get(
    "PROMETHEUS_URL",
    "http://kube-prometheus-stack-prometheus.monitoring.svc.cluster.local:9090")
NTFY_URL = os.environ.get("NTFY_URL", "http://ntfy.ntfy.svc.cluster.local")
NTFY_TOPIC = os.environ.get("NTFY_TOPIC", "bird-up")
TOKEN = os.environ.get("NTFY_TOKEN", "")

REPORTS = [
    ("battery_hours", "powerbench_phase_report_battery_hours", "%.1f"),
    ("slope_mv", "powerbench_phase_report_slope_mv_per_hour", "%.1f"),
    ("slope_pct", "powerbench_phase_report_slope_pct_per_hour", "%.2f"),
    ("duty", "powerbench_phase_report_duty_cycle", None),  # -> %
    ("drops", "powerbench_phase_report_dropped_frames", "%.0f"),
    ("restarts", "powerbench_phase_report_restarts", "%.0f"),
    ("rssi", "powerbench_phase_report_rssi_dbm_avg", "%.0f"),
]


def query(q):
    url = PROM.rstrip("/") + "/api/v1/query?query=" + urllib.parse.quote(q)
    with urllib.request.urlopen(url, timeout=15) as resp:
        data = json.loads(resp.read().decode("utf-8", "replace"))
    return data.get("data", {}).get("result", [])


def value(q):
    res = query(q)
    try:
        return float(res[0]["value"][1])
    except (IndexError, KeyError, TypeError, ValueError):
        return None


def main():
    # Current phase / progress
    phase = "?"
    info = query("powerbench_phase_info")
    if info:
        phase = info[0]["metric"].get("phase", "?")
    run = value("powerbench_phase_run_seconds") or 0.0
    dur = value("powerbench_phase_duration_seconds") or 0.0
    paused = query("powerbench_paused > 0")
    paused_s = paused[0]["metric"].get("reason", "?") if paused else "no"
    halted = value("powerbench_halted")

    lines = ["current phase: %s (%.1f / %.1f battery-h, paused: %s%s)" % (
        phase, run / 3600.0, dur / 3600.0, paused_s,
        ", HALTED" if halted else "")]

    # Completed-phase report table
    rows = {}
    for key, metric, _fmt in REPORTS:
        for series in query(metric):
            ph = series["metric"].get("phase", "?")
            try:
                rows.setdefault(ph, {})[key] = float(series["value"][1])
            except (KeyError, TypeError, ValueError):
                pass
    if rows:
        lines.append("")
        lines.append("%-10s %6s %9s %9s %5s %6s %8s %5s" % (
            "phase", "batt-h", "mV/h", "%/h", "duty", "drops", "restarts", "rssi"))
        base_mv = rows.get("baseline", {}).get("slope_mv")
        for ph in sorted(rows):
            r = rows[ph]
            duty = r.get("duty")
            lines.append("%-10s %6s %9s %9s %5s %6s %8s %5s" % (
                ph,
                "%.1f" % r["battery_hours"] if "battery_hours" in r else "-",
                "%.1f" % r["slope_mv"] if r.get("slope_mv") is not None else "-",
                "%.2f" % r["slope_pct"] if r.get("slope_pct") is not None else "-",
                ("%.1f%%" % (duty * 100)) if duty is not None else "-",
                "%.0f" % r["drops"] if r.get("drops") is not None else "-",
                "%.0f" % r["restarts"] if r.get("restarts") is not None else "-",
                "%.0f" % r["rssi"] if r.get("rssi") is not None else "-"))
            if ph != "baseline" and base_mv and base_mv < 0 and r.get("slope_mv") and r["slope_mv"] < 0:
                delta = (abs(r["slope_mv"]) / abs(base_mv) - 1.0) * 100.0
                lines.append("  -> vs baseline: %+.0f%% discharge rate" % delta)
    else:
        lines.append("")
        lines.append("no completed-phase reports yet (baseline still running)")

    body = "\n".join(lines)
    print(body)
    if not TOKEN:
        print("NTFY_TOKEN not set; not posting")
        return 0
    req = urllib.request.Request(
        "%s/%s" % (NTFY_URL.rstrip("/"), NTFY_TOPIC),
        data=body.encode(),
        headers={"Authorization": "Bearer " + TOKEN,
                 "Title": "powerbench daily digest",
                 "Tags": "bird,chart_with_downwards_trend"})
    with urllib.request.urlopen(req, timeout=15) as resp:
        print("ntfy post: HTTP %d" % resp.status)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
