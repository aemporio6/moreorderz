# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 125 | 52 | 6 | 27 | 0 | 42 | 60 % |
| Finance | 208 | 145 | 30 | 21 | 12 | 0 | 7 | 70 % |
| Sourcing | 185 | 136 | 22 | 13 | 14 | 0 | 3 | 74 % |
| Stock/Warehouse | 162 | 130 | 26 | 3 | 3 | 0 | 11 | 80 % |
| Sales | 155 | 115 | 25 | 9 | 6 | 0 | 8 | 74 % |
| Tasks | 122 | 95 | 22 | 1 | 4 | 0 | 4 | 78 % |
| Products | 101 | 68 | 8 | 16 | 9 | 0 | 5 | 67 % |
| Accounts/People | 97 | 47 | 14 | 27 | 9 | 0 | 3 | 48 % |
| Setup | 87 | 64 | 12 | 8 | 3 | 0 | 10 | 74 % |
| Labels/Printing | 44 | 28 | 9 | 3 | 4 | 0 | 2 | 64 % |
| Security | 28 | 17 | 9 | 0 | 2 | 0 | 8 | 61 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **986** | **236** | **107** | **104** | **0** | **114** | **69 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
