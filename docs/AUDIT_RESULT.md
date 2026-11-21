# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 82 | 78 | 9 | 41 | 0 | 42 | 39 % |
| Finance | 208 | 53 | 70 | 33 | 52 | 0 | 7 | 25 % |
| Sourcing | 185 | 52 | 75 | 20 | 38 | 0 | 3 | 28 % |
| Stock/Warehouse | 162 | 77 | 68 | 7 | 10 | 0 | 11 | 48 % |
| Sales | 155 | 103 | 30 | 11 | 11 | 0 | 8 | 66 % |
| Tasks | 122 | 29 | 53 | 15 | 25 | 0 | 4 | 24 % |
| Products | 101 | 45 | 15 | 21 | 20 | 0 | 5 | 45 % |
| Accounts/People | 97 | 25 | 17 | 41 | 14 | 0 | 3 | 26 % |
| Setup | 87 | 46 | 18 | 12 | 11 | 0 | 10 | 53 % |
| Labels/Printing | 44 | 20 | 13 | 6 | 5 | 0 | 2 | 45 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 6 | 7 | 0 | 12 | 0 | 3 | 24 % |
| Migration | 9 | 4 | 3 | 0 | 2 | 0 | 8 | 44 % |
| **All** | **1433** | **554** | **459** | **176** | **244** | **0** | **114** | **39 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
