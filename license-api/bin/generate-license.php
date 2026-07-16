<?php
// Generates a new license key and inserts it into the licenses table.
//
// Usage:
//   php bin/generate-license.php --email=customer@example.com --expires=2027-12-31 [--seats=1] [--prefix=TDP1]
//
// Run via `docker compose exec api php bin/generate-license.php ...` locally,
// or directly with PHP on the production host (e.g. Hostinger via SSH).
//
// --seats defaults to 1: max_activations=1 is what makes a key work on only
// one machine (LicenseController already rejects a second machine's
// fingerprint once the single seat is taken -- see activation_limit_reached).

require __DIR__ . '/../src/Database.php';

function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
            $args[$m[1]] = $m[2];
        }
    }
    return $args;
}

function generateKey(string $prefix): string
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

$args = parseArgs($argv);

$email = trim((string) ($args['email'] ?? ''));
$expires = trim((string) ($args['expires'] ?? ''));
$seats = isset($args['seats']) ? (int) $args['seats'] : 1;
$prefix = trim((string) ($args['prefix'] ?? 'TDP1'));

if ($email === '' || $expires === '') {
    fwrite(STDERR, "Usage: php bin/generate-license.php --email=customer@example.com --expires=2027-12-31 [--seats=1] [--prefix=TDP1]\n");
    exit(1);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || strtotime($expires) === false) {
    fwrite(STDERR, "Error: --expires must be a valid date in YYYY-MM-DD format.\n");
    exit(1);
}

if ($seats < 1) {
    fwrite(STDERR, "Error: --seats must be at least 1.\n");
    exit(1);
}

$db = Database::get();

$key = null;
for ($attempt = 0; $attempt < 5; $attempt++) {
    $candidate = generateKey($prefix);
    $stmt = $db->prepare('SELECT id FROM licenses WHERE license_key = ?');
    $stmt->execute([$candidate]);
    if (!$stmt->fetch()) {
        $key = $candidate;
        break;
    }
}

if ($key === null) {
    fwrite(STDERR, "Error: could not generate a unique license key after 5 attempts.\n");
    exit(1);
}

$db->prepare('INSERT INTO licenses (license_key, customer_email, max_activations, expiry_date, status) VALUES (?, ?, ?, ?, ?)')
    ->execute([$key, $email, $seats, $expires, 'active']);

echo "License generated:\n";
echo "  Key:     {$key}\n";
echo "  Email:   {$email}\n";
echo "  Seats:   {$seats}\n";
echo "  Expires: {$expires}\n";
