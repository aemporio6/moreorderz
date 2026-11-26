# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 148 | 43 | 5 | 14 | 0 | 42 | 70 % |
| Finance | 208 | 153 | 25 | 19 | 11 | 0 | 7 | 74 % |
| Sourcing | 185 | 151 | 15 | 12 | 7 | 0 | 3 | 82 % |
| Stock/Warehouse | 162 | 145 | 13 | 3 | 1 | 0 | 11 | 90 % |
| Sales | 155 | 129 | 17 | 9 | 0 | 0 | 8 | 83 % |
| Tasks | 122 | 99 | 19 | 1 | 3 | 0 | 4 | 81 % |
| Products | 101 | 80 | 6 | 15 | 0 | 0 | 5 | 79 % |
| Accounts/People | 97 | 55 | 14 | 27 | 1 | 0 | 3 | 57 % |
| Setup | 87 | 70 | 11 | 6 | 0 | 0 | 10 | 80 % |
| Labels/Printing | 44 | 32 | 8 | 3 | 1 | 0 | 2 | 73 % |
| Security | 28 | 21 | 5 | 0 | 2 | 0 | 8 | 75 % |
| Reports | 25 | 10 | 5 | 0 | 10 | 0 | 3 | 40 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **1100** | **183** | **100** | **50** | **0** | **114** | **77 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
