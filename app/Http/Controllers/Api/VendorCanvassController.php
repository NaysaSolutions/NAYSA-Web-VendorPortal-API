<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorCanvassController extends Controller
{
    public function getVendorCanvassByVendCode(Request $request)
    {
        $vendCode = strtoupper(trim(
            $request->vend_code ??
            $request->vendor_code ??
            $request->vendorCode ??
            ''
        ));

        $canId = trim(
            $request->can_id ??
            $request->canId ??
            ''
        );

        $canSupplierId = trim(
            $request->can_supplier_id ??
            $request->canSupplierId ??
            ''
        );

        if ($vendCode === '') {
            return response()->json([
                'success' => false,
                'message' => 'Vendor Code is required.',
            ], 422);
        }

        $params = json_encode([
            'vend_code' => $vendCode,
            'can_id' => $canId,
            'can_supplier_id' => $canSupplierId,
        ], JSON_UNESCAPED_UNICODE);

        $rows = DB::select(
            'EXEC dbo.sproc_PHP_VendorCanvass @mode = ?, @params = ?',
            [
                'GetVendorCanvassByVendCode',
                $params,
            ]
        );

        if (count($rows) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No canvass record found for this vendor.',
            ], 404);
        }

        $data = collect($rows)->map(function ($item) {
            $row = (array) $item;

            $row['detailRows'] = !empty($row['detailRows'])
                ? json_decode($row['detailRows'], true)
                : [];

            if (!is_array($row['detailRows'])) {
                $row['detailRows'] = [];
            }

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function submitVendorCanvass(Request $request)
    {
        $vendCode = strtoupper(trim(
            $request->vend_code ??
            $request->vendor_code ??
            $request->vendorCode ??
            ''
        ));

        if ($vendCode === '') {
            return response()->json([
                'success' => false,
                'message' => 'Vendor Code is required.',
            ], 422);
        }

        $params = json_encode([
            'vend_code' => $vendCode,

            'can_supplier_id' =>
                $request->can_supplier_id ??
                $request->canSupplierId ??
                '',

            'can_id' =>
                $request->can_id ??
                $request->canId ??
                '',

            'offer' => $request->offer ?? [],
            'items' => $request->items ?? [],

            'totalGrossAmount' => $request->totalGrossAmount ?? 0,
            'totalDiscountAmount' => $request->totalDiscountAmount ?? 0,
            'totalVatAmount' => $request->totalVatAmount ?? 0,
            'totalNetAmount' => $request->totalNetAmount ?? 0,
        ], JSON_UNESCAPED_UNICODE);

        $rows = DB::select(
            'EXEC dbo.sproc_PHP_VendorCanvass @mode = ?, @params = ?',
            [
                'SubmitVendorCanvass',
                $params,
            ]
        );

        $row = count($rows) > 0 ? (array) $rows[0] : [];

        if (!($row['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $row['message'] ?? 'Unable to submit vendor canvass.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $row['message'] ?? 'Vendor canvass submitted successfully.',
            'data' => $row,
        ]);
    }
}