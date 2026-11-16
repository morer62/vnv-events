<?php

namespace App\Entity;

class UserPermissions
{
    public const MANAGEMENT_CONFIGURED = "management_configured";
    public const MOCHI = "mochi";
    public const CRM_CLIENTS = "crm_clients";
    public const CRM_SPONSORS = "crm_sponsors";
    public const ORDERS = "orders";
    public const PAYROLL = "payroll";
    public const GROWTH_HUB = "growth_hub";
    public const CRM = "crm";
    public const GUEST_LIST = "guest_list";
    public const PLANNER = "planner";
    public const STORAGE = "storage";


    public const PERMISSIONS = [
        self::CRM,
        self::GUEST_LIST,
        self::PLANNER,
        self::STORAGE
    ];

    /**
     * Level 4 management modules that Level 1 can enable per team member.
     * A selected module grants the complete module; CRUD-level switches are
     * intentionally not exposed here.
     */
    public const MANAGEMENT_MODULES = [
        self::MOCHI => [
            'label' => 'AI (Mochi)',
            'description' => 'Mochi assistant and its operational workspace.',
            'icon' => 'message-circle',
        ],
        self::CRM_CLIENTS => [
            'label' => 'Client CRM',
            'description' => 'Client leads, categories and the standard sales pipeline.',
            'icon' => 'users',
        ],
        self::CRM_SPONSORS => [
            'label' => 'Sponsors CRM',
            'description' => 'Sponsor, nonprofit and commercial partnership pipelines.',
            'icon' => 'target',
        ],
        self::STORAGE => [
            'label' => 'Storage',
            'description' => 'Containers, inventory, QR labels and item management.',
            'icon' => 'archive',
        ],
        self::ORDERS => [
            'label' => 'Orders',
            'description' => 'Event orders, estimates, services and contracts.',
            'icon' => 'briefcase',
        ],
        self::PAYROLL => [
            'label' => 'Payroll',
            'description' => 'Pending hours, approvals and payroll history.',
            'icon' => 'dollar-sign',
        ],
        self::GROWTH_HUB => [
            'label' => 'Growth Hub',
            'description' => 'The complete content, video and distribution suite.',
            'icon' => 'trending-up',
        ],
    ];

    public static function managementModules(): array
    {
        $modules = [];
        foreach (self::MANAGEMENT_MODULES as $slug => $definition) {
            $modules[] = ['slug' => $slug] + $definition;
        }

        return $modules;
    }

    public static function managementModuleSlugs(): array
    {
        return array_keys(self::MANAGEMENT_MODULES);
    }
}
