<?php

namespace App\Services;

final class VnvProductionCatalog
{
    public const FEE_INCLUDES = 'Your production tier has a fixed fee. It covers your producer, the run of show, event-day coordination and the VNV-Q event system.';
    public const DIRECT_COSTS = 'We quote materials, fabrication, rentals, extra staff, installation, technical services and third-party costs separately. You approve those costs before we commit to them.';
    public const OVERTIME = '$220 per hour';

    public static function services(): array
    {
        $services = [
            'day-coordinator' => [
                'name' => 'Day Coordinator', 'tiers' => ['basic' => 780, 'gold' => 990, 'premium' => 1290],
                'summary' => 'Hand over the schedule you have planned so a coordinator can run your event day.',
                'route' => 'event-planners', 'image' => 'assets/images/planner3.jpg',
            ],
            'event-planner' => [
                'name' => 'Event Planner', 'tiers' => ['basic' => 1950, 'gold' => 2650, 'premium' => 3250],
                'summary' => 'Choose a planning package for ongoing guidance, vendor coordination and personal attention.',
                'route' => 'event-planners', 'image' => 'assets/images/planner2.jpg',
            ],
            'production-essential' => [
                'name' => 'Production Essential', 'price' => '$1,950',
                'summary' => 'Bring us a defined assignment, such as the guest arrival plan or a speaker program. The proposal identifies the deliverable, schedule, handoffs and coverage.',
                'route' => 'event-production', 'image' => 'assets/images/corp2.jpg',
            ],
            'full-event-production' => [
                'name' => 'Full Event Production', 'price' => '$3,500',
                'summary' => 'One day at one venue, with up to 300 guests, up to 10 sponsors and no paid ticketing. VNV provides catering, sound and staffing.',
                'route' => 'event-production', 'image' => 'assets/images/productions/hero-productions.jpg',
            ],
            'full-event-production-plus' => [
                'name' => 'Full Event Production Plus', 'price' => '$4,500',
                'summary' => 'Choose Plus for a single-day, single-venue event with 301 to 1,000 guests, more than 10 sponsors, paid ticketing or an outside provider for catering, sound or staffing.',
                'route' => 'event-production', 'image' => 'assets/images/corp4.jpg',
            ],
            'advanced-production' => [
                'name' => 'Advanced & Large-Scale Production', 'price' => 'From $6,500',
                'summary' => 'For events spanning multiple days, using multiple venues or welcoming more than 1,000 guests. We quote one fixed fee for your project.',
                'route' => 'event-production', 'image' => 'assets/images/corp3.jpg',
            ],
            'brand-activation' => [
                'name' => 'Brand Activation Management', 'price' => '$1,500 for the first event day + $500 for each additional day',
                'summary' => 'We coordinate your standalone activation from concept and installation through on-site delivery. When VNV produces your event, its activation is included in the production fee.',
                'route' => 'sponsor-activation-production', 'image' => 'assets/video/production-films/v4/trade-show-booth.webp',
            ],
            'sponsorship-opportunities' => [
                'name' => 'Sponsorship Opportunities', 'price' => 'Custom pricing',
                'summary' => 'Sponsorship rights and benefits are priced for the specific event, audience, available inventory and organizer approval.',
                'route' => 'sponsor-activation-production', 'image' => 'assets/images/case-studies/victoria-venezuela-2026-P4794.webp',
            ],
            'vnv-q-demo' => [
                'name' => 'VNV-Q Event Software', 'price' => 'Included within the agreed scope',
                'summary' => 'Your producers, guest desk and partners share the tools and permissions specified in your agreement.',
                'route' => 'vnv-support', 'image' => 'assets/video/production-films/v8/cuebook.webp',
            ],
        ];

        foreach ($services as $slug => &$service) {
            if (isset($service['tiers'])) {
                $service['price'] = 'Starting at $' . number_format($service['tiers']['basic']);
            }
            $service['request_service'] = $slug;
        }
        unset($service);
        return $services;
    }

    public static function phases(): array
    {
        return [
            ['name' => 'Discover', 'summary' => 'Identify your audience, purpose, budget and the decision that will make the event worth doing.'],
            ['name' => 'Design the Scope', 'summary' => 'Agree on deliverables, priorities, approvals and the people responsible for each part.'],
            ['name' => 'Build the System', 'summary' => 'Select vendors, prepare the run of show and give your team its schedule, tasks and guest workflow.'],
            ['name' => 'Produce the Event', 'summary' => 'Lead setup, brief the crew, call the program and adapt when timing changes.'],
            ['name' => 'Close & Recap', 'summary' => 'Reconcile the work, deliver the agreed reporting and record what should change next time.'],
        ];
    }
}
