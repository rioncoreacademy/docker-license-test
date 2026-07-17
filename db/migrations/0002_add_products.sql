-- Run this against an already-provisioned database (e.g. Hostinger
-- production) that was created before products existed. A fresh install
-- via db/init.sql already includes this -- do not run both.

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL DEFAULT '',
    folder_path VARCHAR(255) NOT NULL UNIQUE,
    encryption_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE licenses
    ADD COLUMN product_id INT NULL AFTER max_activations,
    ADD CONSTRAINT fk_licenses_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE SET NULL;
