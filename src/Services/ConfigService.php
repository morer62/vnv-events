<?php

namespace App\Services;

class ConfigService
{
    // Application Configuration
    public static string $APP_ENV = "";
    public static string $APP_URL = "";
    public static string $VNV_SECRET_KEY = "";
    
    // Database Configuration
    public static string $DATABASE_URL = "";
    
    // Stripe Configuration
    public static string $STRIPE_KEY = "";
    public static string $STRIPE_PUBLIC = "";
    public static string $STRIPE_BASE = "";
    public static string $STRIPE_CONTRACTS_ENABLED = "";

    public static string $STRIPE_SUPPORT_URL = '';
    public static string $STRIPE_SUPPORT_EMAIL = '';
    public static string $STRIPE_BUSINESS_NAME= '';
    public static string $STRIPE_PLATFORM_ACCOUNT_ID = '';
    
    // Square Configuration
    public static string $SQUARE_ACCESS_TOKEN = "";
    public static string $SQUARE_APPLICATION_ID = "";
    public static string $SQUARE_LOCATION_ID = "";
    public static string $SQUARE_ENVIRONMENT = "";
    public static string $SQUARE_BASE = "";
    public static string $SQUARE_PUBLIC = "";
    
    public static string $SQUARE_SUPPORT_URL = '';
    public static string $SQUARE_SUPPORT_EMAIL = '';
    public static string $SQUARE_BUSINESS_NAME = '';
    // SQUARE_PLATFORM_ACCOUNT_ID no es necesario - Square no usa este concepto como Stripe
    
    // Google Configuration
    public static string $GOOGLE_KEY = "";
    public static string $GOOGLE_CLIENT_ID = "";
    public static string $GOOGLE_CLIENT_SECRET = "";
    public static string $ADMIN_GOOGLE_TOKEN = "";
    
    // CORS Configuration
    public static string $CORS_ALLOWED_ORIGINS = "";
    
    // Membership Configuration
    public static string $FREE_MEMBERSHIP_DAYS = "";
    public static string $MEMBERSHIP_VALUE = "";
    public static string $MEMBERSHIP_ANNUAL_VALUE = "";
    
    // Payment Configuration
    public static string $VENUE_PAYMENT_AMOUNT = "";
    public static string $SERVICE_PAYMENT_AMOUNT_PER_ZIP = "";
    
    // Twilio Configuration
    public static string $TWILIO_ID = "";
    public static string $TWILIO_TOKEN = "";
    public static string $TWILIO_NUMBER = "";
    
    // OpenAI Configuration
    public static string $OPENAI_TOKEN = "";
    
    // Cloudinary Configuration
    public static string $CLOUDINARY_CLOUD_NAME = "";
    public static string $CLOUDINARY_API_KEY = "";
    public static string $CLOUDINARY_API_SECRET = "";
    
    // Wasabi Configuration
    public static string $WASABI_BUCKET = "";
    public static string $WASABI_KEY = "";
    public static string $WASABI_SECRET = "";

    // Apple Configuration
    public static string $APPLE_SERVICE_ID = "";
    public static string $APPLE_KEY_ID = "";
    public static string $APPLE_TEAM_ID = "";
    public static string $APPLE_SECRET_NAME = "";
    public static string $APPLE_SIGN_IN_URL = "";

    public static string $APPLE_REDIRECT_SIGN_UP_URL = "";
    public static string $APPLE_REDIRECT_SIGN_IN_URL = "";
    public static string $APPLE_REDIRECT_CONNECT_ACCOUNT = "";

