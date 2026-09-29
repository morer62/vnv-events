<?php

return [
    'default_mode' => 'REVIEW_BEFORE_PUBLISH',
    'timezone' => 'America/New_York',
    'cadence_days' => [0, 2, 4],
    'brands' => [
        'miamitechlab' => [
            'enabled' => true,
            'name' => 'Miami Tech Lab',
            'site_key' => 'miamitechlab',
            'owner_id' => 2,
            'website' => 'https://techlabmiami.com',
            'blog_path' => '/blog/',
            'voice' => 'Educational, technological, accessible, practical, business-oriented and community-focused. Avoid generic AI hype.',
            'linkedin_label' => 'Miami Tech Lab',
            'facebook_label' => 'Miami Tech Lab',
        ],
        'vnvevents' => [
            'enabled' => true,
            'name' => 'VNV Events',
            'site_key' => 'vnvevents',
            'owner_id' => 2,
            'website' => 'https://vnvevents.com',
            'blog_path' => '/blog/',
            'voice' => 'Professional, experienced, practical, visually aware, customer-oriented and event-focused. Never invent services.',
            'linkedin_label' => 'VNV Events',
            'facebook_label' => 'VNV Events',
            'aliases' => ['BnB Events'],
        ],
        'avomeal' => [
            'enabled' => false,
            'name' => 'The Pasta Station',
            'site_key' => 'avomeal',
            'owner_id' => 2,
            'website' => 'https://thepastastation.net',
            'blog_path' => '/blog/',
            'voice' => 'Warm, useful and grounded in the approved pasta offering.',
        ],
    ],
];
