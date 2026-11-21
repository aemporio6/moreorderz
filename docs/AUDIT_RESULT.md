# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 91 | 74 | 9 | 36 | 0 | 42 | 43 % |
| Finance | 208 | 57 | 69 | 33 | 49 | 0 | 7 | 27 % |
| Sourcing | 185 | 88 | 50 | 20 | 27 | 0 | 3 | 48 % |
| Stock/Warehouse | 162 | 87 | 62 | 4 | 9 | 0 | 11 | 54 % |
| Sales | 155 | 104 | 29 | 11 | 11 | 0 | 8 | 67 % |
| Tasks | 122 | 33 | 50 | 15 | 24 | 0 | 4 | 27 % |
| Products | 101 | 55 | 11 | 18 | 17 | 0 | 5 | 54 % |
| Accounts/People | 97 | 39 | 15 | 32 | 11 | 0 | 3 | 40 % |
| Setup | 87 | 57 | 13 | 12 | 5 | 0 | 10 | 66 % |
| Labels/Printing | 44 | 25 | 9 | 5 | 5 | 0 | 2 | 57 % |
| Security | 28 | 12 | 12 | 1 | 3 | 0 | 8 | 43 % |
| Reports | 25 | 9 | 5 | 0 | 11 | 0 | 3 | 36 % |
| Migration | 9 | 6 | 3 | 0 | 0 | 0 | 8 | 67 % |
| **All** | **1433** | **663** | **402** | **160** | **208** | **0** | **114** | **46 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
