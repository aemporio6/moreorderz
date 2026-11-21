# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 77 | 81 | 10 | 42 | 0 | 42 | 37 % |
| Finance | 208 | 42 | 80 | 34 | 52 | 0 | 7 | 20 % |
| Sourcing | 185 | 52 | 75 | 20 | 38 | 0 | 3 | 28 % |
| Stock/Warehouse | 162 | 61 | 74 | 10 | 17 | 0 | 11 | 38 % |
| Sales | 155 | 98 | 31 | 15 | 11 | 0 | 8 | 63 % |
| Tasks | 122 | 15 | 61 | 15 | 31 | 0 | 4 | 12 % |
| Products | 101 | 26 | 20 | 28 | 27 | 0 | 5 | 26 % |
| Accounts/People | 97 | 15 | 23 | 44 | 15 | 0 | 3 | 15 % |
| Setup | 87 | 45 | 18 | 13 | 11 | 0 | 10 | 52 % |
| Labels/Printing | 44 | 19 | 14 | 6 | 5 | 0 | 2 | 43 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 6 | 7 | 0 | 12 | 0 | 3 | 24 % |
| Migration | 9 | 0 | 7 | 0 | 2 | 0 | 8 | 0 % |
| **All** | **1433** | **468** | **503** | **196** | **266** | **0** | **114** | **33 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
