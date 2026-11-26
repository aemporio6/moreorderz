# Register audit – result

Every line of MASTER_REGISTER_v1.7 (1,547 lines) checked against the code. BUILT / PARTIAL / FORM_ONLY need evidence that `audit/verify.py` finds in the code.

| Area | Lines that count | BUILT | PARTIAL | FORM ONLY | MISSING | Not judged | Not applicable | BUILT % |
|---|---|---|---|---|---|---|---|---|
| Core/Platform | 210 | 157 | 36 | 4 | 13 | 0 | 42 | 75 % |
| Finance | 208 | 165 | 22 | 11 | 10 | 0 | 7 | 79 % |
| Sourcing | 185 | 160 | 11 | 7 | 7 | 0 | 3 | 86 % |
| Stock/Warehouse | 162 | 149 | 10 | 3 | 0 | 0 | 11 | 92 % |
| Sales | 155 | 136 | 13 | 6 | 0 | 0 | 8 | 88 % |
| Tasks | 122 | 108 | 13 | 1 | 0 | 0 | 4 | 89 % |
| Products | 101 | 83 | 6 | 12 | 0 | 0 | 5 | 82 % |
| Accounts/People | 97 | 59 | 13 | 24 | 1 | 0 | 3 | 61 % |
| Setup | 87 | 75 | 6 | 6 | 0 | 0 | 10 | 86 % |
| Labels/Printing | 44 | 33 | 7 | 3 | 1 | 0 | 2 | 75 % |
| Security | 28 | 21 | 5 | 0 | 2 | 0 | 8 | 75 % |
| Reports | 25 | 15 | 1 | 0 | 9 | 0 | 3 | 60 % |
| Migration | 9 | 7 | 2 | 0 | 0 | 0 | 8 | 78 % |
| **All** | **1433** | **1168** | **145** | **77** | **43** | **0** | **114** | **82 %** |

FORM ONLY = the form and its list exist and save (with rights and history), but the rule's own logic is not built.
PARTIAL = part of the rule works; the note in `audit/REGISTER_AUDIT.csv` says what is missing.

Full list: `audit/REGISTER_AUDIT.csv` (one row per register line with status, rules, evidence and note).
