<?php

class AdminController
{
    public function generate(array $body): array
    {
        $email = trim((string) ($body['email'] ?? ''));
        $expires = trim((string) ($body['expires'] ?? ''));
        $seats = ($body['seats'] ?? '') !== '' ? (int) $body['seats'] : 1;
        $prefix = trim((string) ($body['prefix'] ?? '')) ?: 'TDP1';

        if ($email === '' || $expires === '') {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_email_or_expires']];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || strtotime($expires) === false) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_expires_date']];
        }

        if ($seats < 1) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_seats']];
        }

        $db = Database::get();

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

        $db->prepare('INSERT INTO licenses (license_key, customer_email, max_activations, expiry_date, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$key, $email, $seats, $expires, 'active']);

        return ['status' => 200, 'body' => [
            'ok' => true,
            'license_key' => $key,
            'customer_email' => $email,
            'seats' => $seats,
            'expiry_date' => $expires,
        ]];
    }

    public function lookup(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_license_key']];
        }

        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM licenses WHERE license_key = ?');
        $stmt->execute([$key]);
        $license = $stmt->fetch();

        if ($license === false) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
        }

        $stmt = $db->prepare('SELECT fingerprint, activated_at, last_seen_at FROM activations WHERE license_id = ? ORDER BY activated_at ASC');
        $stmt->execute([$license['id']]);
        $activations = $stmt->fetchAll();

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
        ]];
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
