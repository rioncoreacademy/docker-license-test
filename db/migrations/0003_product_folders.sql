-- Run this against a database that already has the old single
-- products.folder_path column (from migration 0002). A fresh install via
-- db/init.sql already has product_folders instead -- do not run both.

CREATE TABLE IF NOT EXISTS product_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    folder_path VARCHAR(255) NOT NULL UNIQUE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Backfill: one row per existing product's old single folder_path.
INSERT INTO product_folders (product_id, folder_path)
SELECT id, folder_path FROM products WHERE folder_path IS NOT NULL AND folder_path != '';

ALTER TABLE products DROP COLUMN folder_path;
