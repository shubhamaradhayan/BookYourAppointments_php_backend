USE appointment_saas;

ALTER TABLE users
    MODIFY password_hash VARCHAR(255) NULL;

ALTER TABLE users
    ADD COLUMN google_id VARCHAR(255) NULL AFTER password_hash;

ALTER TABLE users
    ADD COLUMN auth_provider ENUM('password', 'google', 'password_google')
        NOT NULL DEFAULT 'password' AFTER google_id;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_google_id (google_id);


ALTER TABLE availability_rules
    ADD COLUMN created_at DATETIME NOT NULL AFTER is_available;
ALTER TABLE availability_rules
    ADD COLUMN updated_at DATETIME NOT NULL AFTER created_at;
