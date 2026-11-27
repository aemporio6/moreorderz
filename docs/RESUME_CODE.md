# How to resume MoreOrderz – exactly as it runs now (updated 27-11 09:30)

Nothing important lives only in the work container. Code, tests, migrations, guide pictures and docs are on GitHub **aemporio6/moreorderz** (main), pushed after every batch. The plan is **claude/ROADMAP.md** (= docs/ROADMAP.md).

## Where to start next session
1. Read **claude/ROADMAP.md** – section **"NEXT – owner 27-11 09:26"** at the top of the open work:
   - A. Connectors hub (Google Drive / Sheets / Gmail / Calendar, Microsoft, marketplaces, money, messaging, printers, carriers – one catalogue table, connect with permission / OAuth, owner types keys himself).
   - B. Logos everywhere (each marketplace, bank, carrier, connector shows its own current small logo; keyed by the service's web domain, fetched and cached, initial badge fallback, owner can upload his own).
   - C. Finish the open ROADMAP lines that do not wait for the owner.
   - D. My brands page (BYMO, LAMNOON, WE ART LEAVES; logos in owner Drive folder "MoreOrderz – My brand logos").
   - E. Deep audit of the whole system after A–D, fixing on the spot.
2. Also in ROADMAP: "Remember – WhatsApp way 2" (do together with the owner) and "Waiting for the owner" (CNY/USD rates, 31 FBA ASINs, listings reports, MY TOOL key, eBay shop for 25 Nov labels, 106 bank accounts).

## Last state (27-11 09:30)
- Live = commit 7ddeaef+ (user guide part 1+2, Show me tours, ? on figures and every form box, Arrange drag + tile sizes, contacts & chat, tracking links, owner options). 989 tests green.
- Live = 6230e1c: live exchange rates (ECB, mo:fx-live, top-bar strip), guide pictures re-made 168/168 (`node tools/dev/guide-pictures.cjs <dir-with-pw> public/guide`, demo data only). 992 tests green.
- Live = f8694a4+: logos everywhere (Setup › Logos), My brands, + Add on every screen. 994 tests green.
- Next: ROADMAP order (D, B, G done) → A connectors hub → F rest (Wise/WorldFirst deal rates, gain/loss line) → C → E deep audit.

## Resume in a new session – paste this to Claude
> Resume the MoreOrderz build. Clone GitHub aemporio6/moreorderz to /home/claude/moreorderz, composer install, MariaDB databases moreorderz + moreorderz_test, php artisan migrate --seed. Read project docs claude/ROADMAP.md (start at "NEXT – owner 27-11 09:26") and claude/RESUME_CODE.md. Each batch: build with tests, one full suite, commit (git add -A) + push, deploy to app.moreorderz.com through the cPanel terminal (git pull, migrate --force, config/route cache, view:clear), verify live, update ROADMAP + project copy, short Roman Urdu report (table + "Abhi baqi hai"), send_later 1 minute.

## Owner's standing rules
- Roman Urdu reports, say "developer"; no questions – give options in the tool; everything connected, from the centre, full dropdowns, automatic first, never stuck; old-tool things must come over better.
- Security: never open/quote config/database.php or any password, key, token, secret; public repo – no real data, no dumps, no .env; owner types every password/key himself; touch nothing else on the hosting (crontab has other sites' lines).
- Only these words change: Vendor→Supplier, Enterprise/Entity→Company, Branch→Shop.
