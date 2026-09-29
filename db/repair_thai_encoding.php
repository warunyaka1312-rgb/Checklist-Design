<?php
/**
 * One-off repair for Thai text that was stored after its UTF-8 bytes had
 * already been decoded as the Thai single-byte code page (TIS-620 /
 * windows-874). Such a value looks like "เธเธฒเธฃเธงเธฒเธ..." on every page that
 * shows it, because each original UTF-8 byte became its own character.
 *
 * This is NOT a schema migration — the tables are already utf8mb4 and the
 * application's own write paths (PDO with charset=utf8mb4) store Thai
 * correctly. The damage came from rows imported outside the app, so this
 * script only rewrites the values that are provably mangled.
 *
 * Usage (from the project root, with XAMPP's PHP on PATH):
 *   php db/repair_thai_encoding.php            # dry run: report only
 *   php db/repair_thai_encoding.php --apply    # write the repaired values
 *
 * --apply always dumps the affected rows to db/backup/ first, so the change
 * can be undone by replaying that file.
 */

require_once __DIR__ . '/../includes/db.php';

const REPAIR_TARGETS = [
    // table => [primary key, [text columns to check]]
    'checklist_items'   => ['id', ['topic', 'note']],
    'checklists'        => ['id', ['die_no', 'tech']],
    'checklist_results' => ['id', ['comment', 'custom_topic', 'custom_note']],
    'checklist_history' => ['id', ['note']],
    'die_models'        => ['id', ['name']],
    'customers'         => ['id', ['name']],
    'materials'         => ['id', ['name']],
    'notifications'     => ['id', ['message']],
    'users'             => ['id', ['full_name']],
];

/**
 * Byte => character table for the decoder that caused the damage.
 *
 * The damaged rows contain C1 control characters (U+0081, U+0087 and U+0095
 * were all seen), so the decoder was not true Windows cp874 — that maps 0x95
 * to U+2022. It used the ISO-8859-11 / WHATWG "windows-874" mapping, where
 * every byte up to 0xA0 is the same-numbered code point and only 0xA1-0xFB
 * shifts into the Thai block. iconv's CP874 cannot undo this (it rejects the
 * C1 characters), hence the explicit table.
 *
 * @return array<int,string>
 */
function thaiCodePageTable(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    $table = [];
    for ($b = 0x00; $b <= 0xFF; $b++) {
        if ($b >= 0xA1 && $b <= 0xDA) {
            $cp = 0x0E01 + ($b - 0xA1);      // ก .. ฺ
        } elseif ($b >= 0xDF && $b <= 0xFB) {
            $cp = 0x0E3F + ($b - 0xDF);      // ฿ .. ๛
        } else {
            $cp = $b;                        // identity, incl. the unassigned slots
        }
        $table[$b] = mb_chr($cp, 'UTF-8');
    }

    return $table;
}

/**
 * Undo the misdecoding for one value: map every character back to the byte it
 * was read from, then confirm those bytes are valid UTF-8 Thai.
 *
 * Returns null when the value is not this kind of mojibake — already-correct
 * Thai, plain ASCII and anything unrecoverable are all left alone.
 */
function repairThaiValue(?string $value): ?string
{
    $value = (string)$value;
    if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
        return null;
    }

    static $reverse = null;
    if ($reverse === null) {
        $reverse = array_flip(thaiCodePageTable());
    }

    $bytes = '';
    foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $char) {
        if (!isset($reverse[$char])) {
            return null; // a character this decoder could never have produced
        }
        $bytes .= chr($reverse[$char]);
    }

    if ($bytes === $value || !mb_check_encoding($bytes, 'UTF-8')) {
        return null;
    }
    // The result must actually be Thai, so a clean string is never mangled...
    if (!preg_match('/\p{Thai}/u', $bytes)) {
        return null;
    }
    // ...and must not still carry control characters.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]|\xC2[\x80-\x9F]/', $bytes)) {
        return null;
    }

    return $bytes;
}

/**
 * @return array<int,array{table:string,pk:string,id:mixed,column:string,before:string,after:string}>
 */
function findDamagedValues(PDO $pdo): array
{
    $found = [];

    foreach (REPAIR_TARGETS as $table => [$pk, $columns]) {
        $existing = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $col) {
            $existing[$col['Field']] = true;
        }
        $columns = array_values(array_filter($columns, static fn($c) => isset($existing[$c])));
        if (!$columns) {
            continue;
        }

        $sql = 'SELECT `' . $pk . '`, `' . implode('`, `', $columns) . "` FROM `{$table}`";
        foreach ($pdo->query($sql) as $row) {
            foreach ($columns as $column) {
                $fixed = repairThaiValue($row[$column] ?? null);
                if ($fixed !== null) {
                    $found[] = [
                        'table'  => $table,
                        'pk'     => $pk,
                        'id'     => $row[$pk],
                        'column' => $column,
                        'before' => (string)$row[$column],
                        'after'  => $fixed,
                    ];
                }
            }
        }
    }

    return $found;
}

/** Dump the current value of every row about to change, as replayable UPDATEs. */
function writeBackup(PDO $pdo, array $damaged): string
{
    $dir = __DIR__ . '/backup';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir . '/thai_encoding_rollback_' . date('Ymd_His') . '.sql';

    $lines = [
        '-- Rollback for db/repair_thai_encoding.php, generated ' . date('c'),
        '-- Replay this file to put the original (mojibake) values back.',
        'SET NAMES utf8mb4;',
        '',
    ];
    foreach ($damaged as $d) {
        $lines[] = sprintf(
            'UPDATE `%s` SET `%s` = %s WHERE `%s` = %s;',
            $d['table'],
            $d['column'],
            $pdo->quote($d['before']),
            $d['pk'],
            $pdo->quote((string)$d['id'])
        );
    }

    file_put_contents($path, implode("\n", $lines) . "\n");
    return $path;
}

// ---------------------------------------------------------------------------

$apply = in_array('--apply', $argv, true);
$pdo = getDbConnection();
$damaged = findDamagedValues($pdo);

if (!$damaged) {
    echo "No mis-encoded Thai values found. Nothing to do.\n";
    exit(0);
}

$byTable = [];
foreach ($damaged as $d) {
    $byTable[$d['table']][] = $d;
}

echo ($apply ? 'REPAIRING' : 'DRY RUN — no changes written'), "\n\n";
foreach ($byTable as $table => $rows) {
    echo $table, ': ', count($rows), " value(s)\n";
    foreach ($rows as $d) {
        echo '  ', $d['pk'], '=', $d['id'], ' ', $d['column'], "\n";
        echo '    before: ', $d['before'], "\n";
        echo '    after : ', $d['after'], "\n";
    }
    echo "\n";
}

if (!$apply) {
    echo 'Total: ', count($damaged), " value(s) would be repaired.\n";
    echo "Re-run with --apply to write them (a rollback dump is saved first).\n";
    exit(0);
}

$backup = writeBackup($pdo, $damaged);
echo 'Rollback dump written to: ', $backup, "\n";

$pdo->beginTransaction();
try {
    foreach ($damaged as $d) {
        $stmt = $pdo->prepare("UPDATE `{$d['table']}` SET `{$d['column']}` = ? WHERE `{$d['pk']}` = ?");
        $stmt->execute([$d['after'], $d['id']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'FAILED, rolled back: ', $e->getMessage(), "\n";
    exit(1);
}

$remaining = findDamagedValues($pdo);
echo 'Repaired ', count($damaged), " value(s).\n";
echo 'Remaining mis-encoded values: ', count($remaining), "\n";
exit($remaining ? 1 : 0);
