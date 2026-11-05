<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

final class HrmInvoicePdfService
{
    /**
     * Generate an A4 invoice from its immutable commercial snapshot, not from
     * mutable public plans or live supplier/buyer profiles.
     */
    public function render(object $invoice): string
    {
        abort_unless($invoice->status !== 'draft' && $invoice->issued_at, 409,
            'An invoice must be issued before PDF generation');
        $snapshot = json_decode($invoice->snapshot, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($snapshot) && isset($snapshot['subscription']['sha256']), 409);
        $terms = $snapshot['subscription']['terms'] ?? null;
        abort_unless(is_array($terms) && hash_equals(
            $snapshot['subscription']['sha256'], HrmSaasCatalog::hashTerms($terms)
        ), 409, 'Invoice plan snapshot integrity check failed');

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml(view('billing.hrm-invoice', [
            'invoice' => $invoice,
            'snapshot' => $snapshot,
        ])->render(), 'UTF-8');
        $pdf->render();
        $bytes = $pdf->output();
        abort_unless(is_string($bytes) && str_starts_with($bytes, '%PDF-'), 503,
            'Invoice PDF renderer failed');
        return $bytes;
    }
}
