# فاز ۴ — تب «باشگاه» مشتری + تغییر برند کامل | AriunetClub / ariunetclub 0.4.0

این بسته دو کار را همزمان تحویل می‌دهد:

1. **تغییر برند کامل lclub → AriunetClub** با مایگریشن خودکار داده‌ها (بدون از دست رفتن هیچ موجودی، رزرو، قانون یا تنظیمی).
2. **فاز ۴ سند MVP**: تب «باشگاه» در «حساب من» ووکامرس — موجودی، رزروشده، موبایل ماسک‌شده، تاریخچهٔ تراکنش‌ها با تاریخ شمسی و صفحه‌بندی، و بخش «چطور کش‌بک بگیرم؟».

طبق تصمیم‌های شما در پرسش‌های ابتدای فاز: قالب قابل بازنویسی از `yourtheme/ariunetclub/account/club.php` (مسیر قدیمی `lclub` هم پشتیبانی می‌شود)، در بخش قوانین **فقط عنوان + پاداش** (بدون شرایط/سقف‌ها)، **۲۰ ردیف در هر صفحه**، آیتم «باشگاه» به‌عنوان **اولین تب** منو، نسخه **0.4.0** و شاخهٔ **phase4**.

---

## ۱) مراحل ارتقا روی سیستم شما (مهم — ابتدا این را بخوانید)

تغییر برند شامل تغییر نام پوشهٔ افزونه است؛ پس یک‌بار دستی دست می‌بُرد و بعد از آن همه‌چیز خودکار است:

1. بکاپ کامل دیتابیس و پوشهٔ `wp-content/plugins/lclub` بگیرید.
2. پوشهٔ قدیمی `lclub` را حذف و این ریپو را با نام پوشهٔ `ariunetclub` در `wp-content/plugins/` قرار دهید (ریشهٔ ریپو = ریشهٔ افزونه، فایل اصلی حالا `ariunetclub.php` است).
3. افزونهٔ AriunetClub را فعال کنید. در همین لحظهٔ فعال‌سازی:
   - rewrite اندپوینت `club` ثبت و flush می‌شود (نیازی به ذخیرهٔ دستی «پیوندهای یکتا» نیست)؛
   - مایگریشن نسخهٔ `1.3.0` اجرا می‌شود.
4. مایگریشن خودکار چه می‌کند (همه idempotent — دوبار اجرا شدنش بی‌خطر است):
   - `RENAME TABLE` برای چهار جدول `lclub_events/ledger/balances/rules` → `ariunetclub_*`؛
   - کپی گزینه‌های تنظیمات (`lclub_settings` → `ariunetclub_settings`)؛
   - به‌روزرسانی متای سفارش‌های قبلی (هم HPOS از طریق جدول `wc_orders_meta` و هم legacy از `postmeta`)؛
   - تغییر شناسهٔ درگاه پرداخت سفارش‌های قبلی از `lclub_wallet` به `ariunetclub_wallet`؛
   - انتقال اکشن‌های زمان‌بندی‌شدهٔ Action Scheduler به نام هوک/گروه جدید و انتقال cronهای WP.
5. تب «باشگاه» را از «حساب من» چک کنید — باید اولین آیتم منو باشد.

**نکته:** اگر بعد از فعال‌سازی تب «باشگاه» 404 داد، یک‌بار «تنظیمات ← پیوندهای یکتا» را ذخیره کنید؛ ولی در حالت عادی لازم نیست چون flush در فعال‌سازی و سپس با گارد نسخه‌دار یک‌باره انجام می‌شود.

---

## ۲) نقشهٔ فایل‌ها

### الف) فایل‌های جدید فاز ۴ (تب باشگاه)

```
src/Modules/Account/AccountModule.php      — بوت ماژول account (نیازمند core/ledger/rules)
src/Modules/Account/ClubEndpoint.php       — ثبت اندپوینت + منو + ساخت view-model + صفحه‌بندی + ماسک موبایل
src/Modules/Rules/RulePublicSummary.php    — متن امن مشتریِ پاداش («۲٪ کش‌بک» / «کش‌بک ۵۰٬۰۰۰»)
templates/account/club.php                 — قالب مشتری (قابل بازنویسی پوسته)
assets/css/ariunetclub-account.css         — استایل تب (توجه: فقط همین صفحه لود می‌شود)
tests/unit/ClubEndpointTest.php            — ۸ تست واحد
tests/unit/RulePublicSummaryTest.php       — ۴ تست واحد
tools/account-functional-tests.php         — ۴۷ تست عملکردی
tools/rebrand-functional-tests.php         — ۳۰ تست عملکردی مایگریشن تغییر برند
```

