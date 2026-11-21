# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 83 | 77 | 9 | 41 | 0 | 42 | 40 % |
| Finance | 208 | 53 | 70 | 33 | 52 | 0 | 7 | 25 % |
| Sourcing | 185 | 59 | 68 | 20 | 38 | 0 | 3 | 32 % |
| Stock/Warehouse | 162 | 83 | 65 | 4 | 10 | 0 | 11 | 51 % |
| Sales | 155 | 103 | 30 | 11 | 11 | 0 | 8 | 66 % |
| Tasks | 122 | 32 | 50 | 15 | 25 | 0 | 4 | 26 % |
| Products | 101 | 52 | 11 | 21 | 17 | 0 | 5 | 51 % |
| Accounts/People | 97 | 34 | 17 | 35 | 11 | 0 | 3 | 35 % |
| Setup | 87 | 48 | 16 | 12 | 11 | 0 | 10 | 55 % |
| Labels/Printing | 44 | 21 | 13 | 5 | 5 | 0 | 2 | 48 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 6 | 7 | 0 | 12 | 0 | 3 | 24 % |
| Migration | 9 | 4 | 3 | 0 | 2 | 0 | 8 | 44 % |
| **All** | **1433** | **590** | **439** | **166** | **238** | **0** | **114** | **41 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
