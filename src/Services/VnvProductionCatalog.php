<?php

namespace App\Services;

final class VnvProductionCatalog
{
    public const FEE_INCLUDES = 'Your production tier has a fixed fee. It covers your producer, the run of show and the agreed production deliverables tracked in VNV-Q.';
    public const DIRECT_COSTS = 'We quote materials, fabrication, rentals, extra staff, installation, technical services and third-party costs separately. You approve those costs before we commit to them.';
    public const GUEST_TIERS = ['Up to 75 guests', '76–150 guests', '151–250 guests', '251+ guests'];
    public const GUEST_CAPS = [75, 150, 250, null];
    public const PACKAGE_RATES = [
        'event-planner' => [1500, 2250, 2950, 3750],
        'wedding-planner' => [1950, 2750, 3450, 4250],
        'day-coordinator' => [850, 1100, 1400, 1750],
    ];
    public const PACKAGE_SLUGS = [
        'event-planner' => 'event-planner-packages',
        'wedding-planner' => 'wedding-planner-packages',
        'day-coordinator' => 'day-coordinator-service-packages-premium',
    ];
    public const PLANNING_DETAILS = 'We manage your budget and timeline, select and coordinate vendors, and track checklists, RSVPs and follow-up in VNV-Q.';
    public const PLANNING_EXTRAS = 'Additional planning meetings are $150 each. Add a welcome party, rehearsal, brunch or another event day to the program for $500 per additional day.';
    public const COORDINATION_DETAILS = 'A dedicated coordinator leads vendor arrivals, setup, the timeline and guest flow, and resolves on-site issues. Each tier covers one event day with up to 10 hours on site.';
    public const COORDINATION_EXTRAS = 'Additional on-site hours are $180 each. Each additional event day costs 75% of your selected tier.';

    public static function services(): array
    {
        $services = [
            'event-planner' => [
                'name' => 'Event Planner', 'tiers' => array_combine(self::GUEST_TIERS, self::PACKAGE_RATES['event-planner']),
                'summary' => 'Plan a private celebration, fundraiser or corporate event with one budget, a vendor plan and a schedule your team can follow.',
                'details' => self::PLANNING_DETAILS, 'extras' => self::PLANNING_EXTRAS,
                'route' => 'event-planners', 'image' => 'assets/images/planner2.jpg',
            ],
            'wedding-planner' => [
                'name' => 'Wedding Planner', 'tiers' => array_combine(self::GUEST_TIERS, self::PACKAGE_RATES['wedding-planner']),
                'summary' => 'Plan your ceremony and reception together, from vendor selection and etiquette to guest accommodations and the wedding timeline.',
                'details' => self::PLANNING_DETAILS, 'extras' => self::PLANNING_EXTRAS,
                'route' => 'event-planners', 'image' => 'assets/images/planner1.jpg',
            ],
            'day-coordinator' => [
                'name' => 'Day Coordinator', 'tiers' => array_combine(self::GUEST_TIERS, self::PACKAGE_RATES['day-coordinator']),
                'summary' => 'Hand your finished schedule to a dedicated on-site coordinator who runs the event day.',
                'details' => self::COORDINATION_DETAILS, 'extras' => self::COORDINATION_EXTRAS,
                'route' => 'event-planners', 'image' => 'assets/images/planner3.jpg',
            ],
            'production-essential' => [
                'name' => 'Production Essential', 'price' => '$1,950',
                'summary' => 'Bring us a defined assignment, such as the guest arrival plan or a speaker program. The proposal identifies the deliverable, schedule and handoff in VNV-Q.',
                'route' => 'event-production', 'image' => 'assets/images/corp2.jpg',
            ],
            'full-event-production' => [
                'name' => 'Full Event Production', 'price' => '$3,500',
                'summary' => 'One day at one venue, with up to 300 guests, up to 10 sponsors and no paid ticketing. Your run of show, vendor decisions and rehearsal plan are managed in VNV-Q. VNV provides catering, sound and staffing.',
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
                'summary' => 'We plan your standalone activation, vendor specifications, installation schedule and handoff. When VNV produces your event, its activation is included in the production fee.',
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
                $service['product_slug'] = self::PACKAGE_SLUGS[$slug];
                $service['tier_inclusions'] = $slug === 'day-coordinator'
                    ? ['Dedicated coordinator', 'Dedicated coordinator', 'Includes one assistant coordinator', 'Includes one assistant coordinator']
                    : ['Up to 3 scheduled planning meetings', 'Up to 4 scheduled planning meetings', 'Up to 5 scheduled planning meetings', 'Up to 6 scheduled planning meetings'];
                $service['price'] = 'Starting at $' . number_format(reset($service['tiers']));
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
            ['name' => 'Produce the Event', 'summary' => 'Hand the approved schedule and assignments to the event team. A separately booked Day Coordinator runs the on-site schedule.'],
            ['name' => 'Close & Recap', 'summary' => 'Reconcile the work, deliver the agreed reporting and record what should change next time.'],
        ];
    }
}
