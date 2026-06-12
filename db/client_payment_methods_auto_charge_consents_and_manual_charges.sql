-- VNV Events saved client payment methods, auto-charge consent, and manual authorized charges
-- Manual SQL only. Review before running in production.
--
-- Scope:
-- - Gateway-agnostic client payment method references.
-- - Explicit future/automatic charge consent per business/client/provider.
-- - Auditable manual authorized charge attempts.
--
-- Safety:
-- - Never store full card numbers.
-- - Never store CVV.
-- - Store provider tokens/references only.
-- - Keep id_user_business on every row.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `client_saved_payment_methods` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user_business` int(11) NOT NULL,
  `id_client` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `payment_provider` varchar(40) NOT NULL,
  `provider_customer_id` varchar(255) DEFAULT NULL,
  `provider_payment_method_id` varchar(255) DEFAULT NULL,
  `provider_reference` varchar(255) DEFAULT NULL,
  `method_type` varchar(40) NOT NULL DEFAULT 'other',
  `brand` varchar(64) DEFAULT NULL,
  `last4` varchar(8) DEFAULT NULL,
  `exp_month` tinyint(3) UNSIGNED DEFAULT NULL,
  `exp_year` smallint(5) UNSIGNED DEFAULT NULL,
  `billing_name` varchar(255) DEFAULT NULL,
  `billing_email` varchar(255) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('ACTIVE','INACTIVE','EXPIRED','FAILED','REVOKED') NOT NULL DEFAULT 'ACTIVE',
  `source` varchar(80) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cspm_business_client` (`id_user_business`, `id_client`),
  KEY `idx_cspm_business_user` (`id_user_business`, `user_id`),
  KEY `idx_cspm_provider_customer` (`payment_provider`, `provider_customer_id`),
  KEY `idx_cspm_provider_method` (`payment_provider`, `provider_payment_method_id`),
  KEY `idx_cspm_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `client_auto_charge_consents` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user_business` int(11) NOT NULL,
  `id_client` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `payment_provider` varchar(40) NOT NULL,
  `saved_payment_method_id` int(10) UNSIGNED DEFAULT NULL,
  `consent_scope` varchar(120) NOT NULL DEFAULT 'orders_store_balances_tips_future_purchases',
  `consent_text` text NOT NULL,
  `consent_version` varchar(40) NOT NULL DEFAULT '2026-06-10',
  `accepted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  `status` enum('ACTIVE','REVOKED','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
  `source` varchar(80) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `related_order_id` int(11) DEFAULT NULL,
  `related_store_order_id` int(11) DEFAULT NULL,
  `related_payment_id` int(11) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cacc_business_client` (`id_user_business`, `id_client`),
  KEY `idx_cacc_business_user` (`id_user_business`, `user_id`),
  KEY `idx_cacc_method` (`saved_payment_method_id`),
  KEY `idx_cacc_status` (`status`),
  KEY `idx_cacc_order` (`related_order_id`),
  KEY `idx_cacc_store_order` (`related_store_order_id`),
  CONSTRAINT `fk_cacc_saved_method`
    FOREIGN KEY (`saved_payment_method_id`) REFERENCES `client_saved_payment_methods` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `authorized_manual_charge_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user_business` int(11) NOT NULL,
  `id_client` int(11) DEFAULT NULL,
  `id_order` int(11) DEFAULT NULL,
  `id_suborder` int(11) DEFAULT NULL,
  `saved_payment_method_id` int(10) UNSIGNED DEFAULT NULL,
  `auto_charge_consent_id` int(10) UNSIGNED DEFAULT NULL,
  `payment_provider` varchar(40) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `status` enum('PENDING','SUCCESS','FAILED','CANCELED') NOT NULL DEFAULT 'PENDING',
  `gateway_charge_id` varchar(255) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `charged_by_user_id` int(11) NOT NULL,
  `idempotency_key` varchar(120) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_amcl_idempotency` (`idempotency_key`),
  KEY `idx_amcl_business_order` (`id_user_business`, `id_order`),
  KEY `idx_amcl_business_suborder` (`id_user_business`, `id_suborder`),
  KEY `idx_amcl_client` (`id_client`),
  KEY `idx_amcl_method` (`saved_payment_method_id`),
  KEY `idx_amcl_consent` (`auto_charge_consent_id`),
  KEY `idx_amcl_status` (`status`),
  CONSTRAINT `fk_amcl_saved_method`
    FOREIGN KEY (`saved_payment_method_id`) REFERENCES `client_saved_payment_methods` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_amcl_consent`
    FOREIGN KEY (`auto_charge_consent_id`) REFERENCES `client_auto_charge_consents` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
