<?php

namespace App\Entity;

class UserPermissions
{
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
}