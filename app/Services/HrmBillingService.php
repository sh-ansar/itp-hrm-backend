<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class HrmBillingService
{
    /**
     * Accept a bank-verified payment, not a customer-uploaded claim.
     * The invoice and subscription are locked together to ensure idempotency.
     */
    public function confirm(int $invoiceId, int $proofId, int $actor, string $reference, int $amount, string $note): object
    {
        abort_unless(config('hrm.saas_billing_enabled', false), 409, 'HRM billing is disabled');
        $reference = trim($reference);
        abort_unless(strlen($reference) >= 5 && strlen($reference) <= 120, 422, 'Bank reference is required');
        abort_unless(mb_strlen(trim($note)) >= 12, 422, 'Payment review note is required');

        return DB::transaction(function () use ($invoiceId, $proofId, $actor, $reference, $amount, $note): object {
            $invoice = DB::table('hrm_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            $proof = DB::table('hrm_payment_proofs')->where('invoice_id', $invoiceId)
                ->where('id', $proofId)->lockForUpdate()->first();
            abort_unless($proof, 404);
            if ($invoice->status === 'paid') {
                // A retried approval does not extend a subscription twice.
                abort_unless($proof->status === 'accepted'
                    && $invoice->payment_reference === $reference
                    && (int) $invoice->total_minor === $amount, 409, 'Invoice already paid');
                return $invoice;
            }
            abort_unless(in_array($invoice->status, ['issued', 'awaiting_payment', 'overdue'], true), 409);
            abort_unless($proof->status === 'pending', 409, 'Payment proof already reviewed');
            abort_unless($actor !== (int) $proof->user_id, 409, 'Independent payment reviewer is required');
            abort_unless($amount === (int) $invoice->total_minor, 422, 'Paid amount must match the invoice');
            abort_unless(!DB::table('hrm_invoices')
                ->where('payment_reference', $reference)->where('id', '<>', $invoiceId)->exists(),
                409, 'Bank reference already reconciled');

            $recorded = json_decode($invoice->snapshot, true);
            $contract = $recorded['subscription'] ?? null;
            abort_unless(is_array($contract)
                && (int) ($contract['plan_version_id'] ?? 0) === (int) $invoice->plan_version_id
                && isset($contract['terms'], $contract['sha256'])
                && hash_equals($contract['sha256'], HrmSaasCatalog::hashTerms($contract['terms'])),
                409, 'Invoice subscription snapshot is invalid');
            $months = (int) ($recorded['months'] ?? 0);
            abort_unless($months >= 1 && $months <= 120, 409, 'Invoice term is invalid');
            $unitPrice = (int) ($contract['terms']['monthly_price_minor'] ?? 0);
            abort_unless($unitPrice > 0 && $months <= intdiv(PHP_INT_MAX, $unitPrice)
                && $unitPrice * $months === (int) $invoice->total_minor
                && ($contract['terms']['currency'] ?? null) === $invoice->currency,
                409, 'Invoice price or currency disagrees with signed plan terms');
            DB::table('companies')->where('id', $invoice->company_id)->lockForUpdate()->firstOrFail();
            $old = DB::table('hrm_company_subscriptions')
                ->where('company_id', $invoice->company_id)->lockForUpdate()->first();
            $now = CarbonImmutable::now();
            $start = $now;
            if ($old && $old->status === 'active') {
                $isFuture = $old->ends_at === null || CarbonImmutable::parse($old->ends_at)->gt($now);
                if ($isFuture) {
                    abort_unless((int) $old->plan_version_id === (int) $invoice->plan_version_id, 409,
                        'Active plan changes require a separate migration/settlement');
                    abort_unless($old->ends_at !== null, 409, 'Indefinite administrator grant cannot be overwritten');
                    $start = CarbonImmutable::parse($old->ends_at);
                }
            }
            // Month arithmetic uses the actual renewal start and preserves the
            // remaining paid period; no implicit prorating or double spending.
            $end = $start->addMonthsNoOverflow($months);
            $subSnapshot = [
                'code' => $contract['code'], 'version' => $contract['version'],
                'sha256' => $contract['sha256'], 'terms' => $contract['terms'],
                'source_invoice_id' => $invoiceId,
            ];
            DB::table('hrm_company_subscriptions')->updateOrInsert(
                ['company_id' => $invoice->company_id],
                [
                    'plan_version_id' => $invoice->plan_version_id,
                    'snapshot' => json_encode($subSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'status' => 'active', 'starts_at' => $now,
                    'ends_at' => $end, 'assigned_by' => $actor,
                    'created_at' => $old?->created_at ?? now(), 'updated_at' => now(),
                ]
            );
            DB::table('hrm_payment_proofs')->where('id', $proofId)->update([
                'status' => 'accepted', 'reviewed_by' => $actor, 'review_note' => $note,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('hrm_invoices')->where('id', $invoiceId)->update([
                'status' => 'paid', 'paid_at' => now(), 'payment_reference' => $reference,
                'approved_by' => $actor, 'updated_at' => now(),
            ]);
            HrmPlatformAccess::audit($actor, $invoice->company_id, 'invoice.paid', 'invoice:'.$invoiceId, [
                'reference' => $reference, 'proof_id' => $proofId,
                'amount_minor' => $amount, 'months' => $months,
                'term_from' => $start->toIso8601String(), 'term_to' => $end->toIso8601String(),
            ]);
            return DB::table('hrm_invoices')->where('id', $invoiceId)->first();
        }, 3);
    }

    public function reject(int $invoiceId, int $proofId, int $actor, string $note): void
    {
        abort_unless(config('hrm.saas_billing_enabled', false), 409, 'HRM billing is disabled');
        abort_unless(mb_strlen(trim($note)) >= 12, 422, 'Rejection explanation required');
        DB::transaction(function () use ($invoiceId, $proofId, $actor, $note): void {
            $invoice = DB::table('hrm_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(in_array($invoice->status, ['issued', 'awaiting_payment', 'overdue'], true), 409);
            $proof = DB::table('hrm_payment_proofs')->where('invoice_id', $invoiceId)
                ->where('id', $proofId)->lockForUpdate()->first();
            abort_unless($proof && $proof->status === 'pending', 409);
            DB::table('hrm_payment_proofs')->where('id', $proofId)->update([
                'status' => 'rejected', 'review_note' => $note,
                'reviewed_by' => $actor, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            HrmPlatformAccess::audit($actor, $invoice->company_id, 'payment-proof.rejected',
                'proof:'.$proofId, ['invoice_id' => $invoiceId, 'review' => $note]);
        }, 3);
    }
}
