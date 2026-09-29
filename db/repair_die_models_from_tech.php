<?php
/**
 * One-off cleanup for die_models.
 *
 * The master list was seeded with alloy-looking placeholders (AL-6063,
 * AL-6061) and then picked up hand-typed test values, so it never held real
 * Model codes. A Model is the SAP SectionCode of the profile the die is cut
 * for, which the tech-drawing feed gives for every checklist's Tech number.
 *
 * This repoints each checklist at the correct SectionCode (creating that
 * die_models row if needed) and then deletes every die_models row nothing
 * references any more.
 *
 * Usage (from the project root):
 *   php db/repair_die_models_from_tech.sql.php            # dry run
 *   php db/repair_die_models_from_tech.sql.php --apply
 *
 * A checklist whose Tech is missing or unknown to SAP is left alone and
 * reported, so its model row survives the cleanup rather than being guessed at.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/techdrawing_api.php';

$apply = in_array('--apply', $argv, true);
$pdo = getDbConnection();

$checklists = $pdo->query(
    'SELECT c.id, c.die_no, c.tech, c.model_id, m.name AS model_name
       FROM checklists c
       LEFT JOIN die_models m ON m.id = c.model_id
      ORDER BY c.id'
)->fetchAll();

$apiError = null;
$plan = [];
$skipped = [];

foreach ($checklists as $c) {
    $tech = trim((string)$c['tech']);
    if ($tech === '') {
        $skipped[] = [$c, 'no Tech recorded'];
        continue;
    }
    $drawing = findTechDrawing($tech, $apiError);
    if ($drawing === null) {
        $skipped[] = [$c, $apiError !== null ? 'SAP unreachable: ' . $apiError : 'Tech not found in SAP'];
        continue;
    }
    if ($drawing['model'] === '') {
        $skipped[] = [$c, 'SAP has no model code for this drawing'];
        continue;
    }
    $plan[] = [$c, $drawing['model'], $drawing['section_name']];
}

echo $apply ? "APPLYING\n\n" : "DRY RUN — no changes written\n\n";

echo "-- checklists to repoint --\n";
foreach ($plan as [$c, $newModel, $sectionName]) {
    printf(
        "  #%d  %-14s tech=%-12s  model: %-12s -> %s   (%s)\n",
        $c['id'], $c['die_no'], $c['tech'], (string)$c['model_name'], $newModel, $sectionName
    );
}
if (!$plan) {
    echo "  (none)\n";
}

if ($skipped) {
    echo "\n-- left alone --\n";
    foreach ($skipped as [$c, $why]) {
        printf("  #%d  %-14s tech=%-12s  %s\n", $c['id'], $c['die_no'], (string)$c['tech'], $why);
    }
}

$existingModels = $pdo->query('SELECT id, name FROM die_models ORDER BY id')->fetchAll();
echo "\n-- die_models today --\n";
foreach ($existingModels as $m) {
    echo '  #', $m['id'], '  ', $m['name'], "\n";
}

if (!$apply) {
    echo "\nRe-run with --apply to write these changes.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $findModel = $pdo->prepare('SELECT id FROM die_models WHERE name = ?');
    $insertModel = $pdo->prepare('INSERT INTO die_models (name, is_active) VALUES (?, 1)');
    $setModel = $pdo->prepare('UPDATE checklists SET model_id = ? WHERE id = ?');

    $keep = [];
    foreach ($plan as [$c, $newModel, $_]) {
        $findModel->execute([$newModel]);
        $modelId = $findModel->fetchColumn();
        if ($modelId === false) {
            $insertModel->execute([$newModel]);
            $modelId = (int)$pdo->lastInsertId();
        }
        $modelId = (int)$modelId;
        $setModel->execute([$modelId, $c['id']]);
        $keep[$modelId] = true;
    }

    // Anything no checklist points at is the old placeholder data.
    $stillUsed = $pdo->query('SELECT DISTINCT model_id FROM checklists')->fetchAll(PDO::FETCH_COLUMN);
    $stillUsed = array_map('intval', $stillUsed);

    $deleted = [];
    foreach ($existingModels as $m) {
        if (in_array((int)$m['id'], $stillUsed, true)) {
            continue;
        }
        $pdo->prepare('DELETE FROM die_models WHERE id = ?')->execute([$m['id']]);
        $deleted[] = $m['name'];
    }

    $pdo->commit();

    echo "\nRepointed ", count($plan), " checklist(s).\n";
    echo 'Deleted ', count($deleted), " unused die_models row(s)", $deleted ? ': ' . implode(', ', $deleted) : '', "\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nFAILED, rolled back: ", $e->getMessage(), "\n";
    exit(1);
}

echo "\n-- die_models now --\n";
foreach ($pdo->query('SELECT id, name FROM die_models ORDER BY id') as $m) {
    echo '  #', $m['id'], '  ', $m['name'], "\n";
}
