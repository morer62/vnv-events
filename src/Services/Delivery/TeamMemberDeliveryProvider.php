<?php

namespace App\Services\Delivery;

final class TeamMemberDeliveryProvider extends ManualDeliveryProvider
{
    public function __construct()
    {
        parent::__construct('team_member');
    }
}
