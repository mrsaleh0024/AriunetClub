# AriunetClub QA Report

Generated: 2026-11-21T05:33:35+00:00

## Commands

### "C:\Users\ariunetpc6\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" vendor/bin/phpcs
```text

[1mFILE: src\Modules\Events\EventsAdminPage.php[0m
----------------------------------------------------------------------
[1mFOUND 0 ERRORS AND 1 WARNING AFFECTING 1 LINE[0m
----------------------------------------------------------------------
 242 | [33mWARNING[0m | Avoid function calls in a FOR loop test part
----------------------------------------------------------------------


[1mFILE: src\Modules\Rules\RulesAdminPage.php[0m
----------------------------------------------------------------------
[1mFOUND 5 ERRORS AFFECTING 5 LINES[0m
----------------------------------------------------------------------
 247 | [31mERROR[0m | [x] Whitespace found at end of line
 249 | [31mERROR[0m | [x] Whitespace found at end of line
 251 | [31mERROR[0m | [x] Whitespace found at end of line
 252 | [31mERROR[0m | [x] Whitespace found at end of line
 253 | [31mERROR[0m | [x] Closing PHP tag must be on a line by itself
----------------------------------------------------------------------
[1mPHPCBF CAN FIX THE 5 MARKED SNIFF VIOLATIONS AUTOMATICALLY[0m
----------------------------------------------------------------------

Time: 10.39 secs; Memory: 26MB

```

### "C:\Users\ariunetpc6\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" -d memory_limit=512M vendor/bin/phpstan analyse
```text
Note: Using configuration file C:\Users\ariunetpc6\Local Sites\legion\app\public\wp-content\plugins\ariunetclub\phpstan.neon.
  0/56 [>---------------------------]   0%RuntimeException thrown in C:\Users\ariunetpc6\Local Sites\legion\app\public\wp-content\plugins\ariunetclub\tests\bootstrap.php on line 7 while loading bootstrap file C:\Users\ariunetpc6\Local Sites\legion\app\public\wp-content\plugins\ariunetclub\tests\bootstrap.php: AriunetClub tests require a database whose name starts with ariunetclub_test_.

In CommandHelper.php line 514:

  [PHPStan\Command\InceptionNotSuccessfulException]


analyse [-c|--configuration CONFIGURATION] [-l|--level LEVEL] [--no-progress] [--debug] [-a|--autoload-file AUTOLOAD-FILE] [--error-format ERROR-FORMAT] [-b|--generate-baseline [GENERATE-BASELINE]] [--allow-empty-baseline] [--memory-limit MEMORY-LIMIT] [--xdebug] [--tmp-file TMP-FILE] [--instead-of INSTEAD-OF] [--fix] [--fix-ignored] [--fix-round] [--fixed-ignored-in FIXED-IGNORED-IN] [--watch] [--pro] [--fail-without-result-cache] [--] [<paths>...]

```

### "C:\Users\ariunetpc6\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" vendor/bin/phpunit
```text
PHPUnit requires the "dom", "filter", "json", "libxml", "mbstring", "tokenizer", "xmlwriter" extensions, but the "mbstring" extension is not available.
```
