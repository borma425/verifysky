# Local Dev Access (Temporary)

هذا الملف مؤقت للاستخدام المحلي فقط.

## 1) تشغيل المشروع محلياً

### أمر واحد فقط

من داخل مجلد `dashboard`:

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
composer local
```

هذا الأمر يقوم بـ:

- أول تشغيل:
  تجهيز runtime المحلي وإنشاء `.env` وتثبيت dependencies وتوليد `APP_KEY` وتشغيل migrations وseed للحسابات
- في التشغيلات التالية:
  لا يعيد التثبيت ولا الـ migrate إلا إذا كانت الحزم ناقصة أو تغيّر `composer.lock` أو `package-lock.json` أو ظهرت migrations جديدة
- ثم يشغل السيرفر والـ queue والـ Vite

بعدها افتح:

```text
http://127.0.0.1:8000/wow/login
```

### أو بالطريقة اليدوية

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
composer install
npm install
php artisan key:generate
php artisan migrate
SEED_RESET_PASSWORDS=true php artisan db:seed
composer dev
```

بعدها افتح:

```text
http://127.0.0.1:8000/wow/login
```

إذا كنت تحتاج تشغيل `worker` محلياً أيضاً:

```bash
cd /opt/lampp/htdocs/verifysky/worker
npm install
npm run typecheck
```

## 2) بيانات الدخول التجريبية الثابتة

### عميل تجريبي

- البريد: `user@verifysky.test`
- كلمة المرور: `User123!`

### أدمن تجريبي

- البريد: `admin@verifysky.test`
- كلمة المرور: `Admin123!`

## 3) ماذا تفعل إذا لم تعمل بيانات الدخول

أعد زرع البيانات مع فرض إعادة كلمات المرور:

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
SEED_RESET_PASSWORDS=true php artisan db:seed
```

## 4) أوامر الاختبار

من داخل `dashboard`:

```bash
composer test
composer lint
```

ومن داخل `worker`:

```bash
cd /opt/lampp/htdocs/verifysky/worker
npm run typecheck
```

## 5) فحص سريع يدوي

### كعميل

سجل الدخول بالحساب:

- `user@verifysky.test`
- `User123!`

ثم اختبر:

- `/dashboard`
- `/billing`
- `/domains`
- `/firewall`
- `/logs`

### كأدمن

سجل الدخول بالحساب:

- `admin@verifysky.test`
- `Admin123!`

ثم اختبر:

- `/admin`
- `/admin/tenants`
- صفحة tenant details
- زر `View as Customer`
