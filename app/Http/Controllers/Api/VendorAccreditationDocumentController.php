<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorAccreditationDocumentController extends Controller
{
    public function index()
    {
        $documents = DB::table('vendor_accreditation_documents')
            ->select([
                'id',
                'doc_code as code',
                'doc_name as name',
                'description',
                'default_required as defaultRequired',
                'active',
                'sort_order as sortOrder',
            ])
            ->where('active', 'Y')
            ->orderBy('sort_order')
            ->orderBy('doc_name')
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'code' => strtoupper((string) $doc->code),
                    'name' => $doc->name,
                    'description' => $doc->description,
                    'required' => strtoupper((string) $doc->defaultRequired) === 'Y',
                    'active' => $doc->active,
                    'sortOrder' => $doc->sortOrder,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    public function getByRegistration($regNo)
    {
        $regNo = strtoupper(trim((string) $regNo));

        try {
            $rows = $this->execVendorRegistrationSproc('GetRegistration', [
                'regNo' => $regNo,
            ]);

            $result = $rows[0] ?? null;

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'No response returned from vendor registration procedure.',
                ], 500);
            }

            $success = (bool) ($result->success ?? false);
            $statusCode = (int) ($result->statusCode ?? ($success ? 200 : 422));

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => $result->message ?? 'Unable to fetch registration documents.',
                ], $statusCode);
            }

            $application = $this->decodeApplicationResult($result->result ?? null);

            return response()->json([
                'success' => true,
                'data' => $application['requiredDocuments'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('VENDOR REGISTRATION DOCUMENT FETCH ERROR', [
                'reg_no' => $regNo,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch registration documents.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function applications(Request $request)
    {
        try {
            $rows = $this->execVendorRegistrationSproc('Applications', [
                'search' => $request->query('search', ''),
                'status' => $request->query('status', ''),
                'top' => (int) $request->query('top', 500),
            ]);

            $result = $rows[0] ?? null;

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'No response returned from vendor registration procedure.',
                ], 500);
            }

            $success = (bool) ($result->success ?? false);
            $statusCode = (int) ($result->statusCode ?? ($success ? 200 : 422));

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => $result->message ?? 'Unable to fetch vendor applications.',
                ], $statusCode);
            }

            return response()->json([
                'success' => true,
                'data' => $this->decodeApplicationsResult($result->result ?? '[]'),
            ]);
        } catch (\Throwable $e) {
            Log::error('VENDOR APPLICATIONS FETCH ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch vendor applications.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function execVendorRegistrationSproc(string $mode, array $jsonData): array
    {
        $params = json_encode([
            'json_data' => $jsonData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return DB::select(
            'EXEC dbo.sproc_PHP_VendorRegistration @mode = ?, @params = ?',
            [$mode, $params]
        );
    }

    private function decodeApplicationResult($json): array
    {
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
        } elseif (is_array($json)) {
            $decoded = $json;
        } elseif (is_object($json)) {
            $decoded = json_decode(json_encode($json), true);
        } else {
            $decoded = [];
        }

        return $this->normalizeApplication(is_array($decoded) ? $decoded : []);
    }

    private function decodeApplicationsResult($json): array
    {
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
        } elseif (is_array($json)) {
            $decoded = $json;
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(fn ($app) => $this->normalizeApplication(is_array($app) ? $app : []))
            ->values()
            ->all();
    }

    private function normalizeApplication(array $application): array
    {
        $requiredDocuments = $application['requiredDocuments'] ?? [];
        if (is_string($requiredDocuments)) {
            $requiredDocuments = json_decode($requiredDocuments, true) ?: [];
        }

        $uploadedDocuments = $application['uploadedDocuments'] ?? [];
        if (is_string($uploadedDocuments)) {
            $uploadedDocuments = json_decode($uploadedDocuments, true) ?: [];
        }

        $vendorInfo = $application['vendorInfo'] ?? [];
        if (is_string($vendorInfo)) {
            $vendorInfo = json_decode($vendorInfo, true) ?: [];
        }

        $uploadMap = [];
        foreach (is_array($uploadedDocuments) ? $uploadedDocuments : [] as $upload) {
            if (!is_array($upload)) {
                continue;
            }

            $docCode = strtoupper((string) ($upload['docCode'] ?? $upload['code'] ?? ''));
            if ($docCode === '') {
                continue;
            }

            $upload['docCode'] = $docCode;
            $upload['code'] = $docCode;
            $upload['name'] = $upload['docName'] ?? $upload['name'] ?? null;
            $upload['publicUrl'] = !empty($upload['fileUrl']) ? url($upload['fileUrl']) : null;

            $uploadMap[$docCode] = $upload;
        }

        $application['requiredDocuments'] = collect(is_array($requiredDocuments) ? $requiredDocuments : [])
            ->map(function ($doc) {
                $doc = is_array($doc) ? $doc : [];

                return [
                    'id' => $doc['id'] ?? null,
                    'code' => strtoupper((string) ($doc['code'] ?? $doc['docCode'] ?? '')),
                    'name' => $doc['name'] ?? $doc['docName'] ?? null,
                    'required' => (bool) ($doc['required'] ?? false),
                ];
            })
            ->values()
            ->all();

        $application['uploadedDocuments'] = $uploadMap;
        $application['uploads'] = $uploadMap;
        $application['vendorInfo'] = is_array($vendorInfo) ? $vendorInfo : [];
        $application['id'] = $application['id'] ?? $application['vendId'] ?? null;
        $application['vendId'] = $application['vendId'] ?? $application['id'] ?? null;
        $application['status'] = $application['status'] ?? 'PRE-REGISTERED';
        $application['vendorEmail'] = $application['vendorEmail'] ?? ($application['vendorInfo']['email'] ?? null);

        return $application;
    }
}
