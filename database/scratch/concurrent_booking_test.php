<?php

/**
 * PHASE 5: Real Simultaneous Booking & Concurrency Verification against MySQL / Apache
 *
 * Requirements:
 *   1. Dedicated database: `project_ppl_padel_test`
 *   2. Real Apache server running at `http://project-ppl-padel.test`
 *   3. cURL multi-handle for genuine parallel HTTP requests from separate customer sessions
 *
 * Scenarios tested:
 *   A. Simultaneous identical slot requests (Customer A: 13:00-14:00 vs Customer B: 13:00-14:00)
 *   B. Simultaneous overlapping interval requests (Customer A: 13:00-14:00 vs Customer B: 13:00-15:00)
 *   C. Simultaneous overlapping interval requests (Customer A: 13:00-14:00 vs Customer B: 12:00-14:00)
 *   D. Simultaneous adjacent interval requests (Customer A: 13:00-14:00 vs Customer B: 14:00-15:00) - both must succeed!
 *   E. Payment expiration and slot re-acquisition
 *   F. Cancelled booking re-confirmation attempt (admin guard)
 */
$baseUrl = $argv[1] ?? 'http://project-ppl-padel.test';
$dbName = 'project_ppl_padel_test';
$rounds = 5;
$testDate = date('Y-m-d', strtotime('+10 days'));

echo "==================================================================\n";
echo "  REAL MYSQL CONCURRENCY TEST (PHASE 5)\n";
echo "  Target: $baseUrl\n";
echo "  Database: $dbName\n";
echo "==================================================================\n\n";

// Connect to the test database
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$dbName", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// ── Step 1: Seed test court & users in project_ppl_padel_test ────────────────
echo "Step 1: Seeding test court & user accounts in $dbName...\n";

