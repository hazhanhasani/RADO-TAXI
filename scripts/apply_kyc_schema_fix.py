from pathlib import Path

ui = Path('deploy/cpanel/admin/_ui.php')
s = ui.read_text(encoding='utf-8')
old = """function ra_table_exists(PDO $pdo, string $table): bool {\n    try { $s=$pdo->prepare('SHOW TABLES LIKE ?'); $s->execute([$table]); return (bool)$s->fetchColumn(); } catch(Throwable) { return false; }\n}\n"""
new = """function ra_table_exists(PDO $pdo, string $table): bool {\n    try {\n        $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');\n        $s->execute([$table]);\n        return (int)$s->fetchColumn() > 0;\n    } catch(Throwable) { return false; }\n}\n"""
if old not in s:
    raise SystemExit('ra_table_exists target not found')
ui.write_text(s.replace(old, new, 1), encoding='utf-8')

mig = Path('deploy/cpanel/rado-system/lib/migrate.php')
m = mig.read_text(encoding='utf-8')
needle = """    $pdo->exec(\"INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.4.1-shared-hosting-safe') ON DUPLICATE KEY UPDATE version=VALUES(version)\");\n\n    return ['ok' => true, 'files' => $applied];\n"""
replacement = """    $pdo->exec(\"INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.4.1-shared-hosting-safe') ON DUPLICATE KEY UPDATE version=VALUES(version)\");\n\n    // Never report a successful KYC migration unless the required tables are really present.\n    $tableCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');\n    foreach (['driver_verification_profiles','driver_verification_checks','driver_verification_corrections'] as $requiredTable) {\n        $tableCheck->execute([$requiredTable]);\n        if ((int)$tableCheck->fetchColumn() === 0) {\n            throw new RuntimeException('Migration verification failed; missing table: ' . $requiredTable);\n        }\n    }\n\n    return ['ok' => true, 'files' => $applied];\n"""
if needle not in m:
    raise SystemExit('migration verification target not found')
mig.write_text(m.replace(needle, replacement, 1), encoding='utf-8')

print('Applied reliable KYC schema detection + migration verification')
