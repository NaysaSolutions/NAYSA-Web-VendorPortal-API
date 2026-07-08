<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function authorizedLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_code' => 'required|string',
            'password'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'User ID and password are required.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $userCode = strtoupper(trim($request->user_code));
        $password = $request->password;

        $user = DB::table('users')
            ->select([
                'USER_CODE as user_code',
                'USER_NAME as user_name',
                'PASSWORD as password',
                'EMAIL_ADD as email_add',
                'USER_TYPE as user_type',
                'ACTIVE as active',
            ])
            ->where('USER_CODE', $userCode)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid user ID or password.',
            ], 401);
        }

        if (strtoupper((string) $user->active) !== 'Y') {
            return response()->json([
                'success' => false,
                'message' => 'User account is inactive.',
            ], 403);
        }

        $storedPassword = $user->password ?? '';

        $passwordMatched = false;

        if ($storedPassword) {
            if (str_starts_with($storedPassword, '$2y$') || str_starts_with($storedPassword, '$2a$') || str_starts_with($storedPassword, '$2b$')) {
                $passwordMatched = Hash::check($password, $storedPassword);
            } else {
                $passwordMatched = $password === $storedPassword;
            }
        }

        if (!$passwordMatched) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid user ID or password.',
            ], 401);
        }

        /*
            Temporary role mapping:
            USER_TYPE = S means approver/admin.
            You may adjust this depending on your actual user access setup.
        */
        $role = strtoupper((string) $user->user_type) === 'S'
            ? 'APPROVER'
            : 'AUTHORIZED_USER';

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user_code' => $user->user_code,
                'user_name' => $user->user_name,
                'email'     => $user->email_add,
                'user_type' => $user->user_type,
                'role'      => $role,
            ],
        ], 200);
    }
}