// Test court
$pdo->exec("INSERT INTO courts (id, name, location, price_per_hour, created_at, updated_at)
            VALUES (999, '__ConcurrencyTestCourt__', 'Indoor Test', 300000, NOW(), NOW())
            ON DUPLICATE KEY UPDATE name=VALUES(name)");
$courtId = 999;

// User A & User B
$passwordHash = password_hash('password', PASSWORD_BCRYPT);

$pdo->exec("INSERT INTO users (id, name, username, email, password, role, is_online, created_at, updated_at)
            VALUES (991, 'Concurrent Customer A', 'conc_cust_a', 'conc_a@test.local', '$passwordHash', 'user', 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE name=VALUES(name), password=VALUES(password)");

$pdo->exec("INSERT INTO users (id, name, username, email, password, role, is_online, created_at, updated_at)
            VALUES (992, 'Concurrent Customer B', 'conc_cust_b', 'conc_b@test.local', '$passwordHash', 'user', 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE name=VALUES(name), password=VALUES(password)");

// Admin User
$pdo->exec("INSERT INTO users (id, name, username, email, password, role, is_online, created_at, updated_at)
            VALUES (990, 'Concurrent Admin', 'conc_admin', 'conc_admin@test.local', '$passwordHash', 'admin_kasir', 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE name=VALUES(name), password=VALUES(password)");

echo "  Seeded Court ID: $courtId\n";
echo "  Seeded Customer A: conc_a@test.local (ID: 991)\n";
echo "  Seeded Customer B: conc_b@test.local (ID: 992)\n";
echo "  Seeded Admin: conc_admin@test.local (ID: 990)\n\n";

// ── Step 2: Authenticate users and get session cookies ───────────────────────
echo "Step 2: Authenticating customers via HTTP...\n";

function authenticateUser(string $baseUrl, string $username, string $password): array
{
    $cookieFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cookie_'.md5($username).'.txt';
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }

    // 1. GET /login to get initial session & CSRF token
    $ch = curl_init("$baseUrl/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'ConcurrencyTester/1.0',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    preg_match('/<input[^>]+name="_token"[^>]+value="([^"]+)"/', $html, $m);
    $token = $m[1] ?? '';
    if (! $token) {
        throw new RuntimeException("Could not extract CSRF token from /login for $username");
    }

    // 2. POST /login
    $ch = curl_init("$baseUrl/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            '_token' => $token,
            'username' => $username,
            'password' => $password,
        ]),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'ConcurrencyTester/1.0',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || ! str_contains($res, 'Dashboard')) {
        throw new RuntimeException("Failed to login $username, HTTP $code");
    }

    // 3. Extract dashboard CSRF token for subsequent POST requests
    preg_match('/<meta[^>]+name="csrf-token"[^>]+content="([^"]+)"/', $res, $m);
    $dashboardCsrf = $m[1] ?? '';
    if (! $dashboardCsrf) {
        preg_match('/<input[^>]+name="_token"[^>]+value="([^"]+)"/', $res, $m2);
        $dashboardCsrf = $m2[1] ?? $token;
    }

    return [
        'username' => $username,
        'cookie_file' => $cookieFile,
        'csrf_token' => $dashboardCsrf,
    ];
}

$sessionA = authenticateUser($baseUrl, 'conc_cust_a', 'password');
$sessionB = authenticateUser($baseUrl, 'conc_cust_b', 'password');
$sessionAdmin = authenticateUser($baseUrl, 'conc_admin', 'password');

echo '  Customer A session initialized (CSRF: '.substr($sessionA['csrf_token'], 0, 10)."...)\n";
echo '  Customer B session initialized (CSRF: '.substr($sessionB['csrf_token'], 0, 10)."...)\n";
echo '  Admin session initialized (CSRF: '.substr($sessionAdmin['csrf_token'], 0, 10)."...)\n\n";

// Helper to create cURL handle for booking
function makeBookingHandle(
    string $baseUrl,
    array $session,
    int $courtId,
    string $date,
    string $startTime,
    int $durationHours,
    string &$responseBuffer
): CurlHandle {
    // Generate valid dummy PNG image in memory/temp file
    $imgFile = tempnam(sys_get_temp_dir(), 'proof_').'.png';
    $im = imagecreate(10, 10);
    $bg = imagecolorallocate($im, 0, 150, 200);
    imagepng($im, $imgFile);
    imagedestroy($im);

    $ch = curl_init("$baseUrl/bookings");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            '_token' => $session['csrf_token'],
            'court_id' => $courtId,
            'date' => $date,
            'start_time' => $startTime,
            'duration_hours' => $durationHours,
            'payment_proof' => new CURLFile($imgFile, 'image/png', 'proof.png'),
        ],
        CURLOPT_COOKIEFILE => $session['cookie_file'],
        CURLOPT_COOKIEJAR => $session['cookie_file'],
        CURLOPT_FOLLOWLOCATION => true, // follow redirect to see flash in dashboard
        CURLOPT_USERAGENT => 'ConcurrencyTester/1.0',
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$responseBuffer) {
            $responseBuffer .= $data;

            return strlen($data);
        },
    ]);

    return $ch;
}

// ── Test Scenario A: Simultaneous Identical Slot (13:00-14:00) ───────────────
echo "==================================================================\n";
echo "TEST A: SIMULTANEOUS IDENTICAL SLOT (Customer A: 13:00-14:00 vs B: 13:00-14:00)\n";
echo "Running $rounds parallel rounds via cURL multi-exec...\n";
echo "==================================================================\n";

$testAPasses = 0;

for ($r = 1; $r <= $rounds; $r++) {
    // Clean any bookings for this slot
    $pdo->exec("DELETE FROM bookings WHERE court_id = $courtId AND date = '$testDate' AND start_time = '13:00:00'");

    $bodyA = '';
    $bodyB = '';
    $chA = makeBookingHandle($baseUrl, $sessionA, $courtId, $testDate, '13:00', 1, $bodyA);
    $chB = makeBookingHandle($baseUrl, $sessionB, $courtId, $testDate, '13:00', 1, $bodyB);

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $chA);
    curl_multi_add_handle($mh, $chB);

    // Launch both requests concurrently
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.1);
        }
    } while ($active > 0 && $mrc == CURLM_OK);

    $codeA = curl_getinfo($chA, CURLINFO_HTTP_CODE);
    $codeB = curl_getinfo($chB, CURLINFO_HTTP_CODE);

    curl_multi_remove_handle($mh, $chA);
    curl_multi_remove_handle($mh, $chB);
    curl_multi_close($mh);
    curl_close($chA);
    curl_close($chB);

    // Query DB for exact bookings
    $stmt = $pdo->query("SELECT id, user_id, start_time, end_time, status FROM bookings WHERE court_id = $courtId AND date = '$testDate' AND start_time = '13:00:00'");
    $dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = count($dbRows);

    $aSuccess = str_contains($bodyA, 'Booking berhasil dibuat');
    $bSuccess = str_contains($bodyB, 'Booking berhasil dibuat');
    $aConflict = str_contains($bodyA, 'Maaf, lapangan ini baru saja dipesan oleh pelanggan lain');
    $bConflict = str_contains($bodyB, 'Maaf, lapangan ini baru saja dipesan oleh pelanggan lain');

    $winner = $aSuccess ? 'Customer A' : ($bSuccess ? 'Customer B' : 'None');
    $loserConflictMsg = ($aConflict || $bConflict);

    echo "  Round $r: DB Count = $count | Winner: $winner | Loser received conflict message: ".($loserConflictMsg ? 'YES' : 'NO')."\n";

    if ($count === 1 && ($aSuccess xor $bSuccess) && $loserConflictMsg) {
        $testAPasses++;
    } else {
        echo "  ❌ FAILED Round $r: count=$count, aSuccess=".($aSuccess ? '1' : '0').', bSuccess='.($bSuccess ? '1' : '0')."\n";
    }

    usleep(50000); // 50ms pause between rounds
}

