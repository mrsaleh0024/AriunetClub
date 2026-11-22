# AriunetClub QA Report

Generated: 2026-11-22T08:00:04+00:00

## Commands

### "/home/z/tools/php" vendor/bin/phpcs
```text

```

### npm run --silent lint:css
```text

```

### "/home/z/tools/php" -d memory_limit=512M vendor/bin/phpstan analyse
```text
Warning: PHP Startup: Unable to load dynamic library '/home/z/my-project/repo/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.3.so' (tried: /home/z/my-project/repo/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.3.so (Dynamic loading not supported), /lib/php/extensions/no-debug-non-zts-20230831//home/z/my-project/repo/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.3.so.so (Dynamic loading not supported)) in Unknown on line 0
Note: Using configuration file /home/z/my-project/repo/phpstan.neon.
  0/77 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%[1G[2K 77/77 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%


 [OK] No errors

```

### "/home/z/tools/php" vendor/bin/phpunit
```text
PHPUnit 9.6.39 by Sebastian Bergmann and contributors.

...............................................................  63 / 136 ( 46%)
............................................................... 126 / 136 ( 92%)
..........                                                      136 / 136 (100%)

Time: 00:00.021, Memory: 10.00 MB

OK (136 tests, 348 assertions)
```

### "/home/z/tools/php" tools/wallet-functional-tests.php
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

### "/home/z/tools/php" tools/account-functional-tests.php
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

### "/home/z/tools/php" tools/rebrand-functional-tests.php
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

### "/home/z/tools/php" tools/license-functional-tests.php
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
=== 9) Migration 1.4.0 adds the ledger index, idempotently ===
  PASS  dbDelta received the ledger table
  PASS  phase 5 created_at index present in the schema
  PASS  db version option set to 1.4.0
  PASS  second migrate run is harmless
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

================ RESULT: 78 passed, 0 failed ================
```

### "/home/z/tools/php" tools/load-test.php --driver sqlite
```text
=== AriunetClub load test: 100000 ledger rows, driver sqlite ===
Driver: in-memory SQLite (development sandbox; advisory locks simulated)
Seeding 100000 ledger rows across 5000 users...
Seeded in 916.55ms

=== 1) Wallet balance reads (100 lookups) ===
  PASS  average balance read < 50ms — 0.01ms avg, 1.18ms total
  PASS  exactly 100 queries for 100 reads (no N+1) — 100 queries
=== 2) Ledger history pages (a user with ~20 rows/page) ===
  PASS  history page under 1s — 0.21ms
  PASS  history is paginated to 20 rows — 20 rows
  PASS  history uses exactly 2 queries (count + page) — 2 queries
=== 3) Dashboard queries on the full ledger ===
  PASS  lifetime totals under 1s — 28.92ms
  PASS  totals use 5 constant queries — 5 queries
  PASS  totals are non-zero
  PASS  today range under 1s — 0.37ms
  PASS  range uses 1 query — 1 queries
  PASS  30-day daily aggregation under 1s — 13.56ms
  PASS  daily aggregation uses 1 query — 1 queries
  PASS  every bucket day present after fill
  PASS  top rules under 1s — 17.46ms
  PASS  top rules use 1 query — 1 queries
  PASS  recent activity under 1s — 0.08ms
  PASS  recent activity uses 1 query — 1 queries
=== 4) Aggregation consistency ===
  PASS  30-day series sum matches the range query — 29,459,224.90 vs 29,459,224.90

================ LOAD TEST PASSED ================
```
