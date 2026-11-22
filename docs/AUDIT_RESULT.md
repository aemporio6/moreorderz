# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 116 | 55 | 9 | 30 | 0 | 42 | 55 % |
| Finance | 208 | 82 | 51 | 33 | 42 | 0 | 7 | 39 % |
| Sourcing | 185 | 99 | 47 | 19 | 20 | 0 | 3 | 54 % |
| Stock/Warehouse | 162 | 87 | 62 | 4 | 9 | 0 | 11 | 54 % |
| Sales | 155 | 107 | 26 | 11 | 11 | 0 | 8 | 69 % |
| Tasks | 122 | 89 | 27 | 1 | 5 | 0 | 4 | 73 % |
| Products | 101 | 63 | 9 | 18 | 11 | 0 | 5 | 62 % |
| Accounts/People | 97 | 41 | 15 | 32 | 9 | 0 | 3 | 42 % |
| Setup | 87 | 59 | 13 | 12 | 3 | 0 | 10 | 68 % |
| Labels/Printing | 44 | 25 | 9 | 5 | 5 | 0 | 2 | 57 % |
| Security | 28 | 16 | 9 | 1 | 2 | 0 | 8 | 57 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 6 | 3 | 0 | 0 | 0 | 8 | 67 % |
| **All** | **1433** | **799** | **331** | **145** | **158** | **0** | **114** | **56 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
