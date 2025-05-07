<?php

namespace App\Http\Controllers;

use App\Models\other;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use App\Models\roll_user;
use App\Models\product;
use App\Models\offer_code;
use App\Models\Order_tracking;
use App\Models\product_attribute;
use App\Models\product_categorie;
use App\Models\product_image;
use App\Models\roll;
use App\Models\roll_privilege;
use App\Models\privilege;
use App\Models\vendor;
use App\Models\user_payment_list;
use App\Models\product_review;
use App\Models\user_payment_request;
use App\Models\used_code;
use App\Models\campaign;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Exception;
use Carbon\Carbon;
use App\Models\payment_to_vendor;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Illuminate\Support\Facades\Mail;
use App\Mail\campaignMail;
use App\Mail\vendorwelcomeMail;


class admin_controller extends Controller
{
    public function view_vendor_order()
    {
        $user = Auth::user();

        if ($user->user_type != 'vd') {
            return response()->json(['error' => 'No users found with valid token'], 401);
        }
        if ($user->active == 2) {
            return response()->json(['error' => 'User is suspended'], 401);
        }
        
        if (is_null($user->add_by)) {
            return response()->json(['error' => 'User is unapproved'], 401);
        }        
        $vendorProfile = User::leftJoin('vendors', 'users.id', '=', 'vendors.user_id')
            ->where('users.id', $user->id)
            ->where('users.active', 1)
            ->where('vendors.active', 1)
            ->select([
                'users.name as pickup_name',
                'users.phone as pickup_phone',
                'users.email as pickup_email',
                'users.address as pickup_address',
                'users.city as pickup_city',
                'users.state as pickup_state',
                'users.pin_code as pickup_pincode',
                'vendors.shop_name as pickup_warehouse_name',
            ])
            ->first();

        $orders = Order_tracking::with([
            'findp' => function ($query) {
                $query->select('id', 'name', 'total_price', 'data', 'parent_id', 'user_id')
                    ->with([
                        'images' => function ($q) {
                            $q->select('product_id', 'productImages');
                        },
                        'vendor' => function ($q) {
                            $q->select('id', 'name', 'email');
                        }
                    ]);
            },
            'finduser' => function ($query) {
                $query->select('id', 'name','phone');
            },
            'share' => function ($query) {
                $query->with([
                    'finduser' => function ($q) {
                        $q->select('id', 'name');
                    },
                    'roleuser'
                ]);
            },
        ])
            ->where('active', 1)
            ->get()
            ->filter(function ($order) use ($user) {
                return optional(optional($order->findp)->vendor)->id === $user->id;
            })
            ->map(function ($order) {
                $product = $order->findp;
                $image = $product && $product->images->isNotEmpty() ? $product->images->first()->productImages : null;

                // Calculate delivery fee
                $deliveryFee = null;
                if ($product) {
                    $data = json_decode($product->data, true);
                    if (is_null($product->parent_id)) {
                        $deliveryFee = $data['individual_delivery_fee'] ?? null;
                    } else {
                        $parent = \App\Models\Product::select('data')->find($product->parent_id);
                        $parentData = json_decode(optional($parent)->data ?? '{}', true);
                        $deliveryFee = $parentData['individual_delivery_fee'] ?? null;
                    }
                }

                $vendor = optional($product->vendor);
                $orderId = data_get(json_decode($order->additional_information, true), 'order_id');

                return [
                    'tracking' => $order->id,
                    'order_id' => $orderId,
                    'quantity'=>$order->quantity,
                    'product_name' => optional($product)->name,
                    'product_sku' => optional($product)->data->sku,
                    'product_price' => optional($product)->total_price,
                    'payment_mode' => $order->payment_mode,
                    'payment_id' => $order->payment_id,
                    'product_image' => $image,
                    'delivery_fee' => $deliveryFee,
                    'vendor_name' => $vendor->name,
                    'vendor_email' => $vendor->email,
                    'user_name' => optional($order->finduser)->name,
                    'phone' => optional($order->finduser)->phone,
                    'user_id' => optional($order->finduser)->id,
                    'shipped_at' => $order->shipped_at,
                    'delivered_at' => $order->delivered_at,
                    'shipping_details' => $order->shipping_details,
                    'additional_information' => $order->additional_information,
                    'status' => $order->status,
                    'created_at' => $order->created_at,
                    'share' => $order->share,
                ];
            })
            ->groupBy('order_id')
            ->values();
            $reporting_to = user::where('id',$user->add_by)->select('name','email','phone')->first();
            return response()->json([
            'message' => 'Success',
            'vendorProfile' => $vendorProfile,
            'orders' => $orders,
            'reporting_to' => $reporting_to,
        ]);
    }
    public function register_vendor_with_shop(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => [
                'required',
                'unique:users,phone',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'password' => 'required|string|min:6',
            'name' => 'required|string|max:255',
            'website' => [
                'nullable',
                'regex:/^(https?:\/\/)?(www\.)?[a-z0-9-]+\.[a-z]{2,}(\S*)?$/i'
            ],
            'pincode' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            // 'latitude' => 'required|string|max:255',
            // 'longitude' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state_province' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'delivery' => 'required',
            // 'order_prepare_time' => 'required|string|max:255',
            'availability' => 'required',
            // 'auto_accept_order' => 'required',
            'return_request' => 'required',
            'show_profile_details' => 'required',
            // 'auto_reject_time' => 'required|string|max:255',
            // 'service_fee_percent' => 'required|string|max:255|min:1',
            'vendor_detail_to_show' => 'required|string|max:255',
            'number_of_employees' => 'required|string|max:255|min:1',
            'home_business' => 'required|string|max:255|min:1',
            'nature_of_vendor' => 'required|string|max:255',
            'PAN' => 'required|string|max:255',
            'GSTIN' => 'required|string|max:255',
            'govt_registered_number' => 'required|string|max:255',
            'bank_account_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:255',
            'IFSC_code' => 'required|string|max:255',
            'account_type' => 'required|string|max:255',
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'required|string|max:255',
            'PAN_PDF' => 'required|file|mimes:pdf|max:2048',
            'upload_logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'upload_banner_image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        // $user = Auth::user();
        DB::beginTransaction();
        try {
            $data = $request->only('name', 'email', 'phone', 'password', 'address', 'country', 'city');
            $data['password'] = Hash::make($request->input('password'));
            $data['user_type'] = 'vd';
            // $data['create_by'] = $user->id;
            $data['state'] = $request->input('state_province');
            $data['active'] = 0;
            $newuser = User::create($data);
            $slug = '2' . time() . Str::uuid() . (int) Str::random(5);
            $data = $request->except('_token', 'shop_name');
            if ($request->hasFile("PAN_PDF")) {
                $PAN_PDF_file = $this->uploadFile($request, 'PAN_PDF', 'image/vendor/');
                $data['PAN_PDF'] = $PAN_PDF_file;
            }
            if ($request->hasFile("upload_logo")) {
                $upload_logo_file = $this->uploadFile($request, 'upload_logo', 'image/vendor/');
                $data['upload_logo'] = $upload_logo_file;
            }
            if ($request->hasFile("upload_banner_image")) {
                $upload_banner_image_file = $this->uploadFile($request, 'upload_banner_image', 'image/vendor/');
                $data['upload_banner_image'] = $upload_banner_image_file;
            }
            vendor::create([
                'shop_name' => $request['shop_name'],
                // 'add_by' => $user->id,
                'user_id' => $newuser->id,
                'slug' => $slug,
                'shop_data' => json_encode($data)
            ]);
            DB::commit();

            $loki = [
                "name" => $request->name,
                "shop_name" => $request->shop_name,
            ];
            Mail::to($request->email)->send(new vendorwelcomeMail($loki));
            return response()->json(['message' => 'Data add Successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function payment_request(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'request_amount' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $pendingTotal = user_payment_request::where('active', 1)
                ->where('status', 'pending')
                ->where('user_id', $user->id)
                ->sum('amount_req');
            $totalRequested = $request->request_amount + $pendingTotal;
            if ($user->balance_amount < $totalRequested) {
                return response()->json(['error' => 'Balance Amount is less than Request Amount',], 400);
            }
            user_payment_request::create([
                'amount_req' => $request['request_amount'],
                'status' => "pending",
                'user_id' => $user->id,
            ]);
            other::create([
                'title'   => 'notification',
                'active'  => 1,
                'user_id' => $user->id,
                'value'   => json_encode([
                    'user_name' => $user->name,
                    'amount_request' => $request['request_amount'],
                    'request_status' => 0,
                ])
            ]);
            DB::commit();
            $body_data = 'Name: ' . $user->name . '<br> Request Amount: ' . $request->request_amount;
            $messaging = Firebase::messaging();
            $notification = [
                'title' => "Request For Payment",
                'body' => $body_data,
            ];
            $find_n_user = roll_user::where('active', 1)
                ->whereIn('id_roll', [1, 2, 3])
                ->pluck('user_id');

            if ($find_n_user->isNotEmpty()) {
                $users = User::where('active', 1)
                    ->whereIn('id', $find_n_user)
                    ->whereNotNull('fcm_token')
                    ->get();

                if ($users->isEmpty()) {
                    return response()->json(['error' => 'No users found with valid FCM token'], 404);
                }

                foreach ($users as $user) {
                    $message = CloudMessage::withTarget('token', $user->fcm_token)
                        ->withNotification($notification);

                    try {
                        $messaging->send($message);
                    } catch (\Throwable $e) {
                        // Log or skip individual failures
                        \Log::error("FCM send failed for user {$user->id}: " . $e->getMessage());
                    }
                }

                return response()->json(['message' => 'Notification sent to specific users']);
            } else {
                // Fallback: send to topic
                try {
                    $message = CloudMessage::withTarget('topic', 'all')
                        ->withNotification($notification);
                    $messaging->send($message);

                    return response()->json(['message' => 'Notification sent to topic all']);
                } catch (\Throwable $e) {
                    return response()->json(['error' => 'Failed to send topic notification', 'details' => $e->getMessage()], 500);
                }
            }
            return response()->json(['message' => 'Request Amount successfully',], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/list_all_notification",
     *     tags={"Notifications"},
     *     summary="List all unpaid (requested) notifications",
     *     description="Returns notifications where request_status is 0 (pending/unpaid)",
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="list_notification", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */

    public function list_all_notification()
    {
        // $list_notification = other::where('title', 'notification')->whereJsonContains('value->request_status', 0)->where('active', 1)->get();
        // return response()->json([
        //     'message' => 'Success',
        //     'list_notification' => $list_notification,
        // ], 200);

        try {

            $list_notification = Other::where('titel', 'notification')
                ->where('active', 1)
                ->get()
                ->filter(function ($item) {
                    $decoded = json_decode($item->value, true);
                    return isset($decoded['request_status']) && $decoded['request_status'] == 0;
                })
                ->values(); // reset keys




            return response()->json([
                'message' => 'Success',
                'list_notification' => $list_notification,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch notifications',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/list_all_paying_notification",
     *     tags={"Notifications"},
     *     summary="List all paid notifications for current user",
     *     description="Returns notifications where request_status is 1 and user_id matches the authenticated user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="list_notification", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */

    public function list_all_paying_notification()
    {
        // $list_notification = other::where('title', 'notification')->where('active', 1)->whereJsonContains('value->request_status', 1)->whereJsonContains('value->user_id', Auth::id())->get();

        $user = Auth::user();
        $list_notification = Other::where('titel', 'notification')
            ->where('active', 1)
            ->get()
            ->filter(function ($item) use ($user) {
                $decoded = json_decode($item->value, true);
                return isset($decoded['request_status'], $decoded['user_id']) &&
                    $decoded['request_status'] == 1 &&
                    $decoded['user_id'] == $user->id;
            })
            ->values(); // reset keys

        return response()->json([
            'message' => 'Success',
            'list_notification' => $list_notification,
        ], 200);
    }

    // public function list_user_notification()
    // {

    // }

    



    /**
     * @OA\Delete(
     *     path="/veri5d/api/delete_notification/{id}",
     *     tags={"Notifications"},
     *     summary="Delete a notification by ID",
     *     description="Deletes a notification with the given ID where title is 'notification'",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success delete",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */

    public function delete_notification($id)
    {
        other::where('id', $id)->where('title', 'notification')->delete();
        return response()->json([
            'message' => 'Success delete'
        ], 200);
    }
    public function paying_for_user(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_mode' => 'required|string|max:255',
            'payment_id' => 'required|string|max:255',
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'id' => [
                'required',
                Rule::exists('user_payment_requests', 'id')->where('active', 1),
            ],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 400);
        }

        DB::beginTransaction();
        try {
            $find_the_request = user_payment_request::findOrFail($request->id);
            if ((float) $find_the_request->amount_req !== (float) $request->amount) {
                return response()->json(['error' => 'Amount not matching Request Amount'], 400);
            }
            $dataimage = $this->uploadFile($request, 'payment_proof', 'images/banner/');
            $find_the_request->update([
                'payment_mode' => $request->payment_mode,
                'payment_id' => $request->payment_id,
                'status' => 'inprogress',
                'payment_proof' => $dataimage,
            ]);
            $find_user = User::find($find_the_request->user_id);
            $find_user->update([
                'balance_amount' => $find_user->balance_amount - $request->amount,
            ]);
            other::create([
                'title'   => 'notification',
                'active'  => 1,
                'user_id' => Auth::id(),
                'value'   => json_encode([
                    'user_name' => $find_user->name,
                    'message' => 'Payment send',
                    'amount_request' => $request['amount'],
                    'amount_status' => 'Done',
                    'user_id' => $find_user->id,
                    'request_status' => 1,
                ])
            ]);
            DB::commit();
            return response()->json(['message' => 'Request amount successfully sent'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function updateDeviceToken(Request $request)
    {
        // $request->validate([
        //     'fcm_token' => 'required|string',
        // ]);
        // $user = Auth::user();
        // $user->fcm_token = $request->fcm_token;
        // $user->save();
        $validated = $request->validate([
            'fcm_token' => 'required|string',
        ]);
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $user->update(['fcm_token' => $validated['fcm_token']]);
        return response()->json(['message' => 'FCM token updated successfully.']);
    }
    public function sendNotification(Request $request)
    {
        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $messaging = Firebase::messaging();

        // Construct the notification payload
        $notification = [
            'title' => $request->title,
            'body' => $request->body,
        ];

        // Determine the target
        if ($request->filled('user_id')) {
            $user = User::find($request->user_id);

            if (!$user || !$user->fcm_token) {
                return response()->json(['error' => 'User not found or FCM token missing'], 404);
            }

            $message = CloudMessage::withTarget('token', $user->fcm_token)
                ->withNotification($notification);
        } else {
            $message = CloudMessage::withTarget('topic', 'all')
                ->withNotification($notification);
        }

        try {
            $messaging->send($message);
            return response()->json(['message' => 'Notification sent successfully']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to send notification', 'details' => $e->getMessage()], 500);
        }
    }
    public function sendNotificationMulti(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'     => 'required|string|max:255',
            'body'      => 'required|string',
            'email'     => 'required|boolean',
            'enable'    => 'nullable|boolean',
            'user_type' => 'required|string|in:ad,us,vd'
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }
        $data = [
            'title' => $request['title'],
            'body'  => $request['body'],
        ];
        $users = User::where('user_type', $request->user_type)
            ->when($request['email'] == 1, function ($q) {
                $q->where('active', 1);
            })
            ->when($request['email'] == 0, function ($q) {
                $q->whereNotNull('fcm_token');
            })
            ->get();
        if ($users->isEmpty()) {
            return response()->json(['error' => 'No target users found.'], 404);
        }
        $sent = 0;
        $failed = 0;
        foreach ($users as $user) {
            try {
                if ($request['email'] == 1) {
                    $user_id = $user->id;
                    Mail::to($user->email)->send(new campaignMail($data, $user_id));
                } elseif ($request['email'] == 0) {
                    Firebase::messaging()->send(
                        CloudMessage::withTarget('token', $user->fcm_token)
                            ->withNotification($data)
                    );
                }
                $sent++;
            } catch (\Throwable $e) {
                \Log::error("Notification error for user {$user->id}: " . $e->getMessage());
                $failed++;
            }
        }
        other::create([
            'title'   => 'campaigns',
            'active'  => $request['enable'] ?? 1,
            'user_id' => Auth::id(),
            'value'   => json_encode([
                'title' => $data['title'],
                'body'  => $data['body'],
                'users' => $users->pluck('id'),
                'type'  => $request['email'] == 1 ? 'email' : 'push'
            ])
        ]);
        return response()->json([
            'message' => $request['email'] == 1
                ? 'Emails sent to active users.'
                : 'Push notifications sent to users.',
            'success' => $sent,
            'failed'  => $failed,
        ]);
    }
    public function add_campaign(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'notification_type' => 'required|string|max:255',
            'message' => 'required|string',
            'select_users' => 'required|string',
            'schedule_date' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }
        $users = Auth::user();
        DB::beginTransaction();
        try {
            $dataimage = $this->uploadFile($request, 'image', 'images/campaign/');
            campaign::create([
                'title' => $request['title'],
                'notification_type' => $request['notification_type'],
                'message' => $request['message'],
                'select_users' => $request['select_users'],
                'schedule_date' => $request['schedule_date'],
                'image' => $dataimage,
                'user_id' => $users->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'Request Amount successfully',], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function edit_campaign(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'notification_type' => 'required|string|max:255',
            'message' => 'required|string',
            'select_users' => 'required|string',
            'schedule_date' => 'required|string',
            'request_user_count' => 'required|string',
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
            $campaign = Campaign::findOrFail($id);

            $campaign->update([
                'title' => $request->title,
                'notification_type' => $request->notification_type,
                'message' => $request->message,
                'select_users' => $request->select_users,
                'schedule_date' => $request->schedule_date,
                'request_user_count' => $request->request_user_count,
            ]);

            DB::commit();

            return response()->json(['message' => 'Campaign updated successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function list_campaign()
    {
        $list_campaign = campaign::where('active', 1)->get();
        return response()->json([
            'message' => 'Success',
            'list_campaign' => $list_campaign,
        ], 200);
    }
    public function delete_campaign($id)
    {
        $list_campaign = campaign::where('active', 1)->where('id', $id)->first();
        $list_campaign->update([
            'active' => '0',
        ]);
        return response()->json(['message' => 'campaign Delete successfully',], 200);
    }
    public function list_user_code()
    {
        // $code_list = used_code::where('active',1)->get();
        $code_list = used_code::where('active', 1)->get();
        $total_discount = $code_list->sum(function ($item) {
            $info = json_decode($item->offer_information, true);
            return $info['discount_price'] ?? 0;
        });
        $used_code_count = $code_list->pluck('code_id')->unique()->count();
        $used_user_count = $code_list->pluck('user_id')->unique()->count();
        return response()->json([
            'message' => 'Success',
            'used_code_list' => $code_list,
            'total_discount' => $total_discount,
            'used_code_count' => $used_code_count,
            'used_user_count' => $used_user_count,
        ], 200);
    }
    public function delete_request($id)
    {
        $user = Auth::user();
        $paymentRequest = user_payment_request::where('id', $id)
            ->where('user_id', $user->id)
            ->where('active', 1)
            ->where('status', 'pending')
            ->first();
        if (!$paymentRequest) {
            return response()->json(['error' => 'Payment request not found or cannot be edited'], 404);
        }
        $paymentRequest->update([
            'active' => 0,
        ]);
        DB::commit();
        return response()->json(['message' => 'Payment request Delete successfully'], 200);
    }
    public function edit_payment_request(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'request_amount' => 'required|numeric|min:0.01',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $paymentRequest = user_payment_request::where('id', $id)
                ->where('user_id', $user->id)
                ->where('active', 1)
                ->where('status', 'pending')
                ->first();
            if (!$paymentRequest) {
                return response()->json(['error' => 'Payment request not found or cannot be edited'], 404);
            }
            $otherPendingTotal = user_payment_request::where('active', 1)
                ->where('status', 'pending')
                ->where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->sum('amount_req');
            $totalRequested = $request->request_amount + $otherPendingTotal;
            if ($user->balance_amount < $totalRequested) {
                return response()->json(['error' => 'Balance amount is less than the updated request amount'], 400);
            }
            $paymentRequest->update([
                'amount_req' => $request->request_amount,
            ]);
            DB::commit();
            return response()->json(['message' => 'Payment request updated successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function list_all_request()
    {
        $list = user_payment_request::where('active', 1)->get();
        return response()->json([
            'message' => 'Success',
            'list' => $list,
        ], 200);
    }
    public function list_my_request()
    {
        $user = Auth::user();
        $list = user_payment_request::where('active', 1)->where('user_id', $user->id)->get();
        return response()->json([
            'message' => 'Success',
            'list' => $list,
        ], 200);
    }
    public function received_profit()
    {
        $user = Auth::user();
        $all_profit = user_payment_list::with([
            'findorders' => function ($query) {
                $query->with([
                    'images' => function ($q) {
                        $q->where('active', 1)
                            ->select('product_id', 'productImages')
                            ->limit(1);
                    },
                    'findp' => function ($q) {
                        $q->select('id', 'name', 'total_price');
                    }
                ]);
            }
        ])
            ->where('user_id', $user->id)
            ->get();
        return response()->json(['data' => $all_profit], 200);
    }
    public function all_product_orders()
    {
        $products = product::with([
            'vendor:id,name',
            'order' => function ($q) {
                $q->where('active', 1);
            }
        ])
            ->withCount([
                'order as order_count' => function ($q) {
                    $q->where('active', 1);
                }
            ])
            ->has('order') // Only products with at least one order
            ->get();

        $data = $products->map(function ($product) {
            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'vendor_name' => $product->vendor->name ?? null,
                'order_count' => $product->order_count,
                'orders' => $product->order->map(function ($order) {
                    return [
                        'order_id' => $order->id,
                        'quantity' => $order->quantity,
                        'totel_price' => $order->totel_price,
                        'status' => $order->status,
                        'created_at' => $order->created_at,
                    ];
                }),
            ];
        });







        $products_wish = product::with([
            'vendor:id,name',
            'wishlist' => function ($q) {
                $q->where('active', 1)->where('cart', 0);
            },
            'wishlist.user:id,name,email'
        ])
            ->withCount([
                'wishlist as wishlist_count' => function ($q) {
                    $q->where('active', 1)->where('cart', 0);
                }
            ])
            ->has('wishlist') // Only products that are in at least one wishlist
            ->get();

        $wish_list = $products_wish->map(function ($products_wish) {
            return [
                'product_id' => $products_wish->id,
                'product_name' => $products_wish->name,
                'vendor_name' => $products_wish->vendor->name ?? null,
                'wishlist_count' => $products_wish->wishlist_count,
                'wishlisted_by' => $products_wish->wishlist->map(function ($wish) {
                    return [
                        'user_id' => $wish->user->id ?? null,
                        'name' => $wish->user->name ?? null,
                        'email' => $wish->user->email ?? null,
                        'created_at' => $wish->created_at,
                    ];
                }),
            ];
        });


        return response()->json(['data' => $data, 'products_wish_list' => $wish_list], 200);
    }
    public function all_product_review()
    {
        $products = product::with([
            'images' => function ($q) {
                $q->where('active', 1)
                    ->select('product_id', 'productImages')
                    ->limit(1);
            },
            'reviews.finduser:id,name,email,phone'
        ])
            ->has('reviews') // Only products that have at least one review
            ->get();

        $data = $products->map(function ($product) {
            $reviews = $product->reviews;

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => $product->total_price,
                'image' => $product->images->first()->productImages ?? null,
                'total_reviews' => $reviews->count(),
                'avg_rating' => round($reviews->avg('rating'), 2),
                'reviews' => $reviews->map(function ($review) {
                    return [
                        'review_id' => $review->id,
                        'rating' => $review->rating,
                        'text' => $review->review,
                        'user' => [
                            'name' => $review->finduser->name ?? null,
                            'email' => $review->finduser->email ?? null,
                            'phone' => $review->finduser->phone ?? null,
                        ],
                    ];
                }),
            ];
        });

        return response()->json(['data' => $data], 200);
    }
    public function manager_list()
    {
        $find_manager = roll_user::with('finduser')->whereIn('id_roll', [12, 10])->where('active', 1)->get();
        return response()->json($find_manager);
    }
    public function reporting_to_list($id)
    {

        $find_role = roll::find($id);

        if (!$find_role || !$find_role->parent_id) {
            return response()->json(['message' => 'No parent role found'], 404);
        }

        $find_manager = roll_user::with('finduser:name,id', 'findrole:id,roll_name')
            ->where('id_roll', $find_role->parent_id)
            ->where('active', 1)
            ->get();
        $find_manager2 = [];
        if ($id == 10 || $id == 12) {
            $find_manager2 = roll_user::with('finduser:name,id', 'findrole:id,roll_name')
                ->where('id_roll', 7)
                ->where('active', 1)
                ->get();
        }
        return response()->json([
            'message' => 'Successfully',
            'find_manager' => $find_manager,
            'find_manager2' => $find_manager2,
        ], 200);
        // return response()->json($find_manager,$find_manager2);
    }
    public function accounting_taxs()
    {
        $finding_tax = Order_tracking::with('finduser:id,name')->where('active', 1)->get();
        return response()->json($finding_tax);
    }
    public function  payouts_requests()
    {
        $payouts = payment_to_vendor::where('active', 1)->get();
        $total_order_value = $payouts->sum('payment_amount');
        $pending_payout_value = $payouts->whereNull('payment_id')->sum('payment_amount');
        $completed_payout_value = $payouts->whereNotNull('payment_id')->sum('payment_amount');
        return response()->json([
            'message' => 'Success',
            'payouts_requests' => $payouts,
            'total_order_value' => $total_order_value,
            'pending_payout_value' => $pending_payout_value,
            'completed_payout_value' => $completed_payout_value,
        ], 200);
    }
    public function view_all_user_order()
    {
        $orders = Order_tracking::with([
            'findp' => function ($query) {
                $query->select('id', 'name', 'total_price', 'data', 'parent_id', 'user_id')->with([
                    'images' => function ($q) {
                        $q->select('product_id', 'productImages');
                    },
                    'vendor' => function ($q) {
                        $q->select('id', 'name', 'email'); // Add more fields if needed
                    }
                ]);
            },
            'finduser' => function ($query) {
                $query->select('id', 'name');
            },
            'share' => function ($query) {
                $query->with([
                    'finduser' => function ($q) {
                        $q->select('id', 'name');
                    },
                    'roleuser'
                ]);
            },
        ])
            ->where('active', 1)
            ->get()
            ->map(function ($order) {
                $product = $order->findp;
                $image = $product && $product->images->isNotEmpty() ? $product->images->first()->productImages : null;
                // Calculate delivery fee
                $deliveryFee = null;
                if ($product) {
                    $data = json_decode($product->data, true);
                    if (is_null($product->parent_id)) {
                        $deliveryFee = $data['individual_delivery_fee'] ?? null;
                    } else {
                        $parent = \App\Models\Product::select('data')->find($product->parent_id);
                        $parentData = json_decode(optional($parent)->data ?? '{}', true);
                        $deliveryFee = $parentData['individual_delivery_fee'] ?? null;
                    }
                }
                $vendor = optional($product->vendor);
                $orderId = data_get(json_decode($order->additional_information, true), 'order_id');
                return [
                    'order_id' => $orderId,
                    'product_name' => optional($product)->name,
                    'product_price' => optional($product)->total_price,
                    'payment_mode' => $order->payment_mode,
                    'payment_id' => $order->payment_id,
                    'product_image' => $image,
                    'delivery_fee' => $deliveryFee,
                    'vendor_name' => $vendor->name,
                    'vendor_email' => $vendor->email,
                    'user_name' => optional($order->finduser)->name,
                    'user_id' => optional($order->finduser)->id,
                    'shipped_at' => $order->shipped_at,
                    'delivered_at' => $order->delivered_at,
                    'shipping_details' => $order->shipping_details,
                    'additional_information' => $order->additional_information,
                    'status' => $order->status,
                    'created_at' => $order->created_at,
                    'share' => $order->share,
                ];
            })
            ->groupBy('order_id')
            ->values();
        return response()->json($orders);
    }
    public function create_tax(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tax_name' => 'required|string|max:255',
            'tax_Rate' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $data = $request->except('_token');
            other::create([
                'titel' => 'tax',
                'value' => json_encode($data),
                'active' => 1,
                'user_id' => $user->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'Coupon Add successfully',], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function edit_tax($id, Request $request)
    {
        $banner = other::where('id', $id)->where('titel', 'tax')->first();
        if (!$banner) {
            return response()->json(['error' => 'Banner not found'], 404);
        }
        $validator = Validator::make($request->all(), [
            'tax_name' => 'required|string|max:255',
            'tax_Rate' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $data = $request->except('_token');
            $banner->user_id = $user->id;
            $banner->value = json_encode($data);
            $banner->save();
            DB::commit();
            return response()->json(['message' => 'Coupon Add successfully',], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function delete_tax($id)
    {
        $banner = other::where('id', $id)->where('titel', 'tax')->first();
        if (!$banner) {
            return response()->json(['error' => 'Banner not found'], 404);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $banner->user_id = $user->id;
            $banner->active = 0;
            $banner->save();
            DB::commit();
            return response()->json(['message' => 'Tax Delete successfully'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function view_tax()
    {
        $banner = other::where('active', 1)->where('titel', 'tax')->get();
        return response()->json([
            'message' => 'Successfully',
            'all_tax' => $banner
        ], 200);
    }


    public function list_user_data($id)
    {
        $find_user = User::where('id', $id)->select('id', 'name', 'email', 'phone', 'pin_code', 'referral_code', 'gender', 'DOB', 'country', 'state', 'city', 'address')->first();     //find($id);
        $find_role = roll_user::where('user_id', $find_user->id)->where('active', 1)->first();
        $current_role = roll::find($find_role->id_roll);
        $totalRevenueData = user_payment_list::where('user_id', $id)->where('active', 1)->get();
        // return $totalRevenueData;
        $uniqueCustomers = $totalRevenueData->count();
        $totalOrders = $totalRevenueData->pluck('order_id')->unique()->count();
        $uniqueVendors = $totalRevenueData->pluck('vendor_id')->unique()->count();
        $totalRevenue = $totalRevenueData->sum('profit_share'); // or 'total_price' depending on your schema
        $uniqueProducts = $totalRevenueData->pluck('product_id')->unique()->count();
        $list_vendore = User::leftJoin('vendors', 'users.id', '=', 'vendors.user_id')
            ->where('vendors.active', 1)
            ->where('users.active', 1)
            ->where('users.user_type', 'vd')
            ->where('users.add_by', $id)
            ->select('vendors.*', 'users.id as v_u_id', 'users.name', 'users.email', 'users.phone')
            ->get();
        $total_vendors = $list_vendore->pluck('v_u_id')->unique()->count();
        $vendorIds = $list_vendore->pluck('v_u_id');
        $list_product = product::whereIn('user_id', $vendorIds)
            ->select('name', 'total_price', 'profit', 'brand_id')
            ->where('active', 1)
            ->whereNull('parent_id')
            ->get();
        $total_products =  $list_product->count();
        $productIds = $list_product->pluck('id');
        $total_active_order = Order_tracking::whereIn('product_id', $productIds)
            ->whereIn('status', ['pending', 'shipped'])
            ->count();
        $user = $id;
        $findUserRoll = roll_user::where('active', 1)
            ->where('user_id', $user)
            ->first();
        if (!$findUserRoll) {
            return response()->json([
                'userRole' => null,
                'userPrivilege' => collect(),
                'roll_under_user' => null
            ], 200);
        }
        $userRoll = roll::find($findUserRoll->id_roll);
        $findUserPrivilege = roll_privilege::where('roll_id', $findUserRoll->id_roll)
            ->where('active', 1)
            ->pluck('privilege_id');
        $userPrivilege = privilege::whereIn('id', $findUserPrivilege)->get();
        $rollUsers = roll_user::select(
            'roll_users.*',
            'users.name',
            'users.email',
            'rolls.roll_name',
            'rolls.id as role_id'
        )
            ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
            ->leftJoin('users', 'roll_users.user_id', '=', 'users.id')
            ->where('roll_users.active', 1)
            ->get()
            ->toArray();
        $tree = $this->buildRoleUserTree($rollUsers, $user);
        // $vendor_ids = collect($tree)->pluck('user_id');
        $vendor_ids = collect($tree)->flatMap(function ($role) {
            return collect($role['users'])->pluck('user_id');
        });
        if (in_array($current_role->id, [1, 2, 3, 13, 14, 15])) {

            if($current_role->id = 1 ){
                $managers_count = roll_user::where('active', 1)
                ->whereNotIn('id_roll', [13, 14, 15])
                ->distinct('user_id')
                ->count('user_id');
            }elseif($current_role->id = 2){
                $managers_count = roll_user::where('active', 1)
                ->whereNotIn('id_roll', [13, 14, 15, 1])
                ->distinct('user_id')
                ->count('user_id');
            }elseif($current_role->id = 3){
                $managers_count = roll_user::where('active', 1)
                ->whereNotIn('id_roll', [13, 14, 15, 1, 2])
                ->distinct('user_id')
                ->count('user_id');
            }
            $list_vendore_under_user = User::leftJoin('vendors', 'users.id', '=', 'vendors.user_id')
                ->where('vendors.active', 1)
                ->where('users.active', 1)
                ->where('users.user_type', 'vd')
                ->select('vendors.*', 'users.id as v_u_id', 'users.name', 'users.email', 'users.phone')
                ->get();
            $list_vendore_under_user_count = $list_vendore_under_user->pluck('id')->unique()->count();    
        }else{
            $managers_count = $vendor_ids->unique()->count();
            $all_vendor_ids = array_merge((array) $vendor_ids, [$id]);
            $list_vendore_under_user = User::leftJoin('vendors', 'users.id', '=', 'vendors.user_id')
                ->where('vendors.active', 1)
                ->where('users.active', 1)
                ->where('users.user_type', 'vd')
                // ->whereIn('users.add_by', $all_vendor_ids)
                ->whereIn('users.add_by', array_merge($vendor_ids->toArray(), [$id]))
                ->select('vendors.*', 'users.id as v_u_id', 'users.name', 'users.email', 'users.phone')
                ->get();
            $list_vendore_under_user_count = $list_vendore_under_user->pluck('id')->unique()->count();
        }
        // return $vendor_ids;
        return response()->json([
            'roll_under_user' => $tree,
            'total_active_order' => $total_active_order,
            'total_products' => $total_products,
            'list_product' => $list_product,
            'total_vendors' => $total_vendors,
            'list_vendore' => $list_vendore,
            'current_role' => $current_role,
            'customers' => $uniqueCustomers,
            'total_orders' => $totalOrders,
            'vendors' => $uniqueVendors,
            'total_revenue' => $totalRevenue,
            'products' => $uniqueProducts,
            'managers_count' => $managers_count,
            'list_vendore_under_user_count' => $list_vendore_under_user_count,
            'list_vendore_under_user' => $list_vendore_under_user
        ], 200);
    }
    private function buildRoleUserTree(array $items, $parentId)
    {
        $usersByRole = [];
        foreach ($items as $item) {
            if ($item['under_user'] == $parentId) {
                // Fetch user_payment_list for each user
                $userPayments = DB::table('user_payment_lists')
                    ->where('user_id', $item['user_id'])
                    ->get();
                $userData = [
                    'user_id' => $item['user_id'],
                    'user_name' => $item['name'],
                    'email' => $item['email'],
                    'user_payment_list' => $userPayments,
                ];
                $roleKey = $item['id_roll']; // group by role ID
                if (!isset($usersByRole[$roleKey])) {
                    $usersByRole[$roleKey] = [
                        'role_id' => $item['id_roll'],
                        'roll_name' => $item['roll_name'],
                        'users' => [],
                    ];
                }
                $usersByRole[$roleKey]['users'][] = $userData;
                // Recursive children
                $children = $this->buildRoleUserTree($items, $item['user_id']);
                foreach ($children as $roleId => $childRole) {
                    if (!isset($usersByRole[$roleId])) {
                        $usersByRole[$roleId] = $childRole;
                    } else {
                        $usersByRole[$roleId]['users'] = array_merge(
                            $usersByRole[$roleId]['users'],
                            $childRole['users']
                        );
                    }
                }
            }
        }
        return array_values($usersByRole); // convert to indexed array
    }

    public function listing_user_role_data($id)
    {
        $user = Auth::user();
        $find_user_roll = roll_user::where('active', 1)->where('user_id', $user->id)->where('id_roll', $id)->first();
        if ($id == 10 || $id == 9) {
            $find_user_role = roll_user::where('id_roll', 10)->get();
            $userIds = $find_user_role->pluck('user_id');
            $totalRevenueData_vendor = user::where('active', 1)
                ->whereIn('add_by', $userIds)
                ->where('user_type', 'vd')
                ->select('name', 'email', 'phone', 'address', 'country', 'state', 'city')
                ->get();
        } elseif ($id == 12 || $id == 11) {
            $find_user_role = roll_user::where('id_roll', 12)->get();
            $userIds = $find_user_role->pluck('user_id');
            $totalRevenueData_vendor = user::where('active', 1)
                ->whereIn('add_by', $userIds)
                ->where('user_type', 'vd')
                ->select('name', 'email', 'phone', 'address', 'country', 'state', 'city')
                ->get();
        } else {
            $totalRevenueData_vendor = user::where('active', 1)
                ->where('user_type', 'vd')
                ->select('name', 'email', 'phone', 'address', 'country', 'state', 'city')
                ->get();
        }
        if ($find_user_roll) {
            $totalRevenueData = user_payment_list::where('user_id', $user->id)
                ->where('active', 1)
                ->get();
            $uniqueCustomers = $totalRevenueData->count();
            $totalOrders = $totalRevenueData->pluck('order_id')->unique()->count();
            $uniqueVendors = $totalRevenueData_vendor->count();
            $totalRevenue = $totalRevenueData->sum('profit_share');
            $uniqueProducts = $totalRevenueData->pluck('product_id')->unique()->count();
            return response()->json([
                'message' => 'Data retrieved successfully',
                'user_list' => [],
                'customers' => $uniqueCustomers,
                'total_orders' => $totalOrders,
                'vendors' => $uniqueVendors,
                'vendors_list' => $totalRevenueData_vendor,
                'total_revenue' => $totalRevenue,
                'products' => $uniqueProducts,
            ], 200);
        }
        $find_user_role = roll_user::leftJoin('users', 'roll_users.user_id', '=', 'users.id')
            ->leftJoin('rolls', 'rolls.id', '=', 'roll_users.id_roll')
            ->where('roll_users.id_roll', $id)
            ->where('users.active', 1)
            ->where('roll_users.active', 1)
            ->select('users.id', 'users.name', 'users.email', 'users.phone', 'users.pin_code', 'users.address', 'roll_users.under_user', 'roll_users.sub_under_user', 'roll_users.id_roll', 'rolls.roll_name')
            ->get();
        $userIds = $find_user_role->pluck('id');
        $totalRevenueData = user_payment_list::whereIn('user_id', $userIds)
            ->where('active', 1)
            ->get();
        $uniqueCustomers = $totalRevenueData->pluck('user_id')->unique()->count();
        $totalOrders = $totalRevenueData->pluck('order_id')->unique()->count();
        $uniqueVendors = $totalRevenueData_vendor->count();
        $totalRevenue = $totalRevenueData->sum('profit_share'); // or 'total_price' depending on your schema
        $uniqueProducts = $totalRevenueData->pluck('product_id')->unique()->count();
        return response()->json([
            'message' => 'Data retrieved successfully',
            'user_list' => $find_user_role,
            'customers' => $uniqueCustomers,
            'total_orders' => $totalOrders,
            'vendors' => $uniqueVendors,
            'vendors_list' => $totalRevenueData_vendor,
            'total_revenue' => $totalRevenue,
            'products' => $uniqueProducts,
        ], 200);
    }
    public function add_testv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'variants' => 'nullable|array',
            'variants.*.name' => 'required|string|max:255',
            'variants.*.image' => 'array',
            'variants.*.image.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'variants.*.attributes' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            foreach ($request->input('variants') as $index => $variant) {
                if ($request->hasFile("variants.$index.image")) {
                    foreach ($request->file("variants.$index.image") as $file) {
                        if (!$file->isValid()) {
                            throw new \Exception('File upload failed.');
                        }
                        $extension = $file->getClientOriginalExtension();
                        $filename = time() . Str::random(10) . '.' . $extension;
                        $file->move(public_path('image/product/'), $filename);
                        $productImage = new product_image();
                        $productImage->productImages = "image/product/" . $filename;
                        $productImage->user_id = $user->id;
                        $productImage->product_id = 17;
                        if (!$productImage->save()) {
                            throw new \Exception('Failed to save image.');
                        }
                    }
                }
            }
            DB::commit();
            return response()->json(['message' => 'Product added successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function delete_roles($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer|exists:rolls,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 400);
        }
        $find_user = roll_user::where('id_roll', $id)->where('active', 1)->first();
        if ($find_user) {
            return response()->json(['error' => 'Failed to Delete role.'], 400);
        }
        DB::beginTransaction();
        try {

            $find_role = roll::find($id);
            $find_role->update([
                'active'     => 0,
            ]);

            DB::commit();
            return response()->json(['message' => 'Role Delete successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to update role.'], 500);
        }
    }
    public function add_coupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|unique:offer_codes,code',
            'description' => 'required|string|max:255',
            'titel' => 'required|string|max:255',
            'amount_type' => 'required|string|max:255',
            'amount' => 'required|string|max:255',
            'expiry_date' => 'required|string|max:255',
            'limit_per_user' => 'required|string|max:255',
            'totel_limit' => 'required|string|max:255',
            'promo_visibility' => 'required|string|max:255',
            'card_type' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $dataimage = $this->uploadFile($request, 'image', 'images/banner/');
            offer_code::create([
                'code' => $request->code,
                'description' => $request->description,
                'titel' => $request->titel,
                'amount_type' => $request->amount_type,
                'amount' => $request->amount,
                'expiry_date' => $request->expiry_date,
                'limit_per_user' => $request->limit_per_user,
                'totel_limit' => $request->totel_limit,
                'promo_visibility' => $request->promo_visibility,
                'card_type' => $request->card_type,
                'image' => $dataimage,
                'active' => 1,
                'user_id' => $user->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'Coupon Add successfully',], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function edit_coupon(Request $request, $id)
    {
        $coupon = offer_code::find($id);

        if (!$coupon) {
            return response()->json(['error' => 'Coupon not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|unique:offer_codes,code,' . $coupon->id,
            'description' => 'required|string|max:255',
            'titel' => 'required|string|max:255',
            'amount_type' => 'required|string|max:255',
            'amount' => 'required|string|max:255',
            'expiry_date' => 'required|string|max:255',
            'limit_per_user' => 'required|string|max:255',
            'totel_limit' => 'required|string|max:255',
            'promo_visibility' => 'required|string|max:255',
            'card_type' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
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

            if ($request->hasFile('image')) {
                $imagePath = $this->uploadFile($request, 'image', 'images/banner/');
            } else {
                $imagePath = $coupon->image;
            }

            $coupon->update([
                'code' => $request->code,
                'description' => $request->description,
                'titel' => $request->titel,
                'amount_type' => $request->amount_type,
                'amount' => $request->amount,
                'expiry_date' => $request->expiry_date,
                'limit_per_user' => $request->limit_per_user,
                'totel_limit' => $request->totel_limit,
                'promo_visibility' => $request->promo_visibility,
                'card_type' => $request->card_type,
                'image' => $imagePath,
                'user_id' => $user->id,
            ]);

            DB::commit();
            return response()->json(['message' => 'Coupon updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    public function view_all_coupons()
    {
        $coupons = offer_code::orderBy('id', 'desc')->where('active', 1)->get();
        return response()->json([
            'success' => true,
            'data' => $coupons
        ], 200);
    }
    public function delete_coupon($id)
    {
        $coupon = offer_code::find($id);
        if (!$coupon) {
            return response()->json(['error' => 'Coupon not found.'], 404);
        }
        $coupon->update([
            'active' => 0,
        ]);
        return response()->json(['coupon' => $coupon], 200);
    }
    public function find_coupon($code)
    {
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $coupon = offer_code::where('code', $code)->where('active', 1)->first();
            if (!$coupon) {
                return response()->json(['error' => 'Coupon not found.'], 400);
            }
            if (Carbon::today()->gt(Carbon::parse($coupon->offer_up_to))) {
                $coupon->update(['active' => 0]);
                return response()->json(['error' => 'Coupon expired.'], 400);
            }
            if (!is_null($coupon->total_limit) && $coupon->total_limit <= 0) {
                $coupon->update(['active' => 0]);
                return response()->json(['error' => 'Coupon usage limit reached.'], 400);
            }
            $usedCodes = used_code::where([
                ['code_id', '=', $coupon->id],
                ['user_id', '=', $user->id],
                ['active', '=', 1],
            ])->get();
            $hasPending = $usedCodes->contains(function ($used) {
                return $used->status === 'pending';
            });
            if ($hasPending) {
                return response()->json(['coupon' => $coupon], 200);
            }
            if ($usedCodes->count() >= $coupon->limit_per_user) {
                return response()->json(['error' => 'Coupon usage limit per user reached.'], 400);
            }
            if (!is_null($coupon->total_limit) && $coupon->total_limit > 0) {
                $coupon->decrement('totel_limit');
            }
            used_code::create([
                'code_id' => $coupon->id,
                'user_id' => $user->id,
            ]);
            DB::commit();
            return response()->json(['coupon' => $coupon], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Coupon error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/revenue_list",
     *     tags={"Revenue"},
     *     summary="Get revenue data for multiple charts (using form data)",
     *     description="Returns revenue details using form data input.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"chart1_start_date", "chart1_end_date", "chart1_pin_code", "chart2_type", "chart2_pin_code"},
     *                 @OA\Property(property="chart1_start_date", type="string", format="date", example="2024-01-01"),
     *                 @OA\Property(property="chart1_end_date", type="string", format="date", example="2024-01-31"),
     *                 @OA\Property(property="chart1_pin_code", type="string", example="123456"),
     *                 @OA\Property(property="chart2_type", type="string", example="Monthly"),
     *                 @OA\Property(property="chart2_pin_code", type="string", example="123456")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="revenue_chart1", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="revenue_chart2", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="find_revenue_chart_3", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */

    public function revenue_list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chart1_start_date' => 'required|date|before_or_equal:chart1_end_date',
            'chart1_end_date' => 'required|date|after_or_equal:chart1_start_date',
            'chart1_pin_code' => 'required|string',
            'chart2_type' => 'required|string',
            'chart2_pin_code' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $startDatec1 = $request->input('chart1_start_date');
        $endDatec1 = $request->input('chart1_end_date');
        $pinCodec1 = $request->input('chart1_pin_code');
        $pinCodec2 = $request->input('chart2_pin_code');
        $find_revenue_chart_1 = Order_tracking::with('finduser')
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$startDatec1, $endDatec1])
            ->whereHas('finduser', function ($query) use ($pinCodec1) {
                $query->where('pin_code', $pinCodec1);
            })->select(
                'order_trackings.id',
                'order_trackings.product_id',
                'order_trackings.quantity',
                'order_trackings.totel_price',
                'order_trackings.tracking_number'
            )
            ->get();
        if ($request->input('chart2_type') == 'Monthly') {
            $find_revenue_chart_2 = Order_tracking::with('finduser')
                ->where('status', 'delivered')
                ->whereHas('finduser', function ($query) use ($pinCodec2) {
                    $query->where('pin_code', $pinCodec2);
                })
                ->select(
                    DB::raw("DATE_FORMAT(delivered_at, '%Y-%m') as month"),
                    DB::raw("SUM(totel_price) as total_revenue"),
                    DB::raw("COUNT(*) as total_orders")
                )
                ->groupBy(DB::raw("DATE_FORMAT(delivered_at, '%Y-%m')"))
                ->orderBy(DB::raw("DATE_FORMAT(delivered_at, '%Y-%m')"), 'asc')
                ->get();
        } elseif ($request->input('chart2_type') == 'Yearly') {
            $find_revenue_chart_2 = Order_tracking::with('finduser')
                ->where('status', 'delivered')
                ->whereHas('finduser', function ($query) use ($pinCodec2) {
                    $query->where('pin_code', $pinCodec2);
                })
                ->select(
                    DB::raw("YEAR(delivered_at) as year"),
                    DB::raw("SUM(totel_price) as total_revenue"),
                    DB::raw("COUNT(*) as total_orders")
                )
                ->groupBy(DB::raw("YEAR(delivered_at)"))
                ->orderBy(DB::raw("YEAR(delivered_at)"), 'asc')
                ->get();
        } else {
            $find_revenue_chart_2 = Order_tracking::with('finduser')
                ->where('status', 'delivered')
                ->whereHas('finduser', function ($query) use ($pinCodec2) {
                    $query->where('pin_code', $pinCodec2);
                })
                ->select(
                    DB::raw("YEARWEEK(delivered_at, 1) as week"),
                    DB::raw("SUM(totel_price) as total_revenue"),
                    DB::raw("COUNT(*) as total_orders")
                )
                ->groupBy(DB::raw("YEARWEEK(delivered_at, 1)"))
                ->orderBy(DB::raw("YEARWEEK(delivered_at, 1)"), 'asc')
                ->get();
        }
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $stateToCode = [
            "Andhra Pradesh" => "AP",
            "Arunachal Pradesh" => "AR",
            "Assam" => "AS",
            "Bihar" => "BR",
            "Chhattisgarh" => "CG",
            "Goa" => "GA",
            "Gujarat" => "GJ",
            "Haryana" => "HR",
            "Himachal Pradesh" => "HP",
            "Jharkhand" => "JH",
            "Karnataka" => "KA",
            "Kerala" => "KL",
            "Madhya Pradesh" => "MP",
            "Maharashtra" => "MH",
            "Manipur" => "MN",
            "Meghalaya" => "ML",
            "Mizoram" => "MZ",
            "Nagaland" => "NL",
            "Odisha" => "OR",
            "Punjab" => "PB",
            "Rajasthan" => "RJ",
            "Sikkim" => "SK",
            "Tamil Nadu" => "TN",
            "Telangana" => "TS",
            "Tripura" => "TR",
            "Uttar Pradesh" => "UP",
            "Uttarakhand" => "UK",
            "West Bengal" => "WB",
            "Delhi" => "DL",
            "Jammu and Kashmir" => "JK",
            "Ladakh" => "LA",
        ];
        $codeToState = array_flip($stateToCode);
        $find_revenue_chart_3 = Order_tracking::with('finduser')
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$startOfMonth, $endOfMonth])
            ->whereHas('finduser')
            ->join('users', 'order_trackings.user_id', '=', 'users.id')
            ->select(
                'users.state',
                DB::raw('SUM(order_trackings.totel_price) as total_revenue'),
                DB::raw('COUNT(order_trackings.id) as total_orders')
            )
            ->groupBy('users.state')
            ->get()
            ->map(function ($item) use ($codeToState) {
                return [
                    'state' => $codeToState[$item->state] ?? null,
                    'state_code' => $item->state,
                    'total_revenue' => $item->total_revenue,
                    'total_orders' => $item->total_orders,
                ];
            });
        return response()->json([
            'success' => true,
            'revenue_chart1' => $find_revenue_chart_1,
            'revenue_chart2' => $find_revenue_chart_2,
            'find_revenue_chart_3' => $find_revenue_chart_3,
        ], 200);
    }
    public function user_revenue_list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'  => 'required|exists:users,id',
            'chart1_start_date' => 'required|date|before_or_equal:chart1_end_date',
            'chart1_end_date' => 'required|date|after_or_equal:chart1_start_date',
            'chart1_pin_code' => 'required|string',
            'chart2_type' => 'required|string',
            'chart2_pin_code' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $startDatec1 = $request->input('chart1_start_date');
        $endDatec1 = $request->input('chart1_end_date');
        $pinCodec1 = $request->input('chart1_pin_code');
        $pinCodec2 = $request->input('chart2_pin_code');

        $find_revenue_chart_1 = user_payment_list::where('user_id', $request->input('user_id'))
            ->whereBetween('created_at', [$startDatec1, $endDatec1])
            ->get();



        if ($request->input('chart2_type') == 'Monthly') {
            $find_revenue_chart_2 = user_payment_list::where('user_id', $request->input('user_id'))

                ->select(
                    DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                    DB::raw("SUM(profit_share) as total_revenue"),
                    DB::raw("COUNT(*) as total_orders")
                )
                ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
                ->orderBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"), 'asc')
                ->get();
        } elseif ($request->input('chart2_type') == 'Yearly') {
            $find_revenue_chart_2 = Order_tracking::where('user_id', $request->input('user_id'))

                ->select(
                    DB::raw("YEAR(created_at) as year"),
                    DB::raw("SUM(profit_share) as total_revenue"),
                    DB::raw("COUNT(*) as total_orders")
                )
                ->groupBy(DB::raw("YEAR(created_at)"))
                ->orderBy(DB::raw("YEAR(created_at)"), 'asc')
                ->get();
        } else {
            $find_revenue_chart_2 = Order_tracking::where('user_id', $request->input('user_id'))
                ->select(
                    DB::raw("YEARWEEK(created_at, 1) as week"),
                    DB::raw("SUM(profit_share) as total_revenue"),
                    DB::raw("COUNT(*) as total_orders")
                )
                ->groupBy(DB::raw("YEARWEEK(created_at, 1)"))
                ->orderBy(DB::raw("YEARWEEK(created_at, 1)"), 'asc')
                ->get();
        }


        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $stateToCode = [
            "Andhra Pradesh" => "AP",
            "Arunachal Pradesh" => "AR",
            "Assam" => "AS",
            "Bihar" => "BR",
            "Chhattisgarh" => "CG",
            "Goa" => "GA",
            "Gujarat" => "GJ",
            "Haryana" => "HR",
            "Himachal Pradesh" => "HP",
            "Jharkhand" => "JH",
            "Karnataka" => "KA",
            "Kerala" => "KL",
            "Madhya Pradesh" => "MP",
            "Maharashtra" => "MH",
            "Manipur" => "MN",
            "Meghalaya" => "ML",
            "Mizoram" => "MZ",
            "Nagaland" => "NL",
            "Odisha" => "OR",
            "Punjab" => "PB",
            "Rajasthan" => "RJ",
            "Sikkim" => "SK",
            "Tamil Nadu" => "TN",
            "Telangana" => "TS",
            "Tripura" => "TR",
            "Uttar Pradesh" => "UP",
            "Uttarakhand" => "UK",
            "West Bengal" => "WB",
            "Delhi" => "DL",
            "Jammu and Kashmir" => "JK",
            "Ladakh" => "LA",
        ];
        $codeToState = array_flip($stateToCode);
        $find_revenue_chart_3 = Order_tracking::with('finduser')
            ->where('status', 'delivered')
            // ->where('user_id', $request->input('user_id'))
            ->whereBetween('delivered_at', [$startOfMonth, $endOfMonth])
            ->whereHas('finduser')
            ->join('users', 'order_trackings.user_id', '=', 'users.id')
            ->select(
                'users.state',
                DB::raw('SUM(order_trackings.totel_price) as total_revenue'),
                DB::raw('COUNT(order_trackings.id) as total_orders')
            )
            ->groupBy('users.state')
            ->get()
            ->map(function ($item) use ($codeToState) {
                return [
                    'state' => $codeToState[$item->state] ?? null,
                    'state_code' => $item->state,
                    'total_revenue' => $item->total_revenue,
                    'total_orders' => $item->total_orders,
                ];
            });
        return response()->json([
            'success' => true,
            'revenue_chart1' => $find_revenue_chart_1,
            'revenue_chart2' => $find_revenue_chart_2,
            'find_revenue_chart_3' => $find_revenue_chart_3,
        ], 200);
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add-banner",
     *     summary="Add a new banner",
     *     description="Upload a banner image and create a new banner entry.",
     *     tags={"Banners"},
     *     security={{"bearerAuth":{}}},
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"name", "start_date", "end_date", "assign_to", "assign_value", "enable"},
     *                 @OA\Property(property="name", type="string", example="Summer Sale"),
     *                 @OA\Property(property="start_date", type="string", example="2024-04-01"),
     *                 @OA\Property(property="end_date", type="string", example="2024-06-01"),
     *                 @OA\Property(property="assign_to", type="string", example="category"),
     *                 @OA\Property(property="assign_value", type="string", example="electronics"),
     *                 @OA\Property(property="enable", type="string", example="1"),
     *                 @OA\Property(property="image", type="string", format="binary")
     *             )
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="Banner added successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Brand added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
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
     *             @OA\Property(property="error", type="string", example="An unexpected error occurred.")
     *         )
     *     )
     * )
     */
    public function add_banner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'start_date' => 'required|string|max:255',
            'end_date' => 'required|string|max:255',
            'assign_to' => 'required|string|max:255',
            // 'assign_value' => 'required|string|max:255',
            'enable' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $data = $request->except('_token');
            if ($request->hasFile("image")) {
                $data['image'] = $this->uploadFile($request, 'image', 'images/banner/');
            }
            other::create([
                'titel' => 2,
                'active' => $request->enable,
                'user_id' => $user->id,
                'value' => json_encode($data)
            ]);
            DB::commit();
            return response()->json(['message' => 'Brand added successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/update_banner/{id}",
     *     summary="Update banner details",
     *     description="Update banner details including name, dates, and image.",
     *     tags={"Banners"},
     *     security={{"bearerAuth":{}}},
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Banner ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"name", "start_date", "end_date", "assign_to", "assign_value", "enable"},
     *                 @OA\Property(property="name", type="string", example="Winter Sale"),
     *                 @OA\Property(property="start_date", type="string", example="2024-07-01"),
     *                 @OA\Property(property="end_date", type="string", example="2024-12-01"),
     *                 @OA\Property(property="assign_to", type="string", example="brand"),
     *                 @OA\Property(property="assign_value", type="string", example="Nike"),
     *                 @OA\Property(property="enable", type="boolean", example=true),
     *                 @OA\Property(property="image", type="string", format="binary")
     *             )
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="Banner updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Banner updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Banner not found"
     *     )
     * )
     */
    public function update_banner(Request $request, $id)
    {
        $banner = other::find($id);
        if (!$banner) {
            return response()->json(['error' => 'Banner not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'start_date' => 'required|string|max:255',
            'end_date' => 'required|string|max:255',
            'assign_to' => 'required|string|max:255',
            // 'assign_value' => 'required|string|max:255',
            'enable' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }

        DB::beginTransaction();

        try {
            $data = json_decode($banner->value, true);

            // Update new values
            $data['name'] = $request->name;
            $data['start_date'] = $request->start_date;
            $data['end_date'] = $request->end_date;
            $data['assign_to'] = $request->assign_to;
            $data['assign_value'] = $request->assign_value;
            $banner->active = $request->enable;

            if ($request->hasFile("image")) {
                $data['image'] = $this->uploadFile($request, 'image', 'images/banner/');
            }

            $banner->value = json_encode($data);
            $banner->save();

            DB::commit();
            return response()->json(['message' => 'Banner updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_banners",
     *     summary="Get all banners",
     *     description="Retrieve a list of all active banners.",
     *     tags={"Banners"},
     *     security={{"bearerAuth":{}}},
     *     
     *     @OA\Response(
     *         response=200,
     *         description="List of banners retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="banners", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Summer Sale"),
     *                     @OA\Property(property="start_date", type="string", example="2024-04-01"),
     *                     @OA\Property(property="end_date", type="string", example="2024-06-01"),
     *                     @OA\Property(property="assign_to", type="string", example="category"),
     *                     @OA\Property(property="assign_value", type="string", example="electronics"),
     *                     @OA\Property(property="enable", type="boolean", example=true),
     *                     @OA\Property(property="image", type="string", example="images/banner/summer-sale.jpg")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No banners found"
     *     )
     * )
     */
    public function view_all_banners()
    {
        $banners = other::where('titel', 2)->get();
        if ($banners->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No banners found.'], 404);
        }
        $result = $banners->map(function ($banner) {
            $data = json_decode($banner->value, true);
            return [
                'id'           => $banner->id,
                'name'         => $data['name'] ?? '',
                'start_date'   => $data['start_date'] ?? '',
                'end_date'     => $data['end_date'] ?? '',
                'assign_to'    => $data['assign_to'] ?? '',
                'assign_value' => $data['assign_value'] ?? '',
                'enable'       => $banner->active,
                'image'        => $data['image'] ?? null
            ];
        });
        return response()->json(['success' => true, 'banners' => $result], 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/delete_banner/{id}",
     *     summary="Delete a banner",
     *     description="Delete a specific banner by ID.",
     *     tags={"Banners"},
     *     security={{"bearerAuth":{}}},
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Banner ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="Banner deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Banner deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Banner not found"
     *     )
     * )
     */
    public function delete_banner($id)
    {
        $banner = other::find($id);
        if (!$banner) {
            return response()->json(['success' => false, 'message' => 'Banner not found.'], 404);
        }
        DB::beginTransaction();
        try {
            // $data = json_decode($banner->value, true);
            // // Delete the image file if exists
            // if (isset($data['image']) && file_exists(($data['image']))) {
            //     unlink(($data['image']));
            // }
            // // Delete the banner record
            $banner->delete();
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Banner deleted successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_employee",
     *     summary="Add a new employee",
     *     description="Registers a new employee with a role and assigns them under a manager.",
     *     tags={"Employees"},
     *     security={{"bearerAuth":{}}},
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","phone","password","address","role_id","under_user","gender","pin_code"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *             @OA\Property(property="phone", type="string", example="+1234567890"),
     *             @OA\Property(property="password", type="string", example="securePass123"),
     *             @OA\Property(property="address", type="string", example="123 Main Street"),
     *             @OA\Property(property="country", type="string", example="USA"),
     *             @OA\Property(property="state", type="string", example="California"),
     *             @OA\Property(property="city", type="string", example="Los Angeles"),
     *             @OA\Property(property="gender", type="string", example="Male"),
     *             @OA\Property(property="pin_code", type="string", example="90001"),
     *             @OA\Property(property="role_id", type="integer", example=2),
     *             @OA\Property(property="under_user", type="integer", example=1)
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="User registered successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User registered successfully"),
     *             @OA\Property(property="user_type", type="string", example="ad"),
     *             @OA\Property(property="name", type="string", example="John Doe")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    // public function add_employee(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'name' => 'required|string|max:255|min:3',
    //         'email' => 'required|email|unique:users,email',
    //         'phone' => [
    //             'required',
    //             'unique:users,phone',
    //             'regex:/^\+?[0-9]{10,14}$/'
    //         ],
    //         'password' => 'required|string|min:6',
    //         'address' => 'nullable|string|max:255',
    //         'country' => 'nullable|string|max:255',
    //         'state' => 'nullable|string|max:255',
    //         'gender' => 'required|string|max:255',
    //         'pin_code' => 'required|string|max:255',
    //         'city' => 'nullable|string|max:255',
    //         'role_id' => 'required|integer|exists:rolls,id',
    //         'under_user' => 'required|integer|exists:users,id',
    //     ]);
    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors()
    //         ], 400);
    //     }

    //     if($request->role_id == 10 || $request->role_id == 12){
    //         $validator = Validator::make($request->all(), [
    //             'sub_under_user' => 'required|integer|exists:users,id',
    //         ]);
    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Validation error',
    //                 'errors' => $validator->errors()
    //             ], 400);
    //         }
    //         $find_user =roll_user::where('id_roll',7)->where('user_id',$request->sub_under_user)->where('active',1)->first();
    //         if(!$find_user){
    //             return response()->json(['error' => 'Partner not found.'], 400);
    //         }

    //     }
    //     DB::beginTransaction();
    //     try {
    //         $add_by = Auth::user();

    //         $data = $request->only('name', 'email', 'phone', 'password', 'address', 'country', 'state', 'city', 'pin_code', 'gender');
    //         $data['password'] = bcrypt($request->password);
    //         $data['user_type'] = 'ad';
    //         $data['add_by'] = $add_by->id;
    //         $data['referral_code'] = time() . Str::random(10);
    //         $user = User::create($data);
    //         roll_user::create([
    //             'id_roll' => $request->role_id,
    //             'user_id' => $user->id,
    //             'under_user' => $request->under_user,
    //             'add_by' => $add_by->id,
    //             'sub_under_user' =>$request->sub_under_user ? null,
    //         ]);
    //         DB::commit();
    //         return response()->json([
    //             'message' => 'User registered successfully',
    //             'user_type' => $user->user_type,
    //             'name' => $user->name,
    //         ], 200);
    //     } catch (\Exception $e) {
    //         DB::rollback();
    //         Log::error($e->getMessage());
    //         return response()->json(['error' => 'Something went wrong.'], 500);
    //     }
    // }

    public function add_employee(Request $request)
    {
        $baseRules = [
            'name' => 'required|string|max:255|min:3',
            'email' => 'required|email|unique:users,email',
            'phone' => [
                'required',
                'unique:users,phone',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'password' => 'required|string|min:6',
            'address' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'gender' => 'required|string|max:255',
            'pin_code' => 'required|integer|max:6|min:6',
            'city' => 'nullable|string|max:255',
            'role_id' => 'required|integer|exists:rolls,id',
            'under_user' => 'required|integer|exists:users,id',
        ];

        // Additional validation for specific roles
        if (in_array($request->role_id, [10, 12])) {
            $baseRules['sub_under_user'] = 'required|integer|exists:users,id';
        }

        $validator = Validator::make($request->all(), $baseRules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }

        // Partner check for roles 10 and 12
        if (in_array($request->role_id, [10, 12])) {
            $partner = roll_user::where('id_roll', 7)
                ->where('user_id', $request->sub_under_user)
                ->where('active', 1)
                ->first();

            if (!$partner) {
                return response()->json(['error' => 'Partner not found.'], 400);
            }
        }

        DB::beginTransaction();
        try {
            $addBy = Auth::user();

            $data = $request->only('name', 'email', 'phone', 'password', 'address', 'country', 'state', 'city', 'pin_code', 'gender');
            $data['password'] = bcrypt($request->password);
            $data['user_type'] = 'ad';
            $data['add_by'] = $addBy->id;
            $data['referral_code'] = time() . Str::random(10);

            $user = User::create($data);

            roll_user::create([
                'id_roll' => $request->role_id,
                'user_id' => $user->id,
                'under_user' => $request->under_user,
                'add_by' => $addBy->id,
                'sub_under_user' => $request->sub_under_user ?? null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'User registered successfully',
                'user_type' => $user->user_type,
                'name' => $user->name,
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }
    // public function edit_employee(Request $request, $id)
    // {
    //     $findUser = User::where('id', $id)->where('active', 1)->first();
    //     if (!$findUser) {
    //         return response()->json(['message' => 'User not found'], 404);
    //     }
    //     $rules = [
    //         'name' => 'required|string|max:255',
    //         'address' => 'required|string|max:255',
    //         'pin_code' => 'required|string|max:255',
    //         'role_id' => 'required_with:under_user|integer|exists:rolls,id',
    //         'under_user' => 'required_with:role_id|integer|exists:users,id',
    //         'assign_to' => 'required_with:under_user|integer|exists:users,id',
    //     ];
    //     if (in_array($request->role_id, [10, 12])) {
    //         $rules['sub_under_user'] = 'required|integer|exists:users,id';
    //     }
    //     $validator = Validator::make($request->all(), $rules);
    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors()
    //         ], 400);
    //     }
    //     if (in_array($request->role_id, [10, 12])) {
    //         $partner = roll_user::where('id_roll', 7)
    //             ->where('user_id', $request->sub_under_user)
    //             ->where('active', 1)
    //             ->first();
    //         if (!$partner) {
    //             return response()->json(['error' => 'Partner not found.'], 400);
    //         }
    //     }
    //     $currentUser = Auth::user();
    //     DB::beginTransaction();
    //     try {
    //         $findUser->update($request->only('name', 'address', 'pin_code'));
    //         if ($request->filled(['role_id', 'under_user', 'assign_to'])) {
    //             $existingRole = roll_user::where('active', 1)
    //                 ->where('user_id', $findUser->id)
    //                 ->where('id_roll', $request->role_id)
    //                 ->where('under_user', $request->under_user)
    //                 ->first();
    //             if (!$existingRole) {
    //                 // Inactivate old role
    //                 $oldRole = roll_user::where('active', 1)
    //                     ->where('user_id', $findUser->id)
    //                     ->first();
    //                 if ($oldRole) {
    //                     // Reassign subordinates if any
    //                     roll_user::where('active', 1)
    //                         ->where('under_user', $findUser->id)
    //                         ->update(['under_user' => $request->assign_to]);
    //                     $oldRole->update([
    //                         'active' => 0,
    //                         'add_by' => $currentUser->id,
    //                     ]);
    //                 }
    //                 // Assign new role
    //                 roll_user::create([
    //                     'id_roll' => $request->role_id,
    //                     'user_id' => $findUser->id,
    //                     'under_user' => $request->under_user,
    //                     'add_by' => $currentUser->id,
    //                     'sub_under_user' => $request->sub_under_user ?? null,
    //                 ]);
    //             }
    //         }
    //         DB::commit();
    //         return response()->json(['message' => 'Employee updated successfully.'], 200);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error($e->getMessage());
    //         return response()->json(['error' => 'Something went wrong.'], 500);
    //     }
    // }
    public function edit_employee(Request $request, $id)
    {
        $findUser = User::where('id', $id)->where('active', 1)->first();
        if (!$findUser) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $rules = [
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'pin_code' => 'required|string|max:255',
            'role_id' => 'required_with:under_user|integer|exists:rolls,id',
            'under_user' => 'required_with:role_id|integer|exists:users,id',
            'assign_to' => 'required_with:under_user|integer|exists:users,id',
        ];
        if (in_array($request->role_id, [10, 12])) {
            $rules['sub_under_user'] = 'required|integer|exists:users,id';
        }
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        if (in_array($request->role_id, [10, 12])) {
            $partner = roll_user::where('id_roll', 7)
                ->where('user_id', $request->sub_under_user)
                ->where('active', 1)
                ->first();

            if (!$partner) {
                return response()->json(['error' => 'Partner not found.'], 400);
            }
        }
        $currentUser = Auth::user();
        DB::beginTransaction();
        try {
            $findUser->update($request->only('name', 'address', 'pin_code'));
            if ($request->filled(['role_id', 'under_user', 'assign_to'])) {
                // return $request->sub_under_user;
                $existingRole = roll_user::where('active', 1)
                    ->where('user_id', $findUser->id)
                    ->where('id_roll', $request->role_id)
                    ->where('under_user', $request->under_user)
                    ->first();

                if (!$existingRole) {
                    // return $request->sub_under_user;
                    // Inactivate old role
                    $oldRole = roll_user::where('active', 1)
                        ->where('user_id', $findUser->id)
                        ->first();
                    if ($oldRole) {
                        // Reassign subordinates if any
                        roll_user::where('active', 1)
                            ->where('under_user', $findUser->id)
                            ->update(['under_user' => $request->assign_to]);
                        $oldRole->update([
                            'active' => 0,
                            'add_by' => $currentUser->id,
                        ]);
                    }

                    // return $request->sub_under_user;
                    // Assign new role
                    roll_user::create([
                        'id_roll' => $request->role_id,
                        'user_id' => $findUser->id,
                        'under_user' => $request->under_user,
                        'add_by' => $currentUser->id,
                        'sub_under_user' => $request->sub_under_user ?? null,
                    ]);
                } else {
                    $existingRole2 = roll_user::where('active', 1)
                        ->where('user_id', $findUser->id)
                        ->where('id_roll', $request->role_id)
                        ->where('under_user', $request->under_user)
                        ->where('sub_under_user', $request->sub_under_user)
                        ->first();
                    if (!$existingRole2) {
                        $oldRole = roll_user::where('active', 1)
                            ->where('user_id', $findUser->id)
                            ->first();
                        if ($oldRole) {
                            // Reassign subordinates if any
                            roll_user::where('active', 1)
                                ->where('under_user', $findUser->id)
                                ->update(['under_user' => $request->assign_to]);
                            $oldRole->update([
                                'active' => 0,
                                'add_by' => $currentUser->id,
                            ]);
                        }

                        // return $request->sub_under_user;
                        // Assign new role
                        roll_user::create([
                            'id_roll' => $request->role_id,
                            'user_id' => $findUser->id,
                            'under_user' => $request->under_user,
                            'add_by' => $currentUser->id,
                            'sub_under_user' => $request->sub_under_user ?? null,
                        ]);
                    }
                }
                User::where('user_type', 'vd')->where('add_by', $id)->update(['add_by' => $request->assign_to]);
            }
            DB::commit();
            return response()->json(['message' => 'Employee updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }

    // public function edit_employee(Request $request, $id)
    // {
    //     $find_user = User::where('id', $id)->where('active', 1)->first();
    //     if (!$find_user) {
    //         return response()->json(['message' => 'User not found'], 404);
    //     }
    //     $validator = Validator::make($request->all(), [
    //         'name' => 'required|string|max:255',
    //         'address' => 'required|string|max:255',
    //         'pin_code' => 'required|string|max:255',
    //         'role_id' => 'required_with:under_user|integer|exists:rolls,id',
    //         'under_user' => 'required_with:role_id|integer|exists:users,id',
    //         'assign_to' => 'required_with:under_user|integer|exists:users,id',
    //     ]);
    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors()
    //         ], 400);
    //     }


    //     if (in_array($request->role_id, [10, 12])) {
    //         $baseRules['sub_under_user'] = 'required|integer|exists:users,id';
    //     }

    //     $validator = Validator::make($request->all(), $baseRules);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors()
    //         ], 400);
    //     }

    //     // Partner check for roles 10 and 12
    //     if (in_array($request->role_id, [10, 12])) {
    //         $partner = roll_user::where('id_roll', 7)
    //             ->where('user_id', $request->sub_under_user)
    //             ->where('active', 1)
    //             ->first();
    //         if (!$partner) {
    //             return response()->json(['error' => 'Partner not found.'], 400);
    //         }
    //     }

    //     $user = Auth::user();

    //     DB::beginTransaction();
    //     try {
    //         $find_user->update($request->only('name', 'address', 'pin_code'));

    //         if ($request->filled('role_id') && $request->filled('under_user') && $request->filled('assign_to')) {
    //             $user_current_role = roll_user::where('active', 1)
    //                 ->where('user_id', $find_user->id)->where('id_roll', $request->role_id)
    //                 ->where('under_user', $request->under_user)->first();
    //             if (!$user_current_role) {
    //                 $find_role = roll_user::where('active', 1)
    //                     ->where('user_id', $find_user->id)
    //                     ->first();
    //                 if ($find_role) {
    //                     roll_user::where('active', 1)
    //                         ->where('under_user', $find_user->id)
    //                         ->update(['under_user' => $request->assign_to]);

    //                     $find_role->update([
    //                         'active' => 0,
    //                         'add_by' => $user->id,
    //                     ]);
    //                 }
    //                 roll_user::create([
    //                     'id_roll' => $request->role_id,
    //                     'user_id' => $find_user->id,
    //                     'under_user' => $request->under_user,
    //                     'add_by' => $user->id,
    //                     'sub_under_user' => $request->sub_under_user ?? null,
    //                 ]);
    //             }
    //         }
    //         DB::commit();
    //         return response()->json(['message' => 'Edit successfully.'], 200);
    //     } catch (\Exception $e) {
    //         DB::rollback();
    //         Log::error($e->getMessage());
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }



    /**
     * @OA\Post(
     *     path="/veri5d/api/edit_user/{id}",
     *     summary="Edit an employee's details",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Employee ID"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="updated name"),
     *             @OA\Property(property="address", type="string", example="updated address"),
     *             @OA\Property(property="country", type="string", example="updated country"),
     *             @OA\Property(property="state", type="string", example="updated state"),
     *             @OA\Property(property="city", type="string", example="updated city"),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee updated successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User updated successfully"),
     *             @OA\Property(property="user", type="object"),
     *         )
     *     )
     * )
     */
    public function edit_user(Request $request, $id)
    {
        // $user = User::find($id);
        $user = User::where('id', $id)->where('active', 1)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'country' => 'sometimes|required|string|max:255',
            'state' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'gender' => 'sometimes|required|string|max:255',
            'pin_code' => 'sometimes|required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }

        $user->update($request->only('name', 'address', 'country', 'state', 'city', 'gender', 'pin_code'));
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->name
        ], 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_employee/{id}",
     *     summary="View an employee's details",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Employee ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee details retrieved successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="user", type="object")
     *         )
     *     )
     * )
     */
    public function view_employee($id)
    {
        // $user = User::find($id);
        $user = User::where('user_type', 'ad')->where('id', $id)->where('active', 1)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        return response()->json(['user' => $user], 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_employees",
     *     summary="View all employees",
     *     tags={"Users"},
     *     @OA\Response(
     *         response=200,
     *         description="Employees retrieved successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="employees", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function view_all_employees()
    {
        // $users = User::where('user_type', 'ad')->where('active', 1)->get();
        $users = User::leftJoin('roll_users', 'users.id', '=', 'roll_users.user_id')
            ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
            ->where('user_type', 'ad')->where('users.active', 1)
            ->where('roll_users.active', 1)
            ->select('users.*', 'rolls.roll_name')
            ->get();
        return response()->json(['employees' => $users], 200);
    }
    public function view_all_customer()
    {
        $users = User::where('user_type', 'us')->where('active', 1)->get();
        return response()->json(['customer' => $users], 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/delete_employee/{id}",
     *     summary="Delete an employee",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Employee ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee deleted successfully.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User deleted successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found.!",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     )
     * )
     */
    public function delete_employee($id)
    {
        // $user = User::find($id);
        $user = User::where('user_type', 'ad')->where('id', $id)->where('active', 1)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $user->update('active', 0);
        return response()->json(['message' => 'User deleted successfully'], 200);
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_role_privelage",
     *     summary="Add a new role with privileges",
     *     tags={"Role"},
     *     security={{"bearerAuth": {}}},
     *     description="Creates a new role and assigns privileges to it.",
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         description="Role data with privileges",
     *         @OA\JsonContent(
     *             required={"roll_name", "share"},
     *             @OA\Property(property="roll_name", type="string", description="Role name", example="Manager"),
     *             @OA\Property(property="share", type="string", description="Share percentage", example="10%"),
     *             @OA\Property(property="parent_id", type="integer", description="Parent ID (nullable)", example=1),
     *             @OA\Property(
     *                 property="privilege_id",
     *                 type="array",
     *                 description="Array of privilege IDs",
     *                 @OA\Items(
     *                     type="integer",
     *                     example=1
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Role added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Role added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
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
     *             @OA\Property(property="error", type="string", example="Internal server error")
     *         )
     *     )
     * )
     */

    public function add_role_privelage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'roll_name' => 'required|string|max:255',
            'share' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:rolls,id',
            'privilege_id' => 'array',
            'privilege_id.*' => 'integer|exists:privileges,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $new_role = roll::create([
                'roll_name' => $request->roll_name,
                'share' => $request->share,
                'parent_id' => $request->parent_id,
                'user_id' => $user->id,
            ]);
            foreach ($request->privilege_id as $privilege_id) {
                roll_privilege::create([
                    'privilege_id' => $privilege_id,
                    'roll_id' => $new_role->id,
                    'user_id' => $user->id,
                ]);
            }
            DB::commit();
            return response()->json(['message' => 'role added successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Put(
     *     path="/veri5d/api/edit_role_privelage/{id}",
     *     summary="Edit an existing role with privileges",
     *     tags={"Role"},
     *     security={{"bearerAuth": {}}},
     *     description="Updates an existing role and its privileges.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Role ID to be edited",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         description="Updated role data with privileges",
     *         @OA\JsonContent(
     *             @OA\Property(property="roll_name", type="string", description="Updated role name", example="Admin"),
     *             @OA\Property(property="share", type="string", description="Updated share percentage", example="15%"),
     *             @OA\Property(property="parent_id", type="integer", description="Updated parent ID", example=2),
     *             @OA\Property(
     *                 property="privilege_id",
     *                 type="array",
     *                 description="Updated array of privilege IDs",
     *                 @OA\Items(
     *                     type="integer",
     *                     example=1
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Role updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Role updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Role not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Role not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to update role.")
     *         )
     *     )
     * )
     */
    public function edit_role_privelage(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'roll_name' => 'required|string|max:255',
            'share' => 'required|string|max:255',
            // 'parent_id' => 'nullable|integer|exists:rolls,id',
            'privilege_id' => 'array',
            'privilege_id.*' => 'integer|exists:privileges,id',
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
            if(in_array($id, [11,9,10,12])){
                if (in_array($id, [11, 12])) {
                    $ids = array_diff([11, 12], [$id]); // Exclude current $id
                    $findshare = Roll::whereIn('id', $ids)->sum('share');
                    $share_c = $findshare + $request->share;
                    if($share_c >= 40){
                        return response()->json([
                            'success' => false,
                            'message' => 'The share is more than 40% share',
                        ], 400);
                    }
                }
                if (in_array($id, [9, 10,])) {
                    $ids = array_diff([9, 10,], [$id]); // Exclude current $id
                    $findshare = Roll::whereIn('id', $ids)->sum('share');
                    $share_c = $findshare + $request->share;
                    if($share_c >= 40){
                        return response()->json([
                            'success' => false,
                            'message' => 'The share is more than 40% share',
                        ], 400);
                    }
                }
            }else{
                $findshare = Roll::whereNotIn('id', [$id, 9, 10, 11, 12])->sum('share');
                $share_c = $findshare + $request->share;
                if($share_c >= 60){
                    return response()->json([
                        'success' => false,
                        'message' => 'The share is more are less than 60% share',
                    ], 400);
                }
            }
            $findshare = Roll::whereNotIn('id', [$id])->sum('share');
            $share_c = $findshare + $request->share;
            if($share_c >= 100){
                return response()->json([
                    'success' => false,
                    'message' => 'The share is more than 100% share',
                ], 400);
            }
            if(in_array($id, [1,2,3])){
                $ids = array_diff([1,2,3], [$id]); // Exclude current $id
                $findshare = Roll::whereIn('id', $ids)->sum('share');
                $share_c = $findshare + $request->share;
                if($share_c >= 35){
                    return response()->json([
                        'success' => false,
                        'message' => 'The share is more than 35% share admin',
                    ], 400);
                }
            }
            $role = Roll::findOrFail($id);
            $role->update([
                'roll_name' => $request->roll_name,
                'share' => $request->share,
                // 'parent_id' => $request->parent_id
            ]);
            Roll_Privilege::where('roll_id', $id)->delete();
            foreach ($request->privilege_id as $privilege_id) {
                Roll_Privilege::create([
                    'privilege_id' => $privilege_id,
                    'roll_id' => $role->id,
                    'user_id' => Auth::id(),
                ]);
            }
            DB::commit();
            return response()->json(['message' => 'Role updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to update role.'], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_roles",
     *     summary="Get all roles with privileges (paginated)",
     *     tags={"Role"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves all roles with associated privileges using pagination.",
     *     
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number for pagination",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Number of items per page",
     *         required=false,
     *         @OA\Schema(type="integer", example=10)
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="List of paginated roles with privileges",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="current_page", type="integer", example=1),
     *             @OA\Property(property="total", type="integer", example=50),
     *             @OA\Property(property="per_page", type="integer", example=10),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="roll_name", type="string", example="Manager"),
     *                     @OA\Property(property="share", type="string", example="10%"),
     *                     @OA\Property(property="privileges", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="id", type="integer", example=1),
     *                             @OA\Property(property="name", type="string", example="Create Order")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve roles.")
     *         )
     *     )
     * )
     */

    public function view_all_roles()
    {
        try {
            $perPage = request()->get('per_page', 100);  // Default items per page
            $roles = Roll::with('privileges:id,privilege_name')->where('active', 1)->paginate($perPage);

            $formattedRoles = $roles->items();  // Use items() to handle paginated data properly

            $data = array_map(function ($role) {
                return [
                    'id' => $role['id'],
                    'roll_name' => $role['roll_name'],
                    'share' => $role['share'],
                    'privileges' => array_map(function ($privilege) {
                        return [
                            'id' => $privilege['id'],
                            'name' => $privilege['privilege_name']
                        ];
                    }, $role['privileges']->toArray())
                ];
            }, $formattedRoles);

            return response()->json([
                'success' => true,
                'current_page' => $roles->currentPage(),
                'total' => $roles->total(),
                'per_page' => $roles->perPage(),
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/role_user",
     *     summary="Assign role to user",
     *     tags={"Role"},
     *     security={{"bearerAuth": {}}},
     *     description="Assigns a role to a user with an under user reference.",
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         description="Assign role to user",
     *         @OA\JsonContent(
     *             required={"role_id", "user_id", "under_user"},
     *             @OA\Property(property="role_id", type="integer", description="ID of the role", example=1),
     *             @OA\Property(property="user_id", type="integer", description="ID of the user", example=10),
     *             @OA\Property(property="under_user", type="integer", description="ID of the under user", example=5)
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Role assigned successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Role added to user successfully.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to assign role.")
     *         )
     *     )
     * )
     */
    public function role_user(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|integer|exists:rolls,id',
            'user_id' => 'required|integer|exists:users,id',
            'under_user' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $find_role = roll_user::where('active', 1)
                ->where('user_id', $request->user_id)
                ->first();
            if ($find_role) {
                $find_role->update([
                    'active' => 0,
                    'add_by' => $user->id,
                ]);
            }
            roll_user::create([
                'id_roll' => $request->role_id,
                'user_id' => $request->user_id,
                'under_user' => $request->under_user,
                'add_by' => $user->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'Role added to user successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_user_role",
     *     summary="View all role users with privileges and tree hierarchy",
     *     tags={"Role"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves the authenticated user's role, privileges, and hierarchical tree of under users.",
     *     
     *     @OA\Response(
     *         response=200,
     *         description="User role, privileges, and tree data",
     *         @OA\JsonContent(
     *             @OA\Property(property="userRole", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="roll_name", type="string", example="Manager"),
     *                 @OA\Property(property="share", type="string", example="10%"),
     *             ),
     *             @OA\Property(property="userPrivilege", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Create Order")
     *                 )
     *             ),
     *             @OA\Property(property="roll_under_user", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="user_id", type="integer", example=10),
     *                     @OA\Property(property="under_user", type="integer", example=5),
     *                     @OA\Property(property="id_roll", type="integer", example=3),
     *                     @OA\Property(property="roll_name", type="string", example="Sub Manager"),
     *                     @OA\Property(property="share", type="string", example="5%"),
     *                     @OA\Property(property="active", type="integer", example=1),
     *                     @OA\Property(property="children", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="id", type="integer", example=2),
     *                             @OA\Property(property="user_id", type="integer", example=12),
     *                             @OA\Property(property="under_user", type="integer", example=10),
     *                             @OA\Property(property="id_roll", type="integer", example=4),
     *                             @OA\Property(property="roll_name", type="string", example="Team Lead"),
     *                             @OA\Property(property="share", type="string", example="2%"),
     *                             @OA\Property(property="active", type="integer", example=1)
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - No authenticated user found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No authenticated user found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve data.")
     *         )
     *     )
     * )
     */
    public function view_user_role()
    {
        $user = Auth::user();
        if ($user) {
            $findUserRoll = roll_user::where('active', 1)
                ->where('user_id', $user->id)
                ->first();
            if ($findUserRoll) {
                $userRoll = roll::find($findUserRoll->id_roll);
                $findUserPrivilege = roll_privilege::where('roll_id', $findUserRoll->id_roll)
                    ->where('active', 1)
                    ->pluck('privilege_id');
                $userPrivilege = privilege::whereIn('id', $findUserPrivilege)->get();
                $roll_under_user = roll::where('parent_id', $findUserRoll->id_roll)->where('active', 1)->get();
                if ($roll_under_user != null) {
                    // $rolls = Roll::where('id' ,$findUserRoll->id_roll)->with('children')->get();
                    // $tree = $this->buildTree($rolls);

                    $rollUsers = roll_user::select(
                        'roll_users.*',
                        'rolls.roll_name',
                        'rolls.share'
                    )
                        ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
                        ->get()
                        ->toArray();
                    $tree = $this->buildTreeRecursive($rollUsers, $user->id);
                } else {
                    $tree = null;
                }
            } else {
                $userRoll = null;
                $tree = null;
                $userPrivilege = collect();
            }
            return response()->json(['userRole' => $userRoll, 'userPrivilege' => $userPrivilege, 'roll_under_user' => $tree], 200);
        } else {
            return response()->json([
                'message' => 'No authenticated user found',
            ], 401);
        }
    }
    private function buildTreeRecursive(array $items, $parentId)
    {
        $branch = [];

        foreach ($items as $item) {
            if ($item['under_user'] == $parentId) {
                $children = $this->buildTreeRecursive($items, $item['user_id']);

                // Add `children` and `roll_name` to the item
                $item['children'] = $children ?: [];

                $branch[] = [
                    'id' => $item['id'],
                    'user_id' => $item['user_id'],
                    'under_user' => $item['under_user'],
                    'id_roll' => $item['id_roll'],
                    'share' => $item['share'],
                    'roll_name' => $item['roll_name'],   // Add roll name here
                    'active' => $item['active'],
                    'children' => $item['children']
                ];
            }
        }

        return $branch;
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/list_privelage",
     *     summary="Get all active privileges",
     *     tags={"Role"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves all active privileges.",
     *     
     *     @OA\Response(
     *         response=200,
     *         description="List of active privileges",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Create Order"),
     *                     @OA\Property(property="description", type="string", example="Can create a new order"),
     *                     @OA\Property(property="active", type="boolean", example=true)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve privileges.")
     *         )
     *     )
     * )
     */
    public function list_privelage()
    {
        try {
            $list_privelage = Privilege::where('active', 1)->get();

            return response()->json([
                'success' => true,
                'data' => $list_privelage
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return response()->json([
                'error' => 'Failed to retrieve privileges.'
            ], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_product",
     *     summary="Add a new product",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Adds a new product with multiple image uploads, attributes, and variants.",
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         description="Product data with optional image uploads and variants",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"sku", "url_slug", "category", "product_name", "price", "profit", "shop_id", "vendore_id"},
     *                 
     *                 @OA\Property(property="shop_id", type="integer", example=1),
     *                 @OA\Property(property="sku", type="string", example="SKU12345"),
     *                 @OA\Property(property="url_slug", type="string", example="product-url"),
     *                 @OA\Property(property="category", type="integer", example=3),
     *                 @OA\Property(property="product_name", type="string", example="iPhone 13"),
     *                 @OA\Property(property="price", type="number", format="float", example=999.99),
     *                 @OA\Property(property="profit", type="number", format="float", example=100),
     *                 
     *                 @OA\Property(property="product_description", type="string"),
     *                 @OA\Property(property="meta_title", type="string"),
     *                 @OA\Property(property="meta_keyword", type="string"),
     *                 @OA\Property(property="meta_description", type="string"),
     *                 @OA\Property(property="compare_at_price", type="number", format="float", nullable=true),
     *                 @OA\Property(property="track_inventory", type="string"),
     *                 @OA\Property(property="quantity", type="integer"),
     *                 @OA\Property(property="sell_when_out_of_stock", type="string"),
     *                 @OA\Property(property="new", type="string"),
     *                 @OA\Property(property="requires_last_mile_delivery", type="string"),
     *                 @OA\Property(property="replaceable", type="string"),
     *                 @OA\Property(property="individual_delivery_fee", type="number", format="float", nullable=true),
     *                 @OA\Property(property="featured", type="string"),
     *                 @OA\Property(property="returnable", type="string"),
     *                 @OA\Property(property="spotlight_deals", type="string"),
     *                 @OA\Property(property="dispatcher_tags", type="string"),
     *                 @OA\Property(property="live", type="string"),
     *                 @OA\Property(property="brand", type="integer"),
     *                 @OA\Property(property="tax_category", type="string"),
     *                 @OA\Property(property="minimum_order_count", type="integer", nullable=true),
     *                 @OA\Property(property="minimum_increment", type="integer", nullable=true),
     *                 @OA\Property(property="return_replace_days", type="integer", nullable=true),
     *                 @OA\Property(property="select_addon_set", type="string"),
     *                 @OA\Property(property="up_sell_products", type="string"),
     *                 @OA\Property(property="related_products", type="string"),
     *                 @OA\Property(property="cross_sell_products", type="string"),
     *                 @OA\Property(property="select_tag_set", type="string"),
     *                 @OA\Property(property="pickup_point", type="string"),
     *                 @OA\Property(property="processor_name", type="string"),
     *                 @OA\Property(property="processor_date", type="string", format="date"),
     *                 @OA\Property(property="processor_address", type="string"),
     *                 @OA\Property(property="parent_id", type="integer", example=10),
     *                 @OA\Property(property="vendore_id", type="integer", example=5),

     *                 @OA\Property(
     *                     property="product_images[]",
     *                     type="array",
     *                     @OA\Items(type="string", format="binary")
     *                 ),

     *                 @OA\Property(
     *                     property="attributes",
     *                     type="object",
     *                     additionalProperties={
     *                         "type"="string"
     *                     }
     *                 ),

     *                 @OA\Property(
     *                     property="variants",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         required={"name", "price", "profit"},
     *                         @OA\Property(property="name", type="string", example="Red XL"),
     *                         @OA\Property(property="price", type="number", format="float", example=150),
     *                         @OA\Property(property="profit", type="number", format="float", example=20),
     *                         @OA\Property(property="attributes", type="object", example={"color": "red", "size": "xl"}),
     *                         @OA\Property(
     *                             property="image[]",
     *                             type="array",
     *                             @OA\Items(type="string", format="binary")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),

     *     @OA\Response(
     *         response=200,
     *         description="Product added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Product added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
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
     *             @OA\Property(property="error", type="string", example="Internal server error")
     *         )
     *     )
     * )
     */
    public function add_product(Request $request)
    {
        // 1. Validation
        $validator = Validator::make($request->all(), [
            'sku'                         => 'required|string|max:255',
            'url_slug'                    => 'required|string|max:255',
            'category'                    => [
                'required',
                'integer',
                Rule::exists('product_categories', 'id')->whereNotNull('parent_id'),
            ],
            'brand'                       => [
                'required',
                'integer',
                Rule::exists('product_attributes', 'id')->where('attributes_type', 2),
            ],
            'product_name'                => 'required|string|max:255',
            'profit'                      => 'required|numeric',
            'product_description'         => 'required|string',
            'price'                       => 'required|numeric',
            'compare_at_price'            => 'nullable|numeric',
            'track_inventory'             => 'required|string|max:255',
            'quantity'                    => 'required|numeric',
            'sell_when_out_of_stock'      => 'required|string|max:255',
            'new'                         => 'required|string|max:255',
            'requires_last_mile_delivery' => 'required|string|max:255',
            'replaceable'                 => 'required|string|max:255',
            'individual_delivery_fee'     => 'nullable|numeric',
            'featured'                    => 'required|string|max:255',
            'returnable'                  => 'required|string|max:255',
            'spotlight_deals'             => 'required|string|max:255',
            'dispatcher_tags'             => 'required|string|max:255',
            'live'                        => 'required|string|max:255',
            'tax_category'                => 'required|string|max:255',
            'minimum_order_count'         => 'nullable|numeric',
            'minimum_increment'           => 'nullable|numeric',
            'return_replace_days'         => 'nullable|numeric',
            'select_addon_set'            => 'nullable|string|max:255',
            'up_sell_products'            => 'nullable|string|max:255',
            'related_products'            => 'nullable|string|max:255',
            'cross_sell_products'         => 'nullable|string|max:255',
            'select_tag_set'              => 'nullable|string|max:255',
            'pickup_point'                => 'nullable|string|max:255',
            'processor_name'              => 'nullable|string|max:255',
            'processor_date'              => 'nullable|date',
            'processor_address'           => 'nullable|string|max:255',
            'shop_id'                     => 'required|integer|exists:vendors,id',
            'product_images'              => 'array',
            'product_images.*'            => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'vendore_id'                  => 'required|integer|exists:users,id',
            'attributes'                  => 'nullable|array',
            'variants'                    => 'required|array',
            'variants.*.name'             => 'required|string|max:255',
            'variants.*.price'            => 'required|numeric',
            'variants.*.profit'           => 'required|numeric',
            'variants.*.images'           => 'required|array',                            // ← plural key
            'variants.*.images.*'         => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'variants.*.attributes'       => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400);
        }

        // 2. Prevent adding under a top‑level category
        $find_category = product_categorie::where('id', $request->category)
            ->whereNull('parent_id')
            ->first();
        if ($find_category) {
            return response()->json([
                'message' => 'Products must be added under a subcategory.'
            ], 200);
        }

        $user = Auth::user();
        DB::beginTransaction();

        try {
            // 3. Upload main product images
            $imagePaths = [];
            if ($request->hasFile('product_images')) {
                foreach ($request->file('product_images') as $file) {
                    if (!$file->isValid()) {
                        throw new \Exception('Main product image upload failed.');
                    }
                    $ext      = $file->getClientOriginalExtension();
                    $filename = time() . Str::random(8) . '.' . $ext;
                    $file->move(('image/product/'), $filename);
                    $imagePaths[] = "image/product/{$filename}";
                }
            }

            // 4. Create the parent product
            $parentData = [
                'name'        => $request->product_name,
                'category_id' => $request->category,
                'brand_id'    => $request->brand,
                'user_id'     => $request->vendore_id,
                'total_price' => $request->price,
                'profit'      => $request->profit,
                'add_by'      => $user->id,
                'shop_id'     => $request->shop_id,
                'data'        => json_encode(array_merge(
                    $request->except('_token', 'product_images', 'variants', 'product_name', 'price', 'profit'),
                    ['product_images' => $imagePaths]
                )),
            ];
            $parentProduct = product::create($parentData);

            // 5. Save main product images to product_image table
            foreach ($imagePaths as $path) {
                product_image::create([
                    'productImages' => $path,
                    'user_id'       => $user->id,
                    'product_id'    => $parentProduct->id,
                ]);
            }

            // 6. Handle variants
            foreach ($request->input('variants') as $index => $variant) {
                $variantProduct = product::create([
                    'name'        => $variant['name'],
                    'parent_id'   => $parentProduct->id,
                    'category_id' => $parentProduct->category_id,
                    'brand_id'    => $parentProduct->brand_id,
                    'user_id'     => $parentProduct->user_id,
                    'total_price' => $variant['price'],
                    'profit'      => $variant['profit'],
                    'add_by'      => $user->id,
                    'shop_id'     => $request->shop_id,
                    'data'        => json_encode([
                        'attributes'    => $variant['attributes'] ?? [],
                    ]),
                ]);

                // 6a. Upload each variant's images
                if ($request->hasFile("variants.{$index}.images")) {
                    foreach ($request->file("variants.{$index}.images") as $file) {
                        if (!$file->isValid()) {
                            throw new \Exception("Variant #{$index} image upload failed.");
                        }
                        $ext      = $file->getClientOriginalExtension();
                        $filename = time() . Str::random(5) . '.' . $ext;
                        $file->move(('image/product/'), $filename);
                        // $imagePath = $this->uploadFile($request, $file, 'image/product/');
                        product_image::create([
                            'productImages' => "image/product/{$filename}",
                            'user_id'       => $user->id,
                            'product_id'    => $variantProduct->id,
                        ]);
                    }
                }
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Product added successfully.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('add_product error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/edit_product/{id}",
     *     summary="Edit an existing product with optional image deletion",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Updates an existing product with optional image uploads and image deletion by ID.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Product ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         description="Product details to update",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={
     *                     "sku", "url_slug", "category", "product_name", "profit", 
     *                     "product_description", "meta_title", "meta_keyword", 
     *                     "meta_description", "price", "quantity", "shop_id", "vendore_id"
     *                 },
     *                 @OA\Property(property="sku", type="string", example="PROD123"),
     *                 @OA\Property(property="url_slug", type="string", example="product-slug"),
     *                 @OA\Property(property="category", type="string", example="Electronics"),
     *                 @OA\Property(property="product_name", type="string", example="Wireless Headphone"),
     *                 @OA\Property(property="profit", type="number", example=500.00),
     *                 @OA\Property(property="product_description", type="string", example="High-quality wireless headphone."),
     *                 @OA\Property(property="meta_title", type="string", example="Best Wireless Headphone"),
     *                 @OA\Property(property="meta_keyword", type="string", example="headphone, wireless, music"),
     *                 @OA\Property(property="meta_description", type="string", example="Experience high-fidelity audio with this wireless headphone."),
     *                 @OA\Property(property="price", type="number", example=2999.99),
     *                 @OA\Property(property="compare_at_price", type="number", example=3499.99),
     *                 @OA\Property(property="quantity", type="integer", example=100),
     *                 @OA\Property(property="track_inventory", type="string", example="yes"),
     *                 @OA\Property(property="sell_when_out_of_stock", type="string", example="no"),
     *                 @OA\Property(property="new", type="string", example="yes"),
     *                 @OA\Property(property="requires_last_mile_delivery", type="string", example="yes"),
     *                 @OA\Property(property="replaceable", type="string", example="yes"),
     *                 @OA\Property(property="individual_delivery_fee", type="number", example=50.00),
     *                 @OA\Property(property="featured", type="string", example="yes"),
     *                 @OA\Property(property="returnable", type="string", example="yes"),
     *                 @OA\Property(property="spotlight_deals", type="string", example="no"),
     *                 @OA\Property(property="dispatcher_tags", type="string", example="express"),
     *                 @OA\Property(property="live", type="string", example="yes"),
     *                 @OA\Property(property="brand", type="string", example="Sony"),
     *                 @OA\Property(property="tax_category", type="string", example="GST"),
     *                 @OA\Property(property="minimum_order_count", type="integer", example=1),
     *                 @OA\Property(property="minimum_increment", type="integer", example=1),
     *                 @OA\Property(property="return_replace_days", type="integer", example=7),
     *                 @OA\Property(property="select_addon_set", type="string", example="extra-battery"),
     *                 @OA\Property(property="up_sell_products", type="string", example="Product A, Product B"),
     *                 @OA\Property(property="related_products", type="string", example="Product C, Product D"),
     *                 @OA\Property(property="cross_sell_products", type="string", example="Product E, Product F"),
     *                 @OA\Property(property="select_tag_set", type="string", example="tags-set-1"),
     *                 @OA\Property(property="pickup_point", type="string", example="Store Location 1"),
     *                 @OA\Property(property="processor_name", type="string", example="Intel"),
     *                 @OA\Property(property="processor_date", type="string", format="date", example="2025-03-28"),
     *                 @OA\Property(property="processor_address", type="string", example="123 Main St, NY"),
     *                 @OA\Property(property="shop_id", type="string", example="SHOP123"),
     *                 @OA\Property(property="vendore_id", type="integer", example=1),
     * 
     *                 @OA\Property(
     *                     property="product_images",
     *                     type="array",
     *                     @OA\Items(
     *                         type="string",
     *                         format="binary"
     *                     )
     *                 ),
     *                 
     *                 @OA\Property(
     *                     property="product_images_delete_id",
     *                     type="string",
     *                     example="[1,2,3]",
     *                     description="Array of image IDs to delete"
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Product updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Product updated successfully.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object", example={"sku": {"The sku field is required."}})
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Product not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Product not found.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Server error.")
     *         )
     *     )
     * )
     */


    public function edit_product(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'sku' => 'required|string|max:255',
            'url_slug' => 'required|string|max:255',
            'category' => [
                'required',
                'integer',
                Rule::exists('product_categories', 'id')->whereNotNull('parent_id'),
            ],
            'brand' => [
                'required',
                'integer',
                Rule::exists('product_attributes', 'id')->where('attributes_type', 2),
            ],
            'product_name' => 'required|string|max:255',
            'profit' => 'required|numeric',
            'product_description' => 'required|string|max:1000',
            'meta_title' => 'required|string|max:255',
            'meta_keyword' => 'required|string|max:255',
            'meta_description' => 'required|string|max:1000',
            'price' => 'required|numeric',
            'compare_at_price' => 'nullable|numeric',
            'track_inventory' => 'required|string|max:255',
            'quantity' => 'required|numeric',
            'sell_when_out_of_stock' => 'required|string|max:255',
            'new' => 'required|string|max:255',
            'requires_last_mile_delivery' => 'required|string|max:255',
            'replaceable' => 'required|string|max:255',
            'individual_delivery_fee' => 'nullable|numeric',
            'featured' => 'required|string|max:255',
            'returnable' => 'required|string|max:255',
            'spotlight_deals' => 'required|string|max:255',
            'dispatcher_tags' => 'required|string|max:255',
            'live' => 'required|string|max:255',
            'tax_category' => 'required|string|max:255',
            'minimum_order_count' => 'nullable|numeric',
            'minimum_increment' => 'nullable|numeric',
            'return_replace_days' => 'nullable|numeric',
            'select_addon_set' => 'nullable|string|max:255',
            'up_sell_products' => 'nullable|string|max:255',
            'related_products' => 'nullable|string|max:255',
            'cross_sell_products' => 'nullable|string|max:255',
            'select_tag_set' => 'nullable|string|max:255',
            'pickup_point' => 'nullable|string|max:255',
            'processor_name' => 'nullable|string|max:255',
            'processor_date' => 'nullable|date',
            'processor_address' => 'nullable|string|max:255',
            'shop_id' => 'required|integer|exists:vendors,id',
            // 'product_images' => 'nullable|array',
            // 'product_images.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'parent_id' => 'nullable|integer|exists:products,id',
            'vendore_id' => 'required|integer|exists:users,id',
            'product_images_delete_id' => 'nullable|array',
            'product_images_delete_id.*' => 'integer|exists:product_images,id',

        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = Auth::user();
        DB::beginTransaction();

        try {
            $product = Product::findOrFail($id);
            if ($request->has('product_images_delete_id')) {
                $deleteIds = $request->product_images_delete_id;
                $imagesToDelete = product_image::whereIn('id', $deleteIds)->get();
                foreach ($imagesToDelete as $image) {
                    if (file_exists(public_path($image->productImages))) {
                        unlink(public_path($image->productImages));
                    }
                    $image->delete();
                }
            }
            $imagePaths = json_decode($product->product_images, true) ?? [];

            if ($request->hasFile('product_images')) {
                foreach ($request->file('product_images') as $file) {
                    if (!$file->isValid()) {
                        throw new \Exception('File upload failed.');
                    }
                    $product_image = new product_image();
                    $files = $file;
                    $extentions = $files->getClientOriginalExtension();
                    $filenames = time() . Str::random(10) . '.' . $extentions;
                    $files->move('image/product/', $filenames);
                    $product_image->productImages = "image/product/" . $filenames;
                    $product_image->user_id = $user->id;
                    $product_image->product_id = $id;
                    if (!$product_image->save()) {
                        throw new \Exception('Failed to save.');
                    }
                }
            }
            $data = $request->except([
                '_token',
                'product_name',
                'price',
                'profit',
                'parent_id',
                'vendore_id',
                'product_images',
                'product_images_delete_id'
            ]);
            if (!empty($imagePaths)) {
                $data['product_images'] = json_encode($imagePaths);
            }
            $product->update([
                'name'          => $request->product_name,
                'parent_id'     => $request->parent_id,
                'user_id'       => $request->vendore_id,
                'total_price'   => $request->price,
                'category_id'   => $request->category,
                'brand_id'      => $request->brand,
                'profit'        => $request->profit,
                'add_by'        => $user->id,
                'data'          => json_encode($data)
            ]);
            DB::commit();
            return response()->json(['message' => 'Product updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_product/{id}",
     *     summary="View a specific product",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves the details of a product by its ID, including metadata and images.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Product ID",
     *         @OA\Schema(type="integer")
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Product details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="iPhone 13"),
     *                 @OA\Property(property="sku", type="string", example="SKU12345"),
     *                 @OA\Property(property="url_slug", type="string", example="iphone-13"),
     *                 @OA\Property(property="category", type="string", example="Electronics"),
     *                 @OA\Property(property="price", type="number", example=999.99),
     *                 @OA\Property(property="profit", type="number", example=15.00),
     *                 @OA\Property(property="shop_id", type="integer", example=1),
     *                 @OA\Property(property="vendor_id", type="integer", example=5),
     *                 @OA\Property(property="product_images", type="array",
     *                     @OA\Items(type="string", example="image/product/1234567890.jpg")
     *                 ),
     *                 @OA\Property(property="data", type="object", example={"meta_title": "iPhone 13", "meta_description": "Latest model"}),
     *                 @OA\Property(property="created_at", type="string", example="2025-03-28 12:00:00"),
     *                 @OA\Property(property="updated_at", type="string", example="2025-03-28 12:00:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Product not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Product not found")
     *         )
     *     )
     * )
     */
    public function view_product($id)
    {
        try {
            $products = Product::with(['images', 'catname', 'brand'])
                ->where('active', 1)
                ->where('id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            $data = $products->map(function ($product) {
                return [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'category'      => $product->category_id,
                    'category_name'      => $product->catname ? $product->catname->name : null,
                    'brand'      => $product->brand_id,
                    'brand_name'      => $product->brand ? $product->brand->name : null,
                    'price'         => $product->total_price,
                    // 'profit'        => $product->profit,
                    'shop_id'       => $product->shop_id,
                    'vendor_id'     => $product->user_id,
                    'product_images' => $product->images->pluck('productImages')->toArray(),
                    'data'          => json_decode($product->data, true),
                    'created_at'    => $product->created_at,
                    'updated_at'    => $product->updated_at
                ];
            });

            $products_v = Product::with(['images', 'catname', 'brand'])
                ->where('active', 1)
                ->where('parent_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            $data_v = $products_v->map(function ($product) {
                return [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'category'      => $product->category_id,
                    'category_name'      => $product->catname ? $product->catname->name : null,
                    'brand'      => $product->brand_id,
                    'brand_name'      => $product->brand ? $product->brand->name : null,
                    'price'         => $product->total_price,
                    'shop_id'       => $product->shop_id,
                    'vendor_id'     => $product->user_id,
                    'product_images' => $product->images->pluck('productImages')->toArray(),
                    'data'          => json_decode($product->data, true),
                    'created_at'    => $product->created_at,
                    'updated_at'    => $product->updated_at
                ];
            });


            $variantIds = $products_v->pluck('id')->toArray();
            $allProductIds = array_merge([$id], $variantIds);

            $review = product_review::where('active', 1)
                ->whereIn('product_id', $allProductIds)
                ->get();



            return response()->json([
                'products'      => $data,
                'variants'      => $data_v,
                'review' => $review
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'error' => 'Failed to retrieve product.'
            ], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_products",
     *     summary="View all products",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves the list of all products with images, metadata, and pagination.",
     * 
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Page number for pagination",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         required=false,
     *         description="Number of products per page",
     *         @OA\Schema(type="integer", example=10)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Product list retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="current_page", type="integer", example=1),
     *             @OA\Property(property="per_page", type="integer", example=10),
     *             @OA\Property(property="total", type="integer", example=100),
     *             @OA\Property(property="products", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="iPhone 13"),
     *                     @OA\Property(property="sku", type="string", example="SKU12345"),
     *                     @OA\Property(property="price", type="number", example=999.99),
     *                     @OA\Property(property="profit", type="number", example=15.00),
     *                     @OA\Property(property="shop_id", type="integer", example=1),
     *                     @OA\Property(property="vendor_id", type="integer", example=5),
     *                     @OA\Property(property="product_images", type="array",
     *                         @OA\Items(type="string", example="image/product/1234567890.jpg")
     *                     ),
     *                     @OA\Property(property="data", type="object", example={"meta_title": "iPhone 13", "meta_description": "Latest model"}),
     *                     @OA\Property(property="created_at", type="string", example="2025-03-28 12:00:00"),
     *                     @OA\Property(property="updated_at", type="string", example="2025-03-28 12:00:00")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve products.")
     *         )
     *     )
     * )
     */

    public function view_all_products(Request $request)
    {
        try {
            // Pagination parameters
            $perPage = $request->query('per_page', 10);
            $page = $request->query('page', 1);


            // $query = Product::with(['images', 'catname', 'brand'])
            // ->where('active', 1)
            // ->orderBy('created_at', 'desc');


            $query = Product::with(['images', 'catname', 'brand'])
                ->where('active', 1)->whereNull('parent_id')
                ->orderBy('created_at', 'desc');

            if ($request->has('search')) {
                $keyword = $request->search;

                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', "%$keyword%")
                        ->orWhereHas('brand', function ($b) use ($keyword) {
                            $b->where('name', 'like', "%$keyword%");
                        })
                        ->orWhereHas('catname', function ($c) use ($keyword) {
                            $c->where('name', 'like', "%$keyword%");
                        });
                });
            }


            $products = $query->paginate($perPage, ['*'], 'page', $page);






            // // Fetch products with pagination
            // $products = Product::with(['images', 'catname', 'brand'])
            //     ->where('active', 1)
            //     ->orderBy('created_at', 'desc')
            //     ->paginate($perPage, ['*'], 'page', $page);

            $data = $products->map(function ($product) {
                return [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'category'      => $product->category_id,
                    'category_name'      => $product->catname ? $product->catname->name : null,
                    'brand'      => $product->brand_id,
                    'brand_name'      => $product->brand ? $product->brand->name : null,
                    'price'         => $product->total_price,
                    // 'profit'        => $product->profit,
                    'shop_id'       => $product->shop_id,
                    'vendor_id'     => $product->user_id,
                    'product_images' => $product->images->pluck('productImages')->toArray(),
                    'data'          => json_decode($product->data, true),
                    'created_at'    => $product->created_at,
                    'updated_at'    => $product->updated_at
                ];
            });

            return response()->json([
                'success'       => true,
                'current_page'  => $products->currentPage(),
                'per_page'      => $products->perPage(),
                'total'         => $products->total(),
                'products'      => $data
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'error' => 'Failed to retrieve products.'
            ], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/list_products_category/{id}",
     *     summary="Get products by category",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Fetches a paginated list of products based on the given category ID.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Category ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         required=false,
     *         description="Number of products per page (default: 10)",
     *         @OA\Schema(type="integer", example=10)
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Page number (default: 1)",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="List of products in the specified category",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="current_page", type="integer", example=1),
     *             @OA\Property(property="per_page", type="integer", example=10),
     *             @OA\Property(property="total", type="integer", example=50),
     *             @OA\Property(property="products", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="iPhone 13"),
     *                     @OA\Property(property="sku", type="string", example="SKU12345"),
     *                     @OA\Property(property="url_slug", type="string", example="iphone-13"),
     *                     @OA\Property(property="category", type="integer", example=2),
     *                     @OA\Property(property="price", type="number", format="float", example=999.99),
     *                     @OA\Property(property="profit", type="number", format="float", example=150),
     *                     @OA\Property(property="shop_id", type="integer", example=1),
     *                     @OA\Property(property="vendor_id", type="integer", example=5),
     *                     @OA\Property(property="product_images", type="array",
     *                         @OA\Items(type="string", example="image/product/iphone13.jpg")
     *                     ),
     *                     @OA\Property(property="data", type="object"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-01T12:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-01T12:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve products.")
     *         )
     *     )
     * )
     */

    public function list_products_category(Request $request, $id)
    {
        try {
            $perPage = $request->query('per_page', 10);
            $page = $request->query('page', 1);
            $products = Product::with(['images', 'catname', 'brand'])
                ->where('active', 1)
                ->whereNull('parent_id')
                ->where('category_id', $id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
            $data = $products->map(function ($product) {
                return [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'category'      => $product->category_id,
                    'category_name'      => $product->catname ? $product->catname->name : null,
                    'brand'      => $product->brand_id,
                    'brand_name'      => $product->brand ? $product->brand->name : null,
                    'price'         => $product->total_price,
                    'product_images' => $product->images->pluck('productImages')->toArray(),
                    'meta_description'  => json_decode($product->data, true)['meta_description'] ?? null,
                    'attributes'  => json_decode($product->data, true)['attributes'] ?? null,
                    'created_at'    => $product->created_at,
                    'updated_at'    => $product->updated_at
                ];
            });
            return response()->json([
                'success'       => true,
                'current_page'  => $products->currentPage(),
                'per_page'      => $products->perPage(),
                'total'         => $products->total(),
                'products'      => $data
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'error' => 'Failed to retrieve products.'
            ], 500);
        }
    }
    /**
     * @OA\Delete(
     *     path="/veri5d/api/delete_products/{id}",
     *     summary="Soft delete a product",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Soft deletes a product by marking it as inactive (`active = 0`).",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Product ID to be deleted",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Product deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Product deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Product not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Product not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve product.")
     *         )
     *     )
     * )
     */
    public function delete_products($id)
    {
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $product = Product::with('images')
                ->where('id', $id)
                ->where('active', 1)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'error' => 'Product not found'
                ], 404);
            }

            $product->update([
                'active'  => 0,
                'add_by'  => $user->id,
            ]);

            DB::commit();

            return response()->json(['message' => 'Product deleted successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());

            return response()->json([
                'error' => 'Failed to retrieve product.'
            ], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/list_vendor_products/{id}",
     *     summary="Get products by category",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Fetches a paginated list of products based on the given category ID.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Category ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         required=false,
     *         description="Number of products per page (default: 10)",
     *         @OA\Schema(type="integer", example=10)
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Page number (default: 1)",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="List of products in the specified category",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="current_page", type="integer", example=1),
     *             @OA\Property(property="per_page", type="integer", example=10),
     *             @OA\Property(property="total", type="integer", example=50),
     *             @OA\Property(property="products", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="iPhone 13"),
     *                     @OA\Property(property="sku", type="string", example="SKU12345"),
     *                     @OA\Property(property="url_slug", type="string", example="iphone-13"),
     *                     @OA\Property(property="category", type="integer", example=2),
     *                     @OA\Property(property="price", type="number", format="float", example=999.99),
     *                     @OA\Property(property="profit", type="number", format="float", example=150),
     *                     @OA\Property(property="shop_id", type="integer", example=1),
     *                     @OA\Property(property="vendor_id", type="integer", example=5),
     *                     @OA\Property(property="product_images", type="array",
     *                         @OA\Items(type="string", example="image/product/iphone13.jpg")
     *                     ),
     *                     @OA\Property(property="data", type="object"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-01T12:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-01T12:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve products.")
     *         )
     *     )
     * )
     */

    public function list_vendor_products(Request $request, $id)
    {
        try {
            $perPage = $request->query('per_page', 10);
            $page = $request->query('page', 1);
            $products = Product::with([
                'images',
                'catname',
                'brand',


                'children' => function ($query) {
                    $query->with([
                        'images',
                        'catname',
                        'brand'
                        // 'finduser' => function ($q) {
                        //     $q->select('id', 'name');
                        // },
                        // 'roleuser' => function ($q) {
                        //     $q->select('roll_name'); // Add more fields if needed
                        // }
                    ]);
                },




            ])
                ->where('active', 1)
                ->where('user_id', $id)
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
            $data = $products->map(function ($product) {
                return [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'category'      => $product->category_id,
                    'category_name'      => $product->catname ? $product->catname->name : null,
                    'brand'      => $product->brand_id,
                    'brand_name'      => $product->brand ? $product->brand->name : null,
                    'price'         => $product->total_price,
                    'profit'        => $product->profit,
                    'shop_id'       => $product->shop_id,
                    'vendor_id'     => $product->user_id,
                    'product_images' => $product->images->pluck('productImages')->toArray(),
                    'data'          => json_decode($product->data, true),
                    'created_at'    => $product->created_at,
                    'updated_at'    => $product->updated_at


                ];
            });
            return response()->json([
                'success'       => true,
                'current_page'  => $products->currentPage(),
                'per_page'      => $products->perPage(),
                'total'         => $products->total(),
                'products'      => $data
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'error' => 'Failed to retrieve products.'
            ], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/list_vendor_products_min/{id}",
     *     summary="Get products by category",
     *     tags={"Product"},
     *     security={{"bearerAuth": {}}},
     *     description="Fetches a paginated list of products based on the given category ID.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Category ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         required=false,
     *         description="Number of products per page (default: 10)",
     *         @OA\Schema(type="integer", example=10)
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Page number (default: 1)",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="List of products in the specified category",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="current_page", type="integer", example=1),
     *             @OA\Property(property="per_page", type="integer", example=10),
     *             @OA\Property(property="total", type="integer", example=50),
     *             @OA\Property(property="products", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="iPhone 13"),
     *                     @OA\Property(property="sku", type="string", example="SKU12345"),
     *                     @OA\Property(property="url_slug", type="string", example="iphone-13"),
     *                     @OA\Property(property="category", type="integer", example=2),
     *                     @OA\Property(property="price", type="number", format="float", example=999.99),
     *                     @OA\Property(property="profit", type="number", format="float", example=150),
     *                     @OA\Property(property="shop_id", type="integer", example=1),
     *                     @OA\Property(property="vendor_id", type="integer", example=5),
     *                     @OA\Property(property="product_images", type="array",
     *                         @OA\Items(type="string", example="image/product/iphone13.jpg")
     *                     ),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-01T12:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-01T12:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve products.")
     *         )
     *     )
     * )
     */

    public function list_vendor_products_min(Request $request, $id)
    {
        try {
            $perPage = $request->query('per_page', 10);
            $page = $request->query('page', 1);
            $products = Product::with(['images', 'catname'])
                ->where('active', 1)
                ->where('user_id', $id)
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
            $data = $products->map(function ($product) {
                return [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'category'      => $product->category_id,
                    'category_name'      => $product->catname ? $product->catname->name : null,
                    'price'         => $product->total_price,
                    'product_images' => $product->images->pluck('productImages')->toArray(),
                    'created_at'    => $product->created_at,
                    'updated_at'    => $product->updated_at
                ];
            });
            return response()->json([
                'success'       => true,
                'current_page'  => $products->currentPage(),
                'per_page'      => $products->perPage(),
                'total'         => $products->total(),
                'products'      => $data
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'error' => 'Failed to retrieve products.'
            ], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/list_dashboard_products",
     *     summary="Get categorized dashboard products",
     *     tags={"Product"},
     *     description="Fetches featured, new, and on-sale products for the dashboard view. Products include images, brand, and category data.",
     *     security={{"bearerAuth": {}}},
     *     
     *     @OA\Response(
     *         response=200,
     *         description="List of categorized products",
     *         @OA\JsonContent(
     *             @OA\Property(property="featured_products", type="array",
     *                 @OA\Items(ref="#/components/schemas/ProductDashboardResponse")
     *             ),
     *             @OA\Property(property="on_sale_products", type="array",
     *                 @OA\Items(ref="#/components/schemas/ProductDashboardResponse")
     *             ),
     *             @OA\Property(property="new_products", type="array",
     *                 @OA\Items(ref="#/components/schemas/ProductDashboardResponse")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve products",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to retrieve products.")
     *         )
     *     )
     * )
     */

    /**
     * @OA\Schema(
     *     schema="ProductDashboardResponse",
     *     type="object",
     *     @OA\Property(property="id", type="integer", example=1),
     *     @OA\Property(property="name", type="string", example="iPhone 13"),
     *     @OA\Property(property="category", type="integer", example=2),
     *     @OA\Property(property="category_name", type="string", example="Smartphones"),
     *     @OA\Property(property="brand", type="integer", example=1),
     *     @OA\Property(property="brand_name", type="string", example="Apple"),
     *     @OA\Property(property="price", type="number", format="float", example=999.99),
     *     @OA\Property(property="product_images", type="array",
     *         @OA\Items(type="string", example="https://cdn.example.com/images/product1.jpg")
     *     ),
     *     @OA\Property(property="meta_description", type="string", example="Latest iPhone model with great features."),
     *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-01T12:34:56Z"),
     *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-03-10T15:00:00Z")
     * )
     */


    public function list_dashboard_products()
    {
        try {
            $allProducts = Product::with(['images', 'catname', 'brand'])
                ->where('active', 1)
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc')
                ->get();

            $featuredProducts = $allProducts->filter(function ($product) {
                $metaData = json_decode($product->data, true);
                return !isset($metaData['live']) || $metaData['live'] == "Yes" && $metaData['new'] == "No";
            })->take(20)->values();

            $newProducts = $allProducts->filter(function ($product) {
                $metaData = json_decode($product->data, true);
                return !isset($metaData['live']) || $metaData['live'] == "Yes" && $metaData['new'] == "Yes";
            })->take(20)->values();

            $excludedIds = $featuredProducts->pluck('id')->merge($newProducts->pluck('id'))->unique()->toArray();

            $onSaleProducts = $allProducts->reject(function ($product) use ($excludedIds) {
                return in_array($product->id, $excludedIds);
            })->filter(function ($product) {
                $metaData = json_decode($product->data, true);
                return !isset($metaData['live']) || $metaData['live'] != "Yes";
            })->take(20)->values();

            $formatProduct = function ($product) {
                return [
                    'id'                => $product->id,
                    'name'              => $product->name,
                    'category'          => $product->category_id,
                    'category_name'     => $product->catname ? $product->catname->name : null,
                    'brand'             => $product->brand_id,
                    'brand_name'        => $product->brand ? $product->brand->name : null,
                    'price'             => $product->total_price,
                    'product_images'    => $product->images->pluck('productImages')->toArray(),
                    'meta_description'  => json_decode($product->data, true)['meta_description'] ?? null,
                    'created_at'        => $product->created_at,
                    'updated_at'        => $product->updated_at
                ];
            };

            return response()->json([
                'featured_products' => $featuredProducts->map($formatProduct),
                'on_sale_products'  => $onSaleProducts->map($formatProduct),
                'new_products'      => $newProducts->map($formatProduct),
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'error' => 'Failed to retrieve products.'
            ], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_brand",
     *     summary="Add a new brand",
     *     tags={"Brand"},
     *     security={{"bearerAuth": {}}},
     *     description="Adds a new brand with optional images",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"select_category", "brand_title"},
     *                 @OA\Property(property="select_category", type="string", example="Electronics"),
     *                 @OA\Property(property="brand_title", type="string", example="Apple"),
     *                 @OA\Property(property="brand_logo", type="string", format="binary", description="Logo image"),
     *                 @OA\Property(property="brand_banner", type="string", format="binary", description="Banner image")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Brand added successfully"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function add_brand(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'select_category' => 'required|string|max:255',
            'brand_title' => 'required|string|max:255',
            'brand_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'brand_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }

        $user = Auth::user();
        DB::beginTransaction();

        try {
            $slug = $user->id . time() . Str::uuid() . Str::random(5);
            $data = $request->except('_token', 'brand_title');

            if ($request->hasFile("brand_logo")) {
                $data['brand_logo'] = $this->uploadFile($request, 'brand_logo', 'images/brand/');
            }
            if ($request->hasFile("brand_banner")) {
                $data['brand_banner'] = $this->uploadFile($request, 'brand_banner', 'images/brand/');
            }

            // if ($request->hasFile("brand_logo")) {
            //     $brand_logo_file = $this->uploadFile($request, 'brand_logo', 'image/brand/');
            //     $data['brand_logo'] = $brand_logo_file;
            // }
            // if ($request->hasFile("brand_banner")) {
            //     $brand_banner_file = $this->uploadFile($request, 'brand_banner', 'image/brand/');
            //     $data['brand_banner'] = $brand_banner_file;
            // }

            product_attribute::create([
                'name' => $request->brand_title,
                'user_id' => $user->id,
                'attributes_type' => 2,
                'slug' => $slug,
                'attributes' => json_encode($data)
            ]);

            DB::commit();
            return response()->json(['message' => 'Brand added successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Put(
     *     path="/veri5d/api/edit_brand/{id}",
     *     summary="Edit a brand",
     *     tags={"Brand"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Brand ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="select_category", type="string", example="Electronics"),
     *                 @OA\Property(property="brand_title", type="string", example="Apple"),
     *                 @OA\Property(property="brand_logo", type="string", format="binary", description="Optional logo image"),
     *                 @OA\Property(property="brand_banner", type="string", format="binary", description="Optional banner image")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Brand updated successfully"),
     *     @OA\Response(response=404, description="Brand not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function edit_brand(Request $request, $id)
    {
        $brand = product_attribute::where('id', $id)->where('attributes_type', 2)->where('active', 1)->first();
        if (!$brand) {
            return response()->json(['error' => 'Brand not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'select_category' => 'required|string|max:255',
            'brand_title' => 'required|string|max:255',
            'brand_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'brand_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 400);
        }

        DB::beginTransaction();

        try {
            $data = json_decode($brand->attributes, true);

            if ($request->hasFile('brand_logo')) {
                $data['brand_logo'] = $this->uploadFile($request, 'brand_logo', 'images/brand/');
            }
            if ($request->hasFile('brand_banner')) {
                $data['brand_banner'] = $this->uploadFile($request, 'brand_banner', 'images/brand/');
            }

            $data['select_category'] = $request->select_category;

            $brand->update([
                'name' => $request->brand_title,
                'attributes' => json_encode($data)
            ]);

            DB::commit();
            return response()->json(['message' => 'Brand updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Delete(
     *     path="/veri5d/api/delete_brand/{id}",
     *     summary="Delete a brand",
     *     tags={"Brand"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Brand deleted successfully"),
     *     @OA\Response(response=404, description="Brand not found")
     * )
     */
    public function delete_brand($id)
    {
        $brand = product_attribute::where('id', $id)->where('attributes_type', 2)->where('active', 1)->first();
        if (!$brand) {
            return response()->json(['error' => 'Brand not found'], 404);
        }

        $brand->update(['active' => 0]);
        return response()->json(['message' => 'Brand deleted successfully.'], 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_brand",
     *     summary="View all active brands",
     *     tags={"Brand"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of active brands",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Samsung"),
     *                 @OA\Property(property="user_id", type="integer", example=5),
     *                 @OA\Property(property="attributes_type", type="integer", example=2),
     *                 @OA\Property(property="attributes", type="object",
     *                     @OA\Property(property="brand_logo", type="string", example="images/brand/logo.jpg"),
     *                     @OA\Property(property="brand_banner", type="string", example="images/brand/banner.jpg")
     *                 ),
     *                 @OA\Property(property="active", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No brands found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No brands found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Internal server error")
     *         )
     *     )
     * )
     */
    public function view_all_brand()
    {
        try {
            $brands = product_attribute::where('active', 1)
                ->where('attributes_type', 2)
                ->get();

            if ($brands->isEmpty()) {
                return response()->json(['message' => 'No brands found'], 404);
            }

            $brands = $brands->map(function ($brand) {
                $brand->attributes = json_decode($brand->attributes, true);
                return $brand;
            });

            return response()->json($brands, 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_brand/{id}",
     *     summary="View a brand by ID",
     *     tags={"Brand"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the brand to view",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Brand details",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="Samsung"),
     *             @OA\Property(property="user_id", type="integer", example=5),
     *             @OA\Property(property="attributes_type", type="integer", example=2),
     *             @OA\Property(property="attributes", type="object",
     *                 @OA\Property(property="brand_logo", type="string", example="images/brand/logo.jpg"),
     *                 @OA\Property(property="brand_banner", type="string", example="images/brand/banner.jpg")
     *             ),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Brand not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Brand not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Internal server error")
     *         )
     *     )
     * )
     */
    public function view_brand($id)
    {
        try {
            $brand = product_attribute::where('id', $id)
                ->where('active', 1)
                ->where('attributes_type', 2)
                ->first();

            if (!$brand) {
                return response()->json(['message' => 'Brand not found'], 404);
            }

            $brand->attributes = json_decode($brand->attributes, true);

            return response()->json($brand, 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_variant",
     *     summary="Add a new variant",
     *     tags={"Variants"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"select_variant", "type", "variant_title", "variant_options"},
     *             @OA\Property(property="select_variant", type="string", example="Size"),
     *             @OA\Property(property="type", type="string", example="dropdown"),
     *             @OA\Property(property="variant_title", type="string", example="Color"),
     *             @OA\Property(property="variant_options", type="array", @OA\Items(type="string"), example={"Red", "Blue"}),
     *             @OA\Property(property="color_code", type="array", @OA\Items(type="string"), example={"#FF5733", "#33FF57"})
     *         )
     *     ),
     *     @OA\Response(response=200, description="Variant added successfully"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function add_variant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'select_variant' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'variant_title' => 'required|string|max:255',
            'variant_options' => 'required|array|min:1',
            'variant_options.*' => 'string|max:255|min:1',
            'color_code' => 'nullable|array',
            'color_code.*' => 'string|max:7|regex:/^#[0-9A-Fa-f]{6}$/'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
        }

        $user = Auth::user();
        DB::beginTransaction();

        try {
            $slug = $user->id . '-' . time() . '-' . Str::uuid();
            $data = $request->except('_token', 'variant_title');

            product_attribute::create([
                'name' => $request->variant_title,
                'user_id' => $user->id,
                'attributes_type' => 1,
                'slug' => $slug,
                'attributes' => json_encode($data),
                'active' => 1
            ]);

            DB::commit();
            return response()->json(['message' => 'Variant added successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => 'Server error.'], 500);
        }
    }
    /**
     * @OA\Put(
     *     path="/veri5d/api/edit_variant/{id}",
     *     summary="Edit a specific variant",
     *     tags={"Variants"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="variant_title", type="string", example="Updated Color"),
     *             @OA\Property(property="variant_options", type="array", @OA\Items(type="string"), example={"Green", "Yellow"}),
     *             @OA\Property(property="color_code", type="array", @OA\Items(type="string"), example={"#00FF00", "#FFFF00"})
     *         )
     *     ),
     *     @OA\Response(response=200, description="Variant updated successfully"),
     *     @OA\Response(response=404, description="Variant not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function edit_variant(Request $request, $id)
    {
        $variant = product_attribute::where('id', $id)->where('attributes_type', 1)->where('active', 1)->first();

        if (!$variant) {
            return response()->json(['message' => 'Variant not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'variant_title' => 'required|string|max:255',
            'variant_options' => 'required|array|min:1',
            'variant_options.*' => 'string|max:255|min:1',
            'color_code' => 'nullable|array',
            'color_code.*' => 'string|max:7|regex:/^#[0-9A-Fa-f]{6}$/'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
        }

        $variant->name = $request->variant_title;
        $variant->attributes = json_encode($request->except('_token', 'variant_title'));
        $variant->save();

        return response()->json(['message' => 'Variant updated successfully']);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_variant/{id}",
     *     summary="View a specific variant",
     *     tags={"Variants"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Variant details"),
     *     @OA\Response(response=404, description="Variant not found")
     * )
     */
    public function view_variant($id)
    {
        $variant = product_attribute::where('id', $id)->where('attributes_type', 1)->where('active', 1)->first();

        if (!$variant) {
            return response()->json(['message' => 'Variant not found'], 404);
        }

        return response()->json($variant);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_variant",
     *     summary="View all variants",
     *     tags={"Variants"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(response=200, description="List of all variants"),
     *     @OA\Response(response=404, description="No variants found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function view_all_variant()
    {
        $variants = product_attribute::where('active', 1)->where('attributes_type', 1)->get();

        if ($variants->isEmpty()) {
            return response()->json(['message' => 'No variants found'], 404);
        }

        return response()->json($variants, 200);
    }
    /**
     * @OA\Delete(
     *     path="/veri5d/api/delete_variant/{id}",
     *     summary="Soft delete a specific variant",
     *     tags={"Variants"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Variant deleted successfully"),
     *     @OA\Response(response=404, description="Variant not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function delete_variant($id)
    {
        $variant = product_attribute::where('id', $id)->where('active', 1)->first();

        if (!$variant) {
            return response()->json(['message' => 'Variant not found'], 404);
        }

        $variant->update(['active' => 0]);
        return response()->json(['message' => 'Variant deleted successfully']);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_vendors_with_shop",
     *     summary="Get users with their vendors",
     *     tags={"Vendor"},
     *     @OA\Parameter(
     *         name="name",
     *         in="query",
     *         description="Filter by user name",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="email",
     *         in="query",
     *         description="Filter by user email",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="phone",
     *         in="query",
     *         description="Filter by user phone",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="city",
     *         in="query",
     *         description="Filter by user city",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="state",
     *         in="query",
     *         description="Filter by user state",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="country",
     *         in="query",
     *         description="Filter by user country",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="user_id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="John Doe"),
     *                 @OA\Property(property="email", type="string", example="john@example.com"),
     *                 @OA\Property(property="phone", type="string", example="1234567890"),
     *                 @OA\Property(property="city", type="string", example="New York"),
     *                 @OA\Property(property="state", type="string", example="NY"),
     *                 @OA\Property(property="country", type="string", example="USA"),
     *                 @OA\Property(property="shop", type="array", @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="vendor_id", type="integer", example=101),
     *                     @OA\Property(property="shop_name", type="string", example="Shop A"),
     *                     @OA\Property(property="add_by", type="string", example="admin"),
     *                     @OA\Property(property="slug", type="string", example="shop-a"),
     *                     @OA\Property(property="shop_data", type="object"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 ))
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function view_all_vendors_with_shop()
    {
        $users = User::with([
            'vendors' => function ($q) {
                $q->where('vendors.active', 1);
            },

        ])
            ->where('users.user_type', 'vd')
            ->get()
            ->map(function ($user) {
                $products = Product::where('user_id', $user->id)->get();
                $productsWithStats = $products->map(function ($product) {
                    $orders = Order_tracking::where('product_id', $product->id)
                        ->where('active', 1)
                        ->get();
                    return [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'total_orders' => $orders->count(),
                        'total_order_price' => $orders->sum('totel_price'),
                        'total_tax' => $orders->sum('tax_price'),
                        'total_order' => $orders->count(),
                    ];
                });
                $userTotalOrderPrice = $productsWithStats->sum('total_order_price');
                $userTotalTaxFee = $productsWithStats->sum('total_tax');
                $userTotalOrder = $productsWithStats->sum('total_order');
                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'city' => $user->city,
                    'state' => $user->state,
                    'country' => $user->country,
                    'active' => $user->active,
                    'add_by' => $user->add_by,
                    'balance_amount' => $user->balance_amount,
                    'cash_collected' => $user->balance_amount,
                    'create_by' => $user->create_by,
                    'shop' => $user->vendors->map(function ($vendor) {
                        $shopData = json_decode($vendor->shop_data, true) ?? [];
                        return [
                            'shop_id' => $vendor->id,
                            'shop_name' => $vendor->shop_name,
                            'add_by' => $vendor->add_by,
                            'slug' => $vendor->slug,
                            'shop_data' => $shopData,
                            'created_at' => $vendor->created_at,
                            'updated_at' => $vendor->updated_at,
                        ];
                    }),
                    'products' => $productsWithStats,
                    'total_user_order_price' => $userTotalOrderPrice,
                    'total_user_tax_fee' => $userTotalTaxFee,
                    'TotalOrder' => $userTotalOrder,
                ];
            });
        $all_order = Order_tracking::where('active', 1)->get();
        $commission = user_payment_list::where('active', 1)->whereIn('order_id', [1, 2, 3])->get();
        // Log::error('loki');
        return response()->json([
            'success' => true,
            'data' => $users,
            'totel_order_value' => $all_order->sum('totel_price'),
            'totel_delivery_fee' => $all_order->sum('delivery_fee'),
            'totel_admin_commission' => $commission->sum('profit_share'),
        ], 200);
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_vendor",
     *     summary="Add a new vendor",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Adds a new vendor with basic details.",
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "phone", "password", "address", "country", "state", "city"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="johndoe@example.com"),
     *             @OA\Property(property="phone", type="string", example="+1234567890"),
     *             @OA\Property(property="password", type="string", example="secret123"),
     *             @OA\Property(property="address", type="string", example="123 Main St"),
     *             @OA\Property(property="country", type="string", example="USA"),
     *             @OA\Property(property="state", type="string", example="NY"),
     *             @OA\Property(property="city", type="string", example="New York")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Data added successfully.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */





    public function add_vendor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
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
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = Auth::user();
        DB::beginTransaction();
        try {
            $data = $request->only('name', 'email', 'phone', 'password', 'address', 'country', 'state', 'city');
            $data['password'] = Hash::make($request->input('password'));
            $data['user_type'] = 'vd';
            $data['create_by'] = $user->id;
            $data['active'] = 0;
            $newuser = User::create($data);
            DB::commit();
            return response()->json([
                'message' => 'Data add Successfully. Waiting for Approval',
                'new_id' => $newuser->id,
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Put(
     *     path="/veri5d/api/edit_vendor/{id}",
     *     summary="Edit a vendor by ID",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Edits the details of an existing vendor.",
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Vendor ID",
     *         @OA\Schema(type="integer")
     *     ),
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="phone", type="string", example="+1234567890"),
     *             @OA\Property(property="address", type="string", example="456 Main St"),
     *             @OA\Property(property="country", type="string", example="USA"),
     *             @OA\Property(property="state", type="string", example="CA"),
     *             @OA\Property(property="city", type="string", example="Los Angeles")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Vendor updated successfully.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validation error")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */

    public function edit_vendor(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'email|unique:users,email,' . $id,
            'address' => 'string|max:255',
            'country' => 'string|max:255',
            'state' => 'string|max:255|min:2',
            'city' => 'string|max:255',
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
            $vendor = User::findOrFail($id);
            $data = $request->only('name', 'email', 'address', 'country', 'state', 'city');
            $vendor->update($data);
            DB::commit();
            return response()->json(['message' => 'Vendor updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_vendor/{id}",
     *     summary="View a vendor by ID",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves the details of a vendor by ID.",
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Vendor ID",
     *         @OA\Schema(type="integer")
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Vendor not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Vendor not found")
     *         )
     *     )
     * )
     */

    public function view_vendor($id)
    {
        try {
            $vendor = User::where('id', $id)
                ->where('user_type', 'vd')
                ->firstOrFail();
            return response()->json([
                'success' => true,
                'data' => $vendor
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found'
            ], 404);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_vendors",
     *     summary="Get all vendors with optional filters",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves a list of all vendors with pagination and optional filters.",
     * 
     *     @OA\Parameter(
     *         name="name",
     *         in="query",
     *         required=false,
     *         description="Filter by vendor name",
     *         @OA\Schema(type="string", example="John Doe")
     *     ),
     *     @OA\Parameter(
     *         name="email",
     *         in="query",
     *         required=false,
     *         description="Filter by email",
     *         @OA\Schema(type="string", example="john.doe@example.com")
     *     ),
     *     @OA\Parameter(
     *         name="phone",
     *         in="query",
     *         required=false,
     *         description="Filter by phone number",
     *         @OA\Schema(type="string", example="+1234567890")
     *     ),
     *     @OA\Parameter(
     *         name="city",
     *         in="query",
     *         required=false,
     *         description="Filter by city",
     *         @OA\Schema(type="string", example="Los Angeles")
     *     ),
     *     @OA\Parameter(
     *         name="state",
     *         in="query",
     *         required=false,
     *         description="Filter by state",
     *         @OA\Schema(type="string", example="CA")
     *     ),
     *     @OA\Parameter(
     *         name="country",
     *         in="query",
     *         required=false,
     *         description="Filter by country",
     *         @OA\Schema(type="string", example="USA")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Page number for pagination",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendors retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="data", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="name", type="string", example="John Doe"),
     *                         @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *                         @OA\Property(property="phone", type="string", example="+1234567890"),
     *                         @OA\Property(property="city", type="string", example="Los Angeles"),
     *                         @OA\Property(property="state", type="string", example="CA"),
     *                         @OA\Property(property="country", type="string", example="USA"),
     *                         @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-27T10:30:00Z"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time", example="2025-03-27T12:00:00Z")
     *                     )
     *                 ),
     *                 @OA\Property(property="first_page_url", type="string", example="http://localhost/veri5d/api/view_all_vendors?page=1"),
     *                 @OA\Property(property="last_page_url", type="string", example="http://localhost/veri5d/api/view_all_vendors?page=5"),
     *                 @OA\Property(property="next_page_url", type="string", example="http://localhost/veri5d/api/view_all_vendors?page=2"),
     *                 @OA\Property(property="prev_page_url", type="string", nullable=true, example=null)
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve vendors."),
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */

    public function view_all_vendors(Request $request)
    {
        $query = User::where('user_type', 'vd');
        if ($request->has('name')) {
            $query->where('name', 'LIKE', '%' . $request->name . '%');
        }
        if ($request->has('email')) {
            $query->where('email', $request->email);
        }
        if ($request->has('phone')) {
            $query->where('phone', $request->phone);
        }
        if ($request->has('city')) {
            $query->where('city', $request->city);
        }
        if ($request->has('state')) {
            $query->where('state', $request->state);
        }
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }
        if ($request->has('active')) {
            $query->where('active', $request->active);
        }
        $vendors = $query->paginate(10);
        return response()->json([
            'success' => true,
            'data'    => $vendors
        ], 200);
    }
    public function approval_for_vendors(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'under_user'  => 'required|exists:users,id',
            'id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $approval = user::find($request['id']);
        DB::beginTransaction();
        try {
            $approval->update([
                'active' => 1,
                'add_by' => $request['under_user'],
            ]);
            DB::commit();
            return response()->json(['message' => 'Vendor Approved Successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function reject_for_vendors(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $approval = user::find($request['id']);
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $approval->update([
                'active' => 2,
                // 'add_by' => $user->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'Vendor Approved Successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Delete(
     *     path="/veri5d/api/delete_vendor/{id}",
     *     summary="Delete a vendor by ID",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Deletes a vendor by marking them as inactive.",
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the vendor to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Vendor deleted successfully.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Vendor not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Vendor not found.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */

    public function delete_vendor($id)
    {
        $vendor = user::where('active', 1)->where('id', $id)->first();
        if (!$vendor) {
            return response()->json(['error' => 'vendor not found.'], 404);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $vendor->update([
                'active' => 3,
                'create_by' => $user->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'vendor deleted successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_category",
     *     summary="Add Category",
     *     tags={"Category"},
     *     security={{"bearerAuth": {}}},
     *     description="Adds a new category with optional images and metadata.",
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         description="Category data with optional image uploads",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"name", "wishlist", "visible_in_menu", "description"},
     *                 @OA\Property(property="name", type="string", description="Category name", example="Electronics"),
     *                 @OA\Property(property="wishlist", type="boolean", description="Wishlist flag", example=true),
     *                 @OA\Property(property="visible_in_menu", type="boolean", description="Visibility in menu", example=true),
     *                 @OA\Property(property="description", type="string", description="Category description", example="Electronic devices"),
     *                 @OA\Property(property="parent_id", type="integer", nullable=true, description="Parent category ID", example=5),
     *                 
     *                 @OA\Property(
     *                     property="banner_image",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional banner image (jpg, jpeg, png, webp)"
     *                 ),
     *                 @OA\Property(
     *                     property="category_icon",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional category icon (jpg, jpeg, png, webp)"
     *                 ),
     *                 @OA\Property(
     *                     property="hover_icon",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional hover icon (jpg, jpeg, png, webp)"
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Category added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Data added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object", example={"name": {"The name field is required."}})
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to add category: Database error.")
     *         )
     *     )
     * )
     */
    public function add_category(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'visible_in_menu' => 'required',
            'wishlist' => 'required',
            'description' => 'required|string|max:255|min:1',
            'parent_id' => 'nullable|integer|exists:product_categories,id',
            'banner_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'category_icon' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'hover_icon' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        if (!empty($request->parent_id)) {
            $find_category = product_categorie::where('id', $request['parent_id'])->whereNotNull('parent_id')->first();
            if ($find_category) {
                return response()->json(['message' => 'we can add categoy under subcategory.'], 200);
            }
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $slug = $user->id . time() . Str::uuid() . (int) Str::random(5);
            $data = $request->except('_token', 'name', 'parent_id');
            if ($request->hasFile("banner_image")) {
                $banner_image_file = $this->uploadFile($request, 'banner_image', 'image/category/');
                $data['banner_image'] = $banner_image_file;
            }
            if ($request->hasFile("category_icon")) {
                $category_icon_file = $this->uploadFile($request, 'category_icon', 'image/category/');
                $data['category_icon'] = $category_icon_file;
            }
            if ($request->hasFile("hover_icon")) {
                $hover_icon_file = $this->uploadFile($request, 'hover_icon', 'image/category/');
                $data['hover_icon'] = $hover_icon_file;
            }
            product_categorie::create([
                'name' => $request['name'],
                'parent_id' => $request['parent_id'],
                'user_id' => $user->id,
                'slug' => $slug,
                'description' => json_encode($data)
            ]);
            DB::commit();
            return response()->json(['message' => 'Data add Successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/edit_category/{id}",
     *     summary="Edit Category",
     *     tags={"Category"},
     *     security={{"bearerAuth": {}}},
     *     description="Updates an existing category with optional image uploads.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Category ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         description="Category data with optional image uploads",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"name", "wishlist", "visible_in_menu", "description"},
     *                 @OA\Property(property="name", type="string", description="Category name", example="Updated Electronics"),
     *                 @OA\Property(property="wishlist", type="boolean", description="Wishlist flag", example=false),
     *                 @OA\Property(property="visible_in_menu", type="boolean", description="Visibility in menu", example=false),
     *                 @OA\Property(property="description", type="string", description="Updated category description", example="Updated description"),
     *                 @OA\Property(property="parent_id", type="integer", nullable=true, description="Parent category ID", example=5),
     *                 
     *                 @OA\Property(
     *                     property="banner_image",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional updated banner image (jpg, jpeg, png, webp)"
     *                 ),
     *                 @OA\Property(
     *                     property="category_icon",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional updated category icon (jpg, jpeg, png, webp)"
     *                 ),
     *                 @OA\Property(
     *                     property="hover_icon",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional updated hover icon (jpg, jpeg, png, webp)"
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Category updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Category updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Category not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Category not found.")
     *         )
     *     )
     * )
     */
    public function edit_category(Request $request, $id)
    {
        $category = product_categorie::where('active', 1)->where('id', $id)->first();
        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'visible_in_menu' => 'required',
            'wishlist' => 'required',
            'description' => 'required|string|max:255|min:1',
            'parent_id' => 'nullable|integer|exists:product_categories,id',
            'banner_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'category_icon' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'hover_icon' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        if (!empty($request->parent_id)) {
            $find_category = product_categorie::where('id', $request['parent_id'])->whereNotNull('parent_id')->first();
            if ($find_category) {
                return response()->json(['message' => 'we can add categoy under subcategory.'], 200);
            }
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $data = json_decode($category->description, true);
            if ($request->hasFile('banner_image')) {
                $data['banner_image'] = $this->uploadFile($request, 'banner_image', 'image/category/');
            }
            if ($request->hasFile('category_icon')) {
                $data['category_icon'] = $this->uploadFile($request, 'category_icon', 'image/category/');
            }
            if ($request->hasFile('hover_icon')) {
                $data['hover_icon'] = $this->uploadFile($request, 'hover_icon', 'image/category/');
            }
            $data['wishlist'] = $request->wishlist;
            $data['visible_in_menu'] = $request->visible_in_menu;
            $data['description'] = $request->description;
            $category->update([
                'name' => $request->name,
                'user_id' => $user->id,
                'parent_id' => $request->parent_id,
                'description' => json_encode($data)
            ]);
            DB::commit();
            return response()->json(['message' => 'Category updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_category/{id}",
     *     summary="View Category by ID",
     *     tags={"Category"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves details of a specific category by ID.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Category ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Category details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="Electronics"),
     *             @OA\Property(property="wishlist", type="boolean", example=true),
     *             @OA\Property(property="visible_in_menu", type="boolean", example=true),
     *             @OA\Property(property="description", type="string", example="Category description"),
     *             @OA\Property(property="banner_image", type="string", example="image/category/banner.jpg"),
     *             @OA\Property(property="category_icon", type="string", example="image/category/icon.jpg"),
     *             @OA\Property(property="hover_icon", type="string", example="image/category/hover.jpg")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Category not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Category not found.")
     *         )
     *     )
     * )
     */
    public function view_category($id)
    {
        $category = product_categorie::where('active', 1)->where('id', $id)->first();
        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }
        $data = json_decode($category->description, true);
        return response()->json([
            'id' => $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'wishlist' => $data['wishlist'] ?? false,
            'visible_in_menu' => $data['visible_in_menu'] ?? false,
            'description' => $data['description'] ?? '',
            'banner_image' => $data['banner_image'] ?? null,
            'category_icon' => $data['category_icon'] ?? null,
            'hover_icon' => $data['hover_icon'] ?? null
        ], 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_categories",
     *     summary="View All Categories",
     *     tags={"Category"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves a list of all categories with their details.",
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Electronics"),
     *                 @OA\Property(property="wishlist", type="boolean", example=true),
     *                 @OA\Property(property="visible_in_menu", type="boolean", example=true),
     *                 @OA\Property(property="description", type="string", example="Category description"),
     *                 @OA\Property(property="banner_image", type="string", example="image/category/banner.jpg"),
     *                 @OA\Property(property="category_icon", type="string", example="image/category/icon.jpg"),
     *                 @OA\Property(property="hover_icon", type="string", example="image/category/hover.jpg"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-24T12:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No categories found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="No categories available.")
     *         )
     *     )
     * )
     */
    public function view_all_categories()
    {
        $categories = product_categorie::where('active', 1)->get();
        if ($categories->isEmpty()) {
            return response()->json(['error' => 'No categories available.'], 404);
        }
        $result = $categories->map(function ($category) {
            $data = json_decode($category->description, true);
            return [
                'id' => $category->id,
                'name' => $category->name,
                'wishlist' => $data['wishlist'] ?? false,
                'visible_in_menu' => $data['visible_in_menu'] ?? false,
                'description' => $data['description'] ?? '',
                'banner_image' => $data['banner_image'] ?? null,
                'category_icon' => $data['category_icon'] ?? null,
                'hover_icon' => $data['hover_icon'] ?? null,
                'parent_id' => $category->parent_id,
                'created_at' => $category->created_at->toDateTimeString(),
            ];
        });
        return response()->json($result, 200);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_categories_list",
     *     summary="View All Active Categories with Multi-Root Subcategories",
     *     tags={"Category"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves a hierarchical list of all active categories with multiple root and nested subcategories.",
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Categories and subcategories retrieved successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Electronics"),
     *                 @OA\Property(property="wishlist", type="boolean", example=true),
     *                 @OA\Property(property="visible_in_menu", type="boolean", example=true),
     *                 @OA\Property(property="description", type="string", example="Category description"),
     *                 @OA\Property(property="banner_image", type="string", example="image/category/banner.jpg"),
     *                 @OA\Property(property="category_icon", type="string", example="image/category/icon.jpg"),
     *                 @OA\Property(property="hover_icon", type="string", example="image/category/hover.jpg"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-24T12:00:00Z"),
     *                 @OA\Property(
     *                     property="subcategories",
     *                     type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer", example=2),
     *                         @OA\Property(property="name", type="string", example="Laptops"),
     *                         @OA\Property(property="wishlist", type="boolean", example=true),
     *                         @OA\Property(property="visible_in_menu", type="boolean", example=true),
     *                         @OA\Property(property="description", type="string", example="Subcategory description"),
     *                         @OA\Property(property="banner_image", type="string", example="image/category/sub_banner.jpg"),
     *                         @OA\Property(property="category_icon", type="string", example="image/category/sub_icon.jpg"),
     *                         @OA\Property(property="hover_icon", type="string", example="image/category/sub_hover.jpg"),
     *                         @OA\Property(
     *                             property="subcategories",
     *                             type="array",
     *                             @OA\Items(
     *                                 @OA\Property(property="id", type="integer", example=3),
     *                                 @OA\Property(property="name", type="string", example="Gaming Laptops"),
     *                                 @OA\Property(property="description", type="string", example="Third level subcategory description"),
     *                                 @OA\Property(property="banner_image", type="string", example="image/category/third_banner.jpg"),
     *                                 @OA\Property(property="category_icon", type="string", example="image/category/third_icon.jpg"),
     *                                 @OA\Property(property="hover_icon", type="string", example="image/category/third_hover.jpg")
     *                             )
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No categories found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="No categories available.")
     *         )
     *     )
     * )
     */

    public function view_all_categories_list()
    {
        $categories = product_categorie::where('active', 1)
            ->whereNull('parent_id')
            ->with(['subcategories' => function ($query) {
                $query->where('active', 1)
                    ->with(['subcategories' => function ($subQuery) {
                        $subQuery->where('active', 1);
                    }]);
            }])
            ->get();
        if ($categories->isEmpty()) {
            return response()->json(['error' => 'No active categories available.'], 404);
        }
        $result = $categories->map(function ($category) {
            return $this->formatCategory($category);
        });
        return response()->json($result, 200);
    }
    /**
     * @OA\Delete(
     *     path="/veri5d/api/delete_category/{id}",
     *     summary="Delete a Category",
     *     tags={"Category"},
     *     security={{"bearerAuth": {}}},
     *     description="Soft deletes a category by setting its `active` status to 0.",
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the category to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Category deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Category deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Category not found or is a main category",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="This is a Maincategory or category not found.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Category not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Category not found.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Failed to delete category: Database error.")
     *         )
     *     )
     * )
     */
    public function delete_category($id)
    {
        $maincategory = product_categorie::where('active', 1)->where('parent_id', $id)->first();
        if ($maincategory) {
            return response()->json(['error' => 'This is a Maincategory with subcategories, cannot be deleted'], 400);
        }
        $category = product_categorie::where('active', 1)->where('id', $id)->first();
        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $category->update([
                'active' => 0,
                'user_id' => $user->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'Category deleted successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_subcategories",
     *     summary="View All Subcategories",
     *     tags={"Category"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves a list of all categories with their details.",
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Electronics"),
     *                 @OA\Property(property="wishlist", type="boolean", example=true),
     *                 @OA\Property(property="visible_in_menu", type="boolean", example=true),
     *                 @OA\Property(property="description", type="string", example="Category description"),
     *                 @OA\Property(property="banner_image", type="string", example="image/category/banner.jpg"),
     *                 @OA\Property(property="category_icon", type="string", example="image/category/icon.jpg"),
     *                 @OA\Property(property="hover_icon", type="string", example="image/category/hover.jpg"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-24T12:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No categories found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="No categories available.")
     *         )
     *     )
     * )
     */
    public function view_all_subcategories()
    {
        $categories = product_categorie::where('active', 1)->whereNotNull('parent_id')->get();
        if ($categories->isEmpty()) {
            return response()->json(['error' => 'No categories available.'], 404);
        }
        $result = $categories->map(function ($category) {
            $data = json_decode($category->description, true);
            return [
                'id' => $category->id,
                'name' => $category->name,
                'wishlist' => $data['wishlist'] ?? false,
                'visible_in_menu' => $data['visible_in_menu'] ?? false,
                'description' => $data['description'] ?? '',
                'banner_image' => $data['banner_image'] ?? null,
                'category_icon' => $data['category_icon'] ?? null,
                'hover_icon' => $data['hover_icon'] ?? null,
                'parent_id' => $category->parent_id,
                'created_at' => $category->created_at->toDateTimeString(),
            ];
        });
        return response()->json($result, 200);
    }
    /**
     * @OA\Post(
     *     path="/veri5d/api/add_vendor_shop",
     *     summary="Add a Vendor Shop",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Creates a new vendor shop with details, including images, PDF files, and metadata.",
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         description="Vendor shop data with optional file uploads",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={
     *                     "shop_name", "user_id", "name", "email", "phone_number", "website", "pincode", 
     *                     "address", "latitude", "longitude", "city", "state_province", "country", 
     *                     "description", "delivery", "order_prepare_time", "availability", "auto_accept_order", 
     *                     "return_request", "show_profile_details", "auto_reject_time", "slot_duration",
     *                     "absolute_min_order_value", "commission_percent", "commission_fixed_per_order",
     *                     "service_fee_percent", "can_add_category", "vendor_detail_to_show", "vendor_category",
     *                     "number_of_employees", "product_service_both", "home_business", "nature_of_vendor",
     *                     "PAN", "GSTIN", "govt_registered_number", "bank_account_name", "bank_account_number",
     *                     "IFSC_code", "account_type", "bank_name", "bank_branch"
     *                 },
     *                 
     *                 @OA\Property(property="shop_name", type="string", description="Shop name", example="Tech World"),
     *                 @OA\Property(property="user_id", type="integer", description="User ID of the vendor", example=1),
     *                 @OA\Property(property="name", type="string", description="Vendor's full name", example="John Doe"),
     *                 @OA\Property(property="email", type="string", description="Vendor's email", example="johndoe@gmail.com"),
     *                 @OA\Property(property="phone_number", type="string", description="Vendor's phone number", example="+919876543210"),
     *                 @OA\Property(property="website", type="string", description="Vendor website", example="https://www.example.com"),
     *                 @OA\Property(property="pincode", type="string", description="Shop pincode", example="560001"),
     *                 @OA\Property(property="address", type="string", description="Shop address", example="123 Main Street"),
     *                 @OA\Property(property="latitude", type="string", description="Shop latitude", example="12.9716"),
     *                 @OA\Property(property="longitude", type="string", description="Shop longitude", example="77.5946"),
     *                 @OA\Property(property="city", type="string", description="City name", example="Bangalore"),
     *                 @OA\Property(property="state_province", type="string", description="State or province", example="Karnataka"),
     *                 @OA\Property(property="country", type="string", description="Country", example="India"),
     *                 @OA\Property(property="description", type="string", description="Shop description", example="Leading electronics store"),
     *                 
     *                 @OA\Property(property="delivery", type="boolean", description="Delivery availability", example=true),
     *                 @OA\Property(property="order_prepare_time", type="string", description="Order preparation time", example="30 min"),
     *                 @OA\Property(property="availability", type="boolean", description="Shop availability", example=true),
     *                 @OA\Property(property="auto_accept_order", type="boolean", description="Auto accept orders", example=true),
     *                 @OA\Property(property="return_request", type="boolean", description="Allow return requests", example=true),
     *                 @OA\Property(property="show_profile_details", type="boolean", description="Display profile details", example=true),
     *                 @OA\Property(property="auto_reject_time", type="string", description="Auto reject time", example="15 min"),
     *                 @OA\Property(property="slot_duration", type="string", description="Slot duration for orders", example="60 min"),
     *                 
     *                 @OA\Property(property="absolute_min_order_value", type="string", description="Minimum order value", example="1000"),
     *                 @OA\Property(property="commission_percent", type="string", description="Commission percentage", example="10"),
     *                 @OA\Property(property="commission_fixed_per_order", type="string", description="Fixed commission per order", example="50"),
     *                 @OA\Property(property="service_fee_percent", type="string", description="Service fee percentage", example="5"),
     *                 @OA\Property(property="can_add_category", type="boolean", description="Can add new categories", example=true),
     *                 
     *                 @OA\Property(property="vendor_detail_to_show", type="string", description="Details to display", example="Basic Info"),
     *                 @OA\Property(property="vendor_category", type="string", description="Vendor category", example="Retail"),
     *                 @OA\Property(property="number_of_employees", type="string", description="Number of employees", example="10"),
     *                 @OA\Property(property="product_service_both", type="string", description="Product, service, or both", example="Both"),
     *                 @OA\Property(property="home_business", type="string", description="Is it a home business", example="No"),
     *                 @OA\Property(property="nature_of_vendor", type="string", description="Nature of vendor", example="Registered"),
     *                 
     *                 @OA\Property(property="PAN", type="string", description="PAN number", example="ABCDE1234F"),
     *                 @OA\Property(property="GSTIN", type="string", description="GSTIN number", example="22AAAAA0000A1Z5"),
     *                 @OA\Property(property="govt_registered_number", type="string", description="Government registration number", example="1234567890"),
     *                 
     *                 @OA\Property(property="bank_account_name", type="string", description="Bank account name", example="Tech World Pvt Ltd"),
     *                 @OA\Property(property="bank_account_number", type="string", description="Bank account number", example="1234567890"),
     *                 @OA\Property(property="IFSC_code", type="string", description="IFSC code", example="SBIN0001234"),
     *                 @OA\Property(property="account_type", type="string", description="Account type", example="Current"),
     *                 @OA\Property(property="bank_name", type="string", description="Bank name", example="State Bank of India"),
     *                 @OA\Property(property="bank_branch", type="string", description="Bank branch", example="MG Road Branch"),
     *                 
     *                 @OA\Property(
     *                     property="PAN_PDF",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional PAN PDF file (max: 2MB)"
     *                 ),
     *                 @OA\Property(
     *                     property="upload_logo",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional vendor logo image (jpg, jpeg, png, webp)"
     *                 ),
     *                 @OA\Property(
     *                     property="upload_banner_image",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional banner image (jpg, jpeg, png, webp)"
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor shop added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Vendor shop added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object", example={"email": {"The email field is required."}})
     *         )
     *     )
     * )
     */
    public function add_vendor_shop(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_name' => 'required|string|max:255',
            'user_id' => 'required|integer|exists:users,id',
            // 'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone_number' => [
                'required',
                'unique:users,phone',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'website' => [
                'nullable',
                'regex:/^(https?:\/\/)?(www\.)?[a-z0-9-]+\.[a-z]{2,}(\S*)?$/i'
            ],
            'pincode' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'latitude' => 'required|string|max:255',
            'longitude' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state_province' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'delivery' => 'required',
            'order_prepare_time' => 'required|string|max:255',
            'availability' => 'required',
            'auto_accept_order' => 'required',
            'return_request' => 'required',
            'show_profile_details' => 'required',
            'auto_reject_time' => 'required|string|max:255',
            'slot_duration' => 'required|string|max:255',
            'absolute_min_order_value' => 'required|string|max:255',
            'commission_percent' => 'required|string|max:255|min:1',
            'commission_fixed_per_order' => 'required|string|max:255|min:1',
            'service_fee_percent' => 'required|string|max:255|min:1',
            'can_add_category' => 'required|string|max:255',
            'vendor_detail_to_show' => 'required|string|max:255',
            'vendor_category' => 'required|string|max:255',
            'number_of_employees' => 'required|string|max:255|min:1',
            'product_service_both' => 'required|string|max:255',
            'home_business' => 'required|string|max:255|min:1',
            'nature_of_vendor' => 'required|string|max:255',
            'PAN' => 'required|string|max:255',
            'GSTIN' => 'required|string|max:255',
            'govt_registered_number' => 'required|string|max:255',
            'bank_account_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:255',
            'IFSC_code' => 'required|string|max:255',
            'account_type' => 'required|string|max:255',
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'required|string|max:255',
            'PAN_PDF' => 'required|file|mimes:pdf|max:2048',
            'upload_logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'upload_banner_image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $slug = $user->id . time() . Str::uuid() . (int) Str::random(5);
            $data = $request->except('_token', 'shop_name');
            if ($request->hasFile("PAN_PDF")) {
                $PAN_PDF_file = $this->uploadFile($request, 'PAN_PDF', 'image/vendor/');
                $data['PAN_PDF'] = $PAN_PDF_file;
            }
            if ($request->hasFile("upload_logo")) {
                $upload_logo_file = $this->uploadFile($request, 'upload_logo', 'image/vendor/');
                $data['upload_logo'] = $upload_logo_file;
            }
            if ($request->hasFile("upload_banner_image")) {
                $upload_banner_image_file = $this->uploadFile($request, 'upload_banner_image', 'image/vendor/');
                $data['upload_banner_image'] = $upload_banner_image_file;
            }
            vendor::create([
                'shop_name' => $request['shop_name'],
                'add_by' => $user->id,
                'user_id' => $request['user_id'],
                'slug' => $slug,
                'shop_data' => json_encode($data)
            ]);
            DB::commit();
            return response()->json(['message' => 'Data add Successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function create_vendor_with_shop(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => [
                'required',
                'unique:users,phone',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'password' => 'required|string|min:6',
            'name' => 'required|string|max:255',
            'website' => [
                'nullable',
                'regex:/^(https?:\/\/)?(www\.)?[a-z0-9-]+\.[a-z]{2,}(\S*)?$/i'
            ],
            'pincode' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            // 'latitude' => 'required|string|max:255',
            // 'longitude' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state_province' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'delivery' => 'required',
            // 'order_prepare_time' => 'required|string|max:255',
            'availability' => 'required',
            // 'auto_accept_order' => 'required',
            'return_request' => 'required',
            'show_profile_details' => 'required',
            // 'auto_reject_time' => 'required|string|max:255',
            // 'service_fee_percent' => 'required|string|max:255|min:1',
            'vendor_detail_to_show' => 'required|string|max:255',
            'number_of_employees' => 'required|string|max:255|min:1',
            'home_business' => 'required|string|max:255|min:1',
            'nature_of_vendor' => 'required|string|max:255',
            'PAN' => 'required|string|max:255',
            'GSTIN' => 'required|string|max:255',
            'govt_registered_number' => 'required|string|max:255',
            'bank_account_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:255',
            'IFSC_code' => 'required|string|max:255',
            'account_type' => 'required|string|max:255',
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'required|string|max:255',
            'PAN_PDF' => 'required|file|mimes:pdf|max:2048',
            'upload_logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            'upload_banner_image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {

            $data = $request->only('name', 'email', 'phone', 'password', 'address', 'country', 'city');
            $data['password'] = Hash::make($request->input('password'));
            $data['user_type'] = 'vd';
            $data['create_by'] = $user->id;
            $data['state'] = $request->input('state_province');
            $data['active'] = 0;
            $newuser = User::create($data);

            $slug = $user->id . time() . Str::uuid() . (int) Str::random(5);
            $data = $request->except('_token', 'shop_name');
            if ($request->hasFile("PAN_PDF")) {
                $PAN_PDF_file = $this->uploadFile($request, 'PAN_PDF', 'image/vendor/');
                $data['PAN_PDF'] = $PAN_PDF_file;
            }
            if ($request->hasFile("upload_logo")) {
                $upload_logo_file = $this->uploadFile($request, 'upload_logo', 'image/vendor/');
                $data['upload_logo'] = $upload_logo_file;
            }
            if ($request->hasFile("upload_banner_image")) {
                $upload_banner_image_file = $this->uploadFile($request, 'upload_banner_image', 'image/vendor/');
                $data['upload_banner_image'] = $upload_banner_image_file;
            }
            vendor::create([
                'shop_name' => $request['shop_name'],
                'add_by' => $user->id,
                'user_id' => $newuser->id,
                'slug' => $slug,
                'shop_data' => json_encode($data)
            ]);
            DB::commit();
            return response()->json(['message' => 'Data add Successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function update_vendor_with_shop(Request $request, $vendorId)
    {
        $validator = Validator::make($request->all(), [
            'shop_name' => 'required|string|max:255',
            'email' => "required|email|unique:users,email,$vendorId,id",
            'phone' => [
                'required',
                Rule::unique('users', 'phone')->ignore($vendorId, 'id'),
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'name' => 'required|string|max:255',
            'website' => [
                'nullable',
                'regex:/^(https?:\/\/)?(www\.)?[a-z0-9-]+\.[a-z]{2,}(\S*)?$/i'
            ],
            'pincode' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state_province' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'delivery' => 'required',
            'availability' => 'required',
            'return_request' => 'required',
            'show_profile_details' => 'required',
            'vendor_detail_to_show' => 'required|string|max:255',
            'number_of_employees' => 'required|string|max:255|min:1',
            'home_business' => 'required|string|max:255|min:1',
            'nature_of_vendor' => 'required|string|max:255',
            'PAN' => 'required|string|max:255',
            'GSTIN' => 'required|string|max:255',
            'govt_registered_number' => 'required|string|max:255',
            'bank_account_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:255',
            'IFSC_code' => 'required|string|max:255',
            'account_type' => 'required|string|max:255',
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'required|string|max:255',
            'PAN_PDF' => 'nullable|file|mimes:pdf|max:2048',
            'upload_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'upload_banner_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
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
            $vendor = Vendor::where('user_id', $vendorId)->firstOrFail();
            $user = User::where('id', $vendorId)->firstOrFail();

            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state_province,
                'country' => $request->country,
            ]);

            $data = $request->except('_token', 'shop_name');
            
            // if ($request->hasFile("PAN_PDF")) {
            //     $data['PAN_PDF'] = $this->uploadFile($request, 'PAN_PDF', 'image/vendor/');
            // }
            // if ($request->hasFile("upload_logo")) {
            //     $data['upload_logo'] = $this->uploadFile($request, 'upload_logo', 'image/vendor/');
            // }
            // if ($request->hasFile("upload_banner_image")) {
            //     $data['upload_banner_image'] = $this->uploadFile($request, 'upload_banner_image', 'image/vendor/');
            // }
            $existingData = json_decode($vendor->shop_data, true) ?? [];

            $data['PAN_PDF'] = $request->hasFile("PAN_PDF")
            ? $this->uploadFile($request, 'PAN_PDF', 'image/vendor/')
            : ($existingData['PAN_PDF'] ?? null);

            $data['upload_logo'] = $request->hasFile("upload_logo")
            ? $this->uploadFile($request, 'upload_logo', 'image/vendor/')
            : ($existingData['upload_logo'] ?? null);

            $data['upload_banner_image'] = $request->hasFile("upload_banner_image")
            ? $this->uploadFile($request, 'upload_banner_image', 'image/vendor/')
            : ($existingData['upload_banner_image'] ?? null);


            $vendor->update([
                'shop_name' => $request->shop_name,
                'shop_data' => json_encode($data)
            ]);

            DB::commit();
            return response()->json(['message' => 'Vendor updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }





    /**
     * @OA\Put(
     *     path="/veri5d/api/edit_vendor_shop/{id}",
     *     summary="Edit an existing vendor shop",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Updates the details of an existing vendor shop by ID.",
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Vendor shop ID",
     *         @OA\Schema(type="integer")
     *     ),
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         description="Vendor shop details",
     *         @OA\JsonContent(
     *             required={"shop_name", "user_id", "name", "email", "phone_number", "website", "pincode", "address", "latitude", "longitude", "city", "state_province", "country", "description", "delivery", "order_prepare_time", "availability", "auto_accept_order", "return_request", "show_profile_details", "auto_reject_time", "slot_duration", "absolute_min_order_value", "commission_percent", "commission_fixed_per_order", "service_fee_percent", "can_add_category", "vendor_detail_to_show", "vendor_category", "number_of_employees", "product_service_both", "home_business", "nature_of_vendor", "PAN", "GSTIN", "govt_registered_number", "bank_account_name", "bank_account_number", "IFSC_code", "account_type", "bank_name", "bank_branch"},
     *             @OA\Property(property="shop_name", type="string", example="My Vendor Shop"),
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="johndoe@example.com"),
     *             @OA\Property(property="phone_number", type="string", example="+919876543210"),
     *             @OA\Property(property="website", type="string", example="https://example.com"),
     *             @OA\Property(property="pincode", type="string", example="123456"),
     *             @OA\Property(property="address", type="string", example="123 Main St"),
     *             @OA\Property(property="latitude", type="string", example="10.123456"),
     *             @OA\Property(property="longitude", type="string", example="76.543210"),
     *             @OA\Property(property="city", type="string", example="New York"),
     *             @OA\Property(property="state_province", type="string", example="NY"),
     *             @OA\Property(property="country", type="string", example="USA"),
     *             @OA\Property(property="description", type="string", example="Best vendor shop"),
     *             @OA\Property(property="delivery", type="boolean", example=true),
     *             @OA\Property(property="order_prepare_time", type="string", example="30 min"),
     *             @OA\Property(property="availability", type="boolean", example=true),
     *             @OA\Property(property="auto_accept_order", type="boolean", example=true),
     *             @OA\Property(property="return_request", type="boolean", example=true),
     *             @OA\Property(property="show_profile_details", type="boolean", example=true),
     *             @OA\Property(property="auto_reject_time", type="string", example="15 min"),
     *             @OA\Property(property="slot_duration", type="string", example="60 min"),
     *             @OA\Property(property="absolute_min_order_value", type="string", example="100"),
     *             @OA\Property(property="commission_percent", type="string", example="10%"),
     *             @OA\Property(property="commission_fixed_per_order", type="string", example="5"),
     *             @OA\Property(property="service_fee_percent", type="string", example="3%"),
     *             @OA\Property(property="can_add_category", type="string", example="yes"),
     *             @OA\Property(property="vendor_detail_to_show", type="string", example="Full Details"),
     *             @OA\Property(property="vendor_category", type="string", example="Electronics"),
     *             @OA\Property(property="number_of_employees", type="string", example="10"),
     *             @OA\Property(property="product_service_both", type="string", example="both"),
     *             @OA\Property(property="home_business", type="string", example="No"),
     *             @OA\Property(property="nature_of_vendor", type="string", example="Corporate"),
     *             @OA\Property(property="PAN", type="string", example="ABCDE1234F"),
     *             @OA\Property(property="GSTIN", type="string", example="22AAAAA0000A1Z5"),
     *             @OA\Property(property="govt_registered_number", type="string", example="GOVT123456"),
     *             @OA\Property(property="bank_account_name", type="string", example="John Doe"),
     *             @OA\Property(property="bank_account_number", type="string", example="1234567890"),
     *             @OA\Property(property="IFSC_code", type="string", example="HDFC0000123"),
     *             @OA\Property(property="account_type", type="string", example="Saving"),
     *             @OA\Property(property="bank_name", type="string", example="HDFC Bank"),
     *             @OA\Property(property="bank_branch", type="string", example="Main Branch"),
     *             @OA\Property(property="PAN_PDF", type="string", format="binary"),
     *             @OA\Property(property="upload_logo", type="string", format="binary"),
     *             @OA\Property(property="upload_banner_image", type="string", format="binary")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor shop updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Vendor shop updated successfully.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Vendor not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Vendor not found.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */

    public function edit_vendor_shop(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'shop_name' => 'required|string|max:255',
            'user_id' => 'required|integer|exists:users,id',
            // 'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone_number' => [
                'required',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'website' => [
                'required',
                'regex:/^(https?:\/\/)?(www\.)?[a-z0-9-]+\.[a-z]{2,}(\S*)?$/i'
            ],
            'pincode' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'latitude' => 'required|string|max:255',
            'longitude' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state_province' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'delivery' => 'required|boolean',
            'order_prepare_time' => 'required|string|max:255',
            'availability' => 'required|boolean',
            'auto_accept_order' => 'required|boolean',
            'return_request' => 'required|boolean',
            'show_profile_details' => 'required|boolean',
            'auto_reject_time' => 'required|string|max:255',
            'slot_duration' => 'required|string|max:255',
            'absolute_min_order_value' => 'required|string|max:255',
            'commission_percent' => 'required|string|max:255',
            'commission_fixed_per_order' => 'required|string|max:255',
            'service_fee_percent' => 'required|string|max:255',
            'can_add_category' => 'required|string|max:255',
            'vendor_detail_to_show' => 'required|string|max:255',
            'vendor_category' => 'required|string|max:255',
            'number_of_employees' => 'required|string|max:255',
            'product_service_both' => 'required|string|max:255',
            'home_business' => 'required|string|max:255',
            'nature_of_vendor' => 'required|string|max:255',
            'PAN' => 'required|string|max:255',
            'GSTIN' => 'required|string|max:255',
            'govt_registered_number' => 'required|string|max:255',
            'bank_account_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:255',
            'IFSC_code' => 'required|string|max:255',
            'account_type' => 'required|string|max:255',
            'bank_name' => 'required|string|max:255',
            'bank_branch' => 'required|string|max:255',
            'PAN_PDF' => 'nullable|file|mimes:pdf|max:2048',
            'upload_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'upload_banner_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 400);
        }

        // DB::beginTransaction();
        // try {
        //     $vendor = vendor::findOrFail($id);

        //     $data = $request->except('_token', 'shop_name');

        //     // Handle file uploads
        //     if ($request->hasFile("PAN_PDF")) {
        //         $PAN_PDF_file = $this->uploadFile($request, 'PAN_PDF', 'image/vendor/');
        //         $data['PAN_PDF'] = $PAN_PDF_file;
        //     }
        //     if ($request->hasFile("upload_logo")) {
        //         $upload_logo_file = $this->uploadFile($request, 'upload_logo', 'image/vendor/');
        //         $data['upload_logo'] = $upload_logo_file;
        //     }
        //     if ($request->hasFile("upload_banner_image")) {
        //         $upload_banner_image_file = $this->uploadFile($request, 'upload_banner_image', 'image/vendor/');
        //         $data['upload_banner_image'] = $upload_banner_image_file;
        //     }

        //     // Update vendor data
        //     $vendor->update([
        //         'shop_name' => $request['shop_name'],
        //         'user_id' => $request['user_id'],
        //         'shop_data' => json_encode($data),
        //     ]);
        //     DB::commit();
        //     return response()->json(['message' => 'Vendor shop updated successfully.'], 200);
        // } catch (\Exception $e) {
        //     DB::rollback();
        //     Log::error($e->getMessage());
        //     return response()->json(['error' => $e->getMessage()], 500);
        // }

        DB::beginTransaction();
        try {
            $vendor = vendor::findOrFail($id);

            $data = $request->except('_token');

            // Handle file uploads
            if ($request->hasFile('PAN_PDF')) {
                $data['PAN_PDF'] = $request->file('PAN_PDF')->store('vendor_pdfs');
            }

            if ($request->hasFile('upload_logo')) {
                $data['upload_logo'] = $request->file('upload_logo')->store('vendor_logos');
            }

            if ($request->hasFile('upload_banner_image')) {
                $data['upload_banner_image'] = $request->file('upload_banner_image')->store('vendor_banners');
            }

            // Update vendor shop data
            $vendor->update($data);

            DB::commit();
            return response()->json(['message' => 'Vendor shop updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_vendor_shop/{id}",
     *     summary="View a specific vendor shop by ID",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves the details of a vendor shop by its ID.",
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Vendor shop ID",
     *         @OA\Schema(type="integer")
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor shop retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="shop_name", type="string", example="My Vendor Shop"),
     *                 @OA\Property(property="user_id", type="integer", example=1),
     *                 @OA\Property(property="add_by", type="integer", example=2),
     *                 @OA\Property(property="slug", type="string", example="1234abcd-5678"),
     *                 @OA\Property(property="shop_data", type="object",
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="johndoe@example.com"),
     *                     @OA\Property(property="phone_number", type="string", example="+919876543210"),
     *                     @OA\Property(property="website", type="string", example="https://example.com"),
     *                     @OA\Property(property="pincode", type="string", example="123456"),
     *                     @OA\Property(property="address", type="string", example="123 Main St"),
     *                     @OA\Property(property="latitude", type="string", example="10.123456"),
     *                     @OA\Property(property="longitude", type="string", example="76.543210"),
     *                     @OA\Property(property="city", type="string", example="New York"),
     *                     @OA\Property(property="state_province", type="string", example="NY"),
     *                     @OA\Property(property="country", type="string", example="USA"),
     *                     @OA\Property(property="description", type="string", example="Best vendor shop"),
     *                     @OA\Property(property="delivery", type="boolean", example=true),
     *                     @OA\Property(property="order_prepare_time", type="string", example="30 min")
     *                 ),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-27T10:30:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-03-27T12:00:00Z")
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Vendor shop not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Vendor shop not found.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */

    public function view_vendor_shop($id)
    {
        try {
            $users = User::with([
                'vendors' => function ($q) {
                    $q->where('vendors.active', 1);
                },
    
            ])
                ->where('users.user_type', 'vd')
                ->where('users.id', $id)
                ->get()
                ->map(function ($user) {
                    $products = Product::where('user_id', $user->id)->get();
                    $productsWithStats = $products->map(function ($product) {
                        $orders = Order_tracking::where('product_id', $product->id)
                            ->where('active', 1)
                            ->get();
                        return [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'total_orders' => $orders->count(),
                            'total_order_price' => $orders->sum('totel_price'),
                            'total_tax' => $orders->sum('tax_price'),
                            'total_order' => $orders->count(),
                        ];
                    });
                    $userTotalOrderPrice = $productsWithStats->sum('total_order_price');
                    $userTotalTaxFee = $productsWithStats->sum('total_tax');
                    $userTotalOrder = $productsWithStats->sum('total_order');
                    return [
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'city' => $user->city,
                        'state' => $user->state,
                        'country' => $user->country,
                        'active' => $user->active,
                        'add_by' => $user->add_by,
                        'balance_amount' => $user->balance_amount,
                        'cash_collected' => $user->balance_amount,
                        'create_by' => $user->create_by,
                        'shop' => $user->vendors->map(function ($vendor) {
                            $shopData = json_decode($vendor->shop_data, true) ?? [];
                            return [
                                'shop_id' => $vendor->id,
                                'shop_name' => $vendor->shop_name,
                                'add_by' => $vendor->add_by,
                                'slug' => $vendor->slug,
                                'shop_data' => $shopData,
                                'created_at' => $vendor->created_at,
                                'updated_at' => $vendor->updated_at,
                            ];
                        }),
                        'products' => $productsWithStats,
                        'total_user_order_price' => $userTotalOrderPrice,
                        'total_user_tax_fee' => $userTotalTaxFee,
                        'TotalOrder' => $userTotalOrder,
                    ];
                });
            $all_order = Order_tracking::where('active', 1)->get();
            $commission = user_payment_list::where('active', 1)->whereIn('order_id', [1, 2, 3])->get();
            // Log::error('loki');
            return response()->json([
                'success' => true,
                'data' => $users,
                'totel_order_value' => $all_order->sum('totel_price'),
                'totel_delivery_fee' => $all_order->sum('delivery_fee'),
                'totel_admin_commission' => $commission->sum('profit_share'),
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/view_all_vendor_shops",
     *     summary="View all vendor shops",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Retrieves the list of all vendor shops.",
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor shops retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="shop_name", type="string", example="My Vendor Shop"),
     *                     @OA\Property(property="user_id", type="integer", example=1),
     *                     @OA\Property(property="add_by", type="integer", example=2),
     *                     @OA\Property(property="slug", type="string", example="1234abcd-5678"),
     *                     @OA\Property(property="shop_data", type="object",
     *                         @OA\Property(property="name", type="string", example="John Doe"),
     *                         @OA\Property(property="email", type="string", example="johndoe@example.com"),
     *                         @OA\Property(property="phone_number", type="string", example="+919876543210"),
     *                         @OA\Property(property="website", type="string", example="https://example.com"),
     *                         @OA\Property(property="city", type="string", example="New York"),
     *                         @OA\Property(property="state_province", type="string", example="NY"),
     *                         @OA\Property(property="country", type="string", example="USA")
     *                     ),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-27T10:30:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-03-27T12:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */

    public function view_all_vendor_shops()
    {
        try {
            $vendors = vendor::where('active', 1)->get();
            $shops = $vendors->map(function ($vendor) {
                $shopData = json_decode($vendor->shop_data, true) ?? [];
                return [
                    'id' => $vendor->id,
                    'shop_name' => $vendor->shop_name,
                    'user_id' => $vendor->user_id,
                    'add_by' => $vendor->add_by,
                    'slug' => $vendor->slug,
                    'shop_data' => $shopData,
                    'created_at' => $vendor->created_at,
                    'updated_at' => $vendor->updated_at
                ];
            });
            return response()->json([
                'success' => true,
                'data' => $shops
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'An internal server error occurred.'
            ], 500);
        }
    }
    /**
     * @OA\Delete(
     *     path="/veri5d/api/delete_vendor_shop/{id}",
     *     summary="Soft delete a vendor shop by ID",
     *     tags={"Vendor"},
     *     security={{"bearerAuth": {}}},
     *     description="Soft deletes a vendor shop by setting `active` to 0.",
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Vendor shop ID",
     *         @OA\Schema(type="integer")
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Vendor shop deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Shop deleted successfully.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Vendor shop not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Category not found.")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Error message")
     *         )
     *     )
     * )
     */

    public function delete_vendor_shop($id)
    {
        $category = vendor::where('active', 1)->where('id', $id)->first();
        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }
        $user = Auth::user();
        DB::beginTransaction();
        try {
            $category->update([
                'active' => 0,
                'user_id' => $user->id,
            ]);
            DB::commit();
            return response()->json(['message' => 'shop deleted successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
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
    /**
     * Recursively format categories with subcategories
     */
    private function formatCategory($category)
    {
        $data = json_decode($category->description, true);
        return [
            'id' => $category->id,
            'name' => $category->name,
            'wishlist' => $data['wishlist'] ?? false,
            'visible_in_menu' => $data['visible_in_menu'] ?? false,
            'description' => $data['description'] ?? '',
            'banner_image' => $data['banner_image'] ?? null,
            'category_icon' => $data['category_icon'] ?? null,
            'hover_icon' => $data['hover_icon'] ?? null,
            'created_at' => $category->created_at->toDateTimeString(),
            'subcategories' => $category->subcategories->map(function ($subcategory) {
                return $this->formatCategory($subcategory);
            })
        ];
    }
}
