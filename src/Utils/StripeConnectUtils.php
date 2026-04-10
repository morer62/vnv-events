<?php

namespace App\Utils;

use App\Repositories\StripeAccountsRepository;

class StripeConnectUtils
{
    public static function isAccountVerified(int $id_user): bool
    {
        $repo = new StripeAccountsRepository();
        $account = $repo->getByUser($id_user);

        return $account && $account->is_verified;
    }
}
