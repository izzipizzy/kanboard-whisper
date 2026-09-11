<?php
namespace Kanboard\Plugin\Whisper\Schema;

const VERSION = 1;
function version_1($pdo)
{
    $pdo->exec('CREATE TABLE whisper_connections (user_id INTEGER PRIMARY KEY, identity VARCHAR(32) NOT NULL UNIQUE, granted INTEGER NOT NULL DEFAULT 0, settings TEXT NOT NULL, legacy_state INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $options = $pdo->query('SELECT option, value FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
    $grants = json_decode($options['whisper_granted_users'] ?? '[]', true) ?: [];
    $insert = $pdo->prepare('INSERT INTO whisper_connections (user_id, identity, granted, settings, legacy_state) VALUES (?, ?, ?, ?, 1)');
    foreach ($pdo->query('SELECT id FROM users')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $values = [];
        foreach ($options as $key => $value) {
            $prefix = 'whisper_user_'.$id.'_';
            if (strpos($key, $prefix) === 0) { $values[substr($key, strlen($prefix))] = $value; }
        }
        if ($values || in_array((int)$id, array_map('intval', $grants), true)) {
            $insert->execute([$id, bin2hex(random_bytes(16)), in_array((int)$id, array_map('intval', $grants), true) ? 1 : 0, json_encode($values, JSON_THROW_ON_ERROR)]);
        }
    }
    // Remove both live and orphaned legacy credentials. Migration runs only once.
    $pdo->exec("DELETE FROM settings WHERE option LIKE 'whisper_user_%' OR option = 'whisper_granted_users'");
}
