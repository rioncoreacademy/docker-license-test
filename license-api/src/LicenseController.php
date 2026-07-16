<?php

class LicenseController
{
    public function activate(array $body): array
    {
        [$key, $fingerprint, $error] = $this->readKeyAndFingerprint($body);
        if ($error) {
            return $error;
        }

        $db = Database::get();
        $license = $this->findLicense($db, $key);
        if ($license === false) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
        }

        $stateError = $this->checkLicenseState($license);
        if ($stateError) {
            return $stateError;
        }

        // Already activated on this machine? Treat as success (idempotent).
        $stmt = $db->prepare('SELECT id FROM activations WHERE license_id = ? AND fingerprint = ?');
        $stmt->execute([$license['id'], $fingerprint]);
        if ($stmt->fetch()) {
            $db->prepare('UPDATE activations SET last_seen_at = NOW() WHERE license_id = ? AND fingerprint = ?')
                ->execute([$license['id'], $fingerprint]);
            return ['status' => 200, 'body' => ['ok' => true, 'already_activated' => true]];
        }

        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM activations WHERE license_id = ?');
        $stmt->execute([$license['id']]);
        $count = (int) $stmt->fetch()['n'];

        if ($count >= (int) $license['max_activations']) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'activation_limit_reached']];
        }

        $db->prepare('INSERT INTO activations (license_id, fingerprint) VALUES (?, ?)')
            ->execute([$license['id'], $fingerprint]);

        return ['status' => 200, 'body' => ['ok' => true, 'already_activated' => false]];
    }

    public function validate(array $body): array
    {
        [$key, $fingerprint, $error] = $this->readKeyAndFingerprint($body);
        if ($error) {
            return $error;
        }

        $db = Database::get();
        $license = $this->findLicense($db, $key);
        if ($license === false) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'license_not_found']];
        }

        $stateError = $this->checkLicenseState($license);
        if ($stateError) {
            return $stateError;
        }

        $stmt = $db->prepare('SELECT id FROM activations WHERE license_id = ? AND fingerprint = ?');
        $stmt->execute([$license['id'], $fingerprint]);
        if (!$stmt->fetch()) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'machine_not_activated']];
        }

        $db->prepare('UPDATE activations SET last_seen_at = NOW() WHERE license_id = ? AND fingerprint = ?')
            ->execute([$license['id'], $fingerprint]);

        return ['status' => 200, 'body' => [
            'ok' => true,
            'expiry_date' => $license['expiry_date'],
        ]];
    }

    private function readKeyAndFingerprint(array $body): array
    {
        $key = trim((string) ($body['license_key'] ?? ''));
        $fingerprint = trim((string) ($body['fingerprint'] ?? ''));

        if ($key === '' || $fingerprint === '') {
            return [null, null, ['status' => 400, 'body' => ['ok' => false, 'error' => 'missing_license_key_or_fingerprint']]];
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $fingerprint)) {
            return [null, null, ['status' => 400, 'body' => ['ok' => false, 'error' => 'malformed_fingerprint']]];
        }

        return [$key, $fingerprint, null];
    }

    private function findLicense(PDO $db, string $key)
    {
        $stmt = $db->prepare('SELECT * FROM licenses WHERE license_key = ?');
        $stmt->execute([$key]);
        return $stmt->fetch();
    }

    private function checkLicenseState(array $license): ?array
    {
        if ($license['status'] !== 'active') {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'license_revoked']];
        }

        if (strtotime($license['expiry_date']) < strtotime(date('Y-m-d'))) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'license_expired']];
        }

        return null;
    }
}
