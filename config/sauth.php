<?php

/**
 * Social Login Extension Configuration
 *
 * Configuration settings for social authentication providers.
 * You can customize these settings based on your application needs.
 */

return [
    // General settings
    'enabled_providers' => ['google', 'facebook', 'github', 'apple'],
    'auto_register' => true,  // Automatically create user accounts for new social logins
    'link_accounts' => true,  // Allow linking social accounts to existing users
    'sync_profile' => true,   // Sync profile data from social providers
    'post_registration' => [
        // Disabled by default; apps opt in explicitly.
        'enabled' => false,
        // Handler can be:
        // - invokable class-string (resolved via app($context, ClassName::class))
        // - any callable
        // Signature: function (string $userUuid, array $socialData, ApplicationContext $context): void
        'handler' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Mapping (Social payload -> canonical keys)
    |--------------------------------------------------------------------------
    | Configure which social payload keys map to canonical values used by
    | Entrada. First non-empty value wins.
    */
    'field_mapping' => [
        'social' => [
            'uuid' => ['id'],
            'email' => ['email'],
            'username' => ['username', 'login'],
            'first_name' => ['first_name', 'given_name'],
            'last_name' => ['last_name', 'family_name'],
            'photo_url' => ['photo_url', 'picture', 'avatar_url'],
            'email_verified' => ['verified_email', 'email_verified'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Mapping (canonical keys -> DB columns)
    |--------------------------------------------------------------------------
    | Use this when your application uses different table/column names.
    | Defaults match Glueful api-skeleton schema.
    */
    'storage' => [
        'users' => [
            'table' => 'users',
            'columns' => [
                'uuid' => 'uuid',
                'username' => 'username',
                'email' => 'email',
                'password' => 'password',
                'status' => 'status',
                'created_at' => 'created_at',
                'email_verified_at' => 'email_verified_at',
            ],
            'defaults' => [
                'status' => 'active',
                'password' => null,
            ],
        ],
        'profiles' => [
            'table' => 'profiles',
            'columns' => [
                'uuid' => 'uuid',
                'user_uuid' => 'user_uuid',
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'photo_url' => 'photo_url',
                'status' => 'status',
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
            ],
            'defaults' => [
                'status' => 'active',
            ],
        ],
    ],

    // Google OAuth settings
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
    ],

    // Facebook OAuth settings
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID', ''),
        'app_secret' => env('FACEBOOK_APP_SECRET', ''),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI', ''),
    ],

    // GitHub OAuth settings
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID', ''),
        'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
        'redirect_uri' => env('GITHUB_REDIRECT_URI', ''),
    ],

    // Apple OAuth settings
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', ''),
        'client_secret' => env('APPLE_CLIENT_SECRET', ''),
        'team_id' => env('APPLE_TEAM_ID', ''),
        'key_id' => env('APPLE_KEY_ID', ''),
        'redirect_uri' => env('APPLE_REDIRECT_URI', ''),
    ],
];
