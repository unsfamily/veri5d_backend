<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use App\Models\roll_user;
use App\Models\product;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\WellcomeMail;
use App\Mail\bookingeMail;
use App\Mail\passwordMail;
use Illuminate\Support\Facades\Crypt;
use App\Models\user_payment_list;


/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         version="1.0.0",
 *         title="Veri5d API Documentation",
 *         description="API documentation for Veri5d Application",
 *         @OA\Contact(
 *             email="support@Veri5d.com"
 *         )
 *     ),
 *     @OA\Components(
 *         @OA\SecurityScheme(
 *             securityScheme="bearerAuth",
 *             type="http",
 *             scheme="bearer",
 *             bearerFormat="JWT"
 *         )
 *     )
 * )
 */
class auth_controller extends Controller
{
    public function forget_password(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $otp = rand(100000, 999999);
        $user = User::where('email', $request->email)->where('active', 1)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Active user not found with this email.'
            ], 404);
        }
        $user->update(['otp' => $otp]);
        Mail::to($request->email)->send(new passwordMail($otp));
        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully to your email address.'
        ], 200);
    }
    public function forget_password_reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|digits:6', // OTP must be exactly 6 digits
            'password' => 'required|string|min:6|confirmed', // Confirmed password (optional if you want password confirmation)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = User::where('email', $request->email)
                    ->where('otp', $request->otp)
                    ->where('active', 1)
                    ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP or inactive user.'
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'otp' => null, // Clear OTP after reset
        ]);

        return response()->json([
            'success' => true,
            'message' => 'New password created successfully.',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user_type' => $user->user_type,
            'name' => $user->name,
        ], 200);
    }
    public function email()
    {
        $data = "bookorder";
        Mail::to('logeswarankarthikeyan@gmail.com')->send(new bookingeMail($data));
        return $data;
    }
    public function email_dd(Request $request)
    {
        $originalOTP = Crypt::decrypt($request->email_otp);
        if ($originalOTP == $request->otp) {
            return $originalOTP;
        } else {
            return $originalOTP;
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/register_otp",
     *     summary="Register user and send OTP via email",
     *     tags={"Users"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "phone", "password", "address", "country", "state", "city", "gender", "pin_code"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", example="+919876543210"),
     *             @OA\Property(property="password", type="string", format="password", example="secret123"),
     *             @OA\Property(property="address", type="string", example="123 Street Name"),
     *             @OA\Property(property="country", type="string", example="India"),
     *             @OA\Property(property="state", type="string", example="Karnataka"),
     *             @OA\Property(property="city", type="string", example="Bangalore"),
     *             @OA\Property(property="referral_code", type="string", example="REF12345"),
     *             @OA\Property(property="gender", type="string", example="Male"),
     *             @OA\Property(property="pin_code", type="string", example="560001")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="OTP sent and registration data prepared",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="OTP Send successfully"),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", example="+919876543210"),
     *             @OA\Property(property="password", type="string", example="secret123"),
     *             @OA\Property(property="address", type="string", example="123 Street Name"),
     *             @OA\Property(property="country", type="string", example="India"),
     *             @OA\Property(property="state", type="string", example="Karnataka"),
     *             @OA\Property(property="city", type="string", example="Bangalore"),
     *             @OA\Property(property="referral_code", type="string", example="REF12345"),
     *             @OA\Property(property="gender", type="string", example="Male"),
     *             @OA\Property(property="pin_code", type="string", example="560001"),
     *             @OA\Property(property="OTP_send", type="string", example="EncryptedOTPStringHere")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or referral not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */

    public function register_otp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|min:3',
            'email' => 'required|email|unique:users,email',
            'phone' => [
                'required',
                'unique:users,phone',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'password' => 'required|string|min:6',
            'address' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'referral_code' => 'nullable|string|max:255',
            'gender' => 'required|string|max:255',
            'pin_code' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $referrer = null;
        if ($request->filled('referral_code')) {
            $referrer = User::where('referral_code', $request->referral_code)->first();
            if (!$referrer) {
                return response()->json([
                    'error' => 'Referrer ID Not Found',
                ], 400);
            }
        }
        $new_OTP = rand(100000, 999999);
        Mail::to($request->email)->send(new WellcomeMail($new_OTP));
        $send = Crypt::encrypt($new_OTP);
        return response()->json([
            'message' => 'OTP Send successfully',
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => $request->password,
            'address' => $request->address,
            'country' => $request->country,
            'state' => $request->state,
            'city' => $request->city,
            'referral_code' => $request->referral_code,
            'gender' => $request->gender,
            'pin_code' => $request->pin_code,
            'OTP_send' => $send,
        ], 201);
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/register",
     *     summary="Register a new user after OTP verification",
     *     tags={"Users"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "phone", "password", "address", "country", "state", "city", "gender", "pin_code", "OTP_send", "email_otp"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", example="+919876543210"),
     *             @OA\Property(property="password", type="string", format="password", example="secret123"),
     *             @OA\Property(property="address", type="string", example="123 Main St"),
     *             @OA\Property(property="country", type="string", example="India"),
     *             @OA\Property(property="state", type="string", example="Karnataka"),
     *             @OA\Property(property="city", type="string", example="Bangalore"),
     *             @OA\Property(property="referral_code", type="string", example="REF12345", nullable=true),
     *             @OA\Property(property="gender", type="string", example="Male"),
     *             @OA\Property(property="pin_code", type="string", example="560001"),
     *             @OA\Property(property="OTP_send", type="string", example="EncryptedOTPStringHere"),
     *             @OA\Property(property="email_otp", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User registered successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User registered successfully"),
     *             @OA\Property(property="token", type="string", example="1|AbcDefGhiJklMNOPQRSTUVWXYZ"),
     *             @OA\Property(property="user_type", type="string", example="us"),
     *             @OA\Property(property="name", type="string", example="John Doe")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or OTP mismatch",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */




    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|min:3',
            'email' => 'required|email|unique:users,email',
            'phone' => [
                'required',
                'unique:users,phone',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'password' => 'required|string|min:6',
            'address' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'referral_code' => 'nullable|string|max:255',
            'gender' => 'required|string|max:255',
            'pin_code' => 'required|string|max:255',
            'OTP_send' => 'required|string|max:255',
            'email_otp' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }

        $referrer = null;
        if ($request->filled('referral_code')) {
            $referrer = User::where('referral_code', $request->referral_code)->first();
        }
        $originalOTP = Crypt::decrypt($request->OTP_send);
        if ($originalOTP != $request->email_otp) {
            return response()->json([
                'error' => 'OTP not match',
            ], 400);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'address' => $request->address,
            'country' => $request->country,
            'state' => $request->state,
            'city' => $request->city,
            'gender' => $request->gender,
            'pin_code' => $request->pin_code,
            'referral_code' => time() . Str::random(10),
            'user_type' => 'us',
            'add_by' => $referrer?->id,
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'token' => $user->createToken('test')->plainTextToken,
            'user_type' => $user->user_type,
            'name' => $user->name,
        ], 200);
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/login",
     *     summary="login a new user",
     *     tags={"Users"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password",},
     *             @OA\Property(property="email", type="string", example="email@example.com"),
     *             @OA\Property(property="password", type="password", example="password123"),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User registered successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="user", type="object"),
     *             @OA\Property(property="token", type="string", example="generated_token")
     *         )
     *     )
     * )
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:6',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $credentials = $request->only('email', 'password');
        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            if($user->user_type == 'ad'){
                $find_role = roll_user::where('active',1)->where('user_id',$user->id)->first();
                $roll = $find_role->id_roll;
            }else{
                $roll = null;
            }
            $response = [
                'message' => 'Login successful',
                'token' => $user->createToken('customerToken')->plainTextToken,
                'user_type' => $user->user_type,
                'name' => $user->name,
                'role'=>$roll
            ];
            return response()->json($response, 200);
        }
        return response()->json([
            'message' => 'Invalid credentials'
        ], 401);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/logout",
     *     summary="logout a user",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User logout successfully.!",
     *     )
     * )
     */
    public function logout()
    {
        $user = Auth::user();
        if ($user) {
            $user->tokens()->delete();  // Revoke all tokens for the user
            return response()->json([
                'message' => 'User successfully logged out',
            ], 200);
        }
        return response()->json([
            'message' => 'No authenticated user found',
        ], 401);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/test",
     *     summary="Test the API",
     *     tags={"Users"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Working successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="working successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function test()
    {

        $data = 'V5Id9JgOYDESFo';
        $find_order = Order_tracking::whereNotNull('additional_information')
        ->get()
        ->filter(function ($item) use ($data) {
            // First decode the outer string
            $firstDecode = json_decode($item->additional_information, true);

            // Then decode the actual JSON inside
            $info = is_string($firstDecode) ? json_decode($firstDecode, true) : null;

            return is_array($info) && isset($info['order_id']) && $info['order_id'] == $data;
        })
        ->values();



        // $find_product = product::find(1);
        // if (!$find_product) {
        //     return response()->json(['message' => 'Product not found'], 404);
        // }
        // $total_price = $find_product->totel_price;
        // $profit = $find_product->profit;
        // $product_to = $find_product->user_id;
        // $current = roll_user::select(
        //     'roll_users.user_id',
        //     'roll_users.under_user',
        //     'rolls.roll_name',
        //     'rolls.share'
        // )
        //     ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
        //     ->where('roll_users.user_id', $product_to)
        //     ->where('roll_users.active', 1)
        //     ->first();

        // if (!$current) {
        //     return response()->json(['message' => 'User not found or inactive'], 404);
        // }
        // $path = [];
        // while ($current) {
        //     $path[] = [
        //         'user_id'   => $current->user_id,
        //         'roll_name' => $current->roll_name,
        //         'share'     => floatval($current->share)
        //     ];
        //     if (!$current->under_user) break;
        //     $current = roll_user::select(
        //         'roll_users.user_id',
        //         'roll_users.under_user',
        //         'rolls.roll_name',
        //         'rolls.share'
        //     )
        //         ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
        //         ->where('roll_users.user_id', $current->under_user)
        //         ->where('roll_users.active', 1)
        //         ->first();
        // }
        // $reversedPath = array_reverse($path);
        // $distribution = [];
        // foreach ($reversedPath as $user) {
        //     $userShare = round(($profit * $user['share']) / 100, 2);
        //     user_payment_list::create([
        //         'Share' => $user['share'],
        //         'profit_share' => $userShare,
        //         'order_id' => 1,
        //         'product_id' => $find_product->id,
        //         'user_id' => $user['user_id'],
        //     ]);
        //     $distribution[] = [
        //         'user_id'   => $user['user_id'],
        //         'roll_name' => $user['roll_name'],
        //         'share'     => $user['share'] . '%',
        //         'amount'    => $userShare
        //     ];
        // }
        // return response()->json([
        //     'message' => 'Profit distribution completed.',
        //     'distribution' => $distribution
        // ]);
    }
}
