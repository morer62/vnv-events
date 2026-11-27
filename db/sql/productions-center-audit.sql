CREATE TABLE IF NOT EXISTS productions_center_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_owner INT NOT NULL,
    site_key VARCHAR(48) NOT NULL,
    actor_id INT NOT NULL,
    action VARCHAR(80) NOT NULL,
    result VARCHAR(20) NOT NULL,
    target_id BIGINT NULL,
    detail VARCHAR(240) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX pmc_audit_owner (id_owner,site_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
