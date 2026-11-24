# AriunetClub QA Report

Generated: 2026-11-24T13:56:38+00:00

## Commands

### "/home/z/my-project/tools/php" vendor/bin/phpcs
```text

```

### npm run --silent lint:css
```text

```

### "/home/z/my-project/tools/php" -d memory_limit=512M vendor/bin/phpstan analyse
```text
Warning: PHP Startup: Unable to load dynamic library '/home/z/my-project/repo/AriunetClub/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.2.so' (tried: /home/z/my-project/repo/AriunetClub/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.2.so (Dynamic loading not supported), /lib/php/extensions/no-debug-non-zts-20220829//home/z/my-project/repo/AriunetClub/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.2.so.so (Dynamic loading not supported)) in Unknown on line 0
Note: Using configuration file /home/z/my-project/repo/AriunetClub/phpstan.neon.
  0/87 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%[1G[2K 87/87 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%


 [OK] No errors

```

### "/home/z/my-project/tools/php" vendor/bin/phpunit
```text
PHPUnit 9.6.39 by Sebastian Bergmann and contributors.

...............................................................  63 / 149 ( 42%)
............................................................... 126 / 149 ( 84%)
.......................                                         149 / 149 (100%)

Time: 00:00.023, Memory: 10.00 MB

OK (149 tests, 382 assertions)
```

### "/home/z/my-project/tools/php" tools/wallet-functional-tests.php
```text
=== 1) SpendCalculator math ===
  PASS  available below cap wins
  PASS  percent cap 20% of items base
  PASS  zero total -> zero spend
  PASS  zero available -> zero spend
  PASS  below min order -> zero spend
  PASS  coverage off leaves one step payable
=== 2) Full lifecycle: hold -> capture -> idempotent re-fire ===
  PASS  seed balance available=50000
  PASS  stamp recorded the spend
  PASS  stamp recorded the order total snapshot
  PASS  order meta state=held after hold
  PASS  hold moved balance: available=0
  PASS  hold moved balance: held=50000
  PASS  state=captured after payment
  PASS  capture: held=0
  PASS  capture: available still 0 (spent)
  PASS  re-fired capture does not double-spend
  PASS  exactly one capture row in ledger
=== 3) Proportional refund math + caps + idempotency ===
  PASS  first refund returns 25000 (50%)
  PASS  second refund returns the remaining 25000
  PASS  same refund id does not double-credit
  PASS  refund beyond returned cap returns nothing
=== 4) Cancel while held -> release ===
  PASS  held after hold (c4)
  PASS  release on cancel: available restored
  PASS  release on cancel: held zero
  PASS  state=released
=== 5) Recovery paths (regression for the reviewed bugs) ===
  PASS  never-held order gets charged on retry (bug fix)
  PASS  wallet charged 40000 (held 0)
  PASS  available 0 after retry charge
  PASS  debt path marks order captured
  PASS  debt recorded: available=-20000
  PASS  debt recorded: no held left
  PASS  released after failure
  PASS  available restored after release
  PASS  re-hold+capture after retry
  PASS  wallet spent after retry capture
=== 6) Pending timeout release ===
  PASS  held before expiry
  PASS  expired pending hold released
  PASS  available restored after expiry
=== 7) due_total includes other (negative) fee rows ===
  PASS  toggle succeeded
  PASS  spend capped at 95000 (cashback-aware)
  PASS  wallet fee row added
  PASS  cart total never negative
  PASS  apply_fee twice adds only one wallet fee row (per-request guard)
=== 8) Checkout validation (allowed gateways) ===
  PASS  cod rejected when wallet is partial
  PASS  allowed gateway accepted
=== 9) Toggle endpoint behaviour ===
  PASS  toggle returns spend=0 error on empty cart
  PASS  toggle success stores spend
  PASS  session flag persisted
  PASS  throttled second call rejected (429)
  PASS  toggle off clears flag
=== 10) stamp_order caps and gateway stamping ===
  PASS  amount meta stamped
  PASS  zero-total order stamped as wallet payment
  PASS  order total snapshot stored
=== 11) Hold failure marks order failed and surfaces an error ===
  PASS  failed hold throws customer-facing error
  PASS  order marked failed
  PASS  no wallet state recorded on failed hold
=== 12) WalletGateway availability and payment guard ===
  PASS  gateway available when the wallet covers everything
  PASS  gateway hidden while half the cart is still owed
  PASS  gateway hidden for an empty cart
  PASS  partial-coverage payment refused
  PASS  refusal adds an error notice
  PASS  refused order stays pending
  PASS  full-coverage payment succeeds
  PASS  full-coverage order completed

================ RESULT: 64 passed, 0 failed ================
```

