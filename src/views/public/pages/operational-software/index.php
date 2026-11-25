<?php

use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

$router->get(function () {
    $roles = [
        'platform-administrator' => ['Platform administrator', 'Review the organization directory and the boundaries between accounts.'],
        'organization-administrator' => ['Organization administrator', 'Open a gala project, update a task and record the next conversation with a sponsor.'],
        'project-manager' => ['Project manager', 'Approve a run of show, update a delegated CRM record and switch between assigned projects.'],
        'team-member' => ['Team member', 'Find your arrival assignment and tell the producer when the work is complete.'],
        'external-participant' => ['External participant', 'Check your own arrival details before you head to the venue.'],
    ];
    $requested = $_GET['role'] ?? null;
    $role = is_string($requested) && isset($roles[$requested]) ? $requested : 'organization-administrator';
    $seo = [
        'title' => 'Event Production Software Demo | VNV Events',
        'description' => 'Tour VNV’s event production workspace. See five roles use project boards, crew tasks and sponsor follow-up on a fictional South Florida event.',
        'canonical' => 'https://vnvevents.com/operational-software/',
    ];

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'seo' => $seo,
        'roles' => $roles,
        'selected' => $role,
        'demo_live' => filter_var($_ENV['VNV_OPERATIONS_DEMO_LIVE'] ?? getenv('VNV_OPERATIONS_DEMO_LIVE'), FILTER_VALIDATE_BOOLEAN),
    ]);
});

$router->run();
