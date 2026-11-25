-- VNV Events · free Break Club memberships, single-event purchases and individual admission.
CREATE TABLE IF NOT EXISTS vnv_break_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(32) NOT NULL,
    party_size TINYINT UNSIGNED NOT NULL,
    companion_name VARCHAR(150) NULL, companion_email VARCHAR(190) NULL,
    category VARCHAR(24) NOT NULL,
    utm_source VARCHAR(100) NULL, utm_campaign VARCHAR(100) NULL, utm_content VARCHAR(100) NULL,
    status ENUM('PENDING','ACTIVE','UNSUBSCRIBED') NOT NULL DEFAULT 'PENDING',
    consent_at DATETIME NOT NULL, confirmed_at DATETIME NULL, unsubscribed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_break_email (owner_id,email),
    UNIQUE KEY uq_break_user (owner_id,user_id),
    KEY ix_break_member_status (owner_id,status,created_at),
    CHECK (party_size IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vnv_break_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL, location VARCHAR(220) NOT NULL,
    starts_at DATETIME NOT NULL COMMENT 'Local time in America/New_York',
    price_cents INT UNSIGNED NOT NULL COMMENT 'Single-event admission per person in USD',
    status ENUM('DRAFT','PUBLISHED') NOT NULL DEFAULT 'DRAFT',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_break_calendar (owner_id,status,starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vnv_break_registrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL, member_id BIGINT UNSIGNED NOT NULL,
    party_size TINYINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(32) NOT NULL,
    companion_name VARCHAR(150) NULL, companion_email VARCHAR(190) NULL,
    unit_cents INT UNSIGNED NOT NULL,
    status ENUM('INVITED','PAID','PARTIALLY_REFUNDED','REFUNDED') NOT NULL DEFAULT 'INVITED',
    invited_at DATETIME NULL, paid_at DATETIME NULL, paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
    checkout_attempt INT UNSIGNED NOT NULL DEFAULT 1,
    checkout_session_id VARCHAR(190) NULL, stripe_account_id VARCHAR(190) NULL,
    stripe_payment_intent_id VARCHAR(190) NULL,
    principal_checked_at DATETIME NULL, principal_checked_by INT UNSIGNED NULL,
    companion_checked_at DATETIME NULL, companion_checked_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_break_invitation (event_id,member_id),
    UNIQUE KEY uq_break_checkout (checkout_session_id),
    UNIQUE KEY uq_break_intent (stripe_payment_intent_id),
    KEY ix_break_reg_member (member_id),
    FOREIGN KEY(event_id) REFERENCES vnv_break_events(id),
    FOREIGN KEY(member_id) REFERENCES vnv_break_members(id),
    CHECK (party_size IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vnv_break_email_tokens (
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL, registration_id BIGINT UNSIGNED NULL,
    target_user_id INT UNSIGNED NULL,
    purpose ENUM('VERIFY','CLAIM','INVITE','UNSUBSCRIBE') NOT NULL,
    created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
    KEY ix_break_token_member (member_id,purpose,created_at),
    FOREIGN KEY(member_id) REFERENCES vnv_break_members(id),
    FOREIGN KEY(registration_id) REFERENCES vnv_break_registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
