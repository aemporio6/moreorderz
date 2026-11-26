# Items that can only be finished on the live server or with the owner's accounts

These register lines stay PARTIAL / MISSING until the server exists. The code for them is written where it can be; what is
missing is something only the live server, a real account or a real file can give. Nothing here is counted as BUILT.

| Register line / rule | What it is | What is needed | Who |
|---|---|---|---|
| MR-0074, R-FIN-69 parts | Marketplace APIs (eBay, Amazon SP-API, TikTok, OnBuy, Temu; Etsy / Shopify / Wise not written) | API keys typed in GitHub / server settings by the owner, "Test", one pull compared with the marketplace screen | owner + developer |
| MR-0087, MR-0342 | WhatsApp in / out on a real number | Meta (or 360dialog) number, templates approved under the code's names, webhook address set | owner |
| MR-1405 (code built 7e-219) | Sign-in with a WhatsApp code for Owner / Finance / Partner | the WhatsApp provider above and the LOGIN_CODE template approved by Meta – until then sign-in uses the authenticator app or the password, nobody is locked out | owner |
| MR-0094, MR-0095, MR-1543 | Backup status on Health, daily + monthly backup, restore drill, offsite copy (RPO 24 h / RTO 4 h) | the server's backup storage and cron | developer |
| MR-1393, MR-1394, R-G_CORE-114 test | Thermal and A4 printers | print the test page on the real MUNBYN / KYOCERA printers once, then calibrate | owner (5 minutes) |
| R-H-31 | Carrier label PDFs read and linked to their orders | 2–3 real label PDFs of each carrier | owner |
| MR-1547 | Load and security test | the live server with real data volume | developer |
| Opening balances (R-G_CORE-87) | GRNs make lots only after the lock | after the old-data import the Owner locks Month close › Opening balances before the first GRN | owner |
| SUP_BANK (R-B_SOURCING-57) | Supplier bank rows that came with the old data | set them PENDING or phone-check them before the first payment | owner |
| R-C-6 (code built 7e-219) | Person tables move to utf8mb4 | the migration converts them on the live database – take the backup first, it rewrites those tables | developer |
| R-C-50 (code built 7e-219) | Old notification e-mail passwords | copy any still needed into the password manager before the migration empties the column | owner |
