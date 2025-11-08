<?php
/**
 * Преобразует сумму BYN в слова: "сто четыре рубля пятьдесят копеек".
 * Поддержка: до 1 000 000 BYN, отрицательных, "." или "," как разделитель.
 */
function bynToWords($amount) {
    // Нормализуем ввод
    if (is_string($amount)) {
        $s = str_replace(' ', '', $amount);
        $s = str_replace(',', '.', $s);
    } else {
        $s = (string)$amount;
    }

    $isNegative = false;
    if (strlen($s) && $s[0] === '-') {
        $isNegative = true;
        $s = substr($s, 1);
    }

    // Считаем всё в копейках, чтобы избежать ошибок float
    // Разрешаем до 2 знаков после точки: округляем до копеек.
    if ($s === '' || $s === '.') $s = '0';
    $totalKop = (int) round((float)$s * 100);

    if ($totalKop < 0) $totalKop = -$totalKop;

    $rub = intdiv($totalKop, 100);
    $kop = $totalKop % 100;

    if ($rub > 1000000) {
        return 'Поддерживаются суммы не больше 1 000 000 BYN.';
    }

    $parts = [];
    if ($isNegative && ($rub > 0 || $kop > 0)) {
        $parts[] = 'минус';
    }

    // Рубли
    $rubWords = integerToWordsRu($rub, /*female*/false);
    $parts[] = $rubWords;
    $parts[] = morph($rub, 'рубль', 'рубля', 'рублей');

    // Копейки
    if ($kop > 0) {
        $parts[] = integerToWordsRu($kop, /*female*/true);
        $parts[] = morph($kop, 'копейка', 'копейки', 'копеек');
    } else {
        // Часто в договорах копейки пишут словом даже если 00 — по желанию можно убрать блок ниже.
        // Закомментируй эти две строки, если нужно выводить только рубли при .00
        $parts[] = 'ноль';
        $parts[] = 'копеек';
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

/**
 * Целое число 0..1_000_000 в слова. $female=true — женский род для единиц.
 */
function integerToWordsRu($n, $female = false) {
    if ($n === 0) return 'ноль';
    if ($n === 1000000) return 'один миллион';

    $ones_m = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    $ones_f = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    $teens  = [10=>'десять',11=>'одиннадцать',12=>'двенадцать',13=>'тринадцать',14=>'четырнадцать',
               15=>'пятнадцать',16=>'шестнадцать',17=>'семнадцать',18=>'восемнадцать',19=>'девятнадцать'];
    $tens   = ['', 'десять', 'двадцать', 'тридцать', 'сорок', 'пятьдесят',
               'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    $hunds  = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот',
               'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

    $res = [];

    // тысячи
    if ($n >= 1000) {
        $th = intdiv($n, 1000);
        $n  = $n % 1000;

        $res[] = chunkToWords($th, /*female*/true, $ones_m, $ones_f, $teens, $tens, $hunds);
        $res[] = morph($th, 'тысяча', 'тысячи', 'тысяч');
    }

    // остаток
    if ($n > 0) {
        $res[] = chunkToWords($n, $female, $ones_m, $ones_f, $teens, $tens, $hunds);
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ', $res)));
}

/** Кусок 1..999 в слова */
function chunkToWords($num, $female, $ones_m, $ones_f, $teens, $tens, $hunds) {
    $parts = [];

    if ($num >= 100) {
        $parts[] = $hunds[intdiv($num, 100)];
        $num %= 100;
    }

    if ($num >= 20) {
        $parts[] = $tens[intdiv($num, 10)];
        $u = $num % 10;
        if ($u > 0) {
            $parts[] = ($female ? $ones_f[$u] : $ones_m[$u]);
        }
    } elseif ($num >= 10) {
        $parts[] = $teens[$num];
    } elseif ($num > 0) {
        $parts[] = ($female ? $ones_f[$num] : $ones_m[$num]);
    }

    return implode(' ', $parts);
}

/** Склонение по числу: 1, 2–4, 5–0 */
function morph($n, $form1, $form2, $form5) {
    $n = abs($n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) return $form5;
    if ($n1 > 1 && $n1 < 5) return $form2;
    if ($n1 == 1) return $form1;
    return $form5;
}



$webhookUrl = 'https://b24-sts9fm.bitrix24.ru/rest/1/9qwqilkhmijqjrch/';

// Коды пользовательских полей (замени на свои UF_... из CRM)
$FIELD_BALANCE      = 'UF_CRM_1762629678';       // Остаток (число)
$FIELD_BALANCE_TEXT = 'UF_CRM_1762629239';  // Остаток прописью
// =====================

// Проверяем, что передан ID сделки (?deal_id=123)
if (empty($_GET['deal_id'])) {
    exit('no deal_id');
}
$dealId = (int)$_GET['deal_id'];

// --- Вспомогательная функция для запросов к Bitrix24 ---
function callB24($baseUrl, $method, $params = [])
{
    $query = http_build_query($params);
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $query,
            'timeout' => 5,
        ]
    ];
    $context = stream_context_create($opts);
    $res = file_get_contents($baseUrl . $method, false, $context);
    return $res ? json_decode($res, true) : null;
}

// 1. Получаем сделку
$deal = callB24($webhookUrl, 'crm.deal.get.json', ['id' => $dealId]);
if (!$deal || empty($deal['result'])) {
    exit('deal not found');
}
$data = $deal['result'];

// 2. Получаем значение поля Остаток
$balance = isset($data[$FIELD_BALANCE]) ? $data[$FIELD_BALANCE] : null;

// 3. Если значение есть, переводим в текст
if ($balance !== null && $balance !== '' && $balance != 0) {
    $text = bynToWords($balance);

    // 4. Обновляем поле "Остаток прописью"
    callB24($webhookUrl, 'crm.deal.update.json', [
        'id' => $dealId,
        'fields' => [
            $FIELD_BALANCE_TEXT => $text,
        ],
    ]);

    echo "Остаток обновлён: $text";
} else {
    echo "Нет значения в поле Остаток";
}