### "/home/z/my-project/tools/php" tools/account-functional-tests.php
```text
=== 1) Hook wiring and rewrite registration ===
  PASS  rewrite endpoint hook registered on init
  PASS  menu filter registered
  PASS  endpoint content hook registered
  PASS  enqueue hook registered
  PASS  add_rewrite_endpoint called with club + EP_ROOT|EP_PAGES
=== 2) Rewrite flush guard is one-shot ===
  PASS  flush happens when the version option is missing
  PASS  version option stored
  PASS  second run does not flush again
=== 3) Full render: own data only, balance, Jalali dates, Persian digits ===
  PASS  available balance rendered in Persian digits
  PASS  held balance rendered
  PASS  masked phone shown
  PASS  full phone never printed
  PASS  other user note not leaked (S5)
  PASS  own note rendered
  PASS  Jalali date rendered in Persian digits
  PASS  order link rendered
  PASS  currency symbol rendered
  PASS  RTL direction set on the scope
  PASS  rules section shows title
  PASS  rules section shows reward only
  PASS  rules section hides caps
  PASS  no-phone notice absent when phone exists
=== 4) XSS corpus is escaped (S7) ===
  PASS  no raw script tag in output
  PASS  note escaped
  PASS  rule title escaped
  PASS  no raw img tag for attribute injection
  PASS  onerror only survives inside escaped text
=== 5) No-phone guide message instead of an error (acceptance) ===
  PASS  guide message rendered
  PASS  guide links to edit-address
  PASS  balance still rendered
  PASS  history still rendered
=== 6) Logged out: no data, no fatal ===
  PASS  login prompt shown
  PASS  no ledger data for anonymous visitor
=== 7) Pagination links with 20 per page ===
  PASS  pagination rendered for 3 pages
  PASS  page one has 20 rows
  PASS  page two url format honoured
  PASS  aria-current marks the active page
  PASS  beyond-last page falls back to the last page content
=== 8) Empty states ===
  PASS  empty history guide shown
  PASS  empty rules guide shown
=== 9) Assets load on the club tab only ===
  PASS  no stylesheet outside My Account
  PASS  no stylesheet on other endpoints
  PASS  stylesheet enqueued on the club tab
=== 10) Theme template override wins (yourtheme/ariunetclub/account/club.php) ===
  PASS  theme template used when present
  PASS  plugin template used as fallback
=== 11) AccountModule boot is safe without wpdb ===
  PASS  module boot without wpdb does not fatal
  PASS  module metadata

================ RESULT: 47 passed, 0 failed ================
```

### "/home/z/my-project/tools/php" tools/rebrand-functional-tests.php
```text
=== 1) Fresh install: no legacy, clean create ===
  PASS  no RENAME issued on fresh install
  PASS  dbDelta received the four ariunetclub tables
  PASS  db version option set
=== 2) Upgraded install: tables renamed with data kept ===
  PASS  all four legacy tables renamed
  PASS  ledger renamed to ariunetclub_ledger
  PASS  balances renamed
  PASS  events renamed
  PASS  rules renamed
  PASS  table set now has modern names
=== 3) Settings and options copied forward ===
  PASS  settings value copied to new option
  PASS  legacy settings option untouched (rollback safety)
=== 4) Gateway id and order meta rewritten ===
  PASS  postmeta gateway id rewritten
  PASS  wallet state meta key rewritten
  PASS  all four meta keys rewritten
  PASS  HPOS gateway rewrite only when the table exists
=== 5) Scheduled actions moved to the new hooks ===
  PASS  two expiry actions rescheduled under the new hook
  PASS  order ids carried over
  PASS  rescheduled into the ariunetclub group
  PASS  legacy expiry actions unscheduled
  PASS  legacy recurring processor dropped
  PASS  wp-cron expiry event moved
  PASS  wp-cron legacy hooks cleared
=== 6) Idempotency: a second run changes nothing ===
  PASS  no further RENAME on the second run
  PASS  settings not copied again (value kept)
  PASS  no duplicate expiry rescheduling
  PASS  db version still current
=== 7) Both legacy and modern tables exist: modern wins ===
  PASS  ledger not renamed when the modern table exists
  PASS  rules not renamed when the modern table exists
=== 8) maybe_migrate only runs on a version change ===
  PASS  no queries when the stored version matches
  PASS  runs again once the stored version is missing

================ RESULT: 30 passed, 0 failed ================
```

