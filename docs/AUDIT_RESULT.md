# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 101 | 69 | 9 | 31 | 0 | 42 | 48 % |
| Finance | 208 | 82 | 51 | 33 | 42 | 0 | 7 | 39 % |
| Sourcing | 185 | 99 | 47 | 19 | 20 | 0 | 3 | 54 % |
| Stock/Warehouse | 162 | 87 | 62 | 4 | 9 | 0 | 11 | 54 % |
| Sales | 155 | 106 | 27 | 11 | 11 | 0 | 8 | 68 % |
| Tasks | 122 | 44 | 48 | 15 | 15 | 0 | 4 | 36 % |
| Products | 101 | 62 | 10 | 18 | 11 | 0 | 5 | 61 % |
| Accounts/People | 97 | 40 | 15 | 32 | 10 | 0 | 3 | 41 % |
| Setup | 87 | 59 | 13 | 12 | 3 | 0 | 10 | 68 % |
| Labels/Printing | 44 | 25 | 9 | 5 | 5 | 0 | 2 | 57 % |
| Security | 28 | 14 | 10 | 1 | 3 | 0 | 8 | 50 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 6 | 3 | 0 | 0 | 0 | 8 | 67 % |
| **All** | **1433** | **734** | **369** | **159** | **171** | **0** | **114** | **51 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
