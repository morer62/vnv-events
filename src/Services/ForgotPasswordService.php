<?php

namespace App\Services;

use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use Exception;

class ForgotPasswordService
{
    public function sendResetLink(string $email): bool|string
    {
        try {
            $userRepository = new UserRepository();
            $passwordResetRepository = new PasswordResetRepository();
            $emailService = new EmailService();
            
            $user = $userRepository->getOne(['email' => $email]);

            if (!$user) {
                return false;
            }

            if (!empty($user->google_id)) {
                return "google_account";
            }
            
            if (!empty($user->apple_id)) {
                return "apple_account";
            }

            $token = bin2hex(random_bytes(32));
            $resetLink = ($_ENV["APP_URL"] ?? "https://vnvevents.com/") . "/reset-password?token=" . $token;

            $passwordResetRepository->add([
                "email" => $email,
                "token" => $token,
                "expires_at" => date("Y-m-d H:i:s", strtotime("+30 minutes"))
            ]);

            $subject = "Reset Your Password - VNV Events";
            
            $body = '
                <html>
                <head>
                    <style>
                        body {
                            background-color: #f4f4f4;
                            margin: 0;
                            padding: 0;
                            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
                            color: #333;
                        }
                        .email-wrapper {
                            max-width: 600px;
                            margin: 40px auto;
                            background-color: #ffffff;
                            padding: 30px;
                            border-radius: 8px;
                            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
                        }
                        .email-title {
                            font-size: 22px;
                            font-weight: bold;
                            margin-bottom: 20px;
                            color: #111;
                        }
                        .email-text {
                            font-size: 16px;
                            line-height: 1.6;
                        }
                        .btn {
                            display: inline-block;
                            background-color: #000;
                            color: #ffffff !important;
                            padding: 12px 24px;
                            margin: 20px 0;
                            border-radius: 5px;
                            text-decoration: none;
                            font-weight: bold;
                        }
                        .footer {
                            text-align: center;
                            font-size: 13px;
                            color: #777;
                            margin-top: 40px;
                        }
                        .footer a {
                            color: #777;
                            text-decoration: underline;
                        }
                    </style>
                </head>
                <body>
                    <div class="email-wrapper">
                        <div class="email-title">🔐 Password Reset Request</div>
                        <div class="email-text">
                            <p>Hello,</p>
                            <p>We received a request to reset your password. To proceed, click the button below:</p>
                            <p><a href="' . $resetLink . '" class="btn">Reset My Password</a></p>
                            <p>If you didn\'t request this, you can safely ignore this message.</p>
                            <p>— VNV Events Team</p>
                        </div>
                    </div>
                    
                    <div class="footer">
                        © ' . date("Y") . ' VNV Events | <a href="' . ($_ENV["APP_URL"] ?? "https://vnvevents.com/") . '">' . ($_ENV["APP_URL"] ?? "https://vnvevents.com/") . '</a><br>
                        Planning made easier ✨
                    </div>
                </body>
                </html>
            ';

                return $emailService->sendSimpleEmail($email, $subject, $body, true);
                
            } catch (Exception $e) {
                throw new Exception("ForgotPasswordService failed: " . $e->getMessage());
            }
    }
}
