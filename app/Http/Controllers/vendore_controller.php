<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Models\Order_tracking;
use App\Models\product;
use App\Models\other;
use App\Models\User;

class vendore_controller extends Controller
{
    private function getNimbusToken()
    {
        return Cache::remember('nimbus_token', now()->addMinutes(110), function () {
            $response = Http::post('https://ship.nimbuspost.com/api/users/login', [
                'email' => env('NIMBUS_EMAIL'),
                'password' => env('NIMBUS_PASSWORD'),
            ]);
            $data = $response->json();
            if ($response->successful() && $data['status']) {
                return $data['data'];
            }
            throw new \Exception('Failed to authenticate with NimbusPost.');
        });
    }
    public function createShipment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'=>'required|integer|exists:order_trackings,id',
            'order_number' => 'required|string|max:255',
            'shipping_charges' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'cod_charges' => 'nullable|numeric|min:0',
            'payment_type' => 'required|in:prepaid,cod',
            'order_amount' => 'required|numeric|min:0',
            'package_weight' => 'required|numeric|min:0',
            'package_length' => 'required|numeric|min:0',
            'package_breadth' => 'required|numeric|min:0',
            'package_height' => 'required|numeric|min:0',
            // 'request_auto_pickup' => 'required|in:yes,no',
            'consignee' => 'required|array',
            'consignee.name' => 'required|string|max:255',
            'consignee.address' => 'required|string|max:500',
            'consignee.address_2' => 'nullable|string|max:500',
            'consignee.city' => 'required|string|max:100',
            'consignee.state' => 'required|string|max:100',
            'consignee.pincode' => 'required|string|max:10',
            'consignee.phone' => 'required|string|max:10',
            'pickup' => 'required|array',
            'pickup.warehouse_name' => 'required|string|max:255',
            'pickup.name' => 'required|string|max:255',
            'pickup.address' => 'required|string|max:500',
            'pickup.address_2' => 'nullable|string|max:500',
            'pickup.city' => 'required|string|max:100',
            'pickup.state' => 'required|string|max:100',
            'pickup.pincode' => 'required|string|max:10',
            'pickup.phone' => 'required|string|max:10',
            'order_items' => 'required|array|min:1',
            'order_items.*.name' => 'required|string|max:255',
            'order_items.*.qty' => 'required|integer|min:1',
            'order_items.*.price' => 'required|numeric|min:0',
            'order_items.*.sku' => 'required|string|max:255',
            // 'courier_id' => 'nullable|integer',
            'is_insurance' => 'nullable|in:0,1',
            // 'tags' => 'nullable|string'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            $user = Auth::user();
            $api_track = order_tracking::find($request->id);
            if ($api_track && $api_track->status == "pending") {
                $token = $this->getNimbusToken();
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ])->post('https://api.nimbuspost.com/v1/shipments', $request->all());
                $responseData = $response->json(); 
                if (!isset($responseData['status']) || $responseData['status'] === false) {
                    return response()->json([
                        'error' => $responseData['message'] ?? 'Unknown error from NimbusPost'
                    ], 400);
                }
                if (isset($responseData['status']) && $responseData['status'] && isset($responseData['data'])) {
                    $data = $responseData['data'];
                    $api_track->update([
                        'additional_information' => ($api_track->additional_information ? $api_track->additional_information . "<br>" : "") . json_encode($data),
                        'status'=>"shipped",
                    ]);

                    $find_user = User::find($api_track->user_id);
                    other::create([
                        'title'   => 'notification',
                        'active'  => 1,
                        'user_id' => $user->id,
                        'value'   => json_encode([
                            'user_name' => $find_user->name,
                            'message' => 'order is shipped',
                            'user_id' => $find_user->id,
                            'request_status' => 1,
                        ])
                    ]);

                }
                return response()->json($responseData);
            }else{
                return response()->json([
                    'error' => 'Order Not Found'
                ], 400);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function trackShipment(Request $request)
    {
        try {
            $token = $this->getNimbusToken();
            // Ensure the 'awb' key is present in the request body and it is an array
            $awbNumbers = $request->input('awb');
            if (empty($awbNumbers) || !is_array($awbNumbers)) {
                return response()->json(['error' => 'AWB numbers are required and should be an array.'], 400);
            }
            // Make sure the number of AWBs does not exceed 100
            if (count($awbNumbers) > 100) {
                return response()->json(['error' => 'You can track a maximum of 100 AWBs at a time.'], 400);
            }
            // Send the bulk tracking request
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->post('https://api.nimbuspost.com/v1/shipments/track/bulk', [
                'awb' => $awbNumbers,
            ]);
            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function generateManifest(Request $request)
    {
        try {
            $token = $this->getNimbusToken();

            // Ensure the 'awbs' key is present in the request body and it is an array
            $awbNumbers = $request->input('awbs');
            
            if (empty($awbNumbers) || !is_array($awbNumbers)) {
                return response()->json(['error' => 'AWB numbers are required and should be an array.'], 400);
            }

            // Send the manifest creation request
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->post('https://api.nimbuspost.com/v1/shipments/manifest', [
                'awbs' => $awbNumbers,
            ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function cancelShipment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'=>'required|integer|exists:order_trackings,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            $user = Auth::user();
            $api_track = order_tracking::find($request->id);
            $timestamp = now()->format('Y-m-d H:i:s');
            if(!$api_track){
                return response()->json([
                    'error' => 'Order Not Found'
                ], 400);
            }
            if($api_track->status == "pending"){
                $api_track->update([
                    'additional_information' => ($api_track->additional_information ? $api_track->additional_information . "<br>" : "") .  "Order_cancelled_on: $timestamp",
                    'status'=>"cancelled",
                ]);
                return response()->json(['message' => 'Order Cancel successfully'], 200);
            }elseif($api_track->status == "shipped"){
                $validator = Validator::make($request->all(), [
                    'awb' => 'required|string|max:255',
                ]);
                if ($validator->fails()) {
                    return response()->json([
                        'message' => 'Validation error',
                        'errors' => $validator->errors()
                    ], 422);
                }
                $token = $this->getNimbusToken();
                $awbNumber = $request->input('awb');
                if (empty($awbNumber)) {
                    return response()->json(['error' => 'AWB number is required.'], 400);
                }
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ])->post('https://api.nimbuspost.com/v1/shipments/cancel', [
                    'awb' => $awbNumber,
                ]);
                $responseData = $response->json(); 
                if (!isset($responseData['status']) || $responseData['status'] === false) {
                    return response()->json([
                        'error' => $responseData['message'] ?? 'Unknown error from NimbusPost'
                    ], 400);
                }
                $api_track->update([
                    'additional_information' => ($api_track->additional_information ? $api_track->additional_information . "<br>" : "") .  "Order_cancelled_on: $timestamp",
                    'status'=>"cancelled",
                ]);
                $find_product = product::where('id',$api_track->product_id)->first();
                $find_user = User::find($find_product->user_id);
                other::create([
                    'title'   => 'notification',
                    'active'  => 1,
                    'user_id' => $user->id,
                    'value'   => json_encode([
                        'user_name' => $find_user->name,
                        'message' => 'order is cancelled',
                        'user_id' => $find_user->id,
                        'request_status' => 1,
                    ])
                ]);
                return response()->json($response->json());
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/wallet_balance",
     *     tags={"NimbusPost"},
     *     summary="Get wallet balance",
     *     @OA\Response(response=200, description="Balance data")
     * )
     */

    public function walletBalance()
    {
        try {
            $token = $this->getNimbusToken();
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->get("https://ship.nimbuspost.com/api/shipmentcargo/wallet_balance");

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/serviceability",
     *     tags={"NimbusPost"},
     *     summary="Check rate and serviceability",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="origin", type="string"),
     *             @OA\Property(property="destination", type="string"),
     *             @OA\Property(property="payment_type", type="string", enum={"prepaid", "cod"}),
     *             @OA\Property(property="order_value", type="number"),
     *             @OA\Property(property="details", type="array", @OA\Items(
     *                 @OA\Property(property="qty", type="integer"),
     *                 @OA\Property(property="weight", type="number"),
     *                 @OA\Property(property="length", type="number"),
     *                 @OA\Property(property="breadth", type="number"),
     *                 @OA\Property(property="height", type="number")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=200, description="Rate response")
     * )
     */

    public function rateServiceability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'origin' => 'required|string|max:10',
            'destination' => 'required|string|max:10',
            'payment_type' => 'required|in:prepaid,cod',
            'order_value' => 'required|numeric|min:1',
            'details' => 'required|array|min:1',
            'details.*.qty' => 'required|integer|min:1',
            'details.*.weight' => 'required|numeric|min:1',
            'details.*.length' => 'required|numeric|min:1',
            'details.*.breadth' => 'required|numeric|min:1',
            'details.*.height' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $token = $this->getNimbusToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ])->post("https://ship.nimbuspost.com/api/courier/b2b_serviceability", $request->all());

            return response()->json($response->json());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function rejectShipment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => [
                'required',
                Rule::exists('order_trackings', 'id')->where('status', 'pending'),
            ],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }
        $user = Auth::user();
        $find_order = order_tracking::where('active',1)->where('id',$request->id)->first();
        if($find_order && $find_order->status == "pending"){
           $find_products = product::where('id',$find_order->product_id)->where('user_id',$user->id)->first();
           if($find_products){
            $find_order->status = 'cancelled';
            $find_order->update();
            $find_user = User::find($find_order->user_id);
            other::create([
                'title'   => 'notification',
                'active'  => 1,
                'user_id' => $user->id,
                'value'   => json_encode([
                    'user_name' => $find_user->name,
                    'message' => 'order is rejected',
                    'user_id' => $find_user->id,
                    'request_status' => 1,
                ])
            ]);

            return response()->json(['message' => 'Order Reject successfully'], 200);
           }
        }
        return response()->json(['error' => 'Order Not Found'], 400);
    }
}
