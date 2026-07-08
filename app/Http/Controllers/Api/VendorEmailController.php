<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VendorApprovalAccessMail;
use App\Mail\VendorRegistrationAccessMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VendorEmailController extends Controller
{
    public function sendRegistrationAccess(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vendor_email' => 'required|email',
            'vendor_name' => 'required|string|max:200',
            'contact_person' => 'nullable|string|max:200',
            'contact_no' => 'nullable|string|max:100',
            'supplier_type' => 'nullable|string|max:100',
            'remarks' => 'nullable|string',

            'reg_no' => 'nullable|string|max:50',
            'registration_link' => 'nullable|string|max:500',
            'access_key' => 'nullable|string|max:100',

            'branch_code' => 'nullable|string|max:50',
            'registered_by' => 'nullable|string|max:50',

            'required_documents' => 'required|array|min:1',
            'required_documents.*.id' => 'required',
            'required_documents.*.code' => 'required|string|max:50',
            'required_documents.*.name' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid pre-registration details.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $regNo = strtoupper(trim($request->reg_no ?: $this->generateRegistrationNo()));
        $accessKey = strtoupper(trim($request->access_key ?: Str::random(10)));

        $portalUrl = rtrim(env('VENDOR_PORTAL_URL', config('app.url')), '/');
        $registrationLink = $request->registration_link
            ?: $portalUrl . '/vendor-registration/' . urlencode($regNo);

        $branchCode = $request->branch_code ?: 'HO';
        $registeredBy = $request->registered_by ?: 'SYSTEM';
        $supplierSource = $this->mapSupplierSource($request->supplier_type);

        $selectedRequiredDocuments = collect($request->required_documents)
            ->map(function ($doc) {
                return [
                    'id' => (string) ($doc['id'] ?? ''),
                    'code' => strtoupper(trim((string) ($doc['code'] ?? ''))),
                    'name' => (string) ($doc['name'] ?? ''),
                    'required' => true,
                ];
            })
            ->values();

        $preRegId = null;

        try {
            $rows = $this->execVendorPreRegistrationSproc('SavePreRegistration', [
                'regNo' => $regNo,
                'vendorEmail' => $request->vendor_email,
                'vendorName' => $request->vendor_name,
                'contactPerson' => $request->contact_person,
                'contactNo' => $request->contact_no,
                'supplierType' => $request->supplier_type,
                'remarks' => $request->remarks,
                'registrationLink' => $registrationLink,
                'accessKeyHash' => Hash::make($accessKey),
                'branchCode' => $branchCode,
                'registeredBy' => $registeredBy,
                'supplierSource' => $supplierSource,
                'requiredDocuments' => $selectedRequiredDocuments
                    ->map(fn ($doc) => [
                        'id' => $doc['id'],
                        'code' => $doc['code'],
                        'name' => $doc['name'],
                    ])
                    ->all(),
            ]);

            $result = $rows[0] ?? null;

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'No response returned from vendor pre-registration procedure.',
                ], 500);
            }

            $success = (bool) ($result->success ?? false);
            $statusCode = (int) ($result->statusCode ?? ($success ? 200 : 422));

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => $result->message ?? 'Unable to save vendor pre-registration.',
                ], $statusCode);
            }

            $preRegId = $result->vendId ?? null;
            $regNo = strtoupper(trim((string) ($result->regNo ?? $regNo)));

            if (!$preRegId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor pre-registration procedure did not return a Vendor ID.',
                ], 500);
            }

            $this->sendRegistrationAccessEmailAfterResponse(
                vendorEmail: $request->vendor_email,
                vendorName: $request->vendor_name,
                regNo: $regNo,
                accessKey: $accessKey,
                registrationLink: $registrationLink,
                vendId: (int) $preRegId,
                registeredBy: $registeredBy
            );

            return response()->json([
                'success' => true,
                'message' => 'Initial vendor details saved. Registration email is being sent.',
                'data' => [
                    'id' => $preRegId,
                    'vendId' => $preRegId,
                    'regNo' => $regNo,
                    'vendorName' => $request->vendor_name,
                    'vendorEmail' => $request->vendor_email,
                    'contactPerson' => $request->contact_person,
                    'contactNo' => $request->contact_no,
                    'supplierType' => $request->supplier_type,
                    'remarks' => $request->remarks,
                    'registrationLink' => $registrationLink,
                    'tempUserId' => $regNo,
                    'status' => 'PRE-REGISTERED',
                    'emailSent' => false,
                    'emailStatus' => 'PENDING',
                    'requiredDocuments' => $selectedRequiredDocuments,
                ],
            ], 200);
        } catch (\Throwable $e) {
            if ($preRegId) {
                try {
                    $this->execVendorPreRegistrationSproc('UpdateEmailStatus', [
                        'regNo' => $regNo,
                        'vendId' => $preRegId,
                        'emailSent' => 'N',
                        'registeredBy' => $registeredBy,
                    ]);
                } catch (\Throwable $statusException) {
                    Log::warning('VENDOR EMAIL STATUS UPDATE FAILED', [
                        'reg_no' => $regNo,
                        'vend_id' => $preRegId,
                        'message' => $statusException->getMessage(),
                    ]);
                }
            }

            Log::error('VENDOR PRE-REGISTRATION API ERROR', [
                'reg_no' => $regNo,
                'vend_id' => $preRegId,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to save pre-registration.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function temporaryLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reg_no' => 'required|string|max:50',
            'access_key' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Registration No. and Access Key are required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $regNo = strtoupper(trim($request->reg_no));
        $accessKey = strtoupper(trim($request->access_key));

        try {
            $rows = $this->execVendorPreRegistrationSproc('TemporaryLogin', [
                'regNo' => $regNo,
            ]);

            $vendor = $rows[0] ?? null;

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'No response returned from vendor pre-registration procedure.',
                ], 500);
            }

            $success = (bool) ($vendor->success ?? false);
            $statusCode = (int) ($vendor->statusCode ?? ($success ? 200 : 422));

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => $vendor->message ?? 'Invalid Registration No. or Access Key.',
                ], $statusCode);
            }

            if (empty($vendor->ACCESS_KEY_HASH) || !Hash::check($accessKey, $vendor->ACCESS_KEY_HASH)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Registration No. or Access Key.',
                ], 401);
            }

            $registrationRows = $this->execVendorRegistrationSproc('GetRegistration', [
                'regNo' => $regNo,
                'vendorPreRegistrationId' => $vendor->VEND_ID ?? null,
            ]);

            $registrationResult = $registrationRows[0] ?? null;
            $registrationSuccess = (bool) ($registrationResult->success ?? false);

            if ($registrationSuccess && !empty($registrationResult->result)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vendor access verified.',
                    'data' => $this->decodeApplicationResult($registrationResult->result),
                ], 200);
            }

            return response()->json([
                'success' => true,
                'message' => 'Vendor access verified.',
                'data' => [
                    'id' => $vendor->VEND_ID,
                    'vendId' => $vendor->VEND_ID,
                    'regNo' => $vendor->REG_NO,
                    'vendCode' => $vendor->VEND_CODE,
                    'vendorName' => $vendor->VEND_NAME,
                    'businessName' => $vendor->BUSINESS_NAME,
                    'vendorEmail' => $vendor->VEND_EMAIL,
                    'contactPerson' => $vendor->VEND_CONTACT,
                    'contactNo' => $vendor->VEND_MOBILENO,
                    'supplierType' => $vendor->SUPPLIER_TYPE ?? '',
                    'remarks' => $vendor->REMARKS,
                    'registrationLink' => $vendor->REGISTRATION_LINK ?? '',
                    'status' => $vendor->REG_STATUS,
                    'emailSent' => strtoupper((string) ($vendor->EMAIL_SENT ?? 'N')) === 'Y',
                    'accessKeyUsed' => strtoupper((string) ($vendor->ACCESS_KEY_USED ?? 'N')) === 'Y',
                    'createdAt' => $vendor->REGISTERED_DATE,
                    'updatedAt' => $vendor->UPDATED_DATE,
                    'requiredDocuments' => [],
                    'uploadedDocuments' => [],
                    'uploads' => [],
                    'vendorInfo' => [
                        'vendorName' => $vendor->VEND_NAME,
                        'businessName' => $vendor->BUSINESS_NAME,
                        'tinNo' => $vendor->VEND_TIN,
                        'taxType' => $vendor->VAT_CODE ?: 'VAT',
                        'taxClass' => $vendor->TAX_CLASS ?: 'Corporation',
                        'address' => trim(($vendor->VEND_ADDR1 ?? '') . ' ' . ($vendor->VEND_ADDR2 ?? '') . ' ' . ($vendor->VEND_ADDR3 ?? '')),
                        'zipCode' => $vendor->VEND_ZIP,
                        'contactPerson' => $vendor->VEND_CONTACT,
                        'contactNo' => $vendor->VEND_MOBILENO,
                        'email' => $vendor->VEND_EMAIL,
                        'paymentTerms' => $vendor->PAYTERM_CODE,
                    ],
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('VENDOR TEMPORARY LOGIN API ERROR', [
                'reg_no' => $regNo,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to verify vendor temporary access.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function submitVendorRegistration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vendor_pre_registration_id' => 'nullable',
            'reg_no' => 'required|string|max:50',

            'vendor_info' => 'required|array',
            'vendor_info.vendorName' => 'required|string|max:200',
            'vendor_info.businessName' => 'required|string|max:200',
            'vendor_info.tinNo' => 'required|string|max:100',
            'vendor_info.taxClass' => 'required|string|max:100',
            'vendor_info.taxType' => 'required|string|max:100',
            'vendor_info.address' => 'required|string|max:500',
            'vendor_info.zipCode' => 'required|string|max:50',
            'vendor_info.contactPerson' => 'required|string|max:200',
            'vendor_info.contactNo' => 'required|string|max:100',
            'vendor_info.email' => 'required|email|max:200',
            'vendor_info.paymentTerms' => 'required|string|max:100',

            'documents' => 'nullable|array',
            'documents.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
            'document_names' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid vendor registration details.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $regNo = strtoupper(trim($request->reg_no));
        $vendorInfo = $request->input('vendor_info', []);
        $files = $request->file('documents', []);
        $documentNames = $request->input('document_names', []);
        $uploadedDocuments = [];

        try {
            foreach ($files as $docCode => $file) {
                if (!$file || !$file->isValid()) {
                    continue;
                }

                $cleanDocCode = strtoupper(trim((string) $docCode));
                $originalName = $file->getClientOriginalName();
                $extension = strtolower($file->getClientOriginalExtension());

                $storedName = $regNo
                    . '_'
                    . $cleanDocCode
                    . '_'
                    . now()->format('YmdHis')
                    . '_'
                    . Str::random(6)
                    . '.'
                    . $extension;

                $storedPath = $file->storeAs(
                    'vendor_registration_documents/' . $regNo,
                    $storedName,
                    'public'
                );

                $fileUrl = 'storage/' . $storedPath;

                $uploadedDocuments[] = [
                    'docCode' => $cleanDocCode,
                    'docName' => $documentNames[$docCode] ?? $documentNames[$cleanDocCode] ?? null,
                    'originalFileName' => $originalName,
                    'storedFileName' => $storedName,
                    'filePath' => $storedPath,
                    'fileUrl' => $fileUrl,
                    'mimeType' => $file->getMimeType(),
                    'fileSize' => $file->getSize(),
                    'uploadedBy' => $regNo,
                    'uploadedAt' => now()->toDateTimeString(),
                ];
            }

            $rows = $this->execVendorRegistrationSproc('SubmitRegistration', [
                'vendorPreRegistrationId' => $request->vendor_pre_registration_id,
                'regNo' => $regNo,
                'vendorInfo' => $vendorInfo,
                'uploadedDocuments' => $uploadedDocuments,
                'updatedBy' => $regNo,
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
                    'message' => $result->message ?? 'Unable to submit vendor registration.',
                    'missing_documents' => $this->decodeMissingDocuments($result->missingDocuments ?? null),
                ], $statusCode);
            }

            return response()->json([
                'success' => true,
                'message' => $result->message ?? 'Vendor registration submitted for accreditation.',
                'data' => $this->decodeApplicationResult($result->result ?? null),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('VENDOR REGISTRATION SUBMIT API ERROR', [
                'reg_no' => $regNo,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to submit vendor registration.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function accreditationAction(Request $request)
    {
        Log::info('VENDOR ACCREDITATION RAW REQUEST', [
            'payload' => $request->all(),
            'content_type' => $request->header('Content-Type'),
            'ip' => $request->ip(),
        ]);

        $validator = Validator::make($request->all(), [
            'vendor_pre_registration_id' => 'nullable',
            'reg_no' => 'required|string|max:50',
            'action' => 'required|string|in:approve,reject,return',
            'remarks' => 'nullable|string',
            'reviewed_by' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid accreditation action.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $action = strtolower(trim($request->action));

            $params = json_encode([
                'vendor_pre_registration_id' => $request->vendor_pre_registration_id,
                'reg_no' => strtoupper(trim($request->reg_no)),
                'action' => $action,
                'remarks' => $request->remarks,
                'reviewed_by' => $request->reviewed_by ?: 'SYSTEM',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $rows = DB::select(
                'EXEC dbo.sproc_PHP_VendorAccreditation @mode = ?, @params = ?',
                ['AccreditationAction', $params]
            );

            $result = $rows[0] ?? null;

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'No response returned from accreditation procedure.',
                ], 500);
            }

            $success = (bool) ($result->success ?? false);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => $result->message ?? 'Accreditation action failed.',
                ], 422);
            }

            $approvalEmailSent = false;

            if ($action === 'approve') {
                $approvalEmailSent = $this->sendApprovalAccessEmail(
                    strtoupper(trim($request->reg_no)),
                    $result->vendor_code ?? null
                );
            }

            return response()->json([
                'success' => true,
                'message' => $result->message ?? 'Accreditation action saved.',
                'data' => [
                    'status' => $result->status ?? null,
                    'vendor_code' => $result->vendor_code ?? null,
                    'vendorCode' => $result->vendor_code ?? null,
                    'vendor_master_created' => (bool) ($result->vendor_master_created ?? false),
                    'vendorMasterCreated' => (bool) ($result->vendor_master_created ?? false),
                    'procurement_allowed' => (bool) ($result->procurement_allowed ?? false),
                    'procurementAllowed' => (bool) ($result->procurement_allowed ?? false),
                    'approval_email_sent' => $approvalEmailSent,
                    'approvalEmailSent' => $approvalEmailSent,
                    'remarks' => $request->remarks,
                    'reviewedBy' => $request->reviewed_by ?: 'SYSTEM',
                    'reviewedAt' => now(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('VENDOR ACCREDITATION API ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process accreditation action.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function approvedVendorLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vendor_code' => 'required|string|max:50',
            'access_key' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor Code and Access Key are required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $vendorCode = strtoupper(trim($request->vendor_code));
        $accessKey = strtoupper(trim($request->access_key));

        $vendor = DB::table('VEND_MASTREG')
            ->where('VEND_CODE', $vendorCode)
            ->first();

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Vendor Code or Access Key.',
            ], 401);
        }

        if (strtoupper(trim((string) $vendor->REG_STATUS)) !== 'APPROVED') {
            return response()->json([
                'success' => false,
                'message' => 'Vendor is not yet approved.',
            ], 403);
        }

        if (empty($vendor->ACCESS_KEY_HASH) || !Hash::check($accessKey, $vendor->ACCESS_KEY_HASH)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Vendor Code or Access Key.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Approved vendor access verified.',
            'data' => [
                'vendorCode' => $vendor->VEND_CODE,
                'vendor_code' => $vendor->VEND_CODE,
                'vendorName' => $vendor->VEND_NAME,
                'vendor_name' => $vendor->VEND_NAME,
                'vendorEmail' => $vendor->VEND_EMAIL,
                'vendor_email' => $vendor->VEND_EMAIL,
                'regNo' => $vendor->REG_NO,
                'reg_no' => $vendor->REG_NO,
                'status' => $vendor->REG_STATUS,
            ],
        ], 200);
    }

    private function generateRegistrationNo(): string
    {
        return 'VPR-' . now()->format('Ymd') . '-' . random_int(1000, 9999);
    }

    private function sendRegistrationAccessEmailAfterResponse(
        string $vendorEmail,
        string $vendorName,
        string $regNo,
        string $accessKey,
        string $registrationLink,
        int $vendId,
        string $registeredBy
    ): void {
        $mailData = [
            'vendor_email' => $vendorEmail,
            'vendor_name' => $vendorName,
            'reg_no' => $regNo,
            'temp_user_id' => $regNo,
            'access_key' => $accessKey,
            'registration_link' => $registrationLink,
        ];

        app()->terminating(function () use ($vendorEmail, $mailData, $regNo, $vendId, $registeredBy) {
            try {
                Mail::to($vendorEmail)
                    ->send(new VendorRegistrationAccessMail($mailData));

                $this->execVendorPreRegistrationSproc('UpdateEmailStatus', [
                    'regNo' => $regNo,
                    'vendId' => $vendId,
                    'emailSent' => 'Y',
                    'registeredBy' => $registeredBy,
                ]);
            } catch (\Throwable $e) {
                try {
                    $this->execVendorPreRegistrationSproc('UpdateEmailStatus', [
                        'regNo' => $regNo,
                        'vendId' => $vendId,
                        'emailSent' => 'N',
                        'registeredBy' => $registeredBy,
                    ]);
                } catch (\Throwable $statusException) {
                    Log::warning('VENDOR REGISTRATION EMAIL FAILED STATUS UPDATE FAILED', [
                        'reg_no' => $regNo,
                        'vend_id' => $vendId,
                        'message' => $statusException->getMessage(),
                    ]);
                }

                Log::error('VENDOR REGISTRATION EMAIL SEND FAILED AFTER RESPONSE', [
                    'reg_no' => $regNo,
                    'vend_id' => $vendId,
                    'vendor_email' => $vendorEmail,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]);
            }
        });
    }

    private function sendApprovalAccessEmail(string $regNo, ?string $vendorCode): bool
    {
        $vendor = DB::table('VEND_MASTREG')
            ->where('REG_NO', $regNo)
            ->first();

        if (!$vendor || empty($vendor->VEND_EMAIL)) {
            Log::warning('VENDOR APPROVAL EMAIL SKIPPED', [
                'reg_no' => $regNo,
                'reason' => 'Vendor record or email address not found.',
            ]);

            return false;
        }

        $accessKey = strtoupper(Str::random(10));
        $resolvedVendorCode = strtoupper(trim($vendorCode ?: $vendor->VEND_CODE));
        $portalUrl = rtrim(env('VENDOR_PORTAL_URL', config('app.url')), '/');
        $approvedAccessLink = $portalUrl . '/vendor-approved-access/' . urlencode($resolvedVendorCode);

        DB::table('VEND_MASTREG')
            ->where('VEND_ID', $vendor->VEND_ID)
            ->update([
                'VEND_CODE' => $resolvedVendorCode,
                'ACCESS_KEY_HASH' => Hash::make($accessKey),
                'ACCESS_KEY_USED' => 'N',
                'UPDATED_BY' => 'SYSTEM',
                'UPDATED_DATE' => now(),
            ]);

        $mailData = [
            'vendor_email' => $vendor->VEND_EMAIL,
            'vendor_code' => $resolvedVendorCode,
            'vendor_name' => $vendor->VEND_NAME,
            'access_key' => $accessKey,
            'portal_link' => $approvedAccessLink,
        ];

        app()->terminating(function () use ($vendor, $mailData, $regNo, $resolvedVendorCode) {
            try {
                Mail::to($vendor->VEND_EMAIL)
                    ->send(new VendorApprovalAccessMail($mailData));
            } catch (\Throwable $e) {
                Log::error('VENDOR APPROVAL EMAIL SEND FAILED AFTER RESPONSE', [
                    'reg_no' => $regNo,
                    'vend_id' => $vendor->VEND_ID,
                    'vendor_code' => $resolvedVendorCode,
                    'vendor_email' => $vendor->VEND_EMAIL,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]);
            }
        });

        return true;
    }

    private function execVendorPreRegistrationSproc(string $mode, array $jsonData): array
    {
        $params = json_encode([
            'json_data' => $jsonData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return DB::select(
            'EXEC dbo.sproc_PHP_VendorPreRegistration @mode = ?, @params = ?',
            [$mode, $params]
        );
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
        $application = [];

        if (is_array($json)) {
            $application = $json;
        } elseif (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
            $application = is_array($decoded) ? $decoded : [];
        } elseif (is_object($json)) {
            $application = json_decode(json_encode($json), true) ?: [];
        }

        return $this->normalizeApplication($application);
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

    private function decodeMissingDocuments($json): array
    {
        if (empty($json)) {
            return [];
        }

        $decoded = is_string($json) ? json_decode($json, true) : $json;

        if (!is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(function ($item) {
                if (is_array($item)) {
                    return $item['docCode'] ?? $item['code'] ?? null;
                }

                return $item;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function mapSupplierSource(?string $supplierType): string
    {
        $type = strtoupper(trim((string) $supplierType));

        return match ($type) {
            'LOCAL SUPPLIER' => 'L',
            'FOREIGN SUPPLIER' => 'F',
            'SERVICE PROVIDER' => 'S',
            'CONTRACTOR' => 'C',
            default => 'L',
        };
    }
}
