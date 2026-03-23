# Trap Blueprint (المصيدة) — Edge Shield / YouCaptcha

هذا الملف هو مرجع التشغيل والتطوير للمصيدة الحالية داخل المشروع، بهدف:
- توثيق ما تم بناؤه بالضبط.
- منع كسر المصيدة عند أي تعديل مستقبلي.
- توضيح ما هو "تمويه" وما هو "أمن حقيقي".

---

## 1) الهدف التشغيلي

لدينا دورين للدومين:
1. **واجهة تسويقية وهمية/مضللة (SPA Landing)** على `youcaptcha.com`.
2. **نظام الحماية الحقيقي** (Challenge + Telemetry + Turnstile + Session binding) في Worker.

الهدف: إرباك المهاجم في التحليل الأولي، مع بقاء الحكم الأمني الحقيقي دائمًا في السيرفر.

---

## 2) مكونات النظام

### A) Worker (`worker/`)
- يصدر صفحة Challenge مخصصة.
- يتحقق من:
  - `nonce` one-time
  - توقيع المسار
  - `X-ES-Nonce`
  - تطابق IP/UA
  - Telemetry
  - Turnstile server-side
- يربط حل التحدي بكوكي جلسة challenge قصيرة العمر (`es_challenge`).
- يضيف عناصر تمويه في المصدر (`__ES_DECOY` وmeta decoys).

### B) Dashboard (`dashboard/`)
- Laravel admin panel الحقيقي.
- الصفحة الرئيسية `/` أصبحت Landing SPA تسويقية.
- نموذج الاشتراك يحفظ البيانات في جدول `trap_leads`.
- صفحة داخل الأدمن: **شبكة المتصيدين** لعرض الطلبات الملتقطة.
- مسار تسجيل الدخول الحقيقي **ليس `/login`**، بل مسار مخفي (افتراضيًا: `/wow/login`) وقابل للتغيير من الإعدادات.

---

## 3) ما هو تمويه vs ما هو أمني فعلي

### تمويه (Decoy)
- محتوى تسويقي في الصفحة الرئيسية.
- نصوص "كيف نعمل" وأسعار غير مرتبطة بالأسرار الفعلية.
- عناصر source decoy مثل `__ES_DECOY` وmeta عشوائية.
- obfuscation لكود الواجهة.

### أمني فعلي
- تحقق السيرفر من Turnstile.
- nonce one-time + expiry.
- path signature verification.
- cookie/session binding.
- telemetry validation.
- rate limiting / ban logic.

قاعدة ذهبية: **لا تعتمد أمنيًا على التمويه فقط**.

---

## 4) المسارات الحساسة الحالية

### Dashboard
- Landing: `/`
- Lead submit: `POST /contact/interest`
- Admin login (الحالي): `/wow/login`
- Trap leads view: `/trap-network`

### Worker
- Health: `/es-health`
- Dynamic verify: `/es-verify/<nonce-prefix>`

---

## 5) قاعدة "لا تكسر المصيدة" عند التعديل

قبل أي Deploy، راجع التالي:
1. لا تعيد مسار الدخول إلى `/login`.
2. لا تعرض أسرار حقيقية في Landing أو README.
3. لا تعطل `es_challenge` binding.
4. لا تعطل Turnstile server-side verify.
5. لا تحذف `trap_leads` أو صفحة "شبكة المتصيدين".
6. لا تستبدل التحقق السيرفري بواجهة عميل.

---

## 6) Obfuscation Pipeline (مهم)

تم اعتماد pipeline تلقائي قبل النشر:
- المصدر للمطور: `worker/frontend/slider.js` (مقروء).
- التوليد: `worker/scripts/build-slider.mjs`.
- الناتج: `worker/src/generated/slider-obfuscated.ts`.
- `deploy` يشغل `predeploy -> build:slider` تلقائيًا.

هذا يحافظ على:
- سهولة الصيانة داخليًا.
- صعوبة التحليل خارجيًا.

---

## 7) سبب اختلاف السلوك بين دومينات

قد يعمل challenge على دومين ويفشل على آخر بسبب:
- اختلاف `turnstile_sitekey` وقيود hostname.
- اختلاف `security_mode` (`aggressive` vs `balanced`).
- إعدادات المتصفح/الإضافات/الخصوصية.

عند الشك:
1. راجع `domain_configs`.
2. راجع `security_logs` (`turnstile_failed`, `challenge_failed`, `challenge_solved`).

---

## 8) Runbook سريع للتشخيص

### إذا المستخدم قال "تعذر التحقق"
1. افحص آخر logs في D1.
2. حدد نوع الفشل:
   - `turnstile_failed`
   - `x_mismatch`
   - `telemetry_rejected`
   - `challenge cookie validation failed`
3. أصلح السبب الجذري ثم أعد الاختبار.

### إذا الدخول للأدمن فشل
1. تأكد من المسار المخفي الحالي (routes).
2. راجع `DASHBOARD_ADMIN_USER/PASS`.
3. إذا كلمة المرور فيها `#` أو رموز خاصة، استخدم اقتباس في `.env`.

---

## 9) توسيعات مقترحة لاحقًا

1. تنبيه فوري (Telegram/Email) عند lead جديد.
2. تصدير CSV من صفحة "شبكة المتصيدين".
3. تدوير دوري لمسار الدخول المخفي.
4. إضافة honeypot fields خفية في نموذج الاشتراك.

---

## 10) ملاحظة حوكمة

أي تعديل يمس:
- مسار الدخول المخفي
- منطق challenge
- التحقق السيرفري
- أو نماذج الالتقاط

يجب تحديث هذا الملف مباشرة في نفس الـcommit.