### ب) فایل‌های تغییر یافته

| فایل | تغییر |
|---|---|
| `ariunetclub.php` | نسخه 0.4.0 |
| `src/Core/Plugin.php` | ثبت ماژول `account`، rewrite در فعال‌سازی، پاک‌سازی کامل در غیرفعال‌سازی |
| `src/Core/Settings.php` | `account` در `enabled_modules` پیش‌فرض (با merge خودکار روی نصب فعلی فعال می‌شود) |
| `src/Core/Migrator.php` | مایگریشن تغییر برند (DB_VERSION 1.3.0) |
| `src/Modules/Rules/RuleGateway.php` + `RuleRepository.php` | متد جدید `active_for_display()` — فقط ستون‌های امن (id/title/event_type/reward)، سقف ۱۰ ردیف |
| `tools/phpstan-bootstrap.php` | stub ثابت‌های وردپرس (`ARRAY_A`، `EP_ROOT`، `EP_PAGES`، …) + نسخهٔ 0.4.0 |
| `phpstan-baseline.neon` | ۹ ردیف کمتر (بدون هیچ ردیف جدید برای کد فاز ۴) |
| `CHANGELOG.md`، `readme.txt` | ثبت 0.4.0 |
| `composer.json` | اسکریپت‌های `test:account` و `test:rebrand` |
| `uninstall.php` | (از کامیت تغییر برند) پاک‌سازی هم جداول/گزینه‌های قدیمی و هم جدید |

### پ) قواعد بازنویسی قالب

قالب از این مسیرها به‌ترتیب جست‌وجو می‌شود:

1. `yourtheme/ariunetclub/account/club.php`
2. `yourtheme/lclub/account/club.php` (سازگاری با پوسته‌ای که قبلاً برای lclub شخصی‌سازی شده)
3. `templates/account/club.php` داخل افزونه (fallback)

داده‌ای که به قالب می‌رسد `$data` است: کلیدهای `logged_in`, `available_display`, `held_display`, `phone_masked`, `has_phone`, `entries[]` (هر ردیف: `date`, `label`, `family`, `amount_display`, `note`, `order_url`), `page`, `pages`, `total`, `rules[]` (`id`, `title`, `reward`, `reward_kind`), `currency`, `edit_address_url`. همهٔ مقادیر از قبل فرمت شده‌اند (اعداد فارسی، تاریخ شمسی) ولی **مسئولیت escape با قالب است**.

---

## ۳) تصمیم‌های امنیتی فاز ۴ (نگاشت به بند S سند)

| الزام | پیاده‌سازی |
|---|---|
| S4 دسترسی | هر کوئری فقط با `get_current_user_id()`؛ تست عملکردی: خروج از حساب → هیچ داده‌ای رندر نمی‌شود |
| S5 دادهٔ دیگران | `LedgerGateway::history($user_id, ...)` و `balance($user_id)` — کلید کاربر همیشه از session، نه از ورودی |
| S7 خروجی | هر echo در قالب با `esc_html`/`esc_url`/`esc_attr`؛ تست XSS واقعی با payload تزریقی در ۶ نقطه (یادداشت، عنوان قانون، پاداش، تاریخ، برچسب، موبایل) |
| S12 قالب | نام قالب‌ها literal و ثابت؛ مسیر از `locate_template` وردپرس (پوشهٔ پوستهٔ فعال) — هیچ ورودی کاربری در مسیر نیست |
| S14 موبایل | نمایش همیشه به شکل `۰۹۱۲***۴۵۶۷` — شمارهٔ کامل هرگز به HTML پاس نمی‌شود (نه حتی hidden) |
| S14 صفحه‌بندی | شمارهٔ صفحه فقط از `get_query_var` با regex بدون backtracking، clamp بین ۱ و ۱٬۰۰۰٬۰۰۰ (جلوگیری از overflow در OFFSET) و هرگز بیشتر از `ceil(total/20)` |
| سیاست فروشگاه | `RulePublicSummary` فقط نوع+مقدار پاداش را می‌بیند؛ `active_for_display` در SQL فقط ستون‌های id/title/event_type/reward را انتخاب می‌کند — شرایط، سقف‌ها، پنجره‌ها و اولویت از دیتابیس هم بیرون نمی‌آیند |
| DoS | سقف ۱۰ قانون در نمایش؛ `LIMIT/OFFSET` محاسبه‌شدهٔ سروِرم |

