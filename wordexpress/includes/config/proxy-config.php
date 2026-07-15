<?php
/**
 * Proxy Configuration
 *
 * Simplified configuration with inheritance and defaults
 *
 * @package Zeroy
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    // Global configuration
    '_global' => [
        'base_urls' => [
            'production' => 'https://www.zeroy.app/api',
            'development' => 'http://localhost:5173/api',
            'staging' => 'https://zeroy-staging.yansir.workers.dev/api'
        ],
        'default_auth' => 'bearer',
        'default_token_source' => 'settings:zeroy_settings[api_token]',
        'default_headers' => [
            'Content-Type' => 'application/json'
        ],
        'default_timeout' => 60
    ],

    // AI Service
    'ai' => [
        'name' => 'ai',
        // Inherits base_urls from _global
        'defaults' => [
            'method' => 'POST',
            // Inherits auth, headers, timeout from _global
        ],
        'endpoints' => [
            'template' => [
                'path' => '/zeroy-ai/template'
            ],
            'assistant_workflow' => [
                'path' => '/zeroy-ai/assistant/workflow'
            ],
            'models' => [
                'path' => '/zeroy-ai/models',
                'timeout' => 30
            ],
            'validate' => [
                'path' => '/zeroy-ai/validate',
                'timeout' => 10
            ],
            'assistant' => [
                'path' => '/zeroy-ai/assistant',
                'timeout' => 600,
                'stream' => true
            ],
            'abort' => [
                'path' => '/zeroy-ai/abort/{request_id}',
                'method' => 'POST',
                'timeout' => 10
            ],
            'design' => [
                'path' => '/zeroy-ai/design',
                'method' => 'POST',
                'timeout' => 600,
                'stream' => true
            ]
        ]
    ],

    // CSS Compiler Service
    'css_compiler' => [
        'name' => 'css_compiler',
        'defaults' => [
            'method' => 'POST'
        ],
        'endpoints' => [
            'compile' => [
                'path' => '/css-compiler/compile',
                'cache' => [
                    'enabled' => false  // 禁用缓存，因为内容经常变化
                ]
            ]
        ]
    ],

    // Auth Service
    'auth' => [
        'name' => 'auth',
        'endpoints' => [
            'verify_token' => [
                'path' => '/apitokens/verify',
                'method' => 'POST',
                'auth' => false, // No auth required
                'timeout' => 10
            ]
        ]
    ],

    // Google Fonts Service
    'google_fonts' => [
        'name' => 'google_fonts',
        'defaults' => [
            'method' => 'GET',
            'cache' => [
                'enabled' => true,
                'ttl' => 900 // 15 minutes default
            ]
        ],
        'endpoints' => [
            'list' => [
                'path' => '/google-fonts/list'
            ],
            'search' => [
                'path' => '/google-fonts/search'
            ],
            'detail' => [
                'path' => '/fonts/{family}',
                'cache' => [
                    'ttl' => 3600 // 1 hour for font details
                ]
            ]
        ]
    ]
];