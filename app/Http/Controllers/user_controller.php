<?php

namespace App\Http\Controllers;

use App\Models\add_to_cart;
use App\Models\Order_tracking;
use App\Models\User;
use App\Models\offer_code;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\product;
use Exception;
use Razorpay\Api\Api;
use Carbon\Carbon;
use App\Models\roll_user;
use App\Models\user_payment_list;
use App\Models\product_review;
use App\Models\payment_to_vendor;
use App\Models\other;
use App\Models\used_code;
use Illuminate\Support\Facades\Mail;
use App\Mail\bookingeMail;
use Ixudra\Curl\Facades\Curl;

class user_controller extends Controller
{
    public function phonePe()
    {


        $data = [
            'merchantId' => 'PGTESTPAYUAT105',
            'merchantTransactionId' => uniqid(),
            'merchantUserId' => 'MUID123',
            'amount' => 10000, // in paise
            'redirectUrl' => route('api.phonepe.response'),
            'redirectMode' => 'POST',
            'callbackUrl' => route('api.phonepe.response'),
            'mobileNumber' => '9999999999',
            'paymentInstrument' => [
                'type' => 'PAY_PAGE',
            ],
        ];

        $jsonData = json_encode($data);
        $encodedData = base64_encode($jsonData);

        $saltKey = 'c45b52fe-f2c5-4ef6-a6b5-131aa89ed133';
        $saltIndex = 1;

        $stringToHash = $encodedData . "/pg/v1/pay" . $saltKey;
        $hashed = hash('sha256', $stringToHash);
        $finalXHeader = $hashed . "###" . $saltIndex;

        $response = Curl::to('https://api-preprod.phonepe.com/apis/merchant-simulator/pg/v1/pay')
            ->withHeader('Content-Type: application/json')
            ->withHeader('X-VERIFY: ' . $finalXHeader)
            ->withData(json_encode(['request' => $encodedData]))
            ->post();

        $result = json_decode($response);

        // return $result;

        return redirect()->to($result->data->instrumentResponse->redirectInfo->url ?? '/payment-error');
    }
    public function response(Request $request)
    {
        $input = $request->all();

        $saltKey = 'c45b52fe-f2c5-4ef6-a6b5-131aa89ed133';
        $saltIndex = 1;

        $merchantId = $input['merchantId'] ?? 'MERCHANTUAT';
        $transactionId = $input['transactionId'] ?? null;

        if (!$transactionId) {
            return response()->json(['error' => 'Transaction ID missing.'], 400);
        }

        $stringToHash = "/pg/v1/status/{$merchantId}/{$transactionId}" . $saltKey;
        $hashed = hash('sha256', $stringToHash);
        $finalXHeader = $hashed . "###" . $saltIndex;

        $response = Curl::to("https://api-preprod.phonepe.com/apis/merchant-simulator/pg/v1/status/{$merchantId}/{$transactionId}")
            ->withHeader('Content-Type: application/json')
            ->withHeader('accept: application/json')
            ->withHeader('X-VERIFY: ' . $finalXHeader)
            ->withHeader('X-MERCHANT-ID: ' . $merchantId)
            ->get();

        return response()->json(json_decode($response));
    }
    public function refundProcess()
    {
        $merchantTransactionId = 'PGTESTPAYUAT105';
        $originalTransactionId = strrev($merchantTransactionId); // mock reversal
        $saltKey = 'c45b52fe-f2c5-4ef6-a6b5-131aa89ed133';
        $saltIndex = 1;

        $payload = [
            'merchantId' => 'MERCHANTUAT',
            'merchantUserId' => 'MUID123',
            'merchantTransactionId' => $merchantTransactionId,
            'originalTransactionId' => $originalTransactionId,
            'amount' => 5000,
            'callbackUrl' => route('response'),
        ];

        $encoded = base64_encode(json_encode($payload));
        $toHash = $encoded . '/pg/v1/refund' . $saltKey;
        $hash = hash('sha256', $toHash);
        $xVerify = $hash . '###' . $saltIndex;

        $refundResponse = Curl::to('https://api-preprod.phonepe.com/apis/merchant-simulator/pg/v1/refund')
            ->withHeader('Content-Type: application/json')
            ->withHeader('X-VERIFY: ' . $xVerify)
            ->withData(json_encode(['request' => $encoded]))
            ->post();

        // Check status
        $statusHash = hash('sha256', "/pg/v1/status/MERCHANTUAT/{$merchantTransactionId}" . $saltKey) . "###{$saltIndex}";
        $statusResponse = Curl::to("https://api-preprod.phonepe.com/apis/merchant-simulator/pg/v1/status/MERCHANTUAT/{$merchantTransactionId}")
            ->withHeader('Content-Type: application/json')
            ->withHeader('accept: application/json')
            ->withHeader('X-VERIFY: ' . $statusHash)
            ->withHeader('X-MERCHANT-ID: ' . $merchantTransactionId)
            ->get();

        return response()->json([
            'refundResponse' => json_decode($refundResponse),
            'statusResponse' => json_decode($statusResponse)
        ]);
    }
    public function pay(Request $request)
    {
        $request->validate([
            'merchantId' => 'required|string',
            'merchantUserId' => 'required|string',
            'amount' => 'required|integer',
            'mobileNumber' => 'required|string',
        ]);

        $payload = [
            'merchantId'            => $request->merchantId,
            'merchantTransactionId' => uniqid('txn_', true),
            'merchantUserId'        => $request->merchantUserId,
            'amount'                => $request->amount,
            'redirectUrl'           => route('api.phonepe.status'),
            'redirectMode'          => 'POST',
            'callbackUrl'           => route('api.phonepe.status'),
            'mobileNumber'          => $request->mobileNumber,
            'paymentInstrument'     => [
                'type' => 'PAY_PAGE',
            ],
        ];

        $encoded = base64_encode(json_encode($payload));
        $saltKey  = config('services.phonepe.salt_key');
        $saltIdx  = config('services.phonepe.salt_index');

        $toHash   = $encoded . '/pg/v1/pay' . $saltKey;
        $signature = hash('sha256', $toHash) . "###{$saltIdx}";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-VERIFY'     => $signature,
        ])
            ->post(config('services.phonepe.base_url') . '/pg/v1/pay', [
                'request' => $encoded,
            ]);

        if ($response->failed()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Payment initiation failed',
                'error'   => $response->body(),
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $response->json('data.instrumentResponse.redirectInfo'),
        ]);
    }
    /**
     * Check payment or refund status.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(Request $request)
    {
        $request->validate([
            'merchantId'    => 'required|string',
            'transactionId' => 'required|string',
        ]);

        $merchantId    = $request->merchantId;
        $transactionId = $request->transactionId;
        $saltKey       = config('services.phonepe.salt_key');
        $saltIdx       = config('services.phonepe.salt_index');

        $toHash        = "/pg/v1/status/{$merchantId}/{$transactionId}" . $saltKey;
        $signature     = hash('sha256', $toHash) . "###{$saltIdx}";

        $response = Http::withHeaders([
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
            'X-VERIFY'        => $signature,
            'X-MERCHANT-ID'   => $merchantId,
        ])
            ->get(config('services.phonepe.base_url') . "/pg/v1/status/{$merchantId}/{$transactionId}");

        if ($response->failed()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Status check failed',
                'error'   => $response->body(),
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $response->json('data'),
        ]);
    }
    /**
     * Initiate a refund.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refund(Request $request)
    {
        $request->validate([
            'merchantId'            => 'required|string',
            'merchantUserId'        => 'required|string',
            'merchantTransactionId' => 'required|string',
            'originalTransactionId' => 'required|string',
            'amount'                => 'required|integer',
        ]);

        $payload = [
            'merchantId'            => $request->merchantId,
            'merchantUserId'        => $request->merchantUserId,
            'merchantTransactionId' => $request->merchantTransactionId,
            'originalTransactionId' => $request->originalTransactionId,
            'amount'                => $request->amount,
            'callbackUrl'           => route('api.phonepe.status'),
        ];

        $encoded   = base64_encode(json_encode($payload));
        $saltKey   = config('services.phonepe.salt_key');
        $saltIdx   = config('services.phonepe.salt_index');

        $toHash    = $encoded . '/pg/v1/refund' . $saltKey;
        $signature = hash('sha256', $toHash) . "###{$saltIdx}";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-VERIFY'     => $signature,
        ])
            ->post(config('services.phonepe.base_url') . '/pg/v1/refund', [
                'request' => $encoded,
            ]);

        if ($response->failed()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Refund failed',
                'error'   => $response->body(),
            ], 400);
        }

        return $this->status(new Request([
            'merchantId'    => $request->merchantId,
            'transactionId' => $request->merchantTransactionId,
        ]));
    }
    public function createOrder(Request $request)
    {
        $api = new Api(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));

        $orderData = [
            'receipt'         => 'rcptid_' . uniqid(),
            'amount'          => $request->amount * 100, // amount in paise
            'currency'        => 'INR',
            'payment_capture' => 1, // auto capture
        ];
        $razorpayOrder = $api->order->create($orderData);
        return response()->json([
            'order_id' => $razorpayOrder['id'],
            'amount'   => $orderData['amount'],
            'currency' => $orderData['currency'],
        ]);
    }
    public function getOrderDetails($order_id)
    {
        try {
            $api = new Api(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));
            $order = $api->order->fetch($order_id);

            return response()->json([
                'id' => $order['id'],
                'entity' => $order['entity'],
                'amount' => $order['amount'],
                'amount_paid' => $order['amount_paid'],
                'amount_due' => $order['amount_due'],
                'currency' => $order['currency'],
                'receipt' => $order['receipt'],
                'offer_id' => $order['offer_id'],
                'status' => $order['status'],
                'attempts' => $order['attempts'],
                'notes' => $order['notes'],
                'created_at' => $order['created_at'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to fetch order details',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    protected $api;
    public function __construct()
    {
        $this->api = new Api(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));
    }
    /**
     * Initiate a refund for a given payment ID
     * POST /api/refund/{payment_id}
     */
    public function refundPayment(Request $request, $payment_id)
    {
        try {
            $refund = $this->api->payment->fetch($payment_id)->refund([
                'amount' => $request->input('amount') // amount in paise
            ]);
            return response()->json($refund->toArray());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Refund failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get refund details by refund ID
     * GET /api/refund-details/{refund_id}
     */
    public function getRefundDetails($refund_id)
    {
        try {
            $refund = $this->api->refund->fetch($refund_id);
            return response()->json($refund->toArray());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve refund details',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function add_product_reviews(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = Auth::user();

        $find_order = Order_tracking::where('product_id', $request->product_id)
            ->where('user_id', $user->id)
            ->where('active', 1)
            ->first();

        if (!$find_order) {
            return response()->json(['message' => 'User not allowed to review this product'], 403);
        }

        $data = [
            'comment' => $request->comment,
            'product_id' => $request->product_id,
            'rating' => $request->rating,
            'user_id' => $user->id,
        ];

        if ($request->hasFile('image')) {
            $path = $this->uploadFile($request, 'image', 'image/profile/');
            $data['image'] = $path;
        }

        product_review::updateOrCreate(
            [
                'user_id' => $user->id,
                'product_id' => $request->product_id
            ],
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully!'
        ], 200);
    }
    public function delete_product_reviews($id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $find_catlog = product_review::where('id', $id)->first();
            if ($find_catlog) {
                $find_catlog->active = 0;
                $find_catlog->save();
                DB::commit();
                return response()->json(['message' => 'Cart Deleted Successfully.!'], 200);
            } else {
                return response()->json(['message' => 'data not found'], 400);
            }
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/edit_address",
     *     summary="Edit user address",
     *     tags={"User"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"address"},
     *             @OA\Property(property="address", type="string", example="123 Main Street"),
     *             @OA\Property(property="address_1", type="string", example="Near Park", nullable=true),
     *             @OA\Property(property="address_2", type="string", example="Apt 405", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User address updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User updated successfully!")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="An error occurred: Some error message")
     *         )
     *     )
     * )
     */
    public function edit_address(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'default_address' => 'required|string|min:1|max:255',
            'country' => 'required|string|min:1|max:255',
            'state' => 'required|string|min:1|max:255',
            'city' => 'required|string|min:1|max:255',
            'pin_code' => 'required|string|min:1|max:255',
            'type_of_address' => 'required|string|min:1|max:255',
            'address' => 'nullable|array|min:1',
            'address.*.type_of_address' => 'required|string|min:1',
            'address.*.address' => 'required|string|min:1',
            'address.*.house' => 'required|string|min:1',
            'address.*.street' => 'required|string|min:1',
            'address.*.city' => 'required|string|min:1',
            'address.*.state' => 'required|string|min:1',
            'address.*.country' => 'required|string|min:1',
            'address.*.zip_code' => 'required|string|min:1',
            'address.*.extra' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $existingAddresses = json_decode($user->address_1, true) ?? [];
            $newAddresses = $request->address ?? [];
            $mergedAddresses = array_merge($existingAddresses, $newAddresses);
            $updateData = [
                'address' => $request->default_address,
                'country' => $request->country,
                'state' => $request->state,
                'city' => $request->city,
                'pin_code' => $request->pin_code,
                'type_of_address' => $request->type_of_address,
                'address_1' => json_encode($mergedAddresses),
            ];
            $user->update($updateData);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'User updated successfully!'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
    public function remove_address(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $existingAddresses = json_decode($user->address_1, true) ?? [];
            $filteredAddresses = array_filter($existingAddresses, function ($address) use ($request) {
                return $address['address'] !== $request->address;
            });
            $filteredAddresses = array_values($filteredAddresses);
            $user->update([
                'address_1' => json_encode($filteredAddresses)
            ]);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Address removed successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/edit_profile",
     *     summary="Edit authenticated user's profile",
     *     description="Updates the authenticated user's profile details including optional profile photo and additional addresses.",
     *     operationId="editUserProfile",
     *     tags={"profile"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"name", "address", "country", "state", "city"},
     *                 @OA\Property(property="name", type="string", example="John Doe"),
     *                 @OA\Property(property="address", type="string", example="123 Main Street"),
     *                 @OA\Property(property="country", type="string", example="India"),
     *                 @OA\Property(property="state", type="string", example="Tamil Nadu"),
     *                 @OA\Property(property="city", type="string", example="Hosur"),
     *                 @OA\Property(property="address_1", type="string", example="Near Market"),
     *                 @OA\Property(property="address_2", type="string", example="Opp. Park"),
     *                 @OA\Property(
     *                     property="profile_photo_path",
     *                     type="file",
     *                     description="Profile photo (jpg, jpeg, png, webp)",
     *                     format="binary"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User updated successfully!")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="An error occurred: ...")
     *         )
     *     )
     * )
     */
    public function edit_profile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:1|max:255',
            // 'address' => 'required|string|max:255',
            'profile_photo_path' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'gender' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $updateData = [
                'name' => $request->name,
                'gender' => $request->gender,
                // 'address' => $request->address,
            ];
            if ($request->filled('DOB')) {
                $updateData['DOB'] = $request->DOB;
            }
            if ($request->hasFile('profile_photo_path')) {
                $path = $this->uploadFile($request, 'profile_photo_path', 'image/profile/');
                $updateData['profile_photo_path'] = $path;
            }
            $user->update($updateData);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'User updated successfully!'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_to_cart",
     *     summary="add new cart or wish list",
     *     tags={"Cart"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"product_id","quantity",},
     *             @OA\Property(property="product_id", type="string", example="1"),
     *             @OA\Property(property="quantity", type="string", example="12"),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Product add to Cart Successfully.!",
     *     )
     * )
     */
    public function add_to_cart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'nullable|string|min:1',
            'cart' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $data = [
                'quantity' => $request->quantity,
                'product_id' => $request->product_id,
                'cart' => $request->cart,
                'user_id'  => $user->id,
            ];
            add_to_cart::updateOrCreate(
                ['user_id' => $user->id, 'product_id' => $request->product_id, 'cart' => $request->cart],
                $data
            );
            DB::commit();
            return response()->json(['message' => 'Product add to Cart Successfully.!'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/delete_to_cart",
     *     summary="it help to delete cart or wish list",
     *     tags={"Cart"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="string", example="1"),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cart Deleted Successfully.!",
     *     )
     * )
     */
    public function delete_to_cart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:add_to_carts,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $find_catlog = add_to_cart::where('id', $request->id)->where('user_id', $user->id)->where('active', 1)->first();
            if ($find_catlog) {
                $find_catlog->active = 0;
                $find_catlog->save();
                DB::commit();
                return response()->json(['message' => 'Cart Deleted Successfully.!'], 200);
            } else {
                return response()->json(['message' => 'data not found'], 400);
            }
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_user_cart",
     *     summary="View user's cart and wishlist",
     *     tags={"Cart"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User cart and wishlist data",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="list_cart",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="product", type="object",
     *                         @OA\Property(property="id", type="integer", example=10),
     *                         @OA\Property(property="name", type="string", example="Smartphone X"),
     *                         @OA\Property(property="total_price", type="number", format="float", example=499.99),
     *                         @OA\Property(property="category_id", type="integer", example=2),
     *                         @OA\Property(property="brand_id", type="integer", example=1)
     *                     ),
     *                     @OA\Property(
     *                         property="images",
     *                         type="array",
     *                         @OA\Items(type="string", example="https://example.com/image1.jpg")
     *                     )
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="list_wish",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=2),
     *                     @OA\Property(property="product_id", type="integer", example=12),
     *                     @OA\Property(property="cart", type="boolean", example=false)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="An error occurred: Unexpected issue.")
     *         )
     *     )
     * )
     */
    public function view_user_cart()
    {
        try {
            $user = Auth::user();
            $list_cart = add_to_cart::with([
                'product:id,name,total_price,category_id,brand_id,parent_id,data',
                'images' => function ($q) {
                    $q->where('active', 1)
                        ->select('product_id', 'productImages')
                        ->limit(1);
                }
            ])
                ->where('user_id', $user->id)
                ->where('active', 1)
                ->where('cart', 1)
                ->get()
                ->map(function ($item) {
                    $product = $item->product;
                    if (!$product) {
                        $item->delivery_fee = null;
                        return $item;
                    }
                    $productData = json_decode($product->data, true);
                    if (is_null($product->parent_id)) {
                        $item->delivery_fee = $productData['individual_delivery_fee'] ?? null;
                    } else {
                        $parent = product::select('data')->find($product->parent_id);
                        $parentData = json_decode($parent->data ?? '{}', true);
                        $item->delivery_fee = $parentData['individual_delivery_fee'] ?? null;
                    }
                    unset($item->product->data);
                    return $item;
                });


            $list_wish = add_to_cart::with(['product:id,name,total_price,category_id,brand_id', 'images'])
                ->where('user_id', $user->id)->where('active', 1)->where('cart', 0)->get();
            return response()->json(['list_cart' => $list_cart, 'list_wish' => $list_wish], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_order",
     *     summary="Place a new order with multiple products",
     *     tags={"Orders"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"products", "payment_mode", "total_price", "shipping_details"},
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     required={"product_id", "quantity", "price"},
     *                     @OA\Property(property="product_id", type="integer", example=1),
     *                     @OA\Property(property="quantity", type="integer", example=2),
     *                     @OA\Property(property="price", type="number", format="float", example=199.99)
     *                 )
     *             ),
     *             @OA\Property(property="payment_mode", type="string", example="online"),
     *             @OA\Property(property="total_price", type="number", format="float", example=499.97),
     *             @OA\Property(property="payment_id", type="string", example="pay_ABC123"),
     *             @OA\Property(property="payment_amount", type="number", format="float", example=499.97),
     *             @OA\Property(property="shipping_details", type="string", example="123 Main St, New York, NY 10001")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order placed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Products ordered successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or payment mismatch",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object"),
     *             @OA\Property(property="error", type="string", example="Payment amount does not match total price")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="An unexpected error occurred.")
     *         )
     *     )
     * )
     */

    public function add_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.price' => 'required|numeric',
            'payment_mode' => 'required|string|min:1',
            'total_price' => 'required|numeric',
            'payment_id' => 'nullable|string',
            'payment_amount' => 'nullable|numeric',
            'shipping_details' => 'required|string|min:1',
            'coupon_id' => 'nullable|numeric|exists:offer_codes,id',
            'address' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'pin_code' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $users = Auth::user();
        DB::beginTransaction();
        try {
            $order_id = "V5Id" . Str::random(10);
            if ($request->coupon_id) {
                $coupon = offer_code::where('id', $request->coupon_id)->where('active', 1)->first();
                if (!$coupon) {
                    return response()->json(['error' => 'Coupon not found.'], 400);
                } else {
                    if (Carbon::today()->gt(Carbon::parse($coupon->offer_up_to))) {
                        return response()->json(['error' => 'Coupon Expired.'], 400);
                    } else {
                        if ($coupon->totel_limit != null) {
                            if ($coupon->totel_limit > 0) {
                                $coupon->update([
                                    'totel_limit' => $coupon->totel_limit - 1,
                                ]);
                            } else {
                                $coupon->update([
                                    'active' => 0,
                                ]);
                                return response()->json(['error' => 'Coupon Expired.'], 400);
                            }
                        }
                        $totalPrice = collect($request->products)->sum('price');
                        $discount_price = $totalPrice - $request->total_price;
                        $codeData = [
                            'pin_code' => $request->pin_code,
                            'order_price' => $request->total_price,
                            'name' => $users->name,
                            'discount_price' => $discount_price,
                            'actual_price' => $totalPrice
                        ];
                        $addinfo = json_encode($codeData);
                        // used_code::create([
                        //     'code_id' => $coupon->id,
                        //     'order_id' => $order_id,
                        //     'offer_information' => $addinfo,
                        //     'user_id' => $users->id,
                        // ]);
                        $find_code = used_code::where('user_id', $users->id)->where('code_id', $coupon->id)->first();
                        $find_code->update([
                            'order_id' => $order_id,
                            'offer_information' => $addinfo,
                        ]);
                    }
                }
            }
            $orderData = [
                'pin_code' => $request->pin_code,
                'order_price' => $request->total_price,
                'order_id' => $order_id,
                'order_placed_on' => now()->toDateTimeString(),
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
            ];
            if ($request->coupon_id) {
                $orderData['coupon_code'] = $request->code;
            }
            $additionalInfo = json_encode($orderData);
            foreach ($request->products as $item) {
                $find_product = product::find($item['product_id']);
                $delivery_fee = null;
                $tax_category = null;
                $tax_price = 0;
                $tax_Rate = 0;
                $tax_name = '';
                if ($find_product) {
                    $productData = json_decode($find_product->data ?? '{}', true);
                    $delivery_fee = $productData['individual_delivery_fee'] ?? null;
                    $tax_category = $productData['tax_category'] ?? null;
                    if ($find_product->parent_id) {
                        $parent = product::find($find_product->parent_id);
                        $parentData = json_decode($parent->data ?? '{}', true);
                        $delivery_fee = $parentData['individual_delivery_fee'] ?? $delivery_fee;
                        $tax_category = $parentData['tax_category'] ?? $tax_category;
                    }
                    if ($tax_category) {
                        $find_tax = other::where('titel', 'tax')
                            ->where('id', $tax_category)
                            ->first();
                        if ($find_tax) {
                            $taxData = json_decode($find_tax->value ?? '{}', true);
                            $tax_Rate = $taxData['tax_Rate'] ?? 0;
                            $tax_name = $taxData['tax_name'] ?? '';
                            $tax_price = $find_product->total_price * ($tax_Rate / 100);
                        }
                    }
                }
                $decodedInfo = json_decode($additionalInfo, true);
                $combinedInfo = array_merge($decodedInfo, [
                    'tax_name' => $tax_name,
                    'tax_id' => $tax_category,
                ]);
                $finalInfo = json_encode($combinedInfo);
                $try =  Order_tracking::create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'totel_price' => $item['price'],
                    'payment_mode' => $request->payment_mode,
                    'shipping_details' => $request->shipping_details,
                    'payment_id' => $request->payment_id ?? null,
                    'user_id' => $users->id,
                    'status' => 'pending',
                    'additional_information' => $finalInfo,
                    'delivery_fee' => $delivery_fee,
                    'tax_price' => $tax_price,
                    'tax_Rate' => $tax_Rate,
                ]);

                if ($request->payment_mode !== 'cash_on_delivery') {
                    $vendorShare = $find_product->total_price - $find_product->profit;
                    payment_to_vendor::create([
                        'order_id' => $try->id,
                        'product_id' => $find_product->id,
                        'user_id' => $find_product['user_id'],
                        'payment_amount' => $vendorShare,
                    ]);
                    $add_v_share = user::find($find_product['user_id']);
                    if ($add_v_share) {
                        $add_v_share->increment('balance_amount', $vendorShare);
                    }
                    if (!$find_product) {
                        return response()->json(['message' => 'Product not found'], 400);
                    }
                    $total_price = $find_product->totel_price;
                    $profit = $find_product->profit;
                    $product_to = $find_product->user_id;
                    $find_user_a = User::find($product_to);
                    $current = roll_user::select(
                        'roll_users.user_id',
                        'roll_users.under_user',
                        'roll_users.id_roll',
                        'rolls.roll_name',
                        'rolls.share'
                    )
                        ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
                        ->where('roll_users.user_id', $find_user_a->add_by)
                        ->where('roll_users.active', 1)
                        ->first();
                    if (!$current) {
                        return response()->json(['message' => 'User not found or inactive'], 400);
                    }
                    $path = [];
                    while ($current) {
                        $path[] = [
                            'user_id'   => $current->user_id,
                            'roll_name' => $current->roll_name,
                            'id_roll'   => $current->id_roll,
                            'share'     => floatval($current->share)
                        ];
                        if (!$current->under_user) break;
                        $current = roll_user::select(
                            'roll_users.user_id',
                            'roll_users.under_user',
                            'rolls.roll_name',
                            'rolls.share',
                            'roll_users.id_roll'
                        )
                            ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
                            ->where('roll_users.user_id', $current->under_user)
                            ->where('roll_users.active', 1)
                            ->first();
                    }
                    $reversedPath = array_reverse($path);
                    foreach ($reversedPath as $user) {
                        $userShare = round(($profit * $user['share']) / 100, 2);
                        user_payment_list::create([
                            'Share' => $user['share'],
                            'profit_share' => $userShare,
                            'order_id' => $try->id,
                            'product_id' => $find_product->id,
                            'user_id' => $user['user_id'],
                            'id_roll' => $user['id_roll'],
                        ]);
                        $add_user_share = user::find($user['user_id']);
                        $add_user_share->update([
                            'balance_amount' => $add_user_share->balance_amount + $userShare,
                        ]);
                    }

                    $currentffd = roll_user::select('roll_users.sub_under_user')
                        ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
                        ->where('roll_users.user_id', $find_user_a->add_by)
                        ->where('roll_users.active', 1)
                        ->first();

                    $patener_share = roll_user::select(
                        'roll_users.user_id',
                        'roll_users.under_user',
                        'roll_users.id_roll',
                        'rolls.roll_name',
                        'rolls.share'
                    )
                        ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
                        ->where('roll_users.user_id', $currentffd->sub_under_user)
                        ->where('roll_users.active', 1)
                        ->first();



                    $pShare = round(($profit * $patener_share->share) / 100, 2);
                    user_payment_list::create([
                        'Share' => $patener_share['share'],
                        'profit_share' => $pShare,
                        'order_id' => $try->id,
                        'product_id' => $find_product->id,
                        'user_id' => $patener_share['user_id'],
                        'id_roll' => $patener_share['id_roll'],
                    ]);


                    $patener_share2 = roll_user::select(
                        'roll_users.user_id',
                        'roll_users.under_user',
                        'roll_users.id_roll',
                        'rolls.roll_name',
                        'rolls.share'
                    )
                        ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
                        ->where('roll_users.user_id', $patener_share->under_user)
                        ->where('roll_users.active', 1)
                        ->first();


                    // return $patener_share2;


                    $pShare2 = round(($profit * $patener_share2['share']) / 100, 2);
                    user_payment_list::create([
                        'Share' => $patener_share2['share'],
                        'profit_share' => $pShare2,
                        'order_id' => $try->id,
                        'product_id' => $find_product->id,
                        'user_id' => $patener_share2['user_id'],
                        'id_roll' => $patener_share2['id_roll'],
                    ]);
                    // GK@0138
                }
                add_to_cart::where('product_id', $item['product_id'])
                    ->where('user_id', $users->id)
                    ->delete();
            }
            DB::commit();
            $data = $order_id;
            $user_id = $users->id;
            Mail::to($users->email)->send(new bookingeMail($data, $user_id));
            return response()->json(['message' => 'Products ordered successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/veri5d/api/view_order/{id}",
     *     summary="View a single order",
     *     tags={"Orders"},
     *     security={{ "bearerAuth":{} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Order ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order retrieved successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="order", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order not found.")
     *         )
     *     )
     * )
     */
    public function view_order($id)
    {
        $order = Order_tracking::find($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }
        return response()->json(['order' => $order], 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_orders",
     *     summary="View all orders of the authenticated user",
     *     tags={"Orders"},
     *     security={{ "bearerAuth":{} }},
     *     @OA\Response(
     *         response=200,
     *         description="Orders retrieved successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="orders", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function view_all_orders()
    {
        $user = Auth::user();
        $orders = Order_tracking::with([
            'findp' => function ($query) {
                $query->select('id', 'name', 'total_price', 'parent_id', 'user_id')->with([
                    'images' => function ($q) {
                        $q->where('active', 1)
                            ->select('product_id', 'productImages')
                            ->limit(1);
                    }
                ]);
            }
        ])->where('user_id', $user->id)->get();
        return response()->json(['orders' => $orders], 200);
    }
    /**
     * @OA\Put(
     *     path="/veri5d/api/cancel_order/{id}",
     *     summary="Cancel an order",
     *     tags={"Orders"},
     *     security={{ "bearerAuth":{} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Order ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order canceled successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order canceled successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order not found.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Order cannot be canceled.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order cannot be canceled.")
     *         )
     *     )
     * )
     */
    public function cancel_order($id)
    {
        $user = Auth::user();
        $order = Order_tracking::where('id', $id)
            ->where('user_id', $user->id)
            ->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order cannot be canceled'], 400);
        }
        $timestamp = now()->format('Y-m-d H:i:s');
        $order->additional_information = ($order->additional_information ? $order->additional_information . "<br>" : "") . "Order_canceled_on: $timestamp";
        $order->status = 'canceled';
        $order->save();
        return response()->json(['message' => 'Order canceled successfully.'], 200);
    }
    /**
     * @OA\Put(
     *     path="/veri5d/api/ship_order/{id}",
     *     summary="Mark an order as shipped",
     *     tags={"Orders"},
     *     security={{ "bearerAuth":{} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Order ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order shipped successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order shipped successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order not found.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Order cannot be shipped.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order cannot be shipped.")
     *         )
     *     )
     * )
     */
    public function ship_order(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'tracking_number' => 'required|string|min:1',
            'shipping_details' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $order = Order_tracking::find($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order cannot be shipped'], 400);
        }
        $timestamp = now()->format('Y-m-d H:i:s');
        $order->additional_information = ($order->additional_information ? $order->additional_information . "<br>" : "") . "Order_shipped_on: $timestamp";
        $order->status = 'shipped';
        $order->tracking_number = $request->tracking_number;
        $order->shipped_at = $timestamp;
        $order->shipping_details = $request->shipping_details;
        $order->save();
        return response()->json(['message' => 'Order shipped successfully.'], 200);
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/deliver_order/{id}",
     *     summary="Mark an order as delivered",
     *     tags={"Orders"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Order ID",
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="payment_mode", type="string", example="cash"),
     *             @OA\Property(property="payment_id", type="string", example="pay_987654321"),
     *             @OA\Property(property="payment_amount", type="string", example="299.99")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order delivered successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order delivered successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request or validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order cannot be delivered"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\AdditionalProperties(type="array", @OA\Items(type="string"))
     *             ),
     *             @OA\Property(property="error", type="string", example="payment amount is miss match")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order not found")
     *         )
     *     )
     * )
     */
    public function deliver_order(Request $request, $id)
    {
        $order = Order_tracking::find($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }
        if ($order->status !== 'shipped') {
            return response()->json(['message' => 'Order cannot be delivered'], 400);
        }
        if ($order->payment_id == null) {
            $validator = Validator::make($request->all(), [
                'payment_mode' => 'required|string|min:1',
                'payment_id' => 'required|string',
                'payment_amount' => 'required|string',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 400);
            }
            if ($order->totel_price != $request->payment_amount) {
                return response()->json(['error' => 'payment amount is miss match'], 400);
            }
            $order->payment_mode = $request->payment_mode;
            $order->payment_id = $request->payment_id;
        }
        $timestamp = now()->format('Y-m-d H:i:s');
        $order->additional_information = ($order->additional_information ? $order->additional_information . "<br>" : "") . "Order_delivered_on: $timestamp";
        $order->status = 'delivered';
        $order->delivered_at = $timestamp;
        $order->save();
        return response()->json(['message' => 'Order delivered successfully.'], 200);
    }
    /**
     * @OA\Put(
     *     path="/veri5d/api/return_order/{id}",
     *     summary="Return an order",
     *     tags={"Orders"},
     *     security={{ "bearerAuth":{} }},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Order ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order canceled successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order canceled successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order not found.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Order cannot be canceled.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Order cannot be canceled.")
     *         )
     *     )
     * )
     */
    public function return_order($id)
    {
        $user = Auth::user();
        $order = Order_tracking::where('id', $id)
            ->where('user_id', $user->id)
            ->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }
        if ($order->status !== 'delivered') {
            return response()->json(['message' => 'Order cannot be canceled'], 400);
        }
        $timestamp = now()->format('Y-m-d H:i:s');
        $order->additional_information = ($order->additional_information ? $order->additional_information . "<br>" : "") . "Order_return_on: $timestamp";
        $order->status = 'return';
        $order->save();
        return response()->json(['message' => 'Order canceled successfully.'], 200);
    }
    public function change_password(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:6'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $updateData = [
                'password' => Hash::make($request->new_password),
            ];
            $user->update($updateData);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'User updated password!'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
    private function uploadFile($request, $key, $path)
    {
        if ($request->hasFile($key)) {
            $file = $request->file($key);
            $filename = time() . '-' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $file->move($path, $filename);
            return $path . $filename;
        }
        return null;
    }
}
