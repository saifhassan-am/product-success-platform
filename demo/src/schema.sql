-- Product Success Platform demo schema (SQLite).

-- Customer accounts. In production this comes from the store / account system,
-- never from the plugin. The plugin only ever sends an account ID.
CREATE TABLE IF NOT EXISTS accounts (
    account_id       TEXT PRIMARY KEY,
    name             TEXT NOT NULL,
    email            TEXT NOT NULL,
    plan             TEXT NOT NULL,
    help_consent     INTEGER NOT NULL DEFAULT 0, -- the account holder agreed to "Help me succeed", including contact
    help_consent_at  TEXT,
    created_at       TEXT NOT NULL
);

-- One row per install per consent tier. IDs are random and never shared between
-- tiers, so anonymous history can't be joined to an identity.
CREATE TABLE IF NOT EXISTS installs (
    install_id     TEXT PRIMARY KEY,
    token          TEXT NOT NULL UNIQUE,
    tier           TEXT NOT NULL CHECK (tier IN ('anonymous', 'identified')),
    account_id     TEXT REFERENCES accounts(account_id),
    registered_at  TEXT NOT NULL
);

-- Latest values only. Overwritten in place; no history is kept.
CREATE TABLE IF NOT EXISTS site_profiles (
    install_id         TEXT PRIMARY KEY REFERENCES installs(install_id) ON DELETE CASCADE,
    site_name          TEXT,
    site_url           TEXT,
    php_version        TEXT,
    wp_version         TEXT,
    db_version         TEXT,
    locale             TEXT,
    multisite          INTEGER,
    environment        TEXT,
    theme              TEXT,
    ttb_active         INTEGER,
    thrive_products    TEXT NOT NULL DEFAULT '{}',
    dashboard_version  TEXT,
    other_plugins      TEXT NOT NULL DEFAULT '[]',
    last_admin_seen_at TEXT,
    consent_version    TEXT,
    updated_at         TEXT NOT NULL
);

-- Raw events, deduplicated by the client-generated event_id.
CREATE TABLE IF NOT EXISTS events (
    event_id     TEXT PRIMARY KEY,
    install_id   TEXT NOT NULL REFERENCES installs(install_id) ON DELETE CASCADE,
    product      TEXT NOT NULL,
    name         TEXT NOT NULL,
    occurred_at  TEXT NOT NULL,
    received_at  TEXT NOT NULL,
    properties   TEXT NOT NULL DEFAULT '{}'
);
CREATE INDEX IF NOT EXISTS idx_events_install ON events (install_id, product, occurred_at);

-- First-time milestones. The primary key is what makes "first" mean first:
-- retries, reinstalls and duplicates can't create a second row.
CREATE TABLE IF NOT EXISTS milestones (
    install_id   TEXT NOT NULL REFERENCES installs(install_id) ON DELETE CASCADE,
    product      TEXT NOT NULL,
    milestone    TEXT NOT NULL,
    stage        INTEGER NOT NULL,
    occurred_at  TEXT NOT NULL,
    received_at  TEXT NOT NULL,
    source       TEXT NOT NULL CHECK (source IN ('event', 'backfill', 'reconciled')),
    timing_ok    INTEGER NOT NULL, -- 0 when the date is unknown or backfilled; excluded from timing stats
    PRIMARY KEY (install_id, product, milestone)
);

-- Derived state per install and product, maintained by the stage engine.
CREATE TABLE IF NOT EXISTS site_products (
    install_id          TEXT NOT NULL REFERENCES installs(install_id) ON DELETE CASCADE,
    product             TEXT NOT NULL,
    plugin_version      TEXT,
    lifecycle           TEXT NOT NULL DEFAULT 'active' CHECK (lifecycle IN ('active', 'deactivated', 'uninstalled')),
    lifecycle_at        TEXT, -- when lifecycle last changed; older events can't override it
    stage               INTEGER NOT NULL DEFAULT 0,
    stage_reached_at    TEXT,
    stage_timing_ok     INTEGER NOT NULL DEFAULT 1,
    stage_received_at   TEXT,
    activated_at        TEXT,
    last_heartbeat_at   TEXT,
    last_value_at       TEXT,
    value_weeks         TEXT NOT NULL DEFAULT '{}', -- {"2026-W45": 3, ...}, last 8 weeks
    metrics             TEXT NOT NULL DEFAULT '{}', -- latest heartbeat counts
    PRIMARY KEY (install_id, product)
);

-- Pilot randomization, by account, at the moment an account first enters the day's list.
CREATE TABLE IF NOT EXISTS assignments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id    TEXT NOT NULL,
    product       TEXT NOT NULL,
    install_id    TEXT,
    from_stage    INTEGER NOT NULL,
    arm           TEXT NOT NULL CHECK (arm IN ('contact', 'holdout')),
    assigned_at   TEXT NOT NULL,
    withdrawn     INTEGER NOT NULL DEFAULT 0,
    UNIQUE (account_id, product)
);

CREATE TABLE IF NOT EXISTS outreach (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id        TEXT NOT NULL,
    install_id        TEXT NOT NULL REFERENCES installs(install_id) ON DELETE CASCADE,
    product           TEXT NOT NULL,
    stage_at_contact  INTEGER NOT NULL,
    channel           TEXT NOT NULL,
    outcome           TEXT NOT NULL,
    note              TEXT,
    author            TEXT NOT NULL,
    created_at        TEXT NOT NULL
);

-- Who looked at or exported personal data, and when.
CREATE TABLE IF NOT EXISTS access_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    actor       TEXT NOT NULL,
    action      TEXT NOT NULL,
    target      TEXT,
    created_at  TEXT NOT NULL
);

-- Withdrawals are counted, but nothing identifying is kept.
CREATE TABLE IF NOT EXISTS withdrawals (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tier        TEXT NOT NULL,
    created_at  TEXT NOT NULL
);
