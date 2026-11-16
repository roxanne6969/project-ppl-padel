<?php

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=project_ppl_padel', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo 'MySQL version: '.$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)."\n";
    echo "Active DB: project_ppl_padel\n\n";

    echo "--- Storage Engines ---\n";
    $stmt = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='project_ppl_padel' AND TABLE_NAME IN ('bookings','courts','users')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['TABLE_NAME']}: {$row['ENGINE']}\n";
    }

    echo "\n--- Indexes on bookings ---\n";
    $stmt = $pdo->query('SHOW INDEX FROM bookings');
    $indexes = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $indexes[$row['Key_name']][] = $row['Column_name'];
    }
    foreach ($indexes as $name => $cols) {
        echo "Index: $name (".implode(', ', $cols).")\n";
    }

    echo "\n--- Data Count in project_ppl_padel ---\n";
    echo 'Users: '.$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()."\n";
    echo 'Courts: '.$pdo->query('SELECT COUNT(*) FROM courts')->fetchColumn()."\n";
    echo 'Bookings: '.$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn()."\n";

} catch (Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
    exit(1);
}