### "/home/z/my-project/tools/php" tools/license-functional-tests.php
```text
=== 1) S18 matrix: verifier end to end ===
  PASS  genuine token activates
  PASS  tampered payload rejected
  PASS  other-domain token rejected
  PASS  expired license reported
  PASS  payload with a removed part rejected
  PASS  foreign key cannot forge a license for the embedded key
  PASS  missing libsodium is a transparent error
  PASS  missing libsodium never fatals the status path
=== 2) Activation term: one year from activation, anti-reset ===
  PASS  term starts at activation time
  PASS  re-entering the same token does not extend
  PASS  deactivate + reactivate keeps the original window
  PASS  a new token starts a fresh term
=== 3) License admin page: activation flow and rendering ===
  PASS  admin_post hooks registered
  PASS  license submenu registered under the club menu
  PASS  activation redirects to the license page
  PASS  activation flash is positive
  PASS  whitespace-padded token still activated
  PASS  invalid flash renders an escaped message
  PASS  invalid flash shows a Persian reason
  PASS  status card shows the domain
  PASS  status card shows Jalali activation date
  PASS  activation form has a nonce field
  PASS  no full token is echoed back
  PASS  deactivate form present while licensed
  PASS  render produced zero PHP warnings/notices
  PASS  render without capability is refused
  PASS  deactivate clears the binding
=== 4) Guards lock the mutating admin actions ===
  PASS  rule save locked without license
  PASS  manual adjust locked without license
  PASS  ajax adjust returns a 403 JSON error
  PASS  guards open with an active license
=== 5) Settings saving is blocked server-side while locked ===
  PASS  locked save keeps the stored value
  PASS  settings error was raised for the lock
  PASS  unlocked save passes through
=== 6) Lock screen: blur + lock icon on plugin pages ===
  PASS  lock screen renders on a plugin page while locked
  PASS  lock screen carries the lock icon SVG
  PASS  lock screen links to the license page
  PASS  lock screen mentions continuing earning/spending
  PASS  license page itself never locks
  PASS  non-plugin pages are not overlaid
  PASS  no lock screen with an active license
  PASS  lock screen rendering raised no PHP warnings
=== 7) Smart admin notice (dismissable, per status code) ===
  PASS  notice shown for shop managers while missing
  PASS  notice links to the license page
  PASS  dismissed notice hidden
  PASS  expiring-soon warning appears within 14 days
  PASS  non-managers never see the notice
=== 8) S15: no outbound network anywhere ===
  PASS  full license lifecycle made zero network attempts
  PASS  static scan: no network calls in src/
=== 9) Migration adds the tables and indexes, idempotently ===
  PASS  dbDelta received the ledger table
  PASS  phase 5 created_at index present in the schema
  PASS  db version option set to 1.5.0
  PASS  second migrate run is harmless
  PASS  phase 6 credit lots table in the schema
  PASS  phase 6 lot allocations table in the schema
=== 10) Dashboard: real data, charts, escaping, no N+1 ===
  PASS  dashboard uses exactly 9 queries (no N+1)
  PASS  lifetime issued total is exact (142,500)
  PASS  lifetime spent total is exact (3,000)
  PASS  today earned counts only the local day (12,500)
  PASS  today spent counts only the local day (3,000)
  PASS  held total from balances (0)
  PASS  wallet holders counted (2)
  PASS  series has 30 days
  PASS  30-day series totals 52,500
  PASS  top rule joined with its title
  PASS  recent activity has 5 rows
  PASS  dashboard renders the SVG chart
  PASS  chart has 60 bars (30 days x 2 series)
  PASS  dashboard shows Persian digits totals
  PASS  note XSS is escaped
  PASS  username XSS is escaped
  PASS  render produced zero PHP warnings/notices
=== 11) Module wiring ===
  PASS  license module id and dependencies
  PASS  module boot registered admin hooks
  PASS  module boot published the global guard
  PASS  fallback guard fails closed
  PASS  dashboard module boots safely without wpdb
=== 12) S16: no secrets in the repository ===
  PASS  no private keys or 64-byte secrets anywhere in the plugin tree
  PASS  embedded public key is exactly 32 bytes (hex 64)
  PASS  signer tool is present for the maintainer

================ RESULT: 80 passed, 0 failed ================
```

