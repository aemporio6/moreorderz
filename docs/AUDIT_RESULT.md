# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 90 | 75 | 9 | 36 | 0 | 42 | 43 % |
| Finance | 208 | 57 | 69 | 33 | 49 | 0 | 7 | 27 % |
| Sourcing | 185 | 85 | 50 | 20 | 30 | 0 | 3 | 46 % |
| Stock/Warehouse | 162 | 86 | 63 | 4 | 9 | 0 | 11 | 53 % |
| Sales | 155 | 104 | 29 | 11 | 11 | 0 | 8 | 67 % |
| Tasks | 122 | 33 | 50 | 15 | 24 | 0 | 4 | 27 % |
| Products | 101 | 55 | 11 | 18 | 17 | 0 | 5 | 54 % |
| Accounts/People | 97 | 39 | 15 | 32 | 11 | 0 | 3 | 40 % |
| Setup | 87 | 53 | 14 | 12 | 8 | 0 | 10 | 61 % |
| Labels/Printing | 44 | 25 | 9 | 5 | 5 | 0 | 2 | 57 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 6 | 3 | 0 | 0 | 0 | 8 | 67 % |
| **All** | **1433** | **654** | **405** | **160** | **214** | **0** | **114** | **46 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
