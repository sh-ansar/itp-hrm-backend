<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single source of truth for administrator-created drafts and customer checkout.
 * No auto-payment, stored card charges or invoice-triggered activation.
 */
final class HrmInvoiceCreator
{
    public function create(
        string $company, int $planVersion, int $months, string $dueDate,
        int $actor, bool $issue = false, bool $publicOnly = false,
        ?int $expectedTaxRate = null
    ): object {
        abort_unless(config('hrm.saas_billing_enabled', false), 409, 'HRM billing is disabled');
        abort_unless($months >= 1 && $months <= 120, 422, 'Invalid invoice period');
        return DB::transaction(function () use (
            $company, $planVersion, $months, $dueDate, $actor, $issue, $publicOnly, $expectedTaxRate
        ): object {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $plan = DB::table('hrm_plan_versions')->where('id', $planVersion)
                ->where('status', 'published')->lockForUpdate()->first();
            abort_unless($plan && (!$publicOnly || $plan->is_public), 422,
                'Plan is not available');
            $unit = (int) $plan->monthly_price_minor;
            abort_unless($unit > 0 && $months <= intdiv(PHP_INT_MAX, $unit)
                && $plan->sha256, 422, 'Select a valid signed paid tariff');

            if ($publicOnly) {
                $subscription = DB::table('hrm_company_subscriptions')
                    ->where('company_id', $company)->lockForUpdate()->first();
                if ($subscription && $subscription->status === 'active'
                    && (!$subscription->ends_at || now()->lt($subscription->ends_at))) {
                    abort_unless((int) $subscription->plan_version_id === $planVersion, 409,
                        'Plan changes during a paid period need operator review');
                }
                // Repeated checkout clicks must not issue new invoices unnecessarily.
                $existing = DB::table('hrm_invoices')->where('company_id', $company)
                    ->where('plan_version_id', $planVersion)
                    ->whereIn('status', ['issued', 'awaiting_payment'])
                    ->orderByDesc('id')->get();
                foreach ($existing as $invoice) {
                    $snap = json_decode($invoice->snapshot, true) ?: [];
                    if (($snap['months'] ?? null) === $months && $invoice->due_date >= now()->toDateString()) {
                        return $invoice;
                    }
                }
            }

            $supplier = DB::table('hrm_invoice_profiles')->where('scope', 'supplier')
                ->whereNull('company_id')->first();
            $buyer = DB::table('hrm_invoice_profiles')->where('scope', 'buyer')
                ->where('company_id', $company)->first();
            $sellerDetails = $supplier ? json_decode($supplier->details, true) ?: [] : [];
            $buyerDetails = $buyer ? json_decode($buyer->details, true) ?: [] : [];
            if ($issue) {
                abort_unless(!empty($sellerDetails['legal_name']) && !empty($sellerDetails['iban'])
                    && !empty($sellerDetails['bic']), 409, 'Supplier billing profile is incomplete');
            }
            $taxRate = (int) ($sellerDetails['tax_basis_points'] ?? 0);
            abort_unless($taxRate >= 0 && $taxRate <= 10000, 409,
                'Invalid official supplier tax setting');
            abort_unless($expectedTaxRate === null || $expectedTaxRate === $taxRate, 422,
                'Tax rate must match the supplier profile');

            $terms = HrmSaasCatalog::canonicalTerms([
                'names' => json_decode($plan->names, true),
                'descriptions' => json_decode($plan->descriptions ?: '{}', true),
                'monthly_price_minor' => $plan->monthly_price_minor,
                'currency' => $plan->currency,
                'period_months' => $plan->period_months,
                'limits' => json_decode($plan->limits, true),
                'modules' => json_decode($plan->modules, true),
            ]);
            abort_unless(hash_equals($plan->sha256, HrmSaasCatalog::hashTerms($terms)), 409,
                'Tariff version integrity check failed');

            $gross = $unit * $months;
            $tax = intdiv($gross * $taxRate + intdiv(10000 + $taxRate, 2), 10000 + $taxRate);
            $snapshot = [
                'plan' => HrmPlatformAccess::plan($plan),
                'months' => $months, 'tax_basis_points' => $taxRate,
                'seller' => $sellerDetails, 'buyer' => $buyerDetails,
                'subscription' => ['plan_version_id' => $plan->id,
                    'code' => $plan->code, 'version' => $plan->version,
                    'sha256' => $plan->sha256, 'terms' => $terms],
            ];
            $id = DB::table('hrm_invoices')->insertGetId([
                'number' => 'DRAFT-'.Str::uuid(),
                'company_id' => $company, 'plan_version_id' => $plan->id,
                'subtotal_minor' => $gross - $tax, 'tax_minor' => $tax,
                'total_minor' => $gross, 'currency' => $plan->currency,
                'status' => 'draft', 'due_date' => $dueDate,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($issue) {
                DB::table('hrm_invoices')->where('id', $id)->update([
                    'number' => 'HRM-'.now()->format('Ymd').'-'.str_pad((string) $id, 7, '0', STR_PAD_LEFT),
                    'status' => 'issued', 'issued_at' => now(), 'updated_at' => now(),
                ]);
            }
            HrmPlatformAccess::audit($actor, $company, $issue ? 'invoice.issued' : 'invoice.draft',
                'invoice:'.$id, ['plan_version_id' => $planVersion, 'months' => $months, 'gross_minor' => $gross]);
            return DB::table('hrm_invoices')->where('id', $id)->first();
        }, 3);
    }
}
