# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 139 | 45 | 5 | 21 | 0 | 42 | 66 % |
| Finance | 208 | 150 | 27 | 19 | 12 | 0 | 7 | 72 % |
| Sourcing | 185 | 147 | 15 | 12 | 11 | 0 | 3 | 79 % |
| Stock/Warehouse | 162 | 137 | 20 | 3 | 2 | 0 | 11 | 85 % |
| Sales | 155 | 123 | 20 | 9 | 3 | 0 | 8 | 79 % |
| Tasks | 122 | 95 | 22 | 1 | 4 | 0 | 4 | 78 % |
| Products | 101 | 72 | 9 | 16 | 4 | 0 | 5 | 71 % |
| Accounts/People | 97 | 55 | 14 | 27 | 1 | 0 | 3 | 57 % |
| Setup | 87 | 66 | 11 | 8 | 2 | 0 | 10 | 76 % |
| Labels/Printing | 44 | 29 | 11 | 3 | 1 | 0 | 2 | 66 % |
| Security | 28 | 19 | 7 | 0 | 2 | 0 | 8 | 68 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **1048** | **208** | **103** | **74** | **0** | **114** | **73 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
