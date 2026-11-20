# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 40 | 95 | 18 | 57 | 0 | 42 | 19 % |
| Finance | 208 | 39 | 80 | 37 | 52 | 0 | 7 | 19 % |
| Sourcing | 185 | 21 | 98 | 20 | 46 | 0 | 3 | 11 % |
| Stock/Warehouse | 162 | 48 | 86 | 11 | 17 | 0 | 11 | 30 % |
| Sales | 155 | 5 | 66 | 33 | 51 | 0 | 8 | 3 % |
| Tasks | 122 | 5 | 60 | 19 | 38 | 0 | 4 | 4 % |
| Products | 101 | 15 | 20 | 37 | 29 | 0 | 5 | 15 % |
| Accounts/People | 97 | 3 | 31 | 48 | 15 | 0 | 3 | 3 % |
| Setup | 87 | 26 | 17 | 31 | 13 | 0 | 10 | 30 % |
| Labels/Printing | 44 | 16 | 16 | 6 | 6 | 0 | 2 | 36 % |
| Security | 28 | 10 | 14 | 1 | 3 | 0 | 8 | 36 % |
| Reports | 25 | 1 | 9 | 0 | 15 | 0 | 3 | 4 % |
| Migration | 9 | 0 | 7 | 0 | 2 | 0 | 8 | 0 % |
| **All** | **1433** | **229** | **599** | **261** | **344** | **0** | **114** | **16 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
