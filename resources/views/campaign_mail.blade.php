<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Campaign Email</title>
        <style>
            /* Importing Google Font */
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap');

            /* Basic Reset */
            body, table, td, a {
                margin: 0;
                padding: 0;
                font-family: 'Inter', sans-serif;
            }

            /* Body Style */
            body {
                background-color: #f4f4f4;
                font-size: 16px;
                color: #333333;
            }

            /* Table Wrapper */
            .email-wrapper {
                width: 100%;
                background-color: #f4f4f4;
                padding: 30px 0;
            }

            /* Email Container */
            .email-container {
                width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                border-radius: 8px;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            }

            /* Header Style */
            .email-header {
                background-color: #4f46e5;
                padding: 20px 30px;
                text-align: center;
            }

            .email-header h1 {
                color: #ffffff;
                font-size: 24px;
                margin: 0;
            }

            /* Email Body */
            .email-body {
                padding: 30px;
                font-size: 16px;
                line-height: 1.6;
            }

            .email-body p {
                color: #333333;
                margin-bottom: 20px;
            }

            /* Footer */
            .email-footer {
                background-color: #f4f4f4;
                padding: 20px 30px;
                text-align: center;
            }

            .email-footer p {
                color: #999999;
                font-size: 12px;
                margin: 0;
            }

            /* Media Queries for mobile responsiveness */
            @media (max-width: 600px) {
                .email-container {
                    width: 100% !important;
                    padding: 10px;
                }

                .email-header h1 {
                    font-size: 20px !important;
                }

                .email-body {
                    padding: 20px;
                }

                .email-footer {
                    padding: 10px;
                }
            }
        </style>
    </head>
    <body>

        <table class="email-wrapper">
            <tr>
                <td align="center">
                    <table class="email-container">
                        <!-- Header -->
                        <tr>
                            <td class="email-header">
                                <h1>{{ $data['title'] }}</h1>
                            </td>
                        </tr>

                        <!-- Body Content -->
                        <tr>
                            <td class="email-body">
                                @php
                                    $find_user = DB::table('users')->where('id', $user_id)->first();
                                @endphp
                                <p>Hello {{ $find_user->name }},</p>
                                <p>{{ $data['body'] }}</p>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td class="email-footer">
                                <p>This message was sent to you as part of a notification campaign. If you have questions, contact us at <a href="mailto:support@Veri5d.com" style="color: #4f46e5; text-decoration: none;">support@Veri5d.com</a>.</p>
                            </td>
                        </tr>
                        <tr>
                            <td class="email-footer">
                                <p>© {{ \Carbon\Carbon::today()->format('Y') }} Veri5d. All rights reserved.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

    </body>
</html>