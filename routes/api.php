<?php

use App\Http\Controllers\admin_controller;
use App\Http\Controllers\auth_controller;
use App\Http\Controllers\test_controller;
use App\Http\Controllers\user_controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\vendore_controller;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/shipment_create', [vendore_controller::class, 'createShipment']);
    Route::post('/shipment_reject', [vendore_controller::class, 'rejectShipment']);
    Route::post('/shipment_track', [vendore_controller::class, 'trackShipment']);
    Route::post('/shipment_manifest', [vendore_controller::class, 'generateManifest']);
    Route::post('/shipment_cancel', [vendore_controller::class, 'cancelShipment']);
    Route::get('/wallet_balance', [vendore_controller::class, 'walletBalance']);
    Route::post('/serviceability', [vendore_controller::class, 'rateServiceability']);
    Route::controller(admin_controller::class)->group(function () {
        Route::get('/view_vendor_order', 'view_vendor_order');
        Route::get('/list_all_notification', 'list_all_notification');
        Route::get('/list_all_paying_notification', 'list_all_paying_notification');
        Route::get('/delete_notification/{id}', 'delete_notification/{id}');
        Route::post('/sendNotificationMulti', 'sendNotificationMulti');
        Route::post('/create_vendor_with_shop', 'create_vendor_with_shop');
        Route::post('/update_vendor_with_shop/{vendorId}', 'update_vendor_with_shop');


        Route::get('/list_user_notification', 'list_user_notification');


        Route::post('/payment_request', 'payment_request');
        Route::post('/paying_for_user', 'paying_for_user');
        Route::get('/list_all_request', 'list_all_request');
        Route::get('/list_my_request', 'list_my_request');
        Route::get('/list_user_code', 'list_user_code');
        Route::post('/edit_payment_request/{id}', 'edit_payment_request');
        Route::post('/edit_user_payment_inprogress/{id}', 'edit_user_payment_inprogress');
        // Route::post('/edit_payment_request/{id}', 'edit_payment_request'); 
        Route::post('/add_campaign', 'add_campaign');
        Route::get('/delete_campaign/{id}', 'delete_campaign');
        Route::post('/edit_campaign/{id}', 'edit_campaign');
        Route::get('/list_campaign', 'list_campaign');
        Route::post('/update-device-token', 'updateDeviceToken');
        Route::post('/send-notification', 'sendNotification');
        Route::get('/view_all_user_order', 'view_all_user_order');
        Route::get('/payouts_requests', 'payouts_requests');
        Route::get('/manager_list', 'manager_list');
        Route::get('/reporting_to_list/{id}', 'reporting_to_list');
        Route::get('/received_profit', 'received_profit');
        Route::get('/accounting_taxs', 'accounting_taxs');
        Route::get('/all_product_review', 'all_product_review');
        Route::get('/all_product_orders', 'all_product_orders');
        Route::post('/approval_for_vendors', 'approval_for_vendors');
        Route::post('/reject_for_vendors', 'reject_for_vendors');
        Route::post('/edit_tax/{id}', 'edit_tax');
        Route::get('/delete_tax/{id}', 'delete_tax');
        Route::post('/create_tax', 'create_tax');
        Route::get('/view_tax', 'view_tax');
        Route::post('/edit_employee/{id}', 'edit_employee');
        Route::get('/list_user_data/{id}', 'list_user_data');
        Route::get('/listing_user_role_data/{id}', 'listing_user_role_data');
        Route::post('/add_employee', 'add_employee');
        Route::post('/add_testv', 'add_testv');
        Route::post('/add_coupon', 'add_coupon');
        Route::post('/edit_coupon/{id}', 'edit_coupon');
        Route::get('/view_all_coupons', 'view_all_coupons');
        Route::get('/delete_coupon/{id}', 'delete_coupon');
        Route::delete('/delete_roles/{id}', 'delete_roles');
        Route::post('/add_category', 'add_category');
        Route::post('/edit_category/{id}', 'edit_category');
        Route::delete('/delete_category/{id}', 'delete_category');
        Route::post('/add_vendor_shop', 'add_vendor_shop');
        Route::post('/edit_vendor_shop/{id}', 'edit_Vendor_shop');
        Route::get('/view_vendor_shop/{id}', 'view_vendor_shop');
        Route::get('/view_all_vendor_shops', 'view_all_vendor_shops');
        Route::delete('/delete_vendor_shop/{id}', 'delete_vendor_shop');
        Route::get('/view_all_vendors_with_shop', 'view_all_vendors_with_shop');
        Route::get('/view_all_vendors', 'view_all_vendors');
        Route::post('/add_vendor', 'add_vendor');
        Route::put('/edit_vendor/{id}', 'edit_vendor');
        Route::get('/view_vendor/{id}', 'view_vendor');
        Route::delete('/delete_vendor/{id}', 'delete_vendor');
        Route::get('/view_all_variant', 'view_all_variant');
        Route::post('/add_variant', 'add_variant');
        Route::put('/edit_variant/{id}', 'edit_variant');
        Route::get('/view_variant/{id}', 'view_variant');
        Route::delete('/delete_variant/{id}', 'delete_variant');
        Route::get('/view_all_brand', 'view_all_brand');
        Route::post('/add_brand', 'add_brand');
        Route::put('/edit_brand/{id}', 'edit_brand');
        Route::get('/view_brand/{id}', 'view_brand');
        Route::delete('/delete_brand/{id}', 'delete_brand');
        Route::post('/add_product', 'add_product');
        Route::post('/edit_product/{id}', 'edit_product');
        Route::delete('/delete_products/{id}', 'delete_products');
        Route::get('/list_privelage', 'list_privelage');
        Route::get('/view_user_role', 'view_user_role');
        Route::post('/role_user', 'role_user');
        Route::get('/view_all_roles', 'view_all_roles');
        Route::post('/edit_role_privelage/{id}', 'edit_role_privelage');
        Route::post('/add_role_privelage', 'add_role_privelage');
        Route::get('/list_vendor_products_min/{id}', 'list_vendor_products_min');
        Route::get('/list_vendor_products/{id}', 'list_vendor_products');
        Route::post('/add_banner', 'add_banner');
        Route::post('/update_banner/{id}', 'update_banner');
        Route::get('/delete_banner/{id}', 'delete_banner');
        Route::get('/view_all_employees', 'view_all_employees');
        Route::post('/revenue_list', 'revenue_list');
        Route::get('/view_all_customer', 'view_all_customer');
    });
});
Route::controller(admin_controller::class)->group(function () {
    Route::get('/view_category/{id}', 'view_category');
    Route::get('/view_all_categories', 'view_all_categories');
    Route::get('/view_all_subcategories', 'view_all_subcategories');
    Route::get('/view_all_categories_list', 'view_all_categories_list');
    Route::get('/view_product/{id}', 'view_product');
    Route::get('/view_all_products', 'view_all_products');
    Route::get('/list_products_category/{id}', 'list_products_category');
    Route::get('/view_all_banners', 'view_all_banners');
    Route::get('/list_dashboard_products', 'list_dashboard_products');
    Route::post('/register_vendor_with_shop', 'register_vendor_with_shop');
});
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::controller(test_controller::class)->group(function () {
    Route::get('/buildTree', 'index');
});
Route::middleware('auth:sanctum')->group(function () {
    Route::controller(auth_controller::class)->group(function () {
        Route::get('/test', 'test');
        Route::get('/logout', 'logout');
    });
    Route::controller(admin_controller::class)->group(function () {
        Route::get('/find_coupon/{code}', 'find_coupon');
    });
    Route::controller(test_controller::class)->group(function () {
        Route::get('/user_data', 'user_data');
        Route::get('/show_user_under_role/{id}', 'show_user_under_role');
    });
    Route::controller(user_controller::class)->group(function () {
        Route::post('/add_to_cart', 'add_to_cart');
        Route::post('/delete_to_cart', 'delete_to_cart');
        Route::get('/view_user_cart', 'view_user_cart');
        Route::post('/edit_profile', 'edit_profile');
        Route::post('/add_order', 'add_order');
        Route::get('/view_order/{id}', 'view_order');
        Route::get('/view_all_orders', 'view_all_orders');
        Route::put('/cancel_order/{id}', 'cancel_order');
        Route::put('/ship_order/{id}', 'ship_order');
        Route::post('/deliver_order/{id}', 'deliver_order');
        Route::put('/return_order/{id}', 'return_order');
        Route::post('/edit_address', 'edit_address');
        Route::post('/remove_address', 'remove_address');
        Route::post('/change_password', 'change_password');
        Route::post('/add_product_reviews', 'add_product_reviews');
        Route::get('/delete_product_reviews/{id}', 'delete_product_reviews');
    });
    Route::prefix('phonepe')->group(function () {
        Route::post('/pay', [user_controller::class, 'phonePe'])->name('api.phonepe.pay');
        Route::match(['POST', 'GET'], '/response', [user_controller::class, 'response'])->name('api.phonepe.response');
        Route::post('/refund', [user_controller::class, 'refundProcess'])->name('api.phonepe.refund');
    });
});
Route::controller(auth_controller::class)->group(function () {
    Route::post('/register', 'register');
    Route::post('/register_otp', 'register_otp');
    Route::post('/login', 'login');
    Route::get('/email', 'email');
    Route::post('/email_dd', 'email_dd');
    Route::post('/forget_password', 'forget_password');
    Route::post('/forget_password_reset', 'forget_password_reset');
});
Route::controller(user_controller::class)->group(function () {
    Route::post('/payment/orders', 'createOrder');
    Route::get('/order-details/{order_id}', 'getOrderDetails');
    Route::post('/refund/{payment_id}', 'refundPayment');
    Route::get('/refund-details/{refund_id}', 'getRefundDetails');
});















