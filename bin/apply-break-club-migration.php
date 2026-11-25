<?php
declare(strict_types=1);

use App\Repositories\Connection;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    echo "php bin/apply-break-club-migration.php [--apply]\n";
    exit(0);
}
if ($arguments !== [] && $arguments !== ['--apply']) {
    fwrite(STDERR, "Usage: php bin/apply-break-club-migration.php [--apply]\n");
    exit(2);
}

$root = dirname(__DIR__);
$tables = ['vnv_break_members','vnv_break_events','vnv_break_registrations','vnv_break_email_tokens'];

/** Inspect only the additive definitions used by this fixed migration. */
function inspectClubTable(Connection $db, string $table, string $definition): array
{
    $db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name');
    $db->bind(':name',$table);
    $engine = $db->fetchOne();
    if (!$engine) {
        return ['missing'];
    }
    $issues = [];
    if (strcasecmp((string)$engine->ENGINE,'InnoDB') !== 0) {
        $issues[] = 'expected InnoDB';
    }
    // MySQL 8 omits integer display widths. MariaDB can report bigint(20), for example.
    $type = static fn(string $sql): string => preg_replace('/\b(bigint|int|tinyint)\(\d+\)/i','$1',strtolower(trim($sql)));
    $db->query("SHOW COLUMNS FROM `$table`");
    $columns = array_column($db->fetchAll(),'Type','Field');
    preg_match_all("~(?:[(,]\\s*)([a-z_]+)\\s+((?:(?:BIGINT|INT|TINYINT)(?: UNSIGNED)?|VARCHAR\\(\\d+\\)|CHAR\\(\\d+\\)|ENUM\\([^)]*\\)|DATETIME))~i",
        $definition,$required,PREG_SET_ORDER);
    foreach ($required as [, $column, $expected]) {
        if (!isset($columns[$column]) || $type($columns[$column]) !== $type($expected)) {
            $issues[] = "missing or incompatible column $column";
        }
    }
    $db->query("SHOW INDEX FROM `$table`");
    $unique = [];
    foreach ($db->fetchAll() as $index) {
        if ((int)$index->Non_unique === 0) {
            $unique[$index->Key_name][(int)$index->Seq_in_index] = $index->Column_name;
        }
    }
    $actualKeys = [];
    foreach ($unique as $parts) {
        ksort($parts);
        $actualKeys[] = implode(',',$parts);
    }
    preg_match_all('/UNIQUE KEY \w+\s*\(([^)]+)\)/i',$definition,$uniqueKeys);
    $expectedKeys = $uniqueKeys[1];
    $expectedKeys[] = $table === 'vnv_break_email_tokens' ? 'token_hash' : 'id';
    foreach ($expectedKeys as $key) {
        if (!in_array(str_replace(' ','',$key),$actualKeys,true)) {
            $issues[] = "missing unique key ($key)";
        }
    }
    preg_match_all('/FOREIGN KEY\((\w+)\) REFERENCES (\w+)\((\w+)\)/i',$definition,$foreignKeys,PREG_SET_ORDER);
    if ($foreignKeys) {
        $db->query('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name');
        $db->bind(':name',$table);
        $references = [];
        foreach ($db->fetchAll() as $key) {
            $references[$key->COLUMN_NAME] = $key->REFERENCED_TABLE_NAME . '.' . $key->REFERENCED_COLUMN_NAME;
        }
        foreach ($foreignKeys as [, $column, $target, $targetColumn]) {
            if (($references[$column] ?? '') !== "$target.$targetColumn") {
                $issues[] = "missing relationship $column -> $target.$targetColumn";
            }
        }
    }
    return $issues;
}

try {
    require $root . '/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
    $site = strtolower(trim((string)($_ENV['SITE_KEY'] ?? getenv('SITE_KEY') ?: 'vnvevents')));
    if ($site !== 'vnvevents') {
        throw new RuntimeException('This schema is for VNV Events.');
    }
    $_ENV['DATABASE_URL'] ??= getenv('DATABASE_URL') ?: '';
    if ($_ENV['DATABASE_URL'] === '') {
        throw new RuntimeException('DATABASE_URL is not configured.');
    }
    $sql = file_get_contents($root . '/db/20261125_vnv_break_club.sql');
    if ($sql === false) { throw new RuntimeException('Club migration file is missing.'); }
    $sql = preg_replace('/^[ \t]*--[^\r\n]*(\r?\n|$)/m','',$sql);
    $definitions = [];
    foreach (preg_split('/;\s*(?:\r?\n|$)/',trim($sql),-1,PREG_SPLIT_NO_EMPTY) as $statement) {
        $statement = trim($statement);
        if (!preg_match('/^CREATE TABLE IF NOT EXISTS (vnv_break_[a-z_]+)\s*\(/i',$statement,$name)
            || !in_array($name[1],$tables,true) || isset($definitions[$name[1]])) {
            throw new RuntimeException('Unexpected migration definition.');
        }
        $definitions[$name[1]] = $statement;
    }
    if (array_keys($definitions) !== $tables) { throw new RuntimeException('Expected four club table definitions.'); }
    $db = new Connection();
    $pending = [];
    foreach ($definitions as $table=>$definition) {
        $issues = inspectClubTable($db,$table,$definition);
        if ($issues === ['missing']) {
            echo "$table: missing\n";
            $pending[] = $table;
        } elseif ($issues) {
            throw new RuntimeException("$table: " . implode(', ',$issues) . '. Inspect the existing table before applying.');
        } else {
            echo "$table: ready\n";
        }
    }
    if (!$pending) { echo "Break Club schema verified: all four tables are ready.\n"; exit(0); }
    if ($arguments === []) {
        echo "Preflight only. Run with --apply to create " . count($pending) . " missing table(s).\n";
        exit(0);
    }
    foreach ($pending as $table) {
        $db->query($definitions[$table]);
        $db->execute();
    }
    foreach ($definitions as $table=>$definition) {
        if (inspectClubTable($db,$table,$definition)) {
            throw new RuntimeException("The post-apply verification failed for $table.");
        }
    }
    echo "Break Club schema applied and verified: all four tables are ready.\n";
} catch (PDOException $error) {
    // Connection and driver messages can contain account names or DSN values.
    fwrite(STDERR, "Database operation failed. Check the configured connection and migration privileges.\n");
    exit(1);
} catch (Throwable $error) {
    $message = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Check the installation and migration configuration.';
    fwrite(STDERR, $message . "\n");
    exit(1);
}
