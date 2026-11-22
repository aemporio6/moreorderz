# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 123 | 52 | 6 | 29 | 0 | 42 | 59 % |
| Finance | 208 | 113 | 52 | 24 | 19 | 0 | 7 | 54 % |
| Sourcing | 185 | 99 | 47 | 19 | 20 | 0 | 3 | 54 % |
| Stock/Warehouse | 162 | 104 | 45 | 4 | 9 | 0 | 11 | 64 % |
| Sales | 155 | 110 | 26 | 11 | 8 | 0 | 8 | 71 % |
| Tasks | 122 | 93 | 24 | 1 | 4 | 0 | 4 | 76 % |
| Products | 101 | 63 | 10 | 18 | 10 | 0 | 5 | 62 % |
| Accounts/People | 97 | 42 | 14 | 32 | 9 | 0 | 3 | 43 % |
| Setup | 87 | 61 | 13 | 10 | 3 | 0 | 10 | 70 % |
| Labels/Printing | 44 | 25 | 10 | 5 | 4 | 0 | 2 | 57 % |
| Security | 28 | 17 | 9 | 0 | 2 | 0 | 8 | 61 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **866** | **309** | **130** | **128** | **0** | **114** | **60 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
