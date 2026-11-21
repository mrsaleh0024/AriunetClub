# AriunetClub QA Report

Generated: 2026-11-21T18:29:54+00:00

## Commands

### "/tmp/phpbin/php" vendor/bin/phpcs
```text

```

### npm run --silent lint:css
```text

```

### "/tmp/phpbin/php" -d memory_limit=512M vendor/bin/phpstan analyse
```text

Warning: PHP Startup: Unable to load dynamic library '/home/z/my-project/AriunetClub/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.4.so' (tried: /home/z/my-project/AriunetClub/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.4.so (Dynamic loading not supported), /lib/php/extensions/no-debug-non-zts-20240924//home/z/my-project/AriunetClub/vendor/phpstan/phpstan/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.4.so.so (Dynamic loading not supported)) in Unknown on line 0
Note: Using configuration file /home/z/my-project/AriunetClub/phpstan.neon.
  0/66 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%[1G[2K 66/66 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%


 [OK] No errors

```

### "/tmp/phpbin/php" vendor/bin/phpunit
```text
PHPUnit 9.6.39 by Sebastian Bergmann and contributors.

................................................................. 65 / 71 ( 91%)
......                                                            71 / 71 (100%)

Time: 00:00.010, Memory: 6.00 MB

OK (71 tests, 205 assertions)
```

### "/tmp/phpbin/php" tools/wallet-functional-tests.php
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

### "/tmp/phpbin/php" tools/account-functional-tests.php
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

### "/tmp/phpbin/php" tools/rebrand-functional-tests.php
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
