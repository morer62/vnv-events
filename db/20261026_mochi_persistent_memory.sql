CREATE TABLE IF NOT EXISTS mochi_memories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_owner INT NOT NULL,
    site_key VARCHAR(80) NOT NULL,
    scope_type ENUM('COMPANY','SESSION') NOT NULL,
    scope_key VARCHAR(80) NOT NULL,
    memory_json MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mochi_memory_scope (id_owner,site_key,scope_type,scope_key),
    KEY idx_mochi_memory_updated (id_owner,site_key,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
