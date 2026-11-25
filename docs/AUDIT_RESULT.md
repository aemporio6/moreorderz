# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 124 | 52 | 6 | 28 | 0 | 42 | 59 % |
| Finance | 208 | 119 | 46 | 24 | 19 | 0 | 7 | 57 % |
| Sourcing | 185 | 114 | 42 | 13 | 16 | 0 | 3 | 62 % |
| Stock/Warehouse | 162 | 111 | 43 | 3 | 5 | 0 | 11 | 69 % |
| Sales | 155 | 114 | 25 | 10 | 6 | 0 | 8 | 74 % |
| Tasks | 122 | 93 | 24 | 1 | 4 | 0 | 4 | 76 % |
| Products | 101 | 64 | 10 | 18 | 9 | 0 | 5 | 63 % |
| Accounts/People | 97 | 44 | 14 | 30 | 9 | 0 | 3 | 45 % |
| Setup | 87 | 61 | 13 | 10 | 3 | 0 | 10 | 70 % |
| Labels/Printing | 44 | 26 | 11 | 3 | 4 | 0 | 2 | 59 % |
| Security | 28 | 17 | 9 | 0 | 2 | 0 | 8 | 61 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **903** | **296** | **118** | **116** | **0** | **114** | **63 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
