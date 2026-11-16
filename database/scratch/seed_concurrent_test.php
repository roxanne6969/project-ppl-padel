<?php

/**
 * Seeds test data for the concurrent booking test.
 * Outputs a JSON file with IDs for the concurrent test script to consume.
 */
require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Court;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

// Create or find test court
$court = Court::firstOrCreate(
    ['name' => '__ConcurrentTestCourt__'],
    ['location' => 'Test', 'price_per_hour' => 300000]
);

// Create or find two test users with known password
$userA = User::firstOrCreate(
    ['email' => 'concurrent_test_a@test.local'],
    [
        'name' => 'Test User A',
        'username' => 'concurrent_test_a',
        'password' => Hash::make('password'),
        'role' => 'user',
    ]
);

$userB = User::firstOrCreate(
    ['email' => 'concurrent_test_b@test.local'],
    [
        'name' => 'Test User B',
        'username' => 'concurrent_test_b',
        'password' => Hash::make('password'),
        'role' => 'user',
    ]
);

$data = [
    'court_id' => $court->id,
    'user_a' => ['id' => $userA->id, 'email' => $userA->email],
    'user_b' => ['id' => $userB->id, 'email' => $userB->email],
];

file_put_contents(__DIR__.'/concurrent_test_data.json', json_encode($data, JSON_PRETTY_PRINT));
echo json_encode($data, JSON_PRETTY_PRINT).PHP_EOL;
exit(0);
