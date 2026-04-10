<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePaymentProvidersCredentials extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('payment_providers_credentials')) {
            return;
        }

        $table = $this->table('payment_providers_credentials');
        $table
            ->addColumn('id_owner', 'integer')
            ->addColumn('provider_type', 'string', ['limit' => 32]) // stripe|square|paypal
            ->addColumn('provider_name', 'string', ['limit' => 80])
            ->addColumn('api_key', 'text', ['null' => true])
            ->addColumn('api_secret', 'text', ['null' => true])
            ->addColumn('public_key', 'text', ['null' => true])
            ->addColumn('webhook_secret', 'text', ['null' => true])
            ->addColumn('environment', 'string', ['limit' => 16, 'default' => 'sandbox'])
            ->addColumn('currency', 'string', ['limit' => 8, 'default' => 'USD'])
            ->addColumn('merchant_email', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('location_id', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => 0])
            ->addColumn('is_verified', 'boolean', ['default' => 0])
            ->addColumn('is_default', 'boolean', ['default' => 0])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['id_owner'])
            ->addIndex(['id_owner', 'provider_type'])
            ->create();
    }
}

