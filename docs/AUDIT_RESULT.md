# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 66 | 83 | 15 | 46 | 0 | 42 | 31 % |
| Finance | 208 | 41 | 80 | 35 | 52 | 0 | 7 | 20 % |
| Sourcing | 185 | 21 | 98 | 20 | 46 | 0 | 3 | 11 % |
| Stock/Warehouse | 162 | 48 | 86 | 11 | 17 | 0 | 11 | 30 % |
| Sales | 155 | 84 | 44 | 16 | 11 | 0 | 8 | 54 % |
| Tasks | 122 | 10 | 60 | 15 | 37 | 0 | 4 | 8 % |
| Products | 101 | 16 | 20 | 37 | 28 | 0 | 5 | 16 % |
| Accounts/People | 97 | 3 | 31 | 48 | 15 | 0 | 3 | 3 % |
| Setup | 87 | 31 | 19 | 26 | 11 | 0 | 10 | 36 % |
| Labels/Printing | 44 | 16 | 16 | 6 | 6 | 0 | 2 | 36 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 3 | 10 | 0 | 12 | 0 | 3 | 12 % |
| Migration | 9 | 0 | 7 | 0 | 2 | 0 | 8 | 0 % |
| **All** | **1433** | **351** | **566** | **230** | **286** | **0** | **114** | **24 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