echo "Result Test A: $testAPasses / $rounds rounds PASSED.\n\n";

// ── Test Scenario B: Simultaneous Overlapping Intervals (13:00-14:00 vs 13:00-15:00)
echo "==================================================================\n";
echo "TEST B: OVERLAPPING INTERVALS (Customer A: 13:00-14:00 vs Customer B: 13:00-15:00)\n";
echo "==================================================================\n";

$pdo->exec("DELETE FROM bookings WHERE court_id = $courtId AND date = '$testDate'");

$bodyA = '';
$bodyB = '';
$chA = makeBookingHandle($baseUrl, $sessionA, $courtId, $testDate, '13:00', 1, $bodyA);
$chB = makeBookingHandle($baseUrl, $sessionB, $courtId, $testDate, '13:00', 2, $bodyB);

$mh = curl_multi_init();
curl_multi_add_handle($mh, $chA);
curl_multi_add_handle($mh, $chB);

$active = null;
do {
    curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh, 0.1);
    }
} while ($active > 0);

curl_multi_remove_handle($mh, $chA);
curl_multi_remove_handle($mh, $chB);
curl_multi_close($mh);
curl_close($chA);
curl_close($chB);

$dbRows = $pdo->query("SELECT id, user_id, start_time, end_time, status FROM bookings WHERE court_id = $courtId AND date = '$testDate'")->fetchAll(PDO::FETCH_ASSOC);
$countB = count($dbRows);
$testBPass = ($countB === 1);
echo "  DB Bookings created: $countB (Expected: 1)\n";
echo '  Winner: '.($dbRows[0]['user_id'] == 991 ? 'Customer A (1 hr)' : 'Customer B (2 hrs)')."\n";
echo '  Result Test B: '.($testBPass ? "PASSED ✅\n\n" : "FAILED ❌\n\n");

