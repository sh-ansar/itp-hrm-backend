@php
    $seller = $snapshot['seller'] ?? [];
    $buyer = $snapshot['buyer'] ?? [];
    $plan = $snapshot['plan'] ?? [];
    $months = (int) ($snapshot['months'] ?? 1);
    $planName = $plan['terms']['names']['ru'] ?? ($plan['code'] ?? 'ITP HRM');
    $price = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ');
@endphp
<!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Счёт № {{ $invoice->number }} — ITP Кадры</title>
<style>
@page { margin: 35px 39px 45px 39px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.55; color: #1e3644; }
h1 { margin: 0 0 4px; font-size: 22px; font-weight: bold; color:#153b51; }
h2 { margin: 26px 0 10px; font-size: 13px; color:#153b51; }
.header { border-bottom:2px solid #0d8fc9; padding-bottom: 17px; margin-bottom: 21px; }
.subtitle { color:#57778b;font-size: 10px; }
.meta { margin:16px 0 26px; }
.meta td { padding:5px 12px 5px 0; }
.parties { width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:30px; }
.parties td { padding:12px 14px;vertical-align:top;border:1px solid #dce8ef;width:50%; }
.parties strong { color:#315c71; font-size:11px; }
.lines { width:100%;border-collapse:collapse;table-layout:fixed; }
.lines th { background:#eef5f9;color:#315c71;text-align:left; font-weight:bold; }
.lines th,.lines td { border-bottom:1px solid #dce8ef; padding:11px 10px; }
.numbers { width:100%;margin-top:16px; border-collapse:collapse; }
.numbers td { padding:6px 10px; }
.numbers .label { text-align:right; }
.numbers .amount { text-align:right;width:160px; }
.numbers .grand td { border-top:2px solid #8fc8df; padding-top:12px; font-size:13px;font-weight:bold; }
.payment { margin-top:30px; padding:16px 20px;background:#f2f7fa; border-left:3px solid #008fca; }
.footer { position:fixed;bottom:-15px;left:0;right:0;color:#6b8290;font-size:9px;border-top:1px solid #d7e5eb;padding-top:10px; }
.notes { margin-top:12px; font-size:9px;color:#57778b; }
</style>
</head>
<body>
<div class="header">
  <h1>Счёт на оплату № {{ $invoice->number }}</h1>
  <div class="subtitle">ITP Кадры / ITP HRM · Корпоративная система кадрового учёта</div>
</div>

<table class="meta">
<tr><td>Дата выставления</td><td><strong>{{ substr((string) $invoice->issued_at, 0, 10) }}</strong></td></tr>
<tr><td>Оплатить до</td><td><strong>{{ $invoice->due_date }}</strong></td></tr>
<tr><td>Валюта</td><td>{{ $invoice->currency }}</td></tr>
<tr><td>Тарифная версия</td><td>{{ $snapshot['subscription']['code'] ?? '' }} · v{{ $snapshot['subscription']['version'] ?? '' }}</td></tr>
</table>

<table class="parties">
<tr>
<td>
<strong>Исполнитель</strong><br><br>
{{ $seller['legal_name'] ?? 'ITP Mining' }}<br>
БИН/рег. номер: {{ $seller['registration_number'] ?? '' }}<br>
{{ $seller['address'] ?? '' }}<br>
Банк: {{ $seller['bank'] ?? '' }}<br>
IBAN: {{ $seller['iban'] ?? '' }}<br>
БИК: {{ $seller['bic'] ?? '' }}<br>
Подписант: {{ $seller['signatory'] ?? '' }}
</td>
<td>
<strong>Заказчик</strong><br><br>
{{ $buyer['legal_name'] ?? '' }}<br>
БИН/рег. номер: {{ $buyer['registration_number'] ?? '' }}<br>
{{ $buyer['address'] ?? '' }}
</td>
</tr>
</table>

<h2>Услуги и стоимость</h2>
<table class="lines">
<thead>
<tr><th>№</th><th>Наименование услуги</th><th>Период</th><th>Стоимость</th></tr>
</thead>
<tbody>
<tr><td>1</td>
<td>{{ $seller['service_name'] ?? 'Доступ к программной платформе ITP Кадры' }}<br>
Тариф «{{ $planName }}»</td>
<td>{{ $months }} мес.</td>
<td>{{ $price((int) $invoice->total_minor) }} {{ $invoice->currency }}</td>
</tr>
</tbody>
</table>
<table class="numbers">
<tr><td class="label">Стоимость без НДС</td><td class="amount">{{ $price((int) $invoice->subtotal_minor) }} {{ $invoice->currency }}</td></tr>
<tr><td class="label">НДС в том числе</td><td class="amount">{{ $price((int) $invoice->tax_minor) }} {{ $invoice->currency }}</td></tr>
<tr class="grand"><td class="label">Всего к оплате</td><td class="amount">{{ $price((int) $invoice->total_minor) }} {{ $invoice->currency }}</td></tr>
</table>

<div class="payment">
<strong>Назначение платежа:</strong><br>
Оплата услуг ITP HRM по счёту № {{ $invoice->number }}.
Указывайте номер счёта в банковском переводе.
</div>
<div class="notes">
Документ сформирован из зафиксированных реквизитов и версии тарифных условий.
Выставление счёта и загрузка платёжного поручения не подтверждают оплату;
подписка активируется только после банковской сверки уполномоченным лицом.
</div>
<div class="footer">
ТОО «ITP Mining» · info@itpmining.kz · ITP Кадры · {{ $invoice->number }}
</div>
</body>
</html>
