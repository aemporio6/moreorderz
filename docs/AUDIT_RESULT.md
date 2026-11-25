# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 135 | 43 | 5 | 27 | 0 | 42 | 64 % |
| Finance | 208 | 148 | 29 | 19 | 12 | 0 | 7 | 71 % |
| Sourcing | 185 | 137 | 22 | 12 | 14 | 0 | 3 | 74 % |
| Stock/Warehouse | 162 | 135 | 21 | 3 | 3 | 0 | 11 | 83 % |
| Sales | 155 | 121 | 19 | 9 | 6 | 0 | 8 | 78 % |
| Tasks | 122 | 95 | 22 | 1 | 4 | 0 | 4 | 78 % |
| Products | 101 | 68 | 8 | 16 | 9 | 0 | 5 | 67 % |
| Accounts/People | 97 | 47 | 14 | 27 | 9 | 0 | 3 | 48 % |
| Setup | 87 | 65 | 11 | 8 | 3 | 0 | 10 | 75 % |
| Labels/Printing | 44 | 28 | 9 | 3 | 4 | 0 | 2 | 64 % |
| Security | 28 | 18 | 8 | 0 | 2 | 0 | 8 | 64 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **1013** | **213** | **103** | **104** | **0** | **114** | **71 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
