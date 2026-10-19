<?php
// One-time installer. Creates the database, tables and a default admin account.
// DELETE or rename this file after a successful setup.
$cfg = require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = new PDO('mysql:host=' . $cfg['db_host'] . ';charset=utf8mb4', $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = preg_replace('/[^A-Za-z0-9_]/', '', $cfg['db_name']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) {
        $pdo->exec($sql);
    }
    echo "Tables created.\n";

    $accounts = [
        ['System Administrator', 'admin@sagipalert.local', 'admin', 'Admin@12345', null],
        ['Demo Dispatcher', 'dispatcher@sagipalert.local', 'dispatcher', 'Dispatch@12345', null],
        ['Demo Responder (Fire)', 'responder@sagipalert.local', 'responder', 'Respond@12345', 'BFP Minglanilla'],
        ['Demo Resident', 'resident@sagipalert.local', 'resident', 'Resident@12345', null],
    ];
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $ins = $pdo->prepare('INSERT INTO users (full_name, email, role, password_hash, responder_unit) VALUES (?,?,?,?,?)');
    foreach ($accounts as [$n, $em, $role, $pw, $unit]) {
        $chk->execute([$em]);
        if (!$chk->fetchColumn()) {
            $ins->execute([$n, $em, $role, password_hash($pw, PASSWORD_DEFAULT), $unit]);
            echo "Created $role: $em / $pw\n";
        }
    }
    echo "\nSetup complete. Log in at login.php, CHANGE these passwords, then delete setup.php.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Setup failed: ' . $e->getMessage() . "\n";
}
