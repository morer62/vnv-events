CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_owner INT NOT NULL,
    id_order INT UNSIGNED NOT NULL,
    invoice_number VARCHAR(64) NOT NULL,
    status ENUM('DRAFT','UNPAID','PARTIALLY_PAID','PAID','REFUNDED','VOIDED') NOT NULL DEFAULT 'DRAFT',
    auto_generated TINYINT(1) NOT NULL DEFAULT 0,
    company_snapshot_json LONGTEXT NOT NULL,
    billing_name VARCHAR(255) NULL,
    billing_email VARCHAR(255) NULL,
    billing_phone VARCHAR(80) NULL,
    billing_address TEXT NULL,
    line_overrides_json LONGTEXT NULL,
    invoice_notes TEXT NULL,
    internal_reference VARCHAR(255) NULL,
    void_reason VARCHAR(500) NULL,
    voided_at DATETIME NULL,
    voided_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_owner_order (id_owner, id_order),
    UNIQUE KEY uq_invoices_owner_number (id_owner, invoice_number),
    KEY idx_invoices_status_created (id_owner, status, created_at),
    KEY idx_invoices_order (id_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    generated_by INT UNSIGNED NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_to VARCHAR(255) NULL,
    sent_by INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_version (invoice_id, version_number),
    UNIQUE KEY uq_invoice_snapshot (invoice_id, snapshot_hash),
    CONSTRAINT fk_invoice_versions_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    details_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_invoice_audit (invoice_id, created_at),
    CONSTRAINT fk_invoice_audit_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
