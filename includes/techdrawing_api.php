<?php
/**
 * Tech drawing spec -> the "ดึงจากโปรแกรมพี่ตุ้ม" button on the checklist form.
 *
 * Primary source is the per-drawing SAP spec endpoint on the alm_profile host:
 *
 *   GET {IMAGE_FETCH_API_URL}{tech}/sap-spec/     header: X-Api-Key
 *   -> {"code":"A21688-001","section_code":"A21688","model":"A3200",
 *       "material_1":"6063","temper_1":"T5", ...}
 *
 * which maps to the form as:
 *   model       -> Model      (the model code, e.g. A3200 / I1175)
 *   material_1  -> Material
 *   temper_1    -> Temper
 *
 * It shares the host and key with the drawing-image API, so it needs no config
 * of its own; TECHDRAWING_SPEC_URL / TECHDRAWING_SPEC_KEY override it if the
 * two ever diverge.
 *
 * The spec has no Solid/Hollow flag, so the die type and the section's
 * human-readable name are filled in separately from the SAP techdrawing-active
 * feed (U_TYPE / SectionName). That lookup is best-effort: it is cached, and
 * any failure leaves those two fields empty instead of failing the fetch.
 */

require_once __DIR__ . '/db.php';

const TECHDRAWING_CACHE_TTL = 1800; // seconds
const TECHDRAWING_FEED_URL = 'http://almdc.alumetgroup.com/sap/api/v1/techdrawing-active/';

function drawingSpecBaseUrl(): string
{
    return (string)(env('TECHDRAWING_SPEC_URL') ?: env('IMAGE_FETCH_API_URL') ?: '');
}

function drawingSpecApiKey(): string
{
    return (string)(env('TECHDRAWING_SPEC_KEY') ?: env('IMAGE_FETCH_API_KEY') ?: '');
}

/**
 * Fetch one drawing's SAP spec.
 *
 * @return array<string,mixed>|null  null when the drawing does not exist (404)
 * @throws RuntimeException          on transport errors, auth failures or 5xx
 */
function fetchDrawingSpec(string $tech): ?array
{
    $base = drawingSpecBaseUrl();
    if ($base === '') {
        throw new RuntimeException('IMAGE_FETCH_API_URL (or TECHDRAWING_SPEC_URL) is not set in includes/.env');
    }

    $url = rtrim($base, '/') . '/' . rawurlencode($tech) . '/sap-spec/';
    $timeout = (int)(env('TECHDRAWING_API_TIMEOUT') ?: 15);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-Api-Key: ' . drawingSpecApiKey()],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Drawing spec request failed: ' . $err);
    }
    if ($status === 404) {
        return null; // no such drawing — the caller reports this to the engineer
    }
    if ($status === 401 || $status === 403) {
        // A missing or wrong X-Api-Key, not something the engineer can fix.
        throw new RuntimeException('Drawing spec API rejected the API key (HTTP ' . $status . ')');
    }
    if ($status !== 200) {
        throw new RuntimeException('Drawing spec API returned HTTP ' . $status);
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Drawing spec API returned invalid JSON');
    }
    return $data;
}

/**
 * The SAP techdrawing-active feed, cached on disk. Only used to enrich a spec
 * with U_TYPE and SectionName, so callers treat failure as "not available".
 *
 * @return array<int,array<string,mixed>>
 */
function cachedTechDrawings(): array
{
    $dir = ROOT_PATH . '/logs/cache';
    $file = $dir . '/techdrawings_api.json';

    if (is_file($file) && (time() - filemtime($file)) < TECHDRAWING_CACHE_TTL) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    try {
        $url = (string)(env('TECHDRAWING_FEED_URL') ?: TECHDRAWING_FEED_URL);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => (int)(env('TECHDRAWING_FEED_TIMEOUT') ?: 30),
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            throw new RuntimeException('feed unavailable');
        }
        $rows = json_decode((string)$body, true);
        if (!is_array($rows)) {
            throw new RuntimeException('feed returned invalid JSON');
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Temp file + rename, so a concurrent read never sees a partial cache.
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($rows, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $file);
        }
        return $rows;
    } catch (Throwable $e) {
        if (is_file($file)) {
            $stale = json_decode((string)file_get_contents($file), true);
            if (is_array($stale)) {
                return $stale;
            }
        }
        return [];
    }
}

/**
 * U_TYPE + SectionName for one Tech, or ['', ''] when the feed cannot say.
 *
 * @return array{0:?string,1:string}  [die_type, section_name]
 */
function techDrawingExtras(string $tech): array
{
    $needle = mb_strtolower($tech, 'UTF-8');
    foreach (cachedTechDrawings() as $row) {
        if (mb_strtolower(trim((string)($row['TechDrawingCode'] ?? '')), 'UTF-8') !== $needle) {
            continue;
        }
        $uType = strtoupper(trim((string)($row['U_TYPE'] ?? '')));
        return [
            // Anything other than S/H (the feed has a handful of blanks) leaves
            // the die type alone rather than guessing.
            $uType === 'S' ? 'solid' : ($uType === 'H' ? 'hollow' : null),
            trim(preg_replace('/\s+/u', ' ', (string)($row['SectionName'] ?? ''))),
        ];
    }
    return [null, ''];
}

/**
 * Look one Tech number up and normalise it into the shape the checklist form
 * consumes. Returns null when no such drawing exists; $error is set instead
 * when the API could not be reached at all, so the caller can tell the two
 * apart.
 *
 * @return array{tech:string,model:string,section_code:string,section_name:string,material:string,temper:string,die_type:?string}|null
 */
function findTechDrawing(string $tech, ?string &$error): ?array
{
    $error = null;
    $tech = trim($tech);
    if ($tech === '') {
        return null;
    }

    try {
        $spec = fetchDrawingSpec($tech);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return null;
    }
    if ($spec === null) {
        return null;
    }

    // SAP writes "-" where a value is simply not set; treat that as empty so it
    // never overwrites what the engineer already typed.
    $clean = static function ($v): string {
        $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
        return $s === '-' ? '' : $s;
    };

    [$dieType, $sectionName] = techDrawingExtras($tech);

    return [
        'tech' => $clean($spec['code'] ?? $tech) ?: $tech,
        'model' => $clean($spec['model'] ?? ''),
        'section_code' => $clean($spec['section_code'] ?? ''),
        'section_name' => $sectionName,
        'material' => $clean($spec['material_1'] ?? ''),
        'temper' => $clean($spec['temper_1'] ?? ''),
        'die_type' => $dieType,
    ];
}
