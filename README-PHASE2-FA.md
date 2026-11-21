# فاز ۲ — رویداد و قوانین (کسب) | AriunetClub / lclub

این بسته، فاز ۲ سند معماری MVP را کامل پیاده‌سازی می‌کند. همه‌ی قراردادهای بخش ۵ سند رعایت شده: namespace برابر `LClub\`، SQL فقط داخل Repositoryها و همیشه با `$wpdb->prepare`، دفتر کل فقط‌الحاقی، idempotency_key یکتا برای هر رکورد، پردازش ناهمزمان با Action Scheduler و fallback به WP-Cron، همه‌ی فرم‌ها با nonce و همه‌ی خروجی‌ها escape.

---

## ۱) نقشه‌ی فایل‌ها

### الف) فایل‌های «جایگزین» — نسخه‌ی فعلی را با این‌ها overwrite کنید

| فایل | تغییر نسبت به فاز ۱ |
|---|---|
| `lclub.php` | نسخه 0.3.0 |
| `src/Core/Plugin.php` | ثبت ماژول‌های `events` و `rules` + پاک‌سازی Action Scheduler در deactivate |
| `src/Core/Settings.php` | تنظیمات جدید «وضعیت پیش‌فرض کسب» و «حذف مالیات از مبنا» + ماژول‌های جدید در لیست |
| `src/Core/Migrator.php` | ستون `attempts` در `lclub_events` و DB_VERSION نسخه 1.2.0 |
| `src/Modules/Ledger/LedgerGateway.php` | ۳ متد جدید: `entries_for_reference`، `count_for_rule`، `sum_for_rule` |
| `src/Modules/Ledger/LedgerRepository.php` | پیاده‌سازی ۳ متد جدید + اصلاح PHPDoc |
| `tests/bootstrap.php` | stubهای جدید (`apply_filters`، `wp_json_encode`، transientها، `wp_parse_args`، `get_option`) و لود Fixtures |
| `tests/unit/LedgerServiceTest.php` | اینترفیس گسترش‌یافته در fake + تست جدید «بدهی با adjust منفی» |
| `assets/css/lclub-admin.css` | استایل صفحات قوانین/رویدادها (بدون گرادیان، RTL، زیر `.lclub-scope`) |
| `assets/js/lclub-admin.js` | پیش‌نمایش زنده‌ی فرم قانون + تأیید حذف (JS فقط بهبود تدریجی است) |
| `CHANGELOG.md` | ثبت نسخه 0.3.0 |

### ب) فایل‌های «جدول» — فقط کپی کنید، فایل قبلی ندارند

**ماژول Events (۱۰ فایل):**
```
src/Modules/Events/EventsModule.php
src/Modules/Events/EventBus.php
src/Modules/Events/EventGateway.php
src/Modules/Events/EventRepository.php
src/Modules/Events/Processor.php
src/Modules/Events/EventLabels.php
src/Modules/Events/SkipEventException.php
src/Modules/Events/EventsAdminPage.php
src/Modules/Events/Sources/OrderSource.php
src/Modules/Events/Sources/UserSource.php
src/Modules/Events/Sources/ReviewSource.php
```

**ماژول Rules (۱۹ فایل):**
```
src/Modules/Rules/RulesModule.php
src/Modules/Rules/RuleEngine.php
src/Modules/Rules/RuleGateway.php
src/Modules/Rules/RuleRepository.php
src/Modules/Rules/RuleContext.php
src/Modules/Rules/ContextFactoryInterface.php
src/Modules/Rules/WcContextFactory.php
src/Modules/Rules/CashbackBase.php
src/Modules/Rules/LimitChecker.php
src/Modules/Rules/ReversalMath.php
src/Modules/Rules/RewardService.php
src/Modules/Rules/OrderHistoryInterface.php
src/Modules/Rules/WcOrderHistory.php
src/Modules/Rules/OrderLookupInterface.php
src/Modules/Rules/OrderSnapshot.php
src/Modules/Rules/WcOrderLookup.php
src/Modules/Rules/RulesAdminPage.php
src/Modules/Rules/Conditions/ConditionInterface.php
src/Modules/Rules/Conditions/MinAmount.php
src/Modules/Rules/Conditions/Category.php
src/Modules/Rules/Conditions/Product.php
src/Modules/Rules/Conditions/FirstOrder.php
src/Modules/Rules/Conditions/UserRole.php
src/Modules/Rules/Conditions/PaymentMethod.php
src/Modules/Rules/Rewards/RewardInterface.php
src/Modules/Rules/Rewards/PercentageReward.php
src/Modules/Rules/Rewards/FixedReward.php
```

**تست‌های واحد (۷ فایل):**
```
tests/unit/Fixtures.php
tests/unit/EventBusTest.php
tests/unit/ProcessorTest.php
tests/unit/RuleEngineTest.php
tests/unit/LimitCheckerTest.php
tests/unit/ReversalMathTest.php
tests/unit/RewardServiceTest.php
```

### ج) فایل‌هایی که دست نخورده‌اند
`uninstall.php` (جداول فاز ۲ از قبل در لیست حذف بود)، `composer.json`، `phpcs.xml`، `phpstan.neon`، `psalm.xml`، `phpunit.xml`، `tools/*`، ماژول‌های Identity و بقیه‌ی Core.

---

## ۲) مراحل نصب و اجرا

```bash
# ۱) شاخه‌ی فاز ۲ را بسازید (طبق قاعده‌ی «هر فاز یک branch»)
git checkout -b phase-2-events-rules

# ۲) فایل‌ها را کپی کنید:
#    - فایل‌های «جایگزین» را روی نسخه‌های فعلی در ریشه‌ی افزونه overwrite کنید
#    - فایل‌های «جدول» را در همان مسیرهای بالا بسازید
#    (ساختار پوشه‌های این بسته دقیقاً ساختار ریشه‌ی افزونه است؛ کافی است محتوای
#     این بسته را داخل ریشه‌ی افزونه بریزید و overwrite را تأیید کنید.)

# ۳) autoload را به‌روز کنید
composer dump-autoload

# ۴) تست‌های واحد
php vendor/bin/phpunit

# ۵) تحلیل ایستا
php vendor/bin/phpcs
php -d memory_limit=1G vendor/bin/phpstan analyse
php vendor/bin/psalm --taint-analysis

# ۶) گیت تحویل بخش ۱۴ — دو بار پشت‌سرهم
composer qa
composer qa
```

مایگریشن دیتابیس **خودکار** است: با اولین بوتِ افزونه، `Migrator::maybe_migrate()` نسخه را می‌بیند و ستون `attempts` را با `dbDelta` اضافه می‌کند. جدول‌های `lclub_events` و `lclub_rules` از فاز ۰ وجود داشتند و ساختارشان تغییر اساسی نکرده.

---

## ۳) سناریوی تست دستی (پذیرش فاز ۲)

۱. **پنل مدیریت ← باشگاه مشتریان ← تنظیمات ← تب «کسب کش‌بک»**: وضعیت پیش‌فرض کسب را روی «تکمیل‌شده» بگذارید و در صورت نیاز تیک «حذف مالیات از مبنای کش‌بک» را بزنید.

۲. **قوانین کش‌بک ← قانون جدید**: مثلاً — «وقتی سفارش به وضعیت تکمیل‌شده رسید و سفارش حداقل ۵۰۰٬۰۰۰ تومان باشد، پاداش ۵٪ کش‌بک با سقف ۱۰۰٬۰۰۰ تومان بده» — ذخیره و **فعال‌سازی** از لیست. (پیش‌نمایش جمله‌ی فارسی زیر فرم زنده است؛ فرم بدون JS هم کامل کار می‌کند.)

۳. یک سفارش آزمایشی ثبت کنید و تا وضعیت مبنای قانون ببرید. با تیک آن، رویداد در صف می‌رود (tick فوری + صف تکرارشونده‌ی هر ۶۰ ثانیه) و کش‌بک در **کیف پول‌ها** نشسته می‌شود.

۴. **لاگ رویدادها**: وضعیت هر رویداد (در انتظار/پردازش‌شده/ناموفق/نادیده)، تعداد تلاش‌ها، داده‌ی payload و دکمه‌ی «پردازش مجدد» برای ناموفق‌ها را ببینید.

۵. **پذیرش‌ها را چک کنید:**
   - تکرار همان رویداد (مثلاً رفت‌وبرگشت وضعیت) فقط یک بار کش‌بک می‌دهد — کلید یکتای `order:{id}:status:{status}` + کلید اعتبار `rule:{id}:{کلید رویداد}`.
   - **مستردشدهٔ نسبی**: سفارش ۱٬۰۰۰ تومانی با ۵۰ تومان کش‌بک؛ ۲۰۰ تومان مسترد کنید → دقیقاً ۱۰ تومان با رکورد جبرانی کم می‌شود؛ کل مبلغ را مسترد کنید → باقی‌مانده کامل صفر می‌شود.
   - **لغو سفارش**: برگشت کاملِ باقی‌مانده با کلید `order:{id}:reversal` (تکرار لغو اثر دوم ندارد).
   - **قانون غیرفعال یا خارج از بازه** اعمال نمی‌شود؛ زیرسفارش‌ها (دارای والد) نادیده گرفته می‌شوند؛ سفارش مهمان skip می‌شود.
   - **حداقل مبلغ و دسته‌بندی**: با شرط‌های شامل/مستثنا تست کنید.

---

## ۴) تصمیم‌های تأییدشده‌ی شما که در کد اعمال شد

| تصمیم | پیاده‌سازی |
|---|---|
| وضعیت کسب: هر قانون + پیش‌فرض سراسری | فیلد رویداد در فرم قانون؛ تنظیمات فقط پیش‌فرض فرم را می‌سازد |
| مبنای مبلغ: کل منهای حمل + سوییچ مالیات | `CashbackBase::from_order()` — تنها منبع حقیقتِ فرمول |
| اولین سفارش = نبودن سفارش قبلی پردازش‌شده/تکمیل‌شده | `WcOrderHistory` با `wc_get_orders` (سازگار با HPOS) |
| جدول‌های ادمین هم‌سبک فاز ۱ | جدول‌های اختصاصی با کلاس‌های `lclub-table` و توکن‌های فعلی |
| بازه‌ی سقف: پنجره‌ی لغزان | شمارش رکوردهای اعتبار قانون در `N` روز گذشته از دفتر کل |
| رویداد خطادار: ۳ retry + دکمه | ستون `attempts`، وضعیت بعد از تلاش سوم = failed، «پردازش مجدد» در لاگ |
| دامنه‌ی رویداد: فقط وضعیت‌های کلیدی | `processing, completed, cancelled, refunded, failed` در `OrderSource::KEY_STATUSES` |
| ارزیابی شرط‌ها با داده‌ی زنده | `WcContextFactory` از شیء سفارشِ لحظه‌ی پردازش می‌خواند؛ فقط مبلغ مرجوعی snapshot می‌شود |

---

## ۵) رفتارهای مرزی مستندشده (مطابق سند)

- **رفت‌وبرگشت وضعیت** (تکمیل ← لغو ← تکمیل دوباره): کش‌بک دوم صادر نمی‌شود؛ برگشتِ اول معتبر می‌ماند. این دقیقاً معیار «ارسال دوباره‌ی همان رویداد فقط یک بار کش‌بک می‌دهد» است.
- وضعیت `refunded` به‌خودش برگشت نمی‌زند؛ برگشتِ متناسب را رکوردهای مستردشده (refund objects) انجام می‌دهند تا با مستردشدهٔ نسبی دوباره‌کاری نشود.
- برگشت می‌تواند موجودی را منفی کند (بدهی) — طبق پیش‌فرض بخش ۹ سند؛ برگشت با رکورد جبرانی `adjust` منفی ثبت می‌شود، نه با debit (که گارد موجودی دارد).
- پاداش درصدی برای رویدادهای بدون سفارش (ثبت‌نام/دیدگاه) قابل محاسبه نیست و آن قانون نادیده گرفته می‌شود؛ برای این رویدادها «مبلغ ثابت» استفاده کنید.
- برگشت‌ها با کلیدهای `order:{id}:refund:{refund_id}:reversal` و `order:{id}:reversal` از خرج‌کردن‌های فاز ۳ جدا می‌مانند.
- نقاط توسعه‌ی عمومی فقط همان دو مورد سند است: اکشن `lclub_ledger_entry_created` و فیلتر `lclub_rule_reward_amount`. اکشن داخلی `lclub_event_processed` فقط ارتباط ماژول به ماژول است.
- حدود ایمنی: `per_page` سقف ۱۰۰ دارد، شرط‌های JSON با عمق حداکثر ۱۶ decode می‌شوند، متن خطا تا ۳۰۰ نویسه با ماسک در لاگ می‌نشیند، موبایل در لاگ‌ها ماسک است (میراث Logger فاز ۰).

---

## ۶) وضعیت QA در محیط من (گزارش صریح طبق بخش ۱۴)

| ابزار | وضعیت در sandbox من | وضعیت مورد انتظار روی سیستم شما |
|---|---|---|
| `php -l` روی هر ۵۴ فایل PHP | سبز | — |
| PHPUnit (نسخه ۹٫۶٫۳۹) | **سبز — ۴۲ تست، ۱۰۱ assertion** (با autoload مینیمال جایگزین composer) | باید سبز باشد |
| PHPStan سطح ۶ با stub وردپرس و ووکامرس | اجرا شد؛ خطاهای واقعی اصلاح شدند و فقط گاردهای دفاعی هم‌سبک فاز ۱ باقی است | `composer qa` سطح max |
| PHPCS (استاندارد وردپرس) | اجرا نشد (نیازمند نصب composer) | `composer qa` |
| Psalm taint | اجرا نشد (نیازمند نصب composer) | `composer qa:security` |
| Playwright / ZAP / Q9-Q12 | خارج از sandbox | طبق گیت فاز |

طبق قاعده‌ی سند: «ادعای پاس‌شدن بدون اجرای واقعی ممنوع» — پس `composer qa` را دو بار پشت‌سرهم روی سیستم خودتان اجرا کنید و اگر هر موردی خطا داد، خروجی را برایم بفرستید تا اصلاح کنم.

---

## ۷) آنچه در فاز ۲ ساخته نشده (خارج از محدوده، طبق دستور سند)

خرج کیف پول در چک‌اوت و متاباکس سفارش (ف۳)، تب «باشگاه» مشتری (ف۴)، لایسنس (ف۵)، پیامک و سطح‌بندی و ماموریت (بعد از MVP)، داشبورد چهارکارتی ادمین (بخش ۱۰٫۳ — به فاز بعدی خودش موکول شد)، و REST API.
