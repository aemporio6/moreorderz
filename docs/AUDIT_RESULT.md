# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 88 | 75 | 9 | 38 | 0 | 42 | 42 % |
| Finance | 208 | 54 | 70 | 33 | 51 | 0 | 7 | 26 % |
| Sourcing | 185 | 69 | 63 | 20 | 33 | 0 | 3 | 37 % |
| Stock/Warehouse | 162 | 83 | 65 | 4 | 10 | 0 | 11 | 51 % |
| Sales | 155 | 104 | 29 | 11 | 11 | 0 | 8 | 67 % |
| Tasks | 122 | 32 | 50 | 15 | 25 | 0 | 4 | 26 % |
| Products | 101 | 55 | 11 | 18 | 17 | 0 | 5 | 54 % |
| Accounts/People | 97 | 39 | 15 | 32 | 11 | 0 | 3 | 40 % |
| Setup | 87 | 48 | 16 | 12 | 11 | 0 | 10 | 55 % |
| Labels/Printing | 44 | 25 | 9 | 5 | 5 | 0 | 2 | 57 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 6 | 7 | 0 | 12 | 0 | 3 | 24 % |
| Migration | 9 | 6 | 3 | 0 | 0 | 0 | 8 | 67 % |
| **All** | **1433** | **621** | **425** | **160** | **227** | **0** | **114** | **43 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