    public static function init(): void
    {
        // Application Configuration
        self::$APP_ENV = $_ENV['APP_ENV'] ?? 'debug';
        self::$APP_URL = $_ENV['APP_URL'] ?? 'http://localhost:8080/vnv-venues/';
        self::$VNV_SECRET_KEY = $_ENV['VNV_SECRET_KEY'];
        
        // Database Configuration
        self::$DATABASE_URL = $_ENV['DATABASE_URL'];
        
        // Stripe Configuration
        self::$STRIPE_KEY = $_ENV['STRIPE_KEY'];
        self::$STRIPE_PUBLIC = $_ENV['STRIPE_PUBLIC'];
        self::$STRIPE_BASE = $_ENV['STRIPE_BASE'];
        self::$STRIPE_CONTRACTS_ENABLED = $_ENV['STRIPE_CONTRACTS_ENABLED'] ?? false;
        self::$STRIPE_SUPPORT_URL = $_ENV['STRIPE_SUPPORT_URL'];
        self::$STRIPE_SUPPORT_EMAIL = $_ENV['STRIPE_SUPPORT_EMAIL'];
        self::$STRIPE_BUSINESS_NAME = $_ENV['STRIPE_BUSINESS_NAME'];
        
        // Square Configuration
        self::$SQUARE_ACCESS_TOKEN = $_ENV['SQUARE_ACCESS_TOKEN'] ?? '';
        self::$SQUARE_APPLICATION_ID = $_ENV['SQUARE_APPLICATION_ID'] ?? '';
        self::$SQUARE_LOCATION_ID = $_ENV['SQUARE_LOCATION_ID'] ?? '';
        self::$SQUARE_ENVIRONMENT = $_ENV['SQUARE_ENVIRONMENT'] ?? 'sandbox';
        self::$SQUARE_BASE = $_ENV['SQUARE_BASE'] ?? 'https://connect.squareup.com';
        self::$SQUARE_PUBLIC = $_ENV['SQUARE_PUBLIC'] ?? '';
        self::$SQUARE_SUPPORT_URL = $_ENV['SQUARE_SUPPORT_URL'] ?? '';
        self::$SQUARE_SUPPORT_EMAIL = $_ENV['SQUARE_SUPPORT_EMAIL'] ?? '';
        self::$SQUARE_BUSINESS_NAME = $_ENV['SQUARE_BUSINESS_NAME'] ?? '';
        // SQUARE_PLATFORM_ACCOUNT_ID no se usa - Square maneja comisiones de manera diferente
        
        // Google Configuration
        self::$GOOGLE_KEY = $_ENV['GOOGLE_KEY'];
        self::$GOOGLE_CLIENT_ID = $_ENV['GOOGLE_CLIENT_ID'];
        self::$GOOGLE_CLIENT_SECRET = $_ENV['GOOGLE_CLIENT_SECRET'];
        self::$ADMIN_GOOGLE_TOKEN = $_ENV['ADMIN_GOOGLE_TOKEN'];
        
        // CORS Configuration
        self::$CORS_ALLOWED_ORIGINS = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '/^(http:\/\/localhost(:[0-9]+)?)$/';
        
        // Membership Configuration
        self::$FREE_MEMBERSHIP_DAYS = $_ENV['FREE_MEMBERSHIP_DAYS'] ?? 14;
        self::$MEMBERSHIP_VALUE = $_ENV['MEMBERSHIP_VALUE'] ?? 18;
        self::$MEMBERSHIP_ANNUAL_VALUE = $_ENV['MEMBERSHIP_ANNUAL_VALUE'] ?? 36;
        
        // Payment Configuration
        self::$VENUE_PAYMENT_AMOUNT = $_ENV['VENUE_PAYMENT_AMOUNT'] ?? 6.99;
        self::$SERVICE_PAYMENT_AMOUNT_PER_ZIP = $_ENV['SERVICE_PAYMENT_AMOUNT_PER_ZIP'] ?? 6.99;
        
        // Twilio Configuration
        self::$TWILIO_ID = $_ENV['TWILIO_ID'];
        self::$TWILIO_TOKEN = $_ENV['TWILIO_TOKEN'];
        self::$TWILIO_NUMBER = $_ENV['TWILIO_NUMBER'];
        
        // OpenAI Configuration
        self::$OPENAI_TOKEN = $_ENV['OPENAI_TOKEN'];
        
        // Cloudinary Configuration
        self::$CLOUDINARY_CLOUD_NAME = $_ENV['CLOUDINARY_CLOUD_NAME'];
        self::$CLOUDINARY_API_KEY = $_ENV['CLOUDINARY_API_KEY'];
        self::$CLOUDINARY_API_SECRET = $_ENV['CLOUDINARY_API_SECRET'];
        
        // Wasabi Configuration
        self::$WASABI_BUCKET = $_ENV['WASABI_BUCKET'];
        self::$WASABI_KEY = $_ENV['WASABI_KEY'];
        self::$WASABI_SECRET = $_ENV['WASABI_SECRET'];


        // Apple Configuration
        self::$APPLE_SERVICE_ID = $_ENV['APPLE_SERVICE_ID'];
        self::$APPLE_KEY_ID = $_ENV['APPLE_KEY_ID'];
        self::$APPLE_TEAM_ID = $_ENV['APPLE_TEAM_ID'];
        self::$APPLE_SECRET_NAME = $_ENV['APPLE_SECRET_NAME'];
        self::$APPLE_SIGN_IN_URL = $_ENV['APPLE_SIGN_IN_URL'];

        self::$APPLE_REDIRECT_SIGN_UP_URL = $_ENV['APPLE_REDIRECT_SIGN_UP_URL'];
        self::$APPLE_REDIRECT_SIGN_IN_URL = $_ENV['APPLE_REDIRECT_SIGN_IN_URL'];
        self::$APPLE_REDIRECT_CONNECT_ACCOUNT = $_ENV['APPLE_REDIRECT_CONNECT_ACCOUNT'];
    }
}