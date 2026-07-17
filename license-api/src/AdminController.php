<?php

class AdminController
{
    public function generate(array $body): array
    {
        $email = trim((string) ($body['email'] ?? ''));
        $expires = trim((string) ($body['expires'] ?? ''));
        $seats = ($body['seats'] ?? '') !== '' ? (int) $body['seats'] : 1;
        $prefix = trim((string) ($body['prefix'] ?? '')) ?: 'TDP1';
        // Optional: if you ask the customer for their fingerprint (see
        // client/Get-Fingerprint.ps1 / tools/get-fingerprint.sh) before
        // issuing a key, pass it here to check whether THIS MACHINE has had
        // any license before, under any email -- email alone is trivial to
        // fake when someone's really just trying to re-issue themselves a
        // fresh key past a previous key's expiry.
        $fingerprint = trim((string) ($body['fingerprint'] ?? ''));
        $productId = ($body['product_id'] ?? '') !== '' ? (int) $body['product_id'] : null;

        if ($email === '' || $expires === '') {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_email_or_expires']];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || strtotime($expires) === false) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_expires_date']];
        }

        if ($seats < 1) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_seats']];
        }

        if ($fingerprint !== '' && !preg_match('/^[a-f0-9]{64}$/i', $fingerprint)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'malformed_fingerprint']];
        }

        $db = Database::get();

        $product = null;
        if ($productId !== null) {
            $stmt = $db->prepare('SELECT id, slug, name FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if ($product === false) {
                return ['status' => 400, 'body' => ['ok' => false, 'error' => 'product_not_found']];
            }
        }

        // Surface any existing non-revoked license(s) for this email OR this
        // fingerprint so the caller can offer "extend instead" -- doesn't
        // block generation, since a customer legitimately buying a second,
        // separate license is also a real case. Merged by license_key so a
        // license matching on both shows up once, flagged 'both'.
        $byEmail = [];
        $stmt = $db->prepare("SELECT license_key, expiry_date, status FROM licenses WHERE customer_email = ? AND status != 'revoked' ORDER BY created_at DESC");
        $stmt->execute([$email]);
        foreach ($stmt->fetchAll() as $l) {
            $byEmail[$l['license_key']] = $l + ['matched_by' => 'email'];
        }

        if ($fingerprint !== '') {
            foreach ($this->findLicensesByFingerprint($db, $fingerprint) as $l) {
                if (isset($byEmail[$l['license_key']])) {
                    $byEmail[$l['license_key']]['matched_by'] = 'both';
                } else {
                    $byEmail[$l['license_key']] = $l + ['matched_by' => 'fingerprint'];
                }
            }
        }
        $existing = array_values($byEmail);

        $key = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::generateKey($prefix);
            $stmt = $db->prepare('SELECT id FROM licenses WHERE license_key = ?');
            $stmt->execute([$candidate]);
            if (!$stmt->fetch()) {
                $key = $candidate;
                break;
            }
        }

        if ($key === null) {
            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'key_generation_failed']];
        }

        $db->prepare('INSERT INTO licenses (license_key, customer_email, max_activations, product_id, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$key, $email, $seats, $productId, $expires, 'active']);
        $licenseId = (int) $db->lastInsertId();

        $eventDetail = "seats={$seats}, expires={$expires}";
        if ($product !== null) {
            $eventDetail .= ", product={$product['slug']}";
        }
        $this->logEvent($db, $licenseId, 'created', $eventDetail);

        return ['status' => 200, 'body' => [
            'ok' => true,
            'license_key' => $key,
            'customer_email' => $email,
            'seats' => $seats,
            'expiry_date' => $expires,
            'product' => $product !== null ? [
                'id'      => (int) $product['id'],
                'slug'    => $product['slug'],
                'name'    => $product['name'],
                'folders' => $this->productFolders($db, (int) $product['id']),
            ] : null,
            'existing_licenses' => array_map(static fn($l) => [
                'license_key' => $l['license_key'],
                'expiry_date' => $l['expiry_date'],
                'status'      => $l['status'],
                'matched_by'  => $l['matched_by'],
            ], $existing),
        ]];
    }

    public function extend(array $body): array
    {
        $key = trim((string) ($body['license_key'] ?? ''));
        $expires = trim((string) ($body['expires'] ?? ''));
        $seats = ($body['seats'] ?? '') !== '' ? (int) $body['seats'] : null;

        if ($key === '' || $expires === '') {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_license_key_or_expires']];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || strtotime($expires) === false) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_expires_date']];
        }
        if ($seats !== null && $seats < 1) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_seats']];
        }

        $db = Database::get();
        $license = $this->findByKey($db, $key);
        if ($license === false) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
        }

        if ($seats !== null) {
            $db->prepare('UPDATE licenses SET expiry_date = ?, max_activations = ? WHERE id = ?')
                ->execute([$expires, $seats, $license['id']]);
            $detail = "expiry {$license['expiry_date']} -> {$expires}, seats {$license['max_activations']} -> {$seats}";
        } else {
            $db->prepare('UPDATE licenses SET expiry_date = ? WHERE id = ?')
                ->execute([$expires, $license['id']]);
            $detail = "expiry {$license['expiry_date']} -> {$expires}";
        }

        $this->logEvent($db, $license['id'], 'extended', $detail);

        return ['status' => 200, 'body' => ['ok' => true, 'license_key' => $key, 'expiry_date' => $expires]];
    }

    public function revoke(array $body): array
    {
        return $this->setStatus($body, 'revoked');
    }

    public function reactivate(array $body): array
    {
        return $this->setStatus($body, 'active');
    }

    private function setStatus(array $body, string $newStatus): array
    {
        $key = trim((string) ($body['license_key'] ?? ''));
        if ($key === '') {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_license_key']];
        }

        $db = Database::get();
        $license = $this->findByKey($db, $key);
        if ($license === false) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
        }

        $db->prepare('UPDATE licenses SET status = ? WHERE id = ?')->execute([$newStatus, $license['id']]);
        $this->logEvent($db, $license['id'], $newStatus === 'revoked' ? 'revoked' : 'reactivated', "status {$license['status']} -> {$newStatus}");

        return ['status' => 200, 'body' => ['ok' => true, 'license_key' => $key, 'status' => $newStatus]];
    }

    public function lookup(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_license_key']];
        }

        $db = Database::get();
        $license = $this->findByKey($db, $key);

        if ($license === false) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
        }

        $stmt = $db->prepare('SELECT fingerprint, activated_at, last_seen_at FROM activations WHERE license_id = ? ORDER BY activated_at ASC');
        $stmt->execute([$license['id']]);
        $activations = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT event_type, detail, created_at FROM license_events WHERE license_id = ? ORDER BY created_at DESC');
        $stmt->execute([$license['id']]);
        $events = $stmt->fetchAll();

        // Same physical machine, different license -- the strongest signal
        // for "this customer already had a key" since a fingerprint can't be
        // faked by just typing a different email at generate time.
        $related = [];
        foreach ($activations as $a) {
            foreach ($this->findLicensesByFingerprint($db, $a['fingerprint']) as $l) {
                if ($l['license_key'] !== $license['license_key']) {
                    $related[$l['license_key']] = $l;
                }
            }
        }

        return ['status' => 200, 'body' => [
            'ok' => true,
            'license_key' => $license['license_key'],
            'customer_email' => $license['customer_email'],
            'status' => $license['status'],
            'expiry_date' => $license['expiry_date'],
            'expired' => strtotime($license['expiry_date']) < strtotime(date('Y-m-d')),
            'max_activations' => (int) $license['max_activations'],
            'activations_used' => count($activations),
            'created_at' => $license['created_at'],
            'activations' => array_map(static fn($a) => [
                'fingerprint'   => $a['fingerprint'],
                'activated_at'  => $a['activated_at'],
                'last_seen_at'  => $a['last_seen_at'],
            ], $activations),
            'events' => array_map(static fn($e) => [
                'event_type' => $e['event_type'],
                'detail'     => $e['detail'],
                'created_at' => $e['created_at'],
            ], $events),
            'product' => $license['product_id'] !== null ? [
                'id'      => (int) $license['product_id'],
                'slug'    => $license['product_slug'],
                'name'    => $license['product_name'],
                'folders' => $this->productFolders($db, (int) $license['product_id']),
            ] : null,
            'related_by_fingerprint' => array_map(static fn($l) => [
                'license_key'    => $l['license_key'],
                'customer_email' => $l['customer_email'],
                'expiry_date'    => $l['expiry_date'],
                'status'         => $l['status'],
            ], array_values($related)),
        ]];
    }

    private function findByKey(PDO $db, string $key)
    {
        $stmt = $db->prepare('
            SELECT l.*, p.slug AS product_slug, p.name AS product_name
            FROM licenses l
            LEFT JOIN products p ON p.id = l.product_id
            WHERE l.license_key = ?
        ');
        $stmt->execute([$key]);
        return $stmt->fetch();
    }

    /** Folder paths bundled into a product, alphabetical. */
    private function productFolders(PDO $db, int $productId): array
    {
        $stmt = $db->prepare('SELECT folder_path FROM product_folders WHERE product_id = ? ORDER BY folder_path');
        $stmt->execute([$productId]);
        return array_column($stmt->fetchAll(), 'folder_path');
    }

    /** GET /admin/products -- for populating the Generate form's product dropdown. */
    public function listProducts(): array
    {
        $db = Database::get();
        $products = $db->query('SELECT id, slug, name FROM products ORDER BY name, slug')->fetchAll();
        foreach ($products as &$p) {
            $p['folders'] = $this->productFolders($db, (int) $p['id']);
        }
        unset($p);
        // Deliberately not selecting encryption_key here -- shown once, at
        // creation, same as license_key itself.
        return ['status' => 200, 'body' => ['ok' => true, 'products' => $products]];
    }

    /** GET /admin/repo-folders -- top-level folders in tarang2p1-files, for the "Add new product" picker. */
    public function listRepoFolders(): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: docker-license-test-admin\r\n",
            'timeout' => 8,
        ]]);
        $raw = @file_get_contents('https://api.github.com/repos/rioncoreacademy/tarang2p1-files/contents/', false, $ctx);
        if ($raw === false) {
            return ['status' => 502, 'body' => ['ok' => false, 'error' => 'github_unreachable']];
        }

        $entries = json_decode($raw, true);
        if (!is_array($entries)) {
            return ['status' => 502, 'body' => ['ok' => false, 'error' => 'github_bad_response']];
        }

        $folders = [];
        foreach ($entries as $e) {
            if (($e['type'] ?? '') === 'dir') {
                $folders[] = $e['name'];
            }
        }
        sort($folders);

        return ['status' => 200, 'body' => ['ok' => true, 'folders' => $folders]];
    }

    /** POST /admin/products -- create a new product (one or more folders + their shared decryption key). */
    public function createProduct(array $body): array
    {
        $slug = trim((string) ($body['slug'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $encryptionKey = trim((string) ($body['encryption_key'] ?? ''));
        $folderPaths = array_values(array_unique(array_filter(array_map(
            static fn($f) => trim((string) $f),
            is_array($body['folder_paths'] ?? null) ? $body['folder_paths'] : []
        ))));

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_slug']];
        }
        if (empty($folderPaths) || $encryptionKey === '') {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_folder_paths_or_encryption_key']];
        }

        $db = Database::get();
        try {
            $db->beginTransaction();
            $db->prepare('INSERT INTO products (slug, name, encryption_key) VALUES (?, ?, ?)')
                ->execute([$slug, $name, $encryptionKey]);
            $productId = (int) $db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO product_folders (product_id, folder_path) VALUES (?, ?)');
            foreach ($folderPaths as $folderPath) {
                $stmt->execute([$productId, $folderPath]);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            // Unique constraint on slug or a folder_path already claimed by
            // another product (see db/init.sql) -- most likely re-creating
            // an existing product, or bundling a folder that's already
            // spoken for elsewhere.
            if ((int) $e->getCode() === 23000) {
                return ['status' => 400, 'body' => ['ok' => false, 'error' => 'slug_or_folder_already_exists']];
            }
            throw $e;
        }

        return ['status' => 200, 'body' => [
            'ok' => true,
            'id' => $productId,
            'slug' => $slug,
            'name' => $name,
            'folders' => $folderPaths,
            'encryption_key' => $encryptionKey,
        ]];
    }

    /** Every license (any status) that has an activation with this exact fingerprint. */
    private function findLicensesByFingerprint(PDO $db, string $fingerprint): array
    {
        $stmt = $db->prepare('
            SELECT DISTINCT l.license_key, l.customer_email, l.expiry_date, l.status
            FROM licenses l
            JOIN activations a ON a.license_id = l.id
            WHERE a.fingerprint = ?
        ');
        $stmt->execute([$fingerprint]);
        return $stmt->fetchAll();
    }

    private function logEvent(PDO $db, int $licenseId, string $eventType, string $detail): void
    {
        $db->prepare('INSERT INTO license_events (license_id, event_type, detail) VALUES (?, ?, ?)')
            ->execute([$licenseId, $eventType, $detail]);
    }

    private static function generateKey(string $prefix): string
    {
        // Excludes ambiguous characters: 0/O, 1/I/L.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chars = '';
            for ($i = 0; $i < 4; $i++) {
                $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chars;
        }
        return $prefix . '-' . implode('-', $groups);
    }
}
