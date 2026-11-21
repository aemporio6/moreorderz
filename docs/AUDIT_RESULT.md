# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 73 | 79 | 13 | 45 | 0 | 42 | 35 % |
| Finance | 208 | 42 | 79 | 35 | 52 | 0 | 7 | 20 % |
| Sourcing | 185 | 21 | 98 | 20 | 46 | 0 | 3 | 11 % |
| Stock/Warehouse | 162 | 48 | 86 | 11 | 17 | 0 | 11 | 30 % |
| Sales | 155 | 97 | 31 | 16 | 11 | 0 | 8 | 63 % |
| Tasks | 122 | 10 | 60 | 15 | 37 | 0 | 4 | 8 % |
| Products | 101 | 16 | 20 | 37 | 28 | 0 | 5 | 16 % |
| Accounts/People | 97 | 3 | 31 | 48 | 15 | 0 | 3 | 3 % |
| Setup | 87 | 32 | 18 | 26 | 11 | 0 | 10 | 37 % |
| Labels/Printing | 44 | 17 | 15 | 6 | 6 | 0 | 2 | 39 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 6 | 7 | 0 | 12 | 0 | 3 | 24 % |
| Migration | 9 | 0 | 7 | 0 | 2 | 0 | 8 | 0 % |
| **All** | **1433** | **377** | **543** | **228** | **285** | **0** | **114** | **26 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
