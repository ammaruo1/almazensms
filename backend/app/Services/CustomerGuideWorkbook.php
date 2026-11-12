<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class CustomerGuideWorkbook
{
    public function binary(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is required to build the customer guide.');
        }

        $path = tempnam(sys_get_temp_dir(), 'guide');
        if ($path === false) {
            throw new RuntimeException('Unable to build the customer guide.');
        }
        unlink($path);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Unable to build the customer guide.');
        }

        $sheets = $this->sheets();
        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook(array_keys($sheets)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());

        $index = 1;
        foreach ($sheets as $rows) {
            $zip->addFromString('xl/worksheets/sheet'.$index.'.xml', $this->worksheet($rows));
            $index++;
        }

        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        if ($bytes === false) {
            throw new RuntimeException('Unable to build the customer guide.');
        }

        return $bytes;
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: string}>>
     */
    public function sheets(): array
    {
        $base = rtrim((string) config('app.url'), '/').'/api/v1';

        return [
            'ابدأ هنا' => [
                ['ما هذه الخدمة', 'بوابة ألمازن تقبل طلب الرسالة ثم يسلّمه جهاز البوابة. قبول الطلب ليس دليلاً على أن المستلم استلم الرسالة.', ''],
                ['العنوان', 'أضف مسارات هذه الأوراق إلى عنوان الواجهة الذي سلمه لك المدير. إن كان العنوان أدناه عنوان تجربة، استبدله بعنوان الإنتاج.', $base],
                ['المسار الثابت', 'كل خدمات العميل تبدأ بـ /api/v1.', '/api/v1'],
                ['المفتاح', 'يُرسل في الترويسة X-API-Key ولا يوضع في رابط الطلب حتى لا يُحفظ في السجلات.', 'X-API-Key: YOUR_API_KEY'],
                ['حالة queued', 'تعني أن الطلب حُفظ وقُبل في الطابور. الرسالة لم تُسلَّم بعد.', 'queued'],
                ['ما لا يرجع', 'استجابات المتابعة لا تعيد نص الرسالة ولا رقم الهاتف الكامل.', ''],
                ['هذا الملف', 'دليل استخدام فقط. لا يحتوي مفتاحاً حقيقياً. استبدل YOUR_API_KEY بالمفتاح الذي كشفه لك المدير.', 'YOUR_API_KEY'],
            ],
            'المصادقة' => [
                ['الترويسة', 'اسم الترويسة X-API-Key وقيمتها المفتاح الكامل الذي يبدأ بـ azm_live_.', 'X-API-Key: YOUR_API_KEY'],
                ['شكل المفتاح', 'المفتاح سلسلة واحدة. لا تقطعها ولا تضف مسافات ولا تضعها بين علامتي اقتباس داخل الترويسة إلا إذا طلبت ذلك أداة الإرسال.', 'azm_live_…'],
                ['HTTPS', 'في الإنتاج تُرفض الطلبات عبر HTTP. استخدم https فقط.', 'https://'],
                ['مفتاح ملغى أو ناقص', 'المفتاح المفقود أو غير المعروف أو الملغى يرفض الطلب.', '401'],
                ['مكان المفتاح', 'الترويسة فقط. لا ترسله في جسم JSON ولا في query string.', ''],
            ],
            'إرسال رسالة' => [
                ['الطريقة والمسار', 'ينشئ طلباً جديداً ولا يرسل الرسالة بنفسه إلى شبكة الجوال.', 'POST '.$base.'/messages'],
                ['to', 'رقم بصيغة E.164: علامة + ثم من 8 إلى 15 رقماً، وأول رقم بعد + ليس صفراً.', '+967711234567'],
                ['body', 'نص الرسالة. مطلوب، وحدّه 5000 حرفاً.', 'مرحبا'],
                ['Idempotency-Key', 'ترويسة مطلوبة طولها من 8 إلى 128 حرفاً. المسموح: الأحرف الإنجليزية والأرقام والنقطة والشرطة والشرطة السفلية والنقطتان.', 'order-2026-0001'],
                ['202', 'قُبل طلب جديد وخُصمت الحصة. الحالة queued والحقل duplicate قيمته false.', '{"duplicate":false}'],
                ['200', 'نفس Idempotency-Key مع نفس الرقم ونفس النص يعيد الرسالة الأصلية ولا يخصم الحصة مرة ثانية.', '{"duplicate":true}'],
                ['409', 'نفس Idempotency-Key مع رقم أو نص مختلف.', 'Idempotency-Key was already used for a different payload.'],
                ['422', 'حقل ناقص، أو رقم غير صالح، أو ترويسة عدم التكرار مفقودة أو خارج النمط.', ''],
                ['429', 'نفدت الحصة اليومية للمفتاح أو سقف المشروع أو حد الدقيقة. إعادة الطلب المطابق لا تُرفض بـ 429 بسبب الحصة.', ''],
                ['جسم الطلب', 'JSON مع Content-Type: application/json.', '{"to":"+967711234567","body":"مرحبا"}'],
            ],
            'متابعة الحالة' => [
                ['الطريقة', 'اقرأ حالة رسالة تخص مشروع هذا المفتاح.', 'GET '.$base.'/messages/MESSAGE_ID'],
                ['200', 'يعيد المعرف والحالة ووقت الإنشاء وعدد المحاولات ومعرف الجهاز وسجل الأحداث. لا يعيد النص ولا الرقم.', 'data.status'],
                ['404', 'لا توجد رسالة بهذا المعرف داخل مشروع المفتاح.', ''],
                ['queued', 'قُبلت وبانتظار الجهاز. ليست دليلاً على التسليم.', ''],
                ['assigned', 'عُيّنت لجهاز البوابة.', ''],
                ['accepted_by_device', 'قبلها الجهاز وما زالت قيد المعالجة.', ''],
                ['sending', 'بدأ الجهاز خطوة الإرسال.', ''],
                ['sent_to_network', 'سُلّمت إلى شبكة الجوال. هذا لا يثبت أن المستلم استلمها أو قرأها.', ''],
                ['send_failed', 'فشل الإرسال من الجهاز.', ''],
                ['delivery_reported', 'أبلغ الجهاز عن تقرير تسليم من الشبكة.', ''],
                ['delivery_failed', 'أبلغ الجهاز عن فشل التسليم.', ''],
                ['unknown', 'النتيجة غير مؤكدة. لا تُعاد الرسالة تلقائياً.', ''],
                ['cancelled', 'أُلغيت قبل اكتمال الإرسال، ومنها سحب رسالة التحقق عندما يكون السحب ممكناً.', ''],
            ],
            'طلب OTP' => [
                ['التفعيل', 'الخدمة متوقفة حتى يفعّلها المدير للمشروع. الطلب على مشروع متوقف عن التحقق يرجع 403 ولا يُحفظ شيء.', '403'],
                ['الطريقة', 'يطلب رمزاً ويمر من مسار الرسائل نفسه، بما فيه الحصة.', 'POST '.$base.'/otp/request'],
                ['to', 'رقم E.164 الذي تصله الرسالة.', '+967711234567'],
                ['purpose', 'غرض اختياري حتى 32 حرفاً من الحروف والأرقام و _ . : - . الافتراضي login. استخدم الغرض نفسه عند التحقق.', 'login'],
                ['الرمز', 'الخادم ينشئ رمزاً من 6 أرقام. لا يرجع في الاستجابة. يصل داخل نص الرسالة فقط.', ''],
                ['Idempotency-Key', 'مطلوبة. نفس المفتاح مع نفس الرقم والغرض يعيد التحدي الأصلي ولا يخصم الحصة مرة أخرى.', 'otp-2026-0001'],
                ['202', 'قُبل التحدي والرسالة. الاستجابة فيها معرف التحدي والحالة والغرض ووقت الانتهاء ومعرف الرسالة، بلا رمز وبلا رقم.', ''],
                ['200', 'إعادة مطابقة لنفس الطلب.', '{"duplicate":true}'],
                ['409', 'نفس مفتاح عدم التكرار لرقم أو غرض مختلف.', ''],
                ['422', 'البيانات أو الترويسة غير صالحة.', ''],
                ['429', 'الحصة نفدت أو فترة الانتظار بين إعادة الإرسال لم تنته. لا يُحفظ طلب جديد.', ''],
                ['جسم الطلب', '', '{"to":"+967711234567","purpose":"login"}'],
            ],
            'التحقق والإلغاء' => [
                ['التحقق', 'يفحص الرمز الذي وصل في الرسالة مقابل آخر تحدٍ معلّق لنفس المشروع والرقم والغرض.', 'POST '.$base.'/otp/verify'],
                ['الحقول', 'to وcode من 6 أرقام، وpurpose إن أرسلته عند الطلب. الافتراضي login.', '{"to":"+967711234567","code":"123456","purpose":"login"}'],
                ['200', 'التحقق نجح. verified قيمتها true والحالة verified. الرمز لا يُعاد.', 'verified'],
                ['422', 'فشل التحقق. حالة البيانات تكون invalid أو expired أو exhausted. الرمز الذي أرسلته لا يُعاد في الخطأ.', ''],
                ['الحصة', 'التحقق لا يخصم حصة الرسائل.', ''],
                ['403', 'التحقق غير مفعّل لهذا المشروع.', ''],
                ['الإلغاء', 'يلغي التحدي ويحاول سحب الرسالة إن كان ذلك ما زال ممكناً.', 'POST '.$base.'/otp/CHALLENGE_ID/cancel'],
                ['200', 'أُلغي التحدي. message_recalled تكون true إذا سُحبت الرسالة قبل أن تُحجز حالة sending.', 'message_recalled'],
                ['404', 'التحدي ليس ضمن مشروع هذا المفتاح.', ''],
                ['409', 'أُغلق التحدي لكن الرسالة لا تُسحب بعد بدء الإرسال. message_recalled تكون false، وقد تكون الرسالة خرجت إلى الشبكة.', 'message_recalled: false'],
                ['إعادة الإلغاء', 'إلغاء تحدٍ ملغى مسبقاً يعيد النتيجة نفسها بلا أثر إضافي.', ''],
            ],
            'الحصص والأخطاء' => [
                ['حد المفتاح اليومي', 'لكل مفتاح حد رسائل في يوم UTC. المفتاح الجديد يبدأ غالباً بـ 20 رسالة ما لم يغيّر المدير ذلك.', '20'],
                ['سقف المشروع', 'حد يومي إضافي مشترك بين كل مفاتيح المشروع.', ''],
                ['المتبقي الفعّال', 'الأقل بين متبقي حد المفتاح ومتبقي سقف المشروع.', ''],
                ['حد الدقيقة', 'عدد الطلبات الجديدة المقبولة خلال الدقيقة لهذا المفتاح وحده. القيمة الشائعة عند الإنشاء 10.', '10'],
                ['ما يُحتسب', 'إرسال رسالة وطلب رمز تحقق يخصمان الحصة. التحقق من الرمز لا يخصمها. إعادة الطلب المطابق لا تخصمها مرة ثانية.', ''],
                ['429', 'الحد اليومي أو سقف المشروع أو حد الدقيقة أو فترة إعادة الرمز منعت الطلب.', '429'],
                ['401', 'المفتاح مفقود أو غير صالح أو ملغى.', '401'],
                ['409', 'تعارض مفتاح عدم التكرار، أو تعذر سحب رسالة التحقق بعد بدء الإرسال.', '409'],
                ['422', 'البيانات أو ترويسة Idempotency-Key غير صالحة.', '422'],
                ['403', 'خدمة التحقق غير مفعّلة لهذا المشروع.', '403'],
                ['اليوم', 'حدود اليوم تُحسب بتوقيت UTC لا بتوقيت الهاتف.', 'UTC'],
            ],
            'أمثلة جاهزة' => [
                ['إرسال رسالة', 'استبدل YOUR_API_KEY والرقم والنص ومفتاح عدم التكرار.', 'curl -X POST "'.$base.'/messages" -H "X-API-Key: YOUR_API_KEY" -H "Idempotency-Key: order-2026-0001" -H "Content-Type: application/json" -d "{\"to\":\"+967711234567\",\"body\":\"مرحبا\"}"'],
                ['متابعة الحالة', 'ضع معرف الرسالة الذي رجع في data.id.', 'curl "'.$base.'/messages/MESSAGE_ID" -H "X-API-Key: YOUR_API_KEY"'],
                ['طلب رمز', 'الرمز لن يظهر في ناتج هذا الأمر. اقرأه من الرسالة التي تصل إلى الرقم.', 'curl -X POST "'.$base.'/otp/request" -H "X-API-Key: YOUR_API_KEY" -H "Idempotency-Key: otp-2026-0001" -H "Content-Type: application/json" -d "{\"to\":\"+967711234567\",\"purpose\":\"login\"}"'],
                ['التحقق من الرمز', 'code هو الرمز الذي وصل في الرسالة.', 'curl -X POST "'.$base.'/otp/verify" -H "X-API-Key: YOUR_API_KEY" -H "Content-Type: application/json" -d "{\"to\":\"+967711234567\",\"code\":\"123456\",\"purpose\":\"login\"}"'],
                ['إلغاء التحدي', 'ضع معرف التحدي الذي رجع عند الطلب.', 'curl -X POST "'.$base.'/otp/CHALLENGE_ID/cancel" -H "X-API-Key: YOUR_API_KEY"'],
            ],
        ];
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  list<string>  $names
     */
    private function workbook(array $names): string
    {
        $sheets = '';
        foreach ($names as $index => $name) {
            $id = $index + 1;
            $sheets .= '<sheet name="'.$this->xml($name).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(int $sheetCount): string
    {
        $rels = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $rels .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
        }
        $styleId = $sheetCount + 1;
        $rels .= '<Relationship Id="rId'.$styleId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><name val="Calibri"/></font><font><sz val="11"/><name val="Consolas"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" readingOrder="2" vertical="top"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" readingOrder="2" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" readingOrder="1" vertical="top"/></xf>'
            .'</cellXfs></styleSheet>';
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    private function worksheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>'
            .'<cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="72" customWidth="1"/><col min="3" max="3" width="78" customWidth="1"/></cols>'
            .'<sheetData>';
        $xml .= $this->row(1, ['البند', 'الشرح', 'مثال'], true);
        foreach ($rows as $offset => $row) {
            $xml .= $this->row($offset + 2, $row, false);
        }
        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $cells
     */
    private function row(int $number, array $cells, bool $header): string
    {
        $lines = 1;
        foreach ($cells as $cell) {
            $lines = max($lines, (int) ceil(max(1, mb_strlen($cell)) / 42));
        }
        $height = min(140, max(20, $lines * 16));
        $letters = ['A', 'B', 'C'];
        $xml = '<row r="'.$number.'" ht="'.$height.'" customHeight="1">';
        foreach ($cells as $index => $cell) {
            $style = $header ? 1 : ($index === 2 ? 2 : 0);
            $xml .= '<c r="'.$letters[$index].$number.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.$this->xml($cell).'</t></is></c>';
        }
        $xml .= '</row>';

        return $xml;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
