<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HrmBillingService;
use App\Services\HrmPlatformAccess as Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HrmPaymentController extends Controller
{
    private function requireCompanyAdmin(Request $request, string $company): void
    {
        abort_unless($request->user(), 401);
        abort_unless(DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('role_code', 'company_admin')->exists(), 403);
    }

    private function invoice(int $invoice, ?string $company = null): object
    {
        $q = DB::table('hrm_invoices')->where('id', $invoice);
        if ($company) $q->where('company_id', $company);
        $row = $q->first();
        abort_unless($row, 404);
        return $row;
    }

    public function checkout(Request $request, string $company)
    {
        $this->requireCompanyAdmin($request, $company);
        $data = $request->validate([
            'plan_version_id' => 'required|integer|exists:hrm_plan_versions,id',
            'months' => 'sometimes|integer|min:1|max:120',
        ]);
        $invoice = app(\App\Services\HrmInvoiceCreator::class)->create(
            $company, (int) $data['plan_version_id'],
            (int) ($data['months'] ?? 1), now()->addDays(14)->toDateString(),
            (int) $request->user()->getAuthIdentifier(), true, true
        );
        return response()->json(['data' => [
            'id' => $invoice->id, 'number' => $invoice->number,
            'status' => $invoice->status, 'due_date' => $invoice->due_date,
            'total_minor' => $invoice->total_minor, 'currency' => $invoice->currency,
        ]], 201);
    }

    public function myInvoices(Request $request, string $company)
    {
        $this->requireCompanyAdmin($request, $company);
        return response()->json(DB::table('hrm_invoices')
            ->where('company_id', $company)->where('status', '<>', 'draft')
            ->select('id', 'number', 'status', 'total_minor', 'currency', 'due_date', 'issued_at', 'paid_at')
            ->orderByDesc('id')->paginate(30));
    }

    public function mySubscription(Request $request, string $company)
    {
        $this->requireCompanyAdmin($request, $company);
        $row = DB::table('hrm_company_subscriptions as s')
            ->join('hrm_plan_versions as p', 'p.id', '=', 's.plan_version_id')
            ->where('s.company_id', $company)
            ->select('s.status', 's.starts_at', 's.ends_at', 's.snapshot', 'p.code', 'p.version')
            ->first();
        if ($row) {
            $row->snapshot = json_decode($row->snapshot ?: '{}', true);
            $row->effective_status = $row->status === 'active' && $row->ends_at && now()->gte($row->ends_at)
                ? 'expired' : $row->status;
        }
        return response()->json([
            'data' => $row,
            'usage' => [
                'users' => DB::table('company_user_roles')->where('company_id', $company)->count(),
                'employees' => DB::table('employees')->where('company_id', $company)->count(),
                'storage_bytes' => app(\App\Services\HrmSaasEntitlements::class)->storageUsed($company),
            ],
            'enforcement_enabled' => (bool) config('hrm.saas_enforcement_enabled', false),
            'billing_enabled' => (bool) config('hrm.saas_billing_enabled', false),
        ]);
    }

    public function uploadAdmin(Request $request, int $invoice)
    {
        Access::require($request, 'billing.write');
        return $this->upload($request, $invoice, null);
    }

    public function uploadCompany(Request $request, string $company, int $invoice)
    {
        $this->requireCompanyAdmin($request, $company);
        return $this->upload($request, $invoice, $company);
    }

    private function upload(Request $request, int $invoice, ?string $company)
    {
        abort_unless(config('hrm.saas_billing_enabled', false), 409, 'Billing not activated');
        $row = $this->invoice($invoice, $company);
        abort_unless(in_array($row->status, ['issued', 'awaiting_payment', 'overdue'], true), 409);
        $data = $request->validate([
            'file' => 'required|file|max:8192|mimetypes:application/pdf,image/jpeg,image/png',
        ]);
        $file = $data['file'];
        $head = file_get_contents($file->getRealPath(), false, null, 0, 8);
        $mime = $file->getMimeType();
        abort_unless(
            ($mime === 'application/pdf' && str_starts_with($head, '%PDF-'))
            || ($mime === 'image/jpeg' && str_starts_with($head, "\xFF\xD8"))
            || ($mime === 'image/png' && $head === "\x89PNG\r\n\x1A\n"),
            422, 'Invalid payment document contents'
        );
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime];
        $path = 'hrm-payment-proofs/'.$invoice.'/'.Str::random(48).'.'.$extension;
        $disk = Storage::disk('local');
        abort_unless($disk->putFileAs(dirname($path), $file, basename($path)) === $path, 503);
        try {
            $id = DB::transaction(function () use ($request, $invoice, $company, $row, $path, $mime, $file, $hash): int {
                $current = DB::table('hrm_invoices')->where('id', $invoice)->lockForUpdate()->first();
                abort_unless($current && $current->company_id === $row->company_id, 409);
                abort_unless(in_array($current->status, ['issued', 'awaiting_payment', 'overdue'], true), 409);
                abort_unless(!DB::table('hrm_payment_proofs')
                    ->where('invoice_id', $invoice)->where('sha256', $hash)->exists(),
                    409, 'The same proof was already uploaded');
                $id = DB::table('hrm_payment_proofs')->insertGetId([
                    'invoice_id' => $invoice,
                    'user_id' => $request->user()->getAuthIdentifier(),
                    'path' => $path, 'sha256' => $hash, 'mime' => $mime,
                    'size' => $file->getSize(), 'status' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                Access::audit((int) $request->user()->getAuthIdentifier(), $row->company_id,
                    'payment-proof.uploaded', 'proof:'.$id, ['invoice_id' => $invoice, 'sha256' => $hash]);
                return $id;
            }, 3);
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
        return response()->json(['data' => ['id' => $id, 'status' => 'pending']], 201);
    }

    public function adminProofs(Request $request, int $invoice)
    {
        Access::require($request, 'billing.read');
        $this->invoice($invoice);
        return $this->proofs($invoice);
    }

    public function companyProofs(Request $request, string $company, int $invoice)
    {
        $this->requireCompanyAdmin($request, $company);
        $this->invoice($invoice, $company);
        return $this->proofs($invoice);
    }

    private function proofs(int $invoice)
    {
        return response()->json(['data' => DB::table('hrm_payment_proofs')
            ->where('invoice_id', $invoice)
            ->select('id', 'status', 'mime', 'size', 'created_at', 'reviewed_at', 'review_note')
            ->orderByDesc('id')->get()]);
    }

    public function review(Request $request, int $invoice, int $proof, HrmBillingService $billing)
    {
        $actor = Access::require($request, 'billing.write');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'review_note' => 'required|string|min:12|max:500',
            'bank_reference' => 'required_if:decision,accepted|nullable|string|min:5|max:120',
            'received_amount_minor' => 'required_if:decision,accepted|nullable|integer|min:1',
        ]);
        if ($data['decision'] === 'rejected') {
            $billing->reject($invoice, $proof, $actor, $data['review_note']);
            return response()->json(['status' => 'rejected']);
        }
        $paid = $billing->confirm($invoice, $proof, $actor, $data['bank_reference'],
            (int) $data['received_amount_minor'], $data['review_note']);
        return response()->json(['data' => [
            'id' => $paid->id, 'status' => $paid->status, 'paid_at' => $paid->paid_at,
        ]]);
    }

    public function adminProofFile(Request $request, int $invoice, int $proof): StreamedResponse
    {
        Access::require($request, 'billing.read');
        $this->invoice($invoice);
        return $this->downloadProof($invoice, $proof);
    }

    public function companyProofFile(Request $request, string $company, int $invoice, int $proof): StreamedResponse
    {
        $this->requireCompanyAdmin($request, $company);
        $this->invoice($invoice, $company);
        return $this->downloadProof($invoice, $proof);
    }

    private function downloadProof(int $invoice, int $proof): StreamedResponse
    {
        $row = DB::table('hrm_payment_proofs')->where('invoice_id', $invoice)
            ->where('id', $proof)->first();
        abort_unless($row && str_starts_with($row->path, 'hrm-payment-proofs/'.$invoice.'/'), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($row->path), 404);
        $bytes = $disk->get($row->path);
        abort_unless(hash_equals($row->sha256, hash('sha256', $bytes)), 409,
            'Payment document integrity error');
        $extension = ['application/pdf'=>'pdf', 'image/jpeg'=>'jpg', 'image/png'=>'png'][$row->mime] ?? 'bin';
        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'payment-proof-'.$invoice.'-'.$proof.'.'.$extension, [
            'Content-Type' => $row->mime, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function adminInvoicePdf(Request $request, int $invoice)
    {
        Access::require($request, 'billing.read');
        return $this->pdf($this->invoice($invoice));
    }

    public function companyInvoicePdf(Request $request, string $company, int $invoice)
    {
        $this->requireCompanyAdmin($request, $company);
        $row = $this->invoice($invoice, $company);
        abort_if($row->status === 'draft', 404);
        return $this->pdf($row);
    }

    private function pdf(object $invoice)
    {
        $bytes = app(\App\Services\HrmInvoicePdfService::class)->render($invoice);
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="HRM-invoice-'.$invoice->id.'.pdf"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => '"'.hash('sha256', $bytes).'"',
        ]);
    }

    public function adminPrintableInvoice(Request $request, int $invoice)
    {
        Access::require($request, 'billing.read');
        return $this->printable($this->invoice($invoice));
    }

    public function companyPrintableInvoice(Request $request, string $company, int $invoice)
    {
        $this->requireCompanyAdmin($request, $company);
        $row = $this->invoice($invoice, $company);
        abort_if($row->status === 'draft', 404);
        return $this->printable($row);
    }

    private function printable(object $invoice)
    {
        $snapshot = json_decode($invoice->snapshot, true) ?: [];
        $plan = $snapshot['plan'] ?? [];
        $buyer = $snapshot['buyer'] ?? [];
        $seller = $snapshot['seller'] ?? [];
        $e = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn (int $amount) => number_format($amount / 100, 2, '.', ' ');
        $name = $plan['terms']['names']['ru'] ?? ($plan['code'] ?? 'ITP HRM');
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>ITP HRM Invoice</title>'
            .'<style>body{font-family:Arial,sans-serif;color:#182b36;padding:36px;max-width:900px;margin:auto}'
            .'h1{margin:0 0 22px;font-size:28px}.flex{display:flex;gap:30px}section{flex:1;line-height:1.7}'
            .'table{width:100%;border-collapse:collapse;margin:26px 0}td,th{text-align:left;padding:12px;border-bottom:1px solid #dce5ed}'
            .'th{background:#f2f6f8}.total{font-size:22px;font-weight:bold;text-align:right}'
            .'footer{font-size:12px;color:#607582;margin-top:55px}.no-print{margin:0 0 30px;padding:8px 15px}'
            .'@media print{.no-print{display:none}body{padding:0}}</style></head><body>'
            .'<button class="no-print" onclick="print()">Печать / Сохранить PDF</button>'
            .'<h1>Счёт на оплату ITP Кадры № '.$e($invoice->number).'</h1>'
            .'<p>Дата выставления: '.$e($invoice->issued_at).' · Оплатить до: '.$e($invoice->due_date)
            .' · Статус: '.$e($invoice->status).'</p>'
            .'<div class="flex"><section><b>Исполнитель</b><div>'.$e($seller['legal_name'] ?? 'ITP Mining').'</div>'
            .'<div>'.$e($seller['registration_number'] ?? '').'</div><div>'.$e($seller['address'] ?? '').'</div>'
            .'<div>'.$e($seller['bank'] ?? '').'</div><div>'.$e($seller['iban'] ?? '').'</div>'
            .'<div>'.$e($seller['bic'] ?? '').'</div></section><section><b>Заказчик</b><div>'
            .$e($buyer['legal_name'] ?? '').'</div><div>'.$e($buyer['registration_number'] ?? '')
            .'</div><div>'.$e($buyer['address'] ?? '').'</div></section></div>'
            .'<table><thead><tr><th>Услуга</th><th>Период (мес.)</th><th>Стоимость</th></tr></thead><tbody><tr>'
            .'<td>Доступ к ITP Кадры · '.$e($name).'</td><td>'.$e($snapshot['months'] ?? 1).'</td>'
            .'<td>'.$money((int) $invoice->total_minor).' '.$e($invoice->currency).'</td></tr></tbody></table>'
            .'<p>Сумма без НДС: '.$money((int) $invoice->subtotal_minor).' '.$e($invoice->currency)
            .' · НДС в том числе: '.$money((int) $invoice->tax_minor).' '.$e($invoice->currency).'</p>'
            .'<p class="total">Всего к оплате: '.$money((int) $invoice->total_minor).' '.$e($invoice->currency).'</p>'
            .'<footer>Счёт не подтверждает оплату. Подписка активируется после проверки поступления денежных средств.'
            .' Для вопросов: info@itpmining.kz.</footer></body></html>';
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
