# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 143 | 47 | 5 | 15 | 0 | 42 | 68 % |
| Finance | 208 | 151 | 27 | 19 | 11 | 0 | 7 | 73 % |
| Sourcing | 185 | 150 | 16 | 12 | 7 | 0 | 3 | 81 % |
| Stock/Warehouse | 162 | 138 | 20 | 3 | 1 | 0 | 11 | 85 % |
| Sales | 155 | 125 | 21 | 9 | 0 | 0 | 8 | 81 % |
| Tasks | 122 | 96 | 22 | 1 | 3 | 0 | 4 | 79 % |
| Products | 101 | 76 | 9 | 16 | 0 | 0 | 5 | 75 % |
| Accounts/People | 97 | 55 | 14 | 27 | 1 | 0 | 3 | 57 % |
| Setup | 87 | 68 | 11 | 8 | 0 | 0 | 10 | 78 % |
| Labels/Printing | 44 | 31 | 9 | 3 | 1 | 0 | 2 | 70 % |
| Security | 28 | 19 | 7 | 0 | 2 | 0 | 8 | 68 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **1068** | **210** | **103** | **52** | **0** | **114** | **75 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
