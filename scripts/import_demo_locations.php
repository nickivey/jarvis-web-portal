<?php
/**
 * Import Demo Location History
 * 
 * Imports sample location data for demo users for testing and demonstration.
 * 
 * Usage:
 *   php scripts/import_demo_locations.php                    # Use demo@example.com user
 *   php scripts/import_demo_locations.php --user=demo        # By username
 *   php scripts/import_demo_locations.php --user-id=47       # By user ID
 *   php scripts/import_demo_locations.php --clear            # Clear existing locations first
 *   php scripts/import_demo_locations.php --count=20         # Custom demo location count
 */

require_once __DIR__ . '/../db.php';

if (php_sapi_name() !== 'cli') {
    echo "Run from CLI\n";
    exit(2);
}

$opts = getopt('', ['user:', 'user-id:', 'clear::', 'count::']);

// Determine which user to add locations for
$userId = null;

if (isset($opts['user-id'])) {
    $userId = (int)$opts['user-id'];
    if ($userId <= 0) {
        fwrite(STDERR, "Invalid user ID\n");
        exit(1);
    }
} elseif (isset($opts['user'])) {
    $pdo = jarvis_pdo();
    if (!$pdo) {
        fwrite(STDERR, "DB not configured\n");
        exit(2);
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $opts['user']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "User '{$opts['user']}' not found\n");
        exit(1);
    }
    $userId = (int)$row['id'];
} else {
    // Default to demo@example.com or demo username
    $pdo = jarvis_pdo();
    if (!$pdo) {
        fwrite(STDERR, "DB not configured\n");
        exit(2);
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e OR username = :u LIMIT 1');
    $stmt->execute([':e' => 'demo@example.com', ':u' => 'demo']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "Demo user not found (demo@example.com or username 'demo')\n");
        fwrite(STDERR, "Use --user-id=<id> to specify a user\n");
        exit(1);
    }
    $userId = (int)$row['id'];
}

$pdo = jarvis_pdo();
if (!$pdo) {
    fwrite(STDERR, "DB not configured\n");
    exit(2);
}

// Verify user exists
$stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "User ID $userId not found\n");
    exit(1);
}

echo "📍 Importing location history for user #{$userId} ({$user['username']} / {$user['email']})\n";

// Clear existing locations if requested
if (isset($opts['clear'])) {
    $pdo->prepare('DELETE FROM location_logs WHERE user_id = :u')->execute([':u' => $userId]);
    echo "✓ Cleared existing locations\n";
}

// Sample locations with realistic data
$locations = [
    // Home Location (Florida, Orlando area) - repeated for timeline variety
    ['lat' => 28.5383, 'lon' => -81.3792, 'accuracy' => 15, 'source' => 'browser', 'days_ago' => 5],
    
    // Office/Workplace (Downtown Orlando)
    ['lat' => 28.5421, 'lon' => -81.3723, 'accuracy' => 12, 'source' => 'browser', 'days_ago' => 4],
    
    // Coffee Shop (Winter Park area)
    ['lat' => 28.5945, 'lon' => -81.3562, 'accuracy' => 8, 'source' => 'browser', 'days_ago' => 3],
    
    // Shopping Center (Millenia)
    ['lat' => 28.5166, 'lon' => -81.3836, 'accuracy' => 20, 'source' => 'browser', 'days_ago' => 2],
    
    // Home (evening)
    ['lat' => 28.5383, 'lon' => -81.3792, 'accuracy' => 10, 'source' => 'browser', 'days_ago' => 1],
    
    // Office (morning)
    ['lat' => 28.5421, 'lon' => -81.3723, 'accuracy' => 9, 'source' => 'browser', 'days_ago' => 0.5],
    
    // Park/Recreation (Lake Eustis area)
    ['lat' => 28.7452, 'lon' => -81.7365, 'accuracy' => 25, 'source' => 'device', 'days_ago' => 0.33],
    
    // Home (recent)
    ['lat' => 28.5383, 'lon' => -81.3792, 'accuracy' => 7, 'source' => 'browser', 'days_ago' => 0.04],
    
    // Beach location (Daytona Beach)
    ['lat' => 29.2108, 'lon' => -80.9401, 'accuracy' => 35, 'source' => 'device', 'days_ago' => 8],
    
    // Theme Park area (Universal/Disney proximity)
    ['lat' => 28.4756, 'lon' => -81.4670, 'accuracy' => 50, 'source' => 'browser', 'days_ago' => 6],
    
    // Airport location (MCO)
    ['lat' => 28.4312, 'lon' => -81.3088, 'accuracy' => 100, 'source' => 'device', 'days_ago' => 10],
    
    // Gym/Fitness (near home)
    ['lat' => 28.5400, 'lon' => -81.3810, 'accuracy' => 11, 'source' => 'browser', 'days_ago' => 0.3],
];

// Allow custom count
$count = isset($opts['count']) ? (int)$opts['count'] : count($locations);
$locations = array_slice($locations, 0, max(1, $count));

$stmt = $pdo->prepare('INSERT INTO location_logs (user_id, lat, lon, accuracy_m, source, created_at) VALUES (:u, :la, :lo, :a, :s, :c)');

$inserted = 0;
foreach ($locations as $loc) {
    try {
        $daysAgo = $loc['days_ago'];
        $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        
        $stmt->execute([
            ':u' => $userId,
            ':la' => (float)$loc['lat'],
            ':lo' => (float)$loc['lon'],
            ':a' => (float)$loc['accuracy'],
            ':s' => $loc['source'],
            ':c' => $createdAt
        ]);
        $inserted++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Error inserting location: " . $e->getMessage() . "\n");
    }
}

echo "✓ Imported {$inserted} location entries\n";

// Show stats
$stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM location_logs WHERE user_id = :u');
$stmt->execute([':u' => $userId]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total = (int)$result['cnt'];

echo "📊 Total locations for this user: {$total}\n";

exit(0);
