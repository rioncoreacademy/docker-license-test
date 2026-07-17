-- A product is one or more folders inside tarang2p1-files plus the one
-- encryption key that unlocks all of them. Folder-scoped licenses point at
-- a product; the key lives here (not on the license row) because
-- encrypt_lab.sh encrypts a folder's .v.enc files with a specific key --
-- every license unlocking that same content must resolve to the same key.
-- A product can bundle multiple folders (e.g. tarang2p2 + tarang2p3
-- together) as long as they were all encrypted with THIS product's key --
-- see product_folders below. There's no folder_path here directly; a
-- product with zero rows in product_folders is never actually created by
-- the admin UI (every product needs at least one folder) -- that's what a
-- NULL licenses.product_id means instead, see below.
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL DEFAULT '',
    encryption_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- One row per folder a product unlocks. folder_path is globally UNIQUE
-- (not just per-product) -- a folder can only ever belong to one product,
-- so its content only ever needs one key, no matter how many products
-- exist.
CREATE TABLE IF NOT EXISTS product_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    folder_path VARCHAR(255) NOT NULL UNIQUE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(64) NOT NULL UNIQUE,
    customer_email VARCHAR(255) NOT NULL,
    max_activations INT NOT NULL DEFAULT 1,
    -- NULL = legacy/full-access license: falls back to DEFAULT_ENCRYPTION_KEY
    -- and no folder restriction (whole tarang2p1-files repo), matching every
    -- license issued before products existed.
    product_id INT NULL,
    expiry_date DATE NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS activations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    activated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_activation (license_id, fingerprint)
);

CREATE TABLE IF NOT EXISTS license_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    event_type ENUM('created', 'extended', 'revoked', 'reactivated') NOT NULL,
    detail VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
);

-- Test license: 2 activation seats, expires far in the future.
-- Key is plaintext here only because this is a disposable local test DB.
INSERT INTO licenses (license_key, customer_email, max_activations, expiry_date, status)
VALUES ('TEST-1234-5678-9ABC', 'test@example.com', 2, '2027-12-31', 'active');

-- Test license: already expired, for testing the expiry rejection path.
INSERT INTO licenses (license_key, customer_email, max_activations, expiry_date, status)
VALUES ('TEST-EXPIRED-0001', 'test@example.com', 1, '2020-01-01', 'active');
