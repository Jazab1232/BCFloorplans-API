<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VendorInvoice;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;

class VendorInvoiceController extends Controller
{
    /**
     * Display a listing of personal invoices for the vendor.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Ensure the authenticated user is a vendor or has a vendor profile
        // The project structure seems to have a Vendor model that might be an Authenticatable
        // based on line 10 of Vendor.php: class Vendor extends Authenticatable
        
        if (!($user instanceof Vendor)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $invoices = VendorInvoice::with(['lines.orderService.service', 'lines.orderService.order.property'])
            ->where('vendor_id', $user->uuid)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $invoices
        ]);
    }

    /**
     * Display the specified invoice.
     */
    public function show($uuid)
    {
        $user = Auth::user();

        if (!($user instanceof Vendor)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $invoice = VendorInvoice::with(['lines.orderService.service', 'lines.orderService.order.property'])
            ->where('uuid', $uuid)
            ->where('vendor_id', $user->uuid)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $invoice
        ]);
    }

    public function exportCsv(Request $request)
    {
        try {
            $user = Auth::user();

            if (!($user instanceof Vendor)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $invoices = VendorInvoice::where('vendor_id', $user->uuid)
                ->orderBy('created_at', 'desc')
                ->get();

            $filename = "vendor_invoices_personal_" . now()->format('Y-m-d_His') . ".csv";
            $headers = [
                "Content-type"        => "text/csv",
                "Content-Disposition" => "attachment; filename=$filename",
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            ];

            $columns = ['Invoice #', 'Status', 'Subtotal', 'Tax', 'Travel', 'Total', 'Cycle Start', 'Cycle End', 'Paid At'];

            $callback = function() use($invoices, $columns) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);

                foreach ($invoices as $invoice) {
                    fputcsv($file, [
                        $invoice->invoice_number,
                        $invoice->status,
                        $invoice->subtotal,
                        $invoice->tax_amount,
                        $invoice->travel_amount,
                        $invoice->total_amount,
                        $invoice->cycle_start?->format('Y-m-d'),
                        $invoice->cycle_end?->format('Y-m-d'),
                        $invoice->paid_at?->format('Y-m-d H:i:s'),
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Vendor Personal CSV Export failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Export failed'], 500);
        }
    }
}
