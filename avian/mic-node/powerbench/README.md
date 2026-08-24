# powerbench — automated mic-node power experiments

Walks an experiment schedule (`experiments.json`), pushing each phase's node
config via the node's `/api/set`, pausing the phase clock while the node is
charging / below the battery floor / unreachable, and auto-reverting to
baseline + halting when a guard trips.

**Primary deployment: in-cluster** (k3s, namespace `birdup-go`, manifests in
the k8s repo `apps/base/powerbench/`). Telemetry source = Prometheus instant
queries against the birdup exporter metrics; metrics served on :9559 and
scraped via ServiceMonitor; tokens from the SOPS `powerbench-auth` secret;
state on a pinned local PV. `powerbench.py` is mounted from a ConfigMap
**generated** by `apps/base/powerbench/gen-configmap.sh` — the file in THIS
repo is the single source of truth; after editing it, run the generator and
commit both repos.

**Fallback deployment: systemd on sb-birdnet-pi4** (`powerbench.service`,
`deploy-powerbench-to-pi.sh`, telemetry source = dumpd JSONL). Kept for
cluster-outage scenarios; do not run both at once (both would push config).

Stdlib-only Python 3, like `node-telemetry-exporter.py`.

## Why push-with-retry

The node is unreachable except during ECO dump windows (~15 s every 5 min).
Config applies therefore poll `/api/set` until every key lands or
`apply_timeout_s` elapses. Node *state* is read-only observation
(Prometheus in-cluster, JSONL on the Pi) — nothing ever polls the node for state.

## Files (this repo)

| File | Purpose |
|---|---|
| `powerbench.py` | Controller; source for the cluster ConfigMap |
| `experiments.json` | Experiment schedule; source for the cluster ConfigMap |
| `powerbench.json` | Pi-fallback config (jsonl mode, LAN URLs) |
| `powerbench.service` + `deploy-powerbench-to-pi.sh` | Pi fallback only |

Cluster config is `apps/base/powerbench/powerbench.cluster.json` in the k8s repo.
State lives in `state_dir` (`/data` PVC in-cluster, `~/.powerbench` on the Pi).

## Operate

In-cluster:

```sh
kubectl -n birdup-go exec deploy/powerbench -- python3 /app/powerbench.py status
kubectl -n birdup-go exec deploy/powerbench -- python3 /app/powerbench.py pause   # resume|skip|abort
kubectl -n birdup-go logs -f deploy/powerbench
```

On the Pi (fallback mode): `powerbench.py status|pause|resume|skip|abort`.

One control command per tick (~60 s); a second command while one is pending
is refused.

## Secrets

In-cluster: SOPS `powerbench-auth` (namespace birdup-go) — `NTFY_TOKEN`
(dedicated `powerbench` ntfy user, ACL `bird-up:rw`, provisioned in
`apps/base/ntfy/secret.sops.yaml`), `GRAFANA_USER`/`GRAFANA_PASSWORD`
(copied from `grafana-admin-credentials`; swap for a service-account token
later via `GRAFANA_TOKEN`). Env vars override the token files, so the
`GRAFANA_TOKEN`/`NTFY_TOKEN` file paths are only used in Pi-fallback mode.

## Metrics

`powerbench_phase_info{phase,index,config_hash}`, `..._phase_start_timestamp_seconds`,
`..._phase_duration_seconds`, `..._phase_run_seconds` (battery-time accrued),
`powerbench_paused{reason}`, `powerbench_halted`,
`powerbench_guard_tripped_total{guard}`, `powerbench_apply_failures_total`,
`powerbench_last_transition_timestamp_seconds`.

Scraped via ServiceMonitor (`apps/base/powerbench/deployment.yaml`); guard
alerts in `infrastructure/kube-prometheus-stack/prometheusrule-birdup-powerbench.yaml`.

## Deploy / change flow

```sh
# 1. edit powerbench.py / experiments.json HERE, commit
# 2. regenerate + commit the cluster manifests:
~/code/k8s/apps/base/powerbench/gen-configmap.sh
# 3. push k8s repo -> Flux applies (ConfigMap change rolls the pod via restart:
kubectl -n birdup-go rollout restart deploy/powerbench   # if not automatic
```

First `run` enters phase 0 (baseline, no config changes). The schedule only
advances on battery-discharge time; expect the full 4-phase schedule to take
~5–8 days of wall time depending on charge cycles.