### "/home/z/my-project/tools/php" tools/credit-functional-tests.php
```text

=== 1) random earn/spend/hold/capture/release/expire sequences (I1-I4) ===

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 97

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 98

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 99

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 100
  PASS  seed 20261124: 400 ops keep I1-I4 on every step

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 97

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 98

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 99

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 100
  PASS  seed 42: 400 ops keep I1-I4 on every step

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 97

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 98

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 99

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 100
  PASS  seed 987654: 400 ops keep I1-I4 on every step

=== 2) settings sanitize (expiry, budget, guards) ===
  PASS  expiry settings persist
  PASS  budget settings persist
  PASS  guard settings persist
  PASS  empty fee title falls back to the new vocabulary
  PASS  credit module is in the default enabled list
  PASS  out-of-range inputs are clamped

=== 3) credit policy guards + budget (SQLite engine) ===
  PASS  min order guard blocks small carts
  PASS  healthy grant passes untouched
  PASS  per-order cap clamps to the remaining room
  PASS  per-order cap blocks when the order is full
  PASS  monthly per-customer cap lets a within-room grant pass — {"allowed":true,"amount":10000,"reason":""}
  PASS  monthly per-customer cap clamps an over-room grant — {"allowed":true,"amount":20000,"reason":""}
  PASS  monthly cap blocks an exhausted customer
  PASS  budget cap reflects the fixed amount — [200000,155000]
  PASS  grant clamped to the remaining budget
  PASS  budget exhausted → grant blocked at zero
  PASS  reversals reduce the issued budget total — used=170000

=== 4) expiry reminders (7/3/1) and labels ===

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 97

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 98

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 99

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 100
  PASS  exactly one reminder per lot per threshold — ["credit:expiring:lot:1:7d","credit:expiring:lot:2:3d"]
  PASS  reminder payloads carry lot id and days
  PASS  event label: credit.expiring
  PASS  event label: budget.warning
  PASS  event label: budget.blocked
  PASS  event object label: lot

=== 5) club tab lots (view model, XSS, own data) ===

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 97

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 98

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 99

Warning: Undefined property: wpdb::$prefix in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 100
  PASS  two lot rows in the view
  PASS  FEFO order: dated lot first
  PASS  jalali expiry is formatted
  PASS  held flag exposed
  PASS  template renders the lots section
  PASS  XSS payload in the reason is escaped
  PASS  other users see no lots of user 5
  PASS  logged-out view has no lots
  PASS  entry label: grant
  PASS  entry label: expire
  PASS  entry family: grant success / expire danger

=== 6) vocabulary: grep for the old credit word ===
  PASS  no «کیف پول» left in src/ (Migrator legacy literal excepted)
  PASS  no «کیف پول» left in templates/
  PASS  default fee title uses the new vocabulary

=== 7) block checkout notice detection ===
  PASS  no checkout page configured → no notice

================ RESULT: 41 passed, 0 failed ================
```

