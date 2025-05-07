<?php

namespace App\Http\Controllers;

use App\Models\add_to_cart;
use App\Models\privilege;
use App\Models\product;
use App\Models\roll;
use App\Models\roll_privilege;
use App\Models\roll_user;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Models\user_payment_list;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Exception;

class test_controller extends Controller
{
    /**
     * @OA\Get(
     *     path="/veri5d/api/user_data",
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
    public function user_data()
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'No authenticated user found'], 401);
        }

        $findUserRoll = roll_user::where('active', 1)
            ->where('user_id', $user->id)
            ->first();

        if (!$findUserRoll) {
            return response()->json([
                'userRole' => null,
                'roll_under_user' => []
            ], 200);
        }

        $userRoll = roll::find($findUserRoll->id_roll);

        $rollUsers = roll_user::select(
            'roll_users.*',
            'users.name',
            'rolls.roll_name',
            'rolls.share'
        )
            ->leftJoin('rolls', 'roll_users.id_roll', '=', 'rolls.id')
            ->leftJoin('users', 'roll_users.user_id', '=', 'users.id')
            ->where('roll_users.active', 1)
            ->get()
            ->toArray();

        $flatRoles = $this->buildTreeRecursiveFlat($rollUsers, $user->id);
        $uniqueRoles = collect($flatRoles)->unique('id_roll')->values();

        // Revenue Metrics
        $totalRevenueData = user_payment_list::where('user_id', $user->id)
            ->where('active', 1)
            ->get();

        $userRoleData = [
            'roll_name'      => $userRoll->roll_name ?? null,
            'id_roll'        => $userRoll->id ?? null,
            'customers'      => $totalRevenueData->count(),
            'total_orders'   => $totalRevenueData->pluck('order_id')->unique()->count(),
            'vendors'        => $totalRevenueData->pluck('vendor_id')->unique()->count(),
            'total_revenue'  => $totalRevenueData->sum('profit_share'),
            'products'       => $totalRevenueData->pluck('product_id')->unique()->count(),
        ];

        return response()->json([
            'userRole' => $userRoleData,
            'roll_under_user' => $uniqueRoles
        ], 200);
    }
    private function buildTreeRecursiveFlat(array $items, $parentId, &$collected = [])
    {
        foreach ($items as $item) {
            if ($item['under_user'] == $parentId) {
                $collected[] = [
                    'roll_name' => $item['roll_name'],
                    'id_roll'   => $item['id_roll'],
                ];
                $this->buildTreeRecursiveFlat($items, $item['user_id'], $collected);
            }
        }
        return $collected;
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/buy_product",
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
    public function buy_product($id)
    {
        $user = Auth::user();
        $current = roll_user::where('user_id', $user->id)
            ->where('active', 1)
            ->first();
        if (!$current) {
            return response()->json(['message' => 'User not found or inactive'], 404);
        }
        $path = [];
        while ($current) {
            $path[] = [
                'user_id' => $current->user_id,
                'id_roll' => $current->id_roll,
                'under_user' => $current->under_user
            ];
            $current = roll_user::where('user_id', $current->under_user)->first();
        }
        $reversedPath = array_reverse($path);
        return response()->json($reversedPath);
    }
    /**
     * @OA\Get(
     *     path="/veri5d/api/user_order/{id}",
     *     summary="Get user order list",
     *     tags={"Users"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the role",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Users retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *                     @OA\Property(property="role_id", type="integer", example=1)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Role not found"
     *     )
     * )
     */

    public function user_order($id) {}
}
