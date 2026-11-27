<?php

namespace App\Services;

final class VnvProductionCatalog
{
    public const MANAGED_BUDGET = 'The managed budget covers the services VNV directs under the written scope. It excludes taxes, gratuities and the management fee itself.';
    public const DIRECT_COSTS = 'Materials, fabrication, rentals, extra staffing, installation and technical services are quoted separately. You approve the scope and direct costs before we commit to them.';

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
                'name' => 'Production Essential', 'minimum' => 1500,
                'summary' => 'Bring us a defined assignment, such as the guest arrival plan or a speaker program. The proposal identifies the deliverable, schedule, handoffs and coverage.',
                'route' => 'event-production', 'image' => 'assets/images/corp2.jpg',
            ],
            'full-event-production' => [
                'name' => 'Full Event Production', 'minimum' => 1950, 'percent' => 10,
                'summary' => 'Put the agreed budget, vendor decisions, run of show, rehearsals and event-day leadership under one producer.',
                'route' => 'event-production', 'image' => 'assets/images/productions/hero-productions.jpg',
            ],
            'advanced-production' => [
                'name' => 'Advanced & Large-Scale Production', 'price' => 'Custom proposal',
                'summary' => 'Scope a multi-day, multi-venue or technically complex production around the crew and coverage it requires.',
                'route' => 'event-production', 'image' => 'assets/images/corp3.jpg',
            ],
            'brand-activation' => [
                'name' => 'Brand Activation Management', 'minimum' => 750, 'percent' => 12,
                'summary' => 'Create and deliver a standalone activation at an existing event, from audience and concept through installation, live staffing and the agreed recap.',
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
            $service += ['tiers' => [], 'minimum' => null, 'percent' => null];
            $service['minimum'] ??= $service['tiers']['basic'] ?? null;
            if ($service['percent'] !== null) {
                $service['price'] = $service['percent'] . '% · $' . number_format($service['minimum']) . ' minimum';
            } elseif (!isset($service['price'])) {
                $service['price'] = 'Starting at $' . number_format($service['minimum']);
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
