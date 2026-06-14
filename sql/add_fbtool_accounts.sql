BEGIN;

CREATE TABLE IF NOT EXISTS fbtool_accounts (
    id         BIGSERIAL PRIMARY KEY,
    user_id    INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    fbtool_id  VARCHAR(50) NOT NULL UNIQUE,
    name       VARCHAR(255),
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE business_managers
    ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT REFERENCES fbtool_accounts(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_fbtool_accounts_user_id ON fbtool_accounts(user_id);
CREATE INDEX IF NOT EXISTS idx_bm_fbtool_account_id ON business_managers(fbtool_account_id);

COMMIT;