// ── Test Scenario C: Simultaneous Overlapping Intervals (13:00-14:00 vs 12:00-14:00)
echo "==================================================================\n";
echo "TEST C: OVERLAPPING INTERVALS (Customer A: 13:00-14:00 vs Customer B: 12:00-14:00)\n";
echo "==================================================================\n";

$pdo->exec("DELETE FROM bookings WHERE court_id = $courtId AND date = '$testDate'");

$bodyA = '';
$bodyB = '';
$chA = makeBookingHandle($baseUrl, $sessionA, $courtId, $testDate, '13:00', 1, $bodyA);
$chB = makeBookingHandle($baseUrl, $sessionB, $courtId, $testDate, '12:00', 2, $bodyB);

$mh = curl_multi_init();
curl_multi_add_handle($mh, $chA);
curl_multi_add_handle($mh, $chB);

$active = null;
do {
    curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh, 0.1);
    }
} while ($active > 0);

curl_multi_remove_handle($mh, $chA);
curl_multi_remove_handle($mh, $chB);
curl_multi_close($mh);
curl_close($chA);
curl_close($chB);

$dbRows = $pdo->query("SELECT id, user_id, start_time, end_time, status FROM bookings WHERE court_id = $courtId AND date = '$testDate'")->fetchAll(PDO::FETCH_ASSOC);
$countC = count($dbRows);
$testCPass = ($countC === 1);
echo "  DB Bookings created: $countC (Expected: 1)\n";
echo '  Winner: '.($dbRows[0]['user_id'] == 991 ? 'Customer A (13:00-14:00)' : 'Customer B (12:00-14:00)')."\n";
echo '  Result Test C: '.($testCPass ? "PASSED ✅\n\n" : "FAILED ❌\n\n");

// ── Test Scenario D: Adjacent Intervals (13:00-14:00 and 14:00-15:00) ────────
echo "==================================================================\n";
echo "TEST D: ADJACENT SLOTS (Customer A: 13:00-14:00 vs Customer B: 14:00-15:00)\n";
echo "==================================================================\n";

$pdo->exec("DELETE FROM bookings WHERE court_id = $courtId AND date = '$testDate'");

$bodyA = '';
$bodyB = '';
$chA = makeBookingHandle($baseUrl, $sessionA, $courtId, $testDate, '13:00', 1, $bodyA);
$chB = makeBookingHandle($baseUrl, $sessionB, $courtId, $testDate, '14:00', 1, $bodyB);

$mh = curl_multi_init();
curl_multi_add_handle($mh, $chA);
curl_multi_add_handle($mh, $chB);

$active = null;
do {
    curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh, 0.1);
    }
} while ($active > 0);

curl_multi_remove_handle($mh, $chA);
curl_multi_remove_handle($mh, $chB);
curl_multi_close($mh);
curl_close($chA);
curl_close($chB);

$dbRows = $pdo->query("SELECT id, user_id, start_time, end_time, status FROM bookings WHERE court_id = $courtId AND date = '$testDate' ORDER BY start_time")->fetchAll(PDO::FETCH_ASSOC);
$countD = count($dbRows);
$testDPass = ($countD === 2);
echo "  DB Bookings created: $countD (Expected: 2)\n";
foreach ($dbRows as $row) {
    echo "    Booking #{$row['id']} User {$row['user_id']}: {$row['start_time']} - {$row['end_time']}\n";
}
echo '  Result Test D: '.($testDPass ? "PASSED ✅ (Both adjacent slots permitted)\n\n" : "FAILED ❌\n\n");

// ── Test Scenario E: Payment Expiration & Slot Re-acquisition ────────────────
echo "==================================================================\n";
echo "TEST E: EXPIRATION & RE-ACQUISITION\n";
echo "==================================================================\n";

$pdo->exec("DELETE FROM bookings WHERE court_id = $courtId AND date = '$testDate'");

