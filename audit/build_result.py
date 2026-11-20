#!/usr/bin/env python3
"""Joins the register with the verdicts. Usage: build_result.py <register.csv>  ->  audit/REGISTER_AUDIT.csv + docs/AUDIT_RESULT.md"""
import csv, glob, os, re, sys
from collections import Counter, defaultdict
HERE = os.path.dirname(os.path.abspath(__file__)); ROOT = os.path.dirname(HERE)
NAME = {'B': 'BUILT', 'P': 'PARTIAL', 'F': 'FORM_ONLY', 'M': 'MISSING', 'N': 'NOT_APPLICABLE', '?': 'NOT_JUDGED'}
MAP = {'R-A_SALES': ('A_SALES', ''), 'R-B_SOURCING': ('B_SOURCING', ''), 'R-C': ('C_ACCOUNTS', ''), 'R-D': ('D_PRODUCTS', ''), 'R-FIN': ('E_FINANCE', 'FIN-'), 'R-INV': ('E_FINANCE', 'INV-'),
       'R-ACC': ('E_FINANCE', 'ACC-'), 'R-TAX': ('E_FINANCE', 'TAX-'), 'R-F_SETUP': ('F_SETUP', ''), 'R-G_CORE': ('G_CORE', ''), 'R-H': ('H_WAREHOUSE', '')}
V = defaultdict(dict)
for f in glob.glob(os.path.join(HERE, 'verdicts/*.txt')):
    k = os.path.basename(f)[:-4]
    for line in open(f, encoding='utf-8'):
        p = line.rstrip('\n').split('|', 3)
        if len(p) == 4: V[k][p[0]] = p
pat = re.compile(r"(R-[A-Z]+(?:_[A-Z]+)?)-(\d+)")
rows = list(csv.DictReader(open(sys.argv[1], encoding='utf-8')))
out = []; per = defaultdict(Counter)
for x in rows:
    rid = x['reg_id']; st = '?'; ev = ''; note = ''; via = ''
    if x['spec_status'] == 'RETIRED_WITH_REASON': st, note = 'N', 'retired in the spec with a reason'
    elif rid in V['NO_RULE']: _, st, ev, note = V['NO_RULE'][rid]
    else:
        found = []
        for pre, no in pat.findall(x['new_spec_where'] + ' ' + x['source_id'] + ' ' + x['short_text']):
            if pre in MAP and (MAP[pre][1] + no) in V[MAP[pre][0]]: found.append(V[MAP[pre][0]][MAP[pre][1] + no] + [f"{pre}-{no}"])
        if found:
            real = [f for f in found if f[1] != 'N'] or found
            s = set(f[1] for f in real)
            st = s.pop() if len(s) == 1 else ('P' if s & {'B', 'P'} else 'F')
            via = ', '.join(dict.fromkeys(f[4] for f in real)); ev = real[0][2]; note = '; '.join(dict.fromkeys(f"{f[4]}: {f[3]}" for f in real))[:600]
        elif x['spec_status'] == 'OWNER_DECISION_OPEN': st, note = 'N', 'open owner decision'
    out.append({'reg_id': rid, 'area': x['area'], 'source': x['source'], 'source_id': x['source_id'][:60], 'short_text': x['short_text'][:200], 'status': NAME[st], 'via_rules': via, 'evidence': ev, 'note': note})
    per[x['area']][st] += 1
with open(os.path.join(HERE, 'REGISTER_AUDIT.csv'), 'w', newline='', encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=list(out[0].keys())); w.writeheader(); w.writerows(out)
tot = Counter(); [tot.update(c) for c in per.values()]
def pct(c):
    n = sum(c[k] for k in 'BPFM?'); return (f"{100 * c['B'] / n:.0f} %" if n else '–', n)
L = ["# Register audit – result", "", "Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.", "",
     "| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |", "|---|---|---|---|---|---|---|---|---|"]
for a in sorted(per, key=lambda a: -sum(per[a].values())):
    c = per[a]; p, n = pct(c); L.append(f"| {a} | {n} | {c['B']} | {c['P']} | {c['F']} | {c['M']} | {c['?']} | {c['N']} | {p} |")
p, n = pct(tot); L.append(f"| **All** | **{n}** | **{tot['B']}** | **{tot['P']}** | **{tot['F']}** | **{tot['M']}** | **{tot['?']}** | **{tot['N']}** | **{p}** |")
L += ["", "FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.", "PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.", "", "Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note)."]
open(os.path.join(ROOT, 'docs/AUDIT_RESULT.md'), 'w', encoding='utf-8').write('\n'.join(L) + '\n')
print('\n'.join(L[4:-4])); print('not judged:', [o['reg_id'] for o in out if o['status'] == 'NOT_JUDGED'][:60])
