<?php
// Generates a new license key and inserts it into the licenses table.
//
// Usage:
//   php bin/generate-license.php --email=customer@example.com --expires=2027-12-31 [--seats=1] [--prefix=TDP1]
//
// Run via `docker compose exec api php bin/generate-license.php ...` locally,
// or directly with PHP on the production host (e.g. Hostinger via SSH).
// Same underlying logic as the POST /admin/generate HTTP endpoint (see
// public/admin.html for the web UI) -- both call AdminController::generate().
//
// --seats defaults to 1: max_activations=1 is what makes a key work on only
// one machine (LicenseController already rejects a second machine's
// fingerprint once the single seat is taken -- see activation_limit_reached).

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/AdminController.php';

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

$args = parseArgs($argv);

if (($args['email'] ?? '') === '' || ($args['expires'] ?? '') === '') {
    fwrite(STDERR, "Usage: php bin/generate-license.php --email=customer@example.com --expires=2027-12-31 [--seats=1] [--prefix=TDP1]\n");
    exit(1);
}

$result = (new AdminController())->generate($args);
$body = $result['body'];

if (!$body['ok']) {
    fwrite(STDERR, "Error: {$body['error']}\n");
    exit(1);
}

echo "License generated:\n";
echo "  Key:     {$body['license_key']}\n";
echo "  Email:   {$body['customer_email']}\n";
echo "  Seats:   {$body['seats']}\n";
echo "  Expires: {$body['expiry_date']}\n";
