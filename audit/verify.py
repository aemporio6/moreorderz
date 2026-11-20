#!/usr/bin/env python3
"""Checks every verdict line: B / P / F need evidence that exists. Prints failures; exit 1 if any."""
import csv, glob, os, re, subprocess, sys
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PATHS = ['app', 'routes', 'public/app/app.js', 'tests', 'database/migrations', 'config', 'tools']
forms = set(r['form_key'] for r in csv.DictReader(open(os.path.join(ROOT, 'database/meta/field_registry.csv'))))
cache = {}
def found(rx):
    if rx not in cache:
        r = subprocess.run(['grep', '-rlE', '--', rx] + PATHS, cwd=ROOT, capture_output=True, text=True)
        cache[rx] = bool(r.stdout.strip())
    return cache[rx]
bad = 0; n = 0
for f in sorted(glob.glob(os.path.join(ROOT, 'audit/verdicts/*.txt'))):
    for i, line in enumerate(open(f, encoding='utf-8'), 1):
        line = line.rstrip('\n')
        if not line: continue
        p = line.split('|', 3)
        if len(p) != 4: print(f"{os.path.basename(f)}:{i} bad line"); bad += 1; continue
        rid, st, ev, note = p; n += 1
        if st not in 'BPFMN': print(f"{os.path.basename(f)}:{i} bad status {st}"); bad += 1
        if st in 'BPF':
            ok = (ev[5:] in forms) if ev.startswith('form:') else (bool(ev) and found(ev))
            if not ok: print(f"{os.path.basename(f)}:{rid} evidence not found: {ev}"); bad += 1
        if st == 'F' and not ev.startswith('form:'): print(f"{os.path.basename(f)}:{rid} F needs form: evidence"); bad += 1
print(f"{n} verdict lines checked, {bad} problems")
sys.exit(1 if bad else 0)