### "/home/z/my-project/tools/php" tools/integration-tests.php --driver sqlite
```text
=== AriunetClub phase 6 integration tests (driver: sqlite) ===
Engine: in-memory SQLite (sandbox). Concurrency section runs serialized.

=== 3) earn → spend lifecycle (FEFO, reasons, idempotency) ===
  PASS  invariants I1-I4 after earn 1 — av=50000 held=0 lots=50000 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  earn creates one lot with the rule reason
  PASS  lot remaining equals the credited amount
  PASS  idempotent ledger key records once
  PASS  idempotent re-run creates no extra lot
  PASS  invariants I1-I4 after more earns — av=100000 held=0 lots=100000 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  invariants I1-I4 after hold — av=60000 held=40000 lots=100000 lot_held=40000 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  hold allocates FEFO across lots (sooner expiry first) — [{"amount":30000,"state":"held"},{"amount":10000,"state":"held"}]
  PASS  invariants I1-I4 after capture — av=60000 held=0 lots=60000 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  capture marks allocations captured
  PASS  invariants I1-I4 after reversal — av=35000 held=0 lots=35000 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  reversal claws back the order lot exactly — {"remaining":25000,"held":0,"status":"active"}
  PASS  invariants I1-I4 after reversal into debt — av=-55000 held=0 lots=0 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  oversized reversal creates debt — available=-55000
  PASS  debt state leaves no unheld live lots
  PASS  invariants I1-I4 after grant repaying debt — av=5000 held=0 lots=5000 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  grant after debt nets out (I1) — lots=5000 bal=5000

=== 4) expiry job, held-part protection, release after expiry ===
  PASS  I3: no new hold on an expired lot (fail closed)
  PASS  invariants I1-I4 after expiry of user 9 — av=0 held=0 lots=0 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  invariants I1-I4 after expiry with open hold — av=0 held=20000 lots=20000 lot_held=20000 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  due lot fully expires (unheld part)
  PASS  expire ledger entry written once
  PASS  held part survives the expiry date — {"remaining":20000,"held":20000,"status":"active"}
  PASS  expiry job re-run is inert
  PASS  invariants I1-I4 after release after expiry — av=0 held=0 lots=0 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  released amount on an expired lot expires immediately — {"remaining":0,"held":0,"status":"expired"}
  PASS  ledger trail: 1 release + 2 expires (daily unheld part + freed held part)

=== 5) 20 concurrent spends on one user ===
  PASS  20 sequential spends executed (SQLite: single writer)
  PASS  invariants I1-I4 after 20 spends — av=1000000 held=0 lots=1000000 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1
  PASS  20 × 50,000 spent exactly once each — available=1000000
  PASS  exactly 20 capture entries

=== 6) balance → legacy-lot migration, 100,000 users ===
  PASS  100k-user migration finishes in batches — last batch 302 users, 200 batches, 15.18s total
  PASS  one legacy lot per positive-balance user — legacy=85543 positive=85543
  PASS  migration leaves already-consistent users untouched — legacy lots created for lifecycle users: 0
  PASS  full migration re-run is inert — before=85543 after=85543
  PASS  migrated lot matches the seeded balance — {"av":1,"h":0,"lots":1}
  PASS  invariants I1-I4 after post-migration cleanup — av=1000000 held=0 lots=1000000 lot_held=0 ledger_ok=1 lots_ok=1 held_ok=1 i2i4=1

=== 7) reconciliation detects drift, passes on healthy data ===
  PASS  healthy users show no drift
  PASS  no drift on the lifecycle users — []
  PASS  injected drift is detected — drift=1
  PASS  drift disappears after repair

=== 8) budget service on real ledger data ===
  PASS  month window is a sane UTC range
  PASS  issued for user 7 counts credits minus reversals — issued=45000

================ RESULT: 43 passed, 0 failed ================
```

### "/home/z/my-project/tools/php" tools/load-test.php --driver sqlite
```text
=== AriunetClub load test: 100000 ledger rows, driver sqlite ===
Driver: in-memory SQLite (development sandbox; advisory locks simulated)
Seeding 100000 ledger rows across 5000 users...
Seeded in 931.78ms

=== 1) Wallet balance reads (100 lookups) ===
  PASS  average balance read < 50ms — 0.01ms avg, 1.21ms total
  PASS  exactly 100 queries for 100 reads (no N+1) — 100 queries
=== 2) Ledger history pages (a user with ~20 rows/page) ===
  PASS  history page under 1s — 0.22ms
  PASS  history is paginated to 20 rows — 20 rows
  PASS  history uses exactly 2 queries (count + page) — 2 queries
=== 3) Dashboard queries on the full ledger ===
  PASS  lifetime totals under 1s — 32.50ms
  PASS  totals use 5 constant queries — 5 queries
  PASS  totals are non-zero
  PASS  today range under 1s — 0.42ms
  PASS  range uses 1 query — 1 queries
  PASS  30-day daily aggregation under 1s — 14.95ms
  PASS  daily aggregation uses 1 query — 1 queries
  PASS  every bucket day present after fill
  PASS  top rules under 1s — 20.25ms
  PASS  top rules use 1 query — 1 queries
  PASS  recent activity under 1s — 0.12ms
  PASS  recent activity uses 1 query — 1 queries
=== 4) Aggregation consistency ===
  PASS  30-day series sum matches the range query — 29,659,404.70 vs 29,659,404.70
=== 5) Phase 6 credit lots: 1,000,000 ledger rows ===
  PASS  ledger grew to 1,000,000 rows — 1,000,000 rows in 11,030.02ms
  PASS  credit lots seeded (6 per user) — 30,000 lots

Fatal error: Uncaught Error: Call to undefined function AriunetClub\Modules\Credit\esc_sql() in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php:91
Stack trace:
#0 /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php(320): AriunetClub\Modules\Credit\LotRepository::escape_datetime('2026-12-24 13:5...')
#1 /home/z/my-project/repo/AriunetClub/tools/load-test.php(365): AriunetClub\Modules\Credit\LotRepository->liability_summary('2026-11-24 13:5...', '2026-12-24 13:5...')
#2 {main}
  thrown in /home/z/my-project/repo/AriunetClub/src/Modules/Credit/LotRepository.php on line 91
```
