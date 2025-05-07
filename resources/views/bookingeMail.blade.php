<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Email Template</title>
    <style>
      body {
        font-family: Arial, sans-serif;
        background: #f4f4f4;
        margin: 0;
        padding: 0;
      }
      .email-wrapper {
        background: #ffffff;
        max-width: 600px;
        margin: 40px auto;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
      }
      .header {
        text-align: center;
        padding-bottom: 20px;
      }
      .header h2 {
        color: #333333;
      }
      .content {
        font-size: 16px;
        color: #555555;
        line-height: 1.6;
      }
      .product-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        margin-bottom: 20px;
      }
      .product-table th {
        background-color: #4f46e5;
        color: #ffffff;
        padding: 12px;
        font-size: 14px;
        text-align: left;
      }
      .product-table td {
        padding: 12px;
        border-bottom: 1px solid #dddddd;
        font-size: 14px;
        color: #333333;
      }
      .footer {
        text-align: center;
        font-size: 12px;
        color: #999999;
        margin-top: 40px;
      }
      @media (max-width: 600px) {
        .email-wrapper {
          padding: 20px;
        }
        .product-table th, .product-table td {
          font-size: 12px;
          padding: 8px;
        }
      }
    </style>
  </head>
  <body>
    @php
        $find_order = DB::table('order_trackings')->where('additional_information->order_id', $data)->get();
        $find_user = DB::table('users')->where('id', $user_id)->first();
    @endphp
    <div class="email-wrapper">
      <div class="header">
        <h2>Your Order Has Been Booked!</h2>
      </div>
      <div class="content">
        {{$find_user->name}},<br><br>
        Thank you for booking your property deal with Veri5d. Here are the details of your order:
        <ul>
          <li><strong>Order ID:</strong> {{$data}}</li>
          <li><strong>Property:</strong> {{$find_order->shipping_details}}</li>
          <li><strong>Date:</strong> {{ \Carbon\Carbon::today()->format('Y-m-d') }}</li>
        </ul>

        <h3>Product Details</h3>
        <table class="product-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Product</th>
              <th>Price</th>
              <th>Quantity</th>
            </tr>
          </thead>
          <tbody>
            @foreach($find_order as $index => $product)
            @php
              $produt_name = DB::table('products')->where('id', $product['product_id'])->first();
            @endphp
              <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $produt_name->name }}</td>
                <td>${{ number_format($product['order_price'], 2) }}</td>
                <td>{{ $product['quantity'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>

        You will receive another update once it’s confirmed by the seller.
        <br><br>
      </div>
      <div class="footer">
        Need help? Contact support@Veri5d.com
      </div>
    </div>
  </body>
</html>