---

## ۴) صفحه‌بندی

- ۲۰ ردیف در هر صفحه (تصمیم شما).
- URLها: `/my-account/club/`، `/my-account/club/page/2/` (و شکل کوتاه `/club/2/` هم پذیرفته می‌شود).
- اگر شمارهٔ صفحه از تعداد صفحات بیشتر شود، خودکار به آخرین صفحه clamp و دوباره کوئری می‌شود (هیچ صفحهٔ خالی ۲۰۰ نمی‌بینید).
- ناوبری فقط پیوند سادهٔ `<a>` است — بدون JS اضافه، سازگار با هر پوسته.

---

## ۵) تست‌ها — همیشه سبز در محیط من

| مجموعه | نتیجه |
|---|---|
| PHPUnit | ۶۳ تست، ۱۷۲ assertion — OK |
| `tools/account-functional-tests.php` | ۴۷/۴۷ پاس |
| `tools/wallet-functional-tests.php` | ۵۶/۵۶ پاس |
| `tools/rebrand-functional-tests.php` | ۳۰/۳۰ پاس |
| PHPCS (WPCS) | ۰ خطا، ۰ هشدار |
| PHPStan سطح max | No errors — کد جدید فاز ۴ صفر ردیف baseline |
| Psalm taint analysis | بدون یافته (صحت تحلیل با تزریق عمدی XSS و گرفتنش راستی‌آزمایی شد) |

تست‌های عملکردی فاز ۴ چه چیزی را واقعاً اجرا می‌کنند (نه فقط لینت): رندر واقعی قالب با کلاس‌های واقعی افزونه و مخزن درون‌حافظه‌ای با سمانتیک واقعی `LedgerRepository` (شامل idempotency و شمارش total)، شبیه‌سازی کاربر لاگین‌شده/خارج‌شده، payloadهای XSS در همهٔ نقاط خروجی، صفحه‌های ۱/۲/بیش از سقف، قالب بازنویسی‌شده در «پوسته»، عدم لود CSS در صفحات دیگر و مایگریشن کامل lclub→ariunetclub روی جداول/گزینه‌های ساختگی.

روی سیستم خودت:

```powershell
composer test:account   # ۴۷ تست عملکردی تب باشگاه — بدون دیتابیس
composer test:rebrand   # ۳۰ تست عملکردی مایگریشن — بدون دیتابیس
composer test:wallet    # ۵۶ تست عملکردی فاز ۳ — بدون دیتابیس
vendor\bin\phpcs
php -d memory_limit=512M vendor\bin\phpstan analyse
phpunit                 # نیاز به MySQL با پیشوند ariunetclub_test_ + mbstring
```

---

## ۶) آنچه عمداً ساخته نشد (خارج از محدودهٔ این فاز)

لایسنس و سطح‌بندی (ف۵)، پیامک اطلاع‌رسانی، انقضای موجودی، چک‌اوت بلاک (Block Checkout)، ویجت/شورت‌کد داشبورد جدا برای باشگاه و صفحهٔ عمومی معرفی باشگاه برای مهمان‌ها (تب برای کاربر لاگین‌شده پیام راهنما نشان می‌دهد).

---

## ۷) پرسش‌های متداول ارتقا

**«پوشه را تغییر نام دادم ولی افزونه در لیست غیرفعال است»** — طبیعی است؛ وردپرس افزونه را با نام فایل اصلی می‌شناسد که حالا `ariunetclub.php` است. همان را فعال کنید.

**«آیا موجودی مشتری‌ها به‌هم می‌ریزد؟»** — خیر؛ جداول فقط `RENAME` می‌شوند و کلیدها/user_id دست‌نخورده می‌مانند. تست ۳۰ موردی مایگریشن دقیقاً همین را با اجرای دوباره (idempotency) چک می‌کند.

**«رزروهای باز (hold) چک‌اوت‌های در جریان چه می‌شوند؟»** — اکشن‌های انقضای Action Scheduler با نام هوک جدید زمان‌بندی مجدد می‌شوند؛ زمان‌بندی‌شان حفظ می‌شود.

**«پوستهٔ من قبلاً lclub را override کرده بود»** — همان مسیر قدیمی هنوز کار می‌کند؛ ولی توصیه می‌کنم به `yourtheme/ariunetclub/account/club.php` منتقل کنید.