// Customer A books slot 16:00-17:00
$bodyA = '';
$chA = makeBookingHandle($baseUrl, $sessionA, $courtId, $testDate, '16:00', 1, $bodyA);
curl_exec($chA);
curl_close($chA);

$bA = $pdo->query("SELECT id, status FROM bookings WHERE court_id = $courtId AND date = '$testDate' AND start_time = '16:00:00'")->fetch(PDO::FETCH_ASSOC);
echo "  Customer A created pending booking #{$bA['id']}\n";

// Age the booking artificially past 24 hours
$pdo->exec("UPDATE bookings SET created_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = {$bA['id']}");

// Run expiration command in app context pointing to test DB
passthru('php artisan bookings:expire --hours=24', $expResult);

$bAAfter = $pdo->query("SELECT id, status FROM bookings WHERE id = {$bA['id']}")->fetch(PDO::FETCH_ASSOC);
echo "  Booking #{$bA['id']} status after expiration: {$bAAfter['status']} (Expected: cancelled)\n";

// Now Customer B attempts to book the released slot 16:00-17:00
$bodyB = '';
$chB = makeBookingHandle($baseUrl, $sessionB, $courtId, $testDate, '16:00', 1, $bodyB);
curl_exec($chB);
curl_close($chB);

$bB = $pdo->query("SELECT id, user_id, status FROM bookings WHERE court_id = $courtId AND date = '$testDate' AND start_time = '16:00:00' AND user_id = 992")->fetch(PDO::FETCH_ASSOC);
$testEPass = ($bAAfter['status'] === 'cancelled' && $bB && $bB['status'] === 'pending');
echo '  Customer B booked released slot, new booking ID: '.($bB['id'] ?? 'none')."\n";
echo '  Result Test E: '.($testEPass ? "PASSED ✅\n\n" : "FAILED ❌\n\n");

// ── Test Scenario F: Admin cannot confirm an expired/cancelled booking ────────
echo "==================================================================\n";
echo "TEST F: CANCELLED BOOKING CANNOT BE CONFIRMED (Old Callback / Admin Guard)\n";
echo "==================================================================\n";

// Admin attempts to change cancelled booking #bA['id'] to confirmed
$chAdmin = curl_init("$baseUrl/admin/bookings/{$bA['id']}/status");
curl_setopt_array($chAdmin, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $sessionAdmin['csrf_token'],
        'status' => 'confirmed',
    ]),
    CURLOPT_COOKIEFILE => $sessionAdmin['cookie_file'],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'ConcurrencyTester/1.0',
]);
$adminRes = curl_exec($chAdmin);
curl_close($chAdmin);

$bACheck = $pdo->query("SELECT id, status FROM bookings WHERE id = {$bA['id']}")->fetch(PDO::FETCH_ASSOC);
$testFPass = ($bACheck['status'] === 'cancelled');
echo "  Booking #{$bA['id']} status after re-confirm attempt: {$bACheck['status']} (Expected: cancelled)\n";
echo '  Result Test F: '.($testFPass ? "PASSED ✅\n\n" : "FAILED ❌\n\n");

// ── Clean up test data from project_ppl_padel_test ───────────────────────────
echo "Cleaning up test records from $dbName...\n";
$pdo->exec("DELETE FROM bookings WHERE court_id = $courtId");
$pdo->exec("DELETE FROM courts WHERE id = $courtId");
$pdo->exec('DELETE FROM users WHERE id IN (990, 991, 992)');
echo "Done.\n\n";

$allPassed = ($testAPasses === $rounds) && $testBPass && $testCPass && $testDPass && $testEPass && $testFPass;
echo "==================================================================\n";
if ($allPassed) {
    echo "🎉 ALL REAL MYSQL CONCURRENCY TESTS PASSED! (6/6 Scenarios)\n";
} else {
    echo "⚠️  SOME TESTS FAILED! Review output above.\n";
}
echo "==================================================================\n";

exit($allPassed ? 0 : 1);
