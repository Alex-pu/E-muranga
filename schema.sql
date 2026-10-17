BEGIN;

CREATE TABLE IF NOT EXISTS app_users (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'citizen'
        CHECK (role IN ('citizen', 'admin')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE app_users
    ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'citizen';

CREATE TABLE IF NOT EXISTS service_requests (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reference_code VARCHAR(20) NOT NULL UNIQUE,
    user_id BIGINT REFERENCES app_users(id) ON DELETE CASCADE,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    service_name VARCHAR(100) NOT NULL DEFAULT '',
    category VARCHAR(80) NOT NULL DEFAULT 'Other',
    location VARCHAR(200) NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    image_path VARCHAR(255),
    national_id VARCHAR(30) NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'Pending'
        CHECK (status IN ('Pending', 'In Progress', 'Resolved')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE service_requests
    ADD COLUMN IF NOT EXISTS user_id BIGINT REFERENCES app_users(id) ON DELETE CASCADE,
    ADD COLUMN IF NOT EXISTS category VARCHAR(80) NOT NULL DEFAULT 'Other',
    ADD COLUMN IF NOT EXISTS location VARCHAR(200) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS description TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS image_path VARCHAR(255),
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS service_name VARCHAR(100) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS national_id VARCHAR(30) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS notes TEXT NOT NULL DEFAULT '';

UPDATE service_requests
SET category = service_name
WHERE category = 'Other' AND service_name <> '';

UPDATE service_requests
SET description = notes
WHERE description = '' AND notes <> '';

UPDATE service_requests
SET status = CASE status
    WHEN 'Received' THEN 'Pending'
    WHEN 'In review' THEN 'In Progress'
    WHEN 'Completed' THEN 'Resolved'
    ELSE status
END
WHERE status IN ('Received', 'In review', 'Completed');

ALTER TABLE service_requests
    ALTER COLUMN status SET DEFAULT 'Pending',
    ALTER COLUMN national_id SET DEFAULT '';

CREATE INDEX IF NOT EXISTS service_requests_user_created_idx
    ON service_requests (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS service_requests_status_created_idx
    ON service_requests (status, created_at DESC);

CREATE TABLE IF NOT EXISTS request_status_history (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    request_id BIGINT NOT NULL REFERENCES service_requests(id) ON DELETE CASCADE,
    changed_by BIGINT REFERENCES app_users(id) ON DELETE SET NULL,
    old_status VARCHAR(20),
    new_status VARCHAR(20) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
