<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VendorEmailController;
use App\Http\Controllers\Api\VendorAccreditationDocumentController;
use App\Http\Controllers\Api\VendorCanvassController;

/*
|--------------------------------------------------------------------------
| Authorized User Login
|--------------------------------------------------------------------------
*/

Route::post('/authorized-login', [
    AuthController::class,
    'authorizedLogin'
]);

/*
|--------------------------------------------------------------------------
| Vendor Portal - Pre-registration
|--------------------------------------------------------------------------
*/

Route::post('/vendor-portal/send-registration-email', [
    VendorEmailController::class,
    'sendRegistrationAccess'
]);

/*
|--------------------------------------------------------------------------
| Vendor Portal - Temporary Login
| Registration No. + Access Key
|--------------------------------------------------------------------------
*/

Route::post('/vendor-portal/temporary-login', [
    VendorEmailController::class,
    'temporaryLogin'
]);

/*
|--------------------------------------------------------------------------
| Vendor Portal - Vendor Registration Submit
| Updates VEND_MASTREG and sets REG_STATUS = FOR ACCREDITATION
|--------------------------------------------------------------------------
*/

Route::post('/vendor-portal/vendor-registration/submit', [
    VendorEmailController::class,
    'submitVendorRegistration'
]);

/*
|--------------------------------------------------------------------------
| Vendor Portal - Accreditation Documents
|--------------------------------------------------------------------------
*/

Route::get('/vendor-portal/accreditation-documents', [
    VendorAccreditationDocumentController::class,
    'index'
]);

Route::get('/vendor-portal/pre-registrations/{regNo}/documents', [
    VendorAccreditationDocumentController::class,
    'getByRegistration'
]);

Route::get('/vendor-portal/applications', [
    VendorAccreditationDocumentController::class,
    'applications'
]);

Route::post('/vendor-portal/accreditation/action', [
    VendorEmailController::class,
    'accreditationAction'
]);

Route::post('/vendor-portal/approved-vendor-login', [VendorEmailController::class, 'approvedVendorLogin']);

Route::post('/vendor-portal/canvass/by-vendor-code', [VendorCanvassController::class, 'getVendorCanvassByVendCode']);
Route::post('/vendor-portal/canvass/submit', [VendorCanvassController::class, 'submitVendorCanvass']);