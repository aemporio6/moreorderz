# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 89 | 75 | 9 | 37 | 0 | 42 | 42 % |
| Finance | 208 | 54 | 70 | 33 | 51 | 0 | 7 | 26 % |
| Sourcing | 185 | 76 | 57 | 20 | 32 | 0 | 3 | 41 % |
| Stock/Warehouse | 162 | 84 | 65 | 4 | 9 | 0 | 11 | 52 % |
| Sales | 155 | 104 | 29 | 11 | 11 | 0 | 8 | 67 % |
| Tasks | 122 | 32 | 50 | 15 | 25 | 0 | 4 | 26 % |
| Products | 101 | 55 | 11 | 18 | 17 | 0 | 5 | 54 % |
| Accounts/People | 97 | 39 | 15 | 32 | 11 | 0 | 3 | 40 % |
| Setup | 87 | 49 | 15 | 12 | 11 | 0 | 10 | 56 % |
| Labels/Printing | 44 | 25 | 9 | 5 | 5 | 0 | 2 | 57 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 7 | 6 | 0 | 12 | 0 | 3 | 28 % |
| Migration | 9 | 6 | 3 | 0 | 0 | 0 | 8 | 67 % |
| **All** | **1433** | **632** | **417** | **160** | **224** | **0** | **114** | **44 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
