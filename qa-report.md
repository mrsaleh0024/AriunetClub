# AriunetClub QA Report

Generated: 2026-11-18T13:12:07+00:00

## Commands

### "C:\Users\ariunetpc6\AppData\Local\Programs\PHP\php.exe" vendor/bin/phpcs
```text

[1mFILE: src\Modules\Ledger\WalletAdminPage.php[0m
----------------------------------------------------------------------
[1mFOUND 0 ERRORS AND 10 WARNINGS AFFECTING 5 LINES[0m
----------------------------------------------------------------------
 33 | [33mWARNING[0m | Processing form data without nonce verification.
 33 | [33mWARNING[0m | Processing form data without nonce verification.
 35 | [33mWARNING[0m | Processing form data without nonce verification.
 35 | [33mWARNING[0m | Processing form data without nonce verification.
 66 | [33mWARNING[0m | Processing form data without nonce verification.
 66 | [33mWARNING[0m | Processing form data without nonce verification.
 70 | [33mWARNING[0m | Processing form data without nonce verification.
 70 | [33mWARNING[0m | Processing form data without nonce verification.
 79 | [33mWARNING[0m | Processing form data without nonce verification.
 79 | [33mWARNING[0m | Processing form data without nonce verification.
----------------------------------------------------------------------

Time: 3.87 secs; Memory: 16MB

```

### "C:\Users\ariunetpc6\AppData\Local\Programs\PHP\php.exe" -d memory_limit=512M vendor/bin/phpstan analyse
```text

⚠️  You're running an old version of PHPStan.️

The last release in the 1.12.x series with new features
and bugfixes was released on July 17th 2025,
that's 489 days ago.

Since then more than 65 new PHPStan versions were released
with hundreds of new features, bugfixes, and other
quality of life improvements.

To learn about what you're missing out on, check out
this blog with articles about the latest major releases:
https://phpstan.org/blog

Upgrade today to PHPStan 2.2 or newer by using
"phpstan/phpstan": "^2.2" in your composer.json.

Note: Using configuration file C:\Users\ariunetpc6\Local Sites\legion\app\public\wp-content\plugins\lclub\phpstan.neon.
  0/18 [>---------------------------]   0%[1G[2K 18/18 [============================] 100%

 -- ---------------------------------------------------------------------------------------------------
     Error
 -- ---------------------------------------------------------------------------------------------------
     Child process error: PHPStan process crashed because it reached configured PHP memory limit: 512M
     Increase your memory limit in php.ini or run PHPStan with --memory-limit CLI option.
      while running parallel worker
 -- ---------------------------------------------------------------------------------------------------


 [ERROR] Found 1 error

⚠️  Result is incomplete because of severe errors. ⚠️
   Fix these errors first and then re-run PHPStan
   to get all reported errors.

```

### "C:\Users\ariunetpc6\AppData\Local\Programs\PHP\php.exe" vendor/bin/phpunit
```text
PHPUnit 9.6.39 by Sebastian Bergmann and contributors.

...........                                                       11 / 11 (100%)

Time: 00:00.006, Memory: 6.00 MB

OK (11 tests, 12 assertions)
```
