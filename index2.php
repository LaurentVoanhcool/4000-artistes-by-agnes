<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
@set_time_limit(600);

/**
 * ═══════════════════════════════════════════════════════════════════
 *  AGNES STYLE LAB — index2.php
 *  Copie de index.php en LECTURE SEULE sur la base de données.
 *  On saisit un PROMPT → 1 image par artiste (tous les artistes).
 *  Mêmes protocoles, options, clés API et modèles que index.php.
 *  La base (data/agnesref.db) n'est JAMAIS modifiée :
 *    - ouverture SQLite en mode readonly
 *    - aucune table créée ni écrite
 *    - les images sont stockées dans /images2 (dossier séparé)
 * ═══════════════════════════════════════════════════════════════════
 */

$CONFIG = [
    'base_url'    => 'https://apihub.agnes-ai.com/v1',
    'img_model'   => 'agnes-image-2.5-flash',
    'text_model'  => 'agnes-3.0-flash',
    'data_dir'    => __DIR__ . '/data',        // Partagé avec index.php (clés API) — lecture seule pour la DB
    'images_dir'  => __DIR__ . '/images2',     // Propre à index2.php (ne touche pas aux images d'index.php)
    'db_path'     => __DIR__ . '/data/agnesref.db', // Base de index.php — LECTURE SEULE
    'keys_file'   => __DIR__ . '/data/keys.json',   // Mêmes clés API que index.php
    'stats_file'  => __DIR__ . '/data/key_stats.json',
    'offset_file' => __DIR__ . '/data/offset.txt',
    'img_timeout' => 90,
    'chat_timeout'=> 45,
    'max_fail_before_quarantine' => 5,
    'compress_max_width' => 1024,
    'compress_quality'   => 85,
];

if (!is_dir($CONFIG['images_dir'])) @mkdir($CONFIG['images_dir'], 0755, true);

// ─── Fonctions identiques à index.php (protocole strictement identique) ───

function slugify(string $s): string {
    static $map = ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c','œ'=>'oe','æ'=>'ae',
        'À'=>'A','Á'=>'A','Â'=>'A','Ä'=>'A','Ã'=>'A','Å'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Ô'=>'O','Ö'=>'O','Õ'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ñ'=>'N','Ç'=>'C','Œ'=>'OE','Æ'=>'AE'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-zA-Z0-9]+/', '_', $s) ?? '';
    return trim($s, '_') ?: 'x';
}

function compress_image(string $bin, int $maxW = 1024, int $q = 85): array {
    if (!function_exists('imagecreatefromstring')) return ['data' => $bin, 'ext' => 'png'];
    $img = @imagecreatefromstring($bin);
    if ($img === false) return ['data' => $bin, 'ext' => 'png'];
    $w = imagesx($img); $h = imagesy($img);
    if ($maxW > 0 && $w > $maxW) {
        $newH = (int) round($h * ($maxW / $w));
        $resized = imagecreatetruecolor($maxW, $newH);
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $maxW, $newH, $w, $h);
        imagedestroy($img); $img = $resized;
    } else {
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img); $img = $canvas;
    }
    ob_start(); imagejpeg($img, null, $q); $jpeg = ob_get_clean();
    imagedestroy($img);
    return ['data' => $jpeg, 'ext' => 'jpg'];
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_keys(): array {
    global $CONFIG;
    if (!file_exists($CONFIG['keys_file'])) return [];
    return json_decode((string)file_get_contents($CONFIG['keys_file']), true) ?: [];
}
function save_keys(array $keys): void {
    global $CONFIG;
    $clean = [];
    foreach ($keys as $k) {
        $k = trim((string)$k);
        if (strlen($k) >= 20 && str_starts_with($k, 'sk-') && !in_array($k, $clean, true)) $clean[] = $k;
    }
    file_put_contents($CONFIG['keys_file'], json_encode($clean, JSON_PRETTY_PRINT), LOCK_EX);
}
function load_key_stats(): array {
    global $CONFIG;
    if (!file_exists($CONFIG['stats_file'])) return [];
    return json_decode((string)file_get_contents($CONFIG['stats_file']), true) ?: [];
}
function save_key_stats(array $s): void {
    global $CONFIG;
    file_put_contents($CONFIG['stats_file'], json_encode($s, JSON_PRETTY_PRINT), LOCK_EX);
}
function mark_key_result(string $key, bool $ok): void {
    $s = load_key_stats();
    $s[$key] = $ok ? 0 : (($s[$key] ?? 0) + 1);
    save_key_stats($s);
}
function get_usable_keys(): array {
    global $CONFIG;
    $keys = get_keys();
    if (!$keys) return [];
    $stats = load_key_stats();
    $max = $CONFIG['max_fail_before_quarantine'];
    $usable = array_values(array_filter($keys, fn($k) => ($stats[$k] ?? 0) < $max));
    return $usable ?: $keys;
}
function next_key(array $usable): ?string {
    global $CONFIG;
    if (!$usable) return null;
    $fp = @fopen($CONFIG['offset_file'], 'c+');
    if (!$fp) return $usable[0];
    flock($fp, LOCK_EX);
    $offset = (int)trim((string)stream_get_contents($fp));
    $key = $usable[$offset % count($usable)];
    $offset = ($offset + 1) % max(count($usable), 1);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)$offset);
    flock($fp, LOCK_UN); fclose($fp);
    return $key;
}

/**
 * Base SQLite en LECTURE SEULE absolue.
 * - PDO::ATTR_SUBMODE = SQLITE_SUBMODE_READONLY (PHP >= 8.2.3) empêche toute écriture SQL
 *   même si le fichier est inscriptible.
 * - Fallback : URL URI "mode=ro" de SQLite (lecture seule native).
 * - Aucun CREATE TABLE, aucun seed : on se contente de lire ce qu'a créé index.php.
 */
function db_readonly(): PDO {
    global $CONFIG;
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!file_exists($CONFIG['db_path'])) {
        json_out(['ok' => false, 'error' => 'Base de données introuvable — lance d\'abord index.php pour initialiser le catalogue.'], 500);
    }
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        if (defined('SQLITE_SUBMODE_READONLY')) {
            $opts[PDO::ATTR_SUBMODE] = SQLITE_SUBMODE_READONLY; // LECTURE SEULE
        }
        $pdo = new PDO('sqlite:' . $CONFIG['db_path'], null, null, $opts);
    } catch (Throwable $e) {
        // Fallback : URI file: avec mode=ro (impose la lecture seule au moteur SQLite)
        $pdo = new PDO('sqlite:file:' . $CONFIG['db_path'] . '?mode=ro', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function is_html(string $r): bool {
    $t = strtolower(ltrim(substr($r, 0, 100)));
    return str_starts_with($t, '<!doctype') || str_starts_with($t, '<html') || str_starts_with($t, '<head') || str_starts_with($t, '<body');
}

function agnes(string $path, array $payload, string $key, int $timeout = 90): array {
    global $CONFIG;
    $ch = curl_init($CONFIG['base_url'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'error' => 'Curl: ' . $err, 'status' => 0, 'retryable' => true];
    if (is_html((string)$resp)) return ['ok' => false, 'error' => 'Serveur surchargé', 'status' => 503, 'retryable' => true];
    $data = json_decode((string)$resp, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? substr((string)$resp, 0, 300);
        return ['ok' => false, 'error' => "HTTP $code: $msg", 'status' => $code, 'retryable' => in_array($code, [0, 429, 500, 502, 503, 504])];
    }
    if ($data === null) return ['ok' => false, 'error' => 'Réponse non-JSON', 'status' => $code, 'retryable' => true];
    return ['ok' => true, 'data' => $data, 'status' => $code];
}

function gen_image(string $prompt, string $size = '1K', string $ratio = '3:4'): array {
    global $CONFIG;
    $payload = ['model' => $CONFIG['img_model'], 'prompt' => mb_substr($prompt, 0, 5000),
        'size' => $size, 'ratio' => $ratio, 'extra_body' => ['response_format' => 'b64_json']];
    $usable = get_usable_keys();
    if (!$usable) return ['ok' => false, 'error' => 'Aucune clé API'];
    $maxTries = min(8, count($usable) * 3);
    $lastErr = 'Toutes les clés ont échoué';
    $delay = 1500;
    for ($i = 0; $i < $maxTries; $i++) {
        $key = next_key($usable);
        if (!$key) break;
        $r = agnes('/images/generations', $payload, $key, $CONFIG['img_timeout']);
        if ($r['ok']) {
            $d = $r['data']['data'][0] ?? [];
            if (!empty($d['b64_json'])) {
                $bin = base64_decode($d['b64_json'], true);
                if ($bin !== false) { mark_key_result($key, true); return ['ok' => true, 'data' => $bin]; }
            }
            if (!empty($d['url'])) {
                $img = @file_get_contents($d['url']);
                if ($img !== false) { mark_key_result($key, true); return ['ok' => true, 'data' => $img]; }
            }
            $lastErr = 'Pas d\'image';
            mark_key_result($key, false);
        } else {
            $lastErr = $r['error'];
            mark_key_result($key, false);
            if (empty($r['retryable'])) break;
        }
        usleep($delay * 1000); $delay = min($delay * 2, 8000);
    }
    return ['ok' => false, 'error' => $lastErr];
}

function gen_chat(array $msgs, float $temp = 0.7, int $maxTok = 2500): array {
    global $CONFIG;
    $payload = ['model' => $CONFIG['text_model'], 'messages' => $msgs, 'temperature' => $temp,
        'max_tokens' => $maxTok, 'reasoning_effort' => 'none',
        'chat_template_kwargs' => ['enable_thinking' => false]];
    $usable = get_usable_keys();
    if (!$usable) return ['ok' => false, 'error' => 'Aucune clé API'];
    $maxTries = min(6, count($usable) * 2);
    $lastErr = 'Toutes les clés ont échoué';
    for ($i = 0; $i < $maxTries; $i++) {
        $key = next_key($usable);
        if (!$key) break;
        $r = agnes('/chat/completions', $payload, $key, $CONFIG['chat_timeout']);
        if ($r['ok']) {
            $c = $r['data']['choices'][0]['message']['content'] ?? '';
            if ($c !== '') { mark_key_result($key, true); return ['ok' => true, 'content' => $c]; }
            mark_key_result($key, false); $lastErr = 'Réponse vide';
        } else {
            $lastErr = $r['error']; mark_key_result($key, false);
            if (empty($r['retryable'])) break;
        }
        usleep(800000);
    }
    return ['ok' => false, 'error' => $lastErr];
}

/**
 * Construit le prompt final — même protocole que build_final_prompt() de index.php,
 * mais ici le sujet = le prompt saisi par l'utilisateur dans le formulaire.
 */
function build_final_prompt(string $artist, string $userPrompt, string $globalPrompt = ''): string {
    $parts = [];
    $parts[] = "Create the following scene: {$userPrompt}";
    if (trim($globalPrompt) !== '') {
        $parts[] = "ADDITIONAL CREATIVE REQUIREMENTS (apply these to the scene):\n" . trim($globalPrompt);
    }
    $parts[] = "STYLE PRIORITY: The image must be instantly recognizable as the work of {$artist} — their signature technique, brushwork, color palette, lighting philosophy, composition, and creative identity must dominate the image.";
    $parts[] = "OUTPUT: Professional quality, high resolution, highly detailed.";
    return implode("\n\n", $parts);
}

/** Nom de fichier unique par artiste + session (aucun risque d'écraser une image existante). */
function result_filename(string $slugArtist, string $session, int $resultId): string {
    return $slugArtist . '_' . $session . '_' . $resultId . '.jpg';
}

// ─── Actions (API JSON) ────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$body = null;
if ($action) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true);
    if (!is_array($body)) $body = $_POST;

    try {
        switch ($action) {

            case 'init':
                $db = db_readonly();
                $artists = $db->query("SELECT id, name, birth_year, movement, category FROM artists ORDER BY birth_year ASC")->fetchAll();
                $movements = $db->query("SELECT DISTINCT movement FROM artists WHERE movement != '' ORDER BY movement")->fetchAll(PDO::FETCH_COLUMN);
                json_out([
                    'ok' => true,
                    'readonly' => true,
                    'artists' => $artists,
                    'movements' => $movements,
                    'stats' => [
                        'artists' => count($artists),
                        'keys' => count(get_keys()),
                        'keys_usable' => count(get_usable_keys()),
                    ],
                ]);

            case 'results_view':
                // Liste des résultats STOCKÉS SUR DISQUE dans /images2 (aucune écriture en base)
                $db = db_readonly();
                $page = max(1, (int)($_GET['page'] ?? 1));
                $per = 15;
                $offset = ($page - 1) * $per;
                $sortBy = $_GET['sort'] ?? 'recent';
                $filterMov = trim((string)($_GET['movement'] ?? ''));
                $search = trim((string)($_GET['search'] ?? ''));

                $where = []; $params = [];
                if ($filterMov !== '') { $where[] = "a.movement = ?"; $params[] = $filterMov; }
                if ($search !== '')    { $where[] = "a.name LIKE ?"; $params[] = '%' . $search . '%'; }
                $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $orderMap = [
                    'name'     => 'a.name ASC',
                    'year'     => 'a.birth_year ASC',
                    'movement' => 'a.movement ASC, a.birth_year ASC',
                    'recent'   => 'a.name ASC',
                ];
                $orderSql = $orderMap[$sortBy] ?? 'a.name ASC';

                $stmt = $db->prepare("SELECT a.id, a.name, a.birth_year, a.movement, a.category
                    FROM artists a $whereSql GROUP BY a.id ORDER BY $orderSql LIMIT $per OFFSET $offset");
                $stmt->execute($params);
                $artists = $stmt->fetchAll();

                $stmt = $db->prepare("SELECT COUNT(*) FROM artists a $whereSql");
                $stmt->execute($params);
                $total = (int)$stmt->fetchColumn();

                // Compter les images sur disque pour ces artistes (scan léger du dossier)
                $diskIndex = scan_images_index();

                $result = [];
                foreach ($artists as $a) {
                    $slug = slugify($a['name']);
                    $entries = $diskIndex[$slug] ?? [];
                    usort($entries, fn($x, $y) => strcmp($y['mtime'], $x['mtime'])); // récent en premier
                    $a['images'] = array_slice($entries, 0, 12); // max 12 dernières sessions affichées
                    $a['image_count'] = count($entries);
                    $result[] = $a;
                }
                json_out(['ok' => true, 'artists' => $result, 'total' => $total, 'page' => $page,
                    'per_page' => $per, 'disk_images' => array_sum(array_map(fn($e) => count($e), array_values($diskIndex)))]);

            case 'run_batch':
                // Renvoie les prochains artistes À FAIRE pour le prompt courant (1 image par artiste).
                // La détection "déjà fait" se fait par nom de fichier sur le disque — jamais par écriture SQL.
                $userPrompt = trim((string)($body['prompt'] ?? ''));
                $limit = max(1, min(50, (int)($body['limit'] ?? 10)));
                $includeDone = !empty($body['include_done']);
                if ($userPrompt === '') json_out(['ok' => false, 'error' => 'Prompt vide'], 400);

                $session = preg_match('/^[a-z0-9]{6,16}$/', (string)($body['session'] ?? ''))
                    ? (string)$body['session'] : date('YmdHis');

                $db = db_readonly();
                $artists = $db->query("SELECT id, name, birth_year, movement FROM artists ORDER BY RANDOM()")->fetchAll();
                $diskIndex = scan_images_index();

                $jobs = [];
                foreach ($artists as $a) {
                    if (count($jobs) >= $limit) break;
                    $slug = slugify($a['name']);
                    // Déjà généré pour CE prompt précis ? (hash du prompt dans le nom de fichier)
                    $ph = substr(md5($userPrompt), 0, 8);
                    $already = false;
                    foreach ($diskIndex[$slug] ?? [] as $e) {
                        if (str_contains($e['filename'], '_' . $ph . '_')) { $already = true; break; }
                    }
                    if ($already && !$includeDone) continue;
                    $jobs[] = [
                        'artist_id' => (int)$a['id'],
                        'artist_name' => $a['name'],
                        'movement' => $a['movement'],
                        'already_done' => $already,
                    ];
                }
                json_out(['ok' => true, 'jobs' => $jobs, 'remaining_estimate' => count($jobs),
                    'total_artists' => count($artists), 'session' => $session]);

            case 'generate_one':
                // Génère 1 image pour 1 artiste avec le prompt utilisateur. Écriture UNIQUEMENT sur disque (/images2).
                $artist_id = (int)($body['artist_id'] ?? 0);
                $userPrompt = trim((string)($body['prompt'] ?? ''));
                $globalPrompt = trim((string)($body['global_prompt'] ?? ''));
                $size = in_array((string)($body['size'] ?? '1K'), ['1K','2K','3K','4K'], true) ? (string)$body['size'] : '1K';
                $ratio = in_array((string)($body['ratio'] ?? '3:4'), ['1:1','3:4','4:3','16:9','9:16'], true) ? (string)$body['ratio'] : '3:4';
                $session = preg_match('/^[a-z0-9]{6,16}$/', (string)($body['session'] ?? ''))
                    ? (string)$body['session'] : date('YmdHis');
                if ($userPrompt === '') json_out(['ok' => false, 'error' => 'Prompt vide'], 400);

                $db = db_readonly();
                $stmt = $db->prepare("SELECT * FROM artists WHERE id=?");
                $stmt->execute([$artist_id]);
                $artist = $stmt->fetch();
                if (!$artist) json_out(['ok' => false, 'error' => 'Artiste introuvable'], 404);

                $finalPrompt = build_final_prompt($artist['name'], $userPrompt, $globalPrompt);

                $t0 = microtime(true);
                $r = gen_image($finalPrompt, $size, $ratio);
                $elapsed = (int)((microtime(true) - $t0) * 1000);
                if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error'], 'elapsed_ms' => $elapsed]);

                $slugA = slugify($artist['name']);
                $dir = $CONFIG['images_dir'] . '/' . $slugA;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $comp = compress_image($r['data'], $CONFIG['compress_max_width'], $CONFIG['compress_quality']);
                $ph = substr(md5($userPrompt), 0, 8);
                $fn = $ph . '_' . date('His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
                if (@file_put_contents($dir . '/' . $fn, $comp['data']) === false) {
                    json_out(['ok' => false, 'error' => 'Écriture disque impossible (dossier images2/)']);
                }
                json_out(['ok' => true, 'url' => 'images2/' . $slugA . '/' . $fn,
                    'artist_name' => $artist['name'], 'movement' => $artist['movement'],
                    'birth_year' => $artist['birth_year'],
                    'file_size_kb' => round(strlen($comp['data']) / 1024),
                    'elapsed_ms' => $elapsed, 'prompt_used' => $finalPrompt]);

            case 'optimize_prompt':
                // Identique à index.php — restructure le prompt (Sujet → Scène → Style → Lumière → Composition → Qualité)
                $currentPrompt = trim((string)($body['prompt'] ?? ''));
                if (!$currentPrompt) json_out(['ok' => false, 'error' => 'Prompt vide'], 400);
                $sys = "You are an expert prompt engineer specialized in Agnes AI image generation models.\n"
                     . "Your task: improve and restructure the given prompt to maximize Agnes model quality.\n\n"
                     . "AGNES OPTIMAL STRUCTURE:\n"
                     . "1. SUBJECT (who/what, precise details: age, clothing, expression, action)\n"
                     . "2. SCENE (location, environment, background, objects)\n"
                     . "3. STYLE (artistic style, technique, art references, visual language)\n"
                     . "4. LIGHTING (light source, direction, quality, mood)\n"
                     . "5. COMPOSITION (camera angle, framing, depth, perspective)\n"
                     . "6. QUALITY (resolution, detail level, rendering quality)\n\n"
                     . "RULES:\n"
                     . "- Preserve the original INTENT and ARTIST STYLE reference\n"
                     . "- Keep the SAME language (French if input is French, English if English)\n"
                     . "- Make it VIVID, SPECIFIC, DESCRIPTIVE\n"
                     . "- Return ONLY the improved prompt, no preamble, no quotes, no markdown";
                $r = gen_chat([
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => "Prompt to optimize:\n\n" . $currentPrompt],
                ], 0.6, 900);
                if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']]);
                $improved = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($r['content'])), '"\' \n\r\t');
                json_out(['ok' => true, 'prompt' => $improved]);

            case 'get_keys_info':
                $keys = get_keys();
                $stats = load_key_stats();
                $info = [];
                foreach ($keys as $k) $info[] = ['prefix' => substr($k, 0, 8) . '…' . substr($k, -4), 'fails' => (int)($stats[$k] ?? 0)];
                json_out(['ok' => true, 'count' => count($keys), 'usable' => count(get_usable_keys()), 'info' => $info]);

            case 'set_keys':
                save_keys((array)($body['keys'] ?? []));
                json_out(['ok' => true, 'count' => count(get_keys())]);

            case 'test_keys':
                $keys = get_keys();
                $results = [];
                foreach ($keys as $key) {
                    $r = agnes('/chat/completions', [
                        'model' => $CONFIG['text_model'],
                        'messages' => [['role' => 'user', 'content' => 'ok']],
                        'max_tokens' => 1,
                    ], $key, 15);
                    mark_key_result($key, $r['ok']);
                    $results[] = ['prefix' => substr($key, 0, 8) . '…' . substr($key, -4), 'ok' => $r['ok'], 'status' => $r['status'] ?? 0];
                }
                json_out(['ok' => true, 'results' => $results]);

            case 'reset_key_stats':
                if (file_exists($CONFIG['stats_file'])) @unlink($CONFIG['stats_file']);
                json_out(['ok' => true]);

            case 'disk_usage':
                $size = 0; $count = 0;
                if (is_dir($CONFIG['images_dir'])) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($CONFIG['images_dir'], FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        if ($f->isFile() && in_array(strtolower($f->getExtension()), ['png','jpg','jpeg','webp'])) { $size += $f->getSize(); $count++; }
                    }
                }
                json_out(['ok' => true, 'total_mb' => round($size/1024/1024, 1), 'image_count' => $count, 'avg_kb' => $count > 0 ? round($size/1024/$count) : 0]);

            default:
                json_out(['ok' => false, 'error' => 'Action inconnue: ' . $action], 404);
        }
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    exit;
}

/**
 * Indexe les images déjà présentes dans /images2 (lecture seule du disque).
 * Retourne : [ slug_artiste => [ ['filename'=>..,'url'=>..,'mtime'=>..], ... ], ... ]
 */
function scan_images_index(): array {
    global $CONFIG;
    static $cache = null;
    static $cacheTime = 0;
    // Cache court pour éviter de rescanner à chaque page
    if ($cache !== null && (time() - $cacheTime) < 10) return $cache;
    $index = [];
    $base = $CONFIG['images_dir'];
    if (is_dir($base)) {
        foreach (scandir($base) ?: [] as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $full = $base . '/' . $dir;
            if (!is_dir($full)) continue;
            $list = [];
            foreach (scandir($full) ?: [] as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;
                $list[] = [
                    'filename' => $f,
                    'url' => 'images2/' . rawurlencode($dir) . '/' . rawurlencode($f),
                    'mtime' => date('Y-m-d H:i:s', (int)@filemtime($full . '/' . $f)),
                ];
            }
            if ($list) $index[$dir] = $list;
        }
    }
    $cache = $index; $cacheTime = time();
    return $index;
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agnes Style Lab — Prompt × Tous les artistes (lecture seule)</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0f1a;--bg2:#131c2e;--bg3:#1a2740;--line:#2a3a55;--line2:#3d5070;
  --ink:#e6eef8;--ink2:#8ba3c4;--ink3:#5a7292;
  --accent:#4dabf7;--accent2:#7fa6cc;--gold:#ffd93d;--green:#42e8a4;--red:#ff6b6b;--orange:#ff9940;--purple:#a78bfa;
  --radius:12px;--sans:'Inter',system-ui,sans-serif;--mono:'JetBrains Mono',ui-monospace,monospace;
}
html,body{background:var(--bg);color:var(--ink);font:14px/1.5 var(--sans);-webkit-font-smoothing:antialiased}
.hidden{display:none!important}
button{font:inherit;color:inherit;background:none;border:none;cursor:pointer;font-family:inherit}
input,textarea,select{font-family:inherit;color:inherit}

/* HEADER */
#header{position:sticky;top:0;z-index:50;background:linear-gradient(180deg,#0d1524,#0a0f1a);
  border-bottom:1px solid var(--line);padding:.8rem 1.2rem}
#header .top{display:flex;align-items:center;gap:.8rem;margin-bottom:.7rem;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:.5rem;flex:0 0 auto}
.brand-logo{width:32px;height:32px;border-radius:50%;
  background:conic-gradient(from 45deg,#a78bfa,#ffd93d,#42e8a4,#4dabf7,#a78bfa);
  box-shadow:0 0 20px rgba(167,139,250,.4)}
.brand-name{font:800 1.1rem var(--sans);letter-spacing:-.02em}
.brand-name i{font-style:normal;color:var(--purple)}
.ro-badge{background:rgba(255,217,61,.12);border:1px solid rgba(255,217,61,.4);color:var(--gold);
  font:800 9px var(--mono);letter-spacing:.1em;text-transform:uppercase;padding:.3rem .6rem;border-radius:99px;white-space:nowrap}
.header-stats{display:flex;gap:.6rem;flex-wrap:wrap;flex:1;justify-content:flex-end}
.hs{background:var(--bg2);border:1px solid var(--line);padding:.35rem .7rem;border-radius:99px;
  font:600 10px var(--mono);letter-spacing:.04em;white-space:nowrap}
.hs b{color:var(--accent);font-weight:800;font-size:12px}
.hs.gold b{color:var(--gold)}
.hs.done b{color:var(--green)}

/* FORMULAIRE PROMPT */
.lab-bar{display:flex;gap:.6rem;align-items:stretch;flex-wrap:wrap}
.prompt-wrap{flex:3;min-width:280px;display:flex;flex-direction:column;gap:.4rem}
.prompt-wrap label{font:700 10px var(--mono);letter-spacing:.1em;text-transform:uppercase;color:var(--ink2);
  display:flex;justify-content:space-between;align-items:center}
.prompt-wrap label .count{color:var(--ink3);font-weight:500;text-transform:none;letter-spacing:0}
#userPrompt{width:100%;background:var(--bg2);border:1px solid var(--line);border-radius:12px;
  padding:.65rem 1rem;font:500 13px var(--sans);color:var(--ink);resize:vertical;min-height:74px;
  outline:none;transition:.2s;line-height:1.5}
#userPrompt::placeholder{color:var(--ink3)}
#userPrompt:focus{border-color:var(--purple);background:#0d1524}
.lab-options{flex:2;min-width:260px;display:flex;flex-direction:column;gap:.45rem;justify-content:flex-end}
.opt-row{display:flex;gap:.45rem;align-items:center;flex-wrap:wrap}
.opt-row select,.opt-row input[type=text]{background:var(--bg2);border:1px solid var(--line);border-radius:8px;
  padding:.45rem .6rem;font:600 11px var(--mono);color:var(--ink);outline:none;min-height:34px}
.opt-row select:focus{border-color:var(--accent)}
.opt-row .lbl{font:700 9px var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);min-width:52px}
.opt-row input[type=checkbox]{accent-color:var(--purple);width:15px;height:15px}
.opt-row .check-lbl{font:600 10px var(--mono);color:var(--ink2);display:flex;align-items:center;gap:.35rem;cursor:pointer}
#globalPrompt2{background:var(--bg2);border:1px solid var(--line);border-radius:8px;
  padding:.45rem .6rem;font:500 11px var(--sans);color:var(--ink);outline:none;min-height:34px;width:100%}
#globalPrompt2:focus{border-color:var(--accent)}
.run-actions{display:flex;gap:.5rem;flex:1 1 100%;flex-wrap:wrap}
#runBtn{flex:2;min-height:52px;min-width:220px;padding:.6rem 1.4rem;
  background:linear-gradient(135deg,#a78bfa,#6d28d9);color:#fff;
  border-radius:12px;font:800 .95rem var(--sans);letter-spacing:.04em;text-transform:uppercase;
  display:flex;align-items:center;justify-content:center;gap:.6rem;
  box-shadow:0 6px 20px rgba(167,139,250,.4);transition:.2s}
#runBtn:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(167,139,250,.5)}
#runBtn.running{background:linear-gradient(135deg,#ff6b6b,#a52f28);color:#fff;box-shadow:0 6px 20px rgba(255,107,107,.4)}
#runBtn:not(.running)::before{content:"🎨";font-size:1.2rem}
#runBtn.running::before{content:"⏹"}
#stopBtn{flex:1;min-width:120px;min-height:52px;border-radius:12px;border:1px solid var(--line);
  background:var(--bg3);color:var(--ink2);font:700 .8rem var(--mono);text-transform:uppercase;transition:.2s}
#stopBtn:hover{border-color:var(--red);color:var(--red)}
#optimizeBtn2{flex:1;min-width:140px;min-height:52px;border-radius:12px;border:1px solid var(--line);
  background:var(--bg3);color:var(--ink2);font:700 .8rem var(--mono);text-transform:uppercase;transition:.2s;
  display:flex;align-items:center;justify-content:center;gap:.4rem}
#optimizeBtn2:hover:not(:disabled){border-color:var(--purple);color:var(--purple)}
#optimizeBtn2:disabled{opacity:.4;cursor:not-allowed}

/* PROGRESS */
.progress-wrap{padding:.5rem 0}
.progress-line{display:flex;justify-content:space-between;font:600 10px var(--mono);color:var(--ink2);margin-bottom:.3rem}
.progress-bar{height:6px;background:var(--bg2);border-radius:99px;overflow:hidden;margin-bottom:.4rem}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--purple),var(--accent));transition:width .5s}

/* TABS */
#tabs{display:flex;background:var(--bg2);border-radius:10px;padding:4px;margin:.7rem 0;
  border:1px solid var(--line);overflow-x:auto;gap:2px}
.tab{flex:1;min-width:max-content;padding:.55rem .9rem;border-radius:7px;
  font:700 10px var(--mono);letter-spacing:.1em;text-transform:uppercase;
  color:var(--ink2);transition:.15s;white-space:nowrap}
.tab:hover{color:var(--ink);background:rgba(255,255,255,.04)}
.tab.active{background:var(--purple);color:#0f0620}

/* MAIN */
#main{max-width:1600px;margin:0 auto;padding:0 1.2rem 100px}

/* LIVE LOG */
.live-log{background:#050a14;border:1px solid var(--line);border-radius:10px;padding:.7rem;
  font:500 10px var(--mono);max-height:320px;overflow-y:auto;line-height:1.7;
  scrollbar-width:thin;scrollbar-color:var(--line2) transparent;margin-bottom:1rem}
.live-log .ok{color:var(--green)}
.live-log .err{color:var(--red)}
.live-log .info{color:var(--accent)}
.live-log .grow{color:var(--gold);font-weight:700}
.live-log .warn{color:var(--orange)}
.live-log .art{color:var(--purple);font-weight:700}

/* GALERIE GRILLE */
.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.9rem}
.g-card{background:var(--bg2);border:1px solid var(--line);border-radius:14px;overflow:hidden;
  transition:.25s;position:relative;cursor:pointer}
.g-card:hover{border-color:var(--purple);transform:translateY(-2px);box-shadow:0 12px 30px -10px rgba(0,0,0,.6)}
.g-card .g-imgwrap{aspect-ratio:3/4;background:var(--bg3);overflow:hidden;position:relative}
.g-card img{width:100%;height:100%;object-fit:cover;display:block;transition:.3s}
.g-card:hover img{transform:scale(1.04)}
.g-card .g-new{position:absolute;top:8px;left:8px;background:var(--green);color:#062018;
  font:800 8px var(--mono);padding:.25rem .5rem;border-radius:99px;text-transform:uppercase;letter-spacing:.06em;
  animation:pulse 1.5s infinite}
@keyframes pulse{50%{opacity:.6}}
.g-foot{padding:.55rem .7rem;border-top:1px solid var(--line);background:linear-gradient(135deg,var(--bg3),var(--bg2))}
.g-name{font:800 .85rem var(--sans);letter-spacing:-.01em;color:var(--ink);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.g-meta{font:600 9px var(--mono);color:var(--ink3);text-transform:uppercase;letter-spacing:.06em;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:.15rem}
.g-date{font:500 8.5px var(--mono);color:var(--ink3);margin-top:.2rem}

/* ARTISTE ROWS (vue sessions) */
.artist-block{background:var(--bg2);border:1px solid var(--line);border-radius:14px;margin-bottom:1rem;overflow:hidden}
.artist-head{padding:.7rem .9rem;background:linear-gradient(135deg,var(--bg3),var(--bg2));
  border-bottom:1px solid var(--line);display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.artist-name{font:800 1rem var(--sans);letter-spacing:-.02em;color:var(--ink);flex:1;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.artist-meta{font:600 9px var(--mono);color:var(--ink3);text-transform:uppercase;letter-spacing:.06em;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:0 1 auto}
.badge{padding:.22rem .55rem;border-radius:99px;font:800 9px var(--mono);
  text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
.badge.has{background:rgba(66,232,164,.2);color:var(--green)}
.badge.none{background:rgba(139,163,196,.15);color:var(--ink3)}
.img-strip{display:flex;gap:.5rem;padding:.7rem;overflow-x:auto;scrollbar-width:thin}
.strip-item{flex:0 0 auto;width:130px}
.strip-item .thumb{aspect-ratio:3/4;border-radius:8px;overflow:hidden;background:var(--bg3);
  border:1px solid transparent;cursor:pointer;transition:.2s}
.strip-item .thumb:hover{border-color:var(--purple);transform:scale(1.03)}
.strip-item img{width:100%;height:100%;object-fit:cover;display:block}
.strip-item .cap{font:500 8.5px var(--mono);color:var(--ink3);margin-top:.3rem;text-align:center;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* PAGINATION */
.pagination{display:flex;gap:.4rem;justify-content:center;align-items:center;margin-top:1.4rem;flex-wrap:wrap}
.pbtn{padding:.45rem .9rem;background:var(--bg2);border:1px solid var(--line);
  border-radius:8px;font:700 10px var(--mono);text-transform:uppercase;color:var(--ink2);transition:.15s}
.pbtn:hover:not(:disabled){border-color:var(--accent);color:var(--accent)}
.pbtn:disabled{opacity:.3;cursor:not-allowed}
.page-info{font:600 11px var(--mono);color:var(--ink2);padding:.4rem .8rem}

/* MODAL IMAGE */
.modal-overlay{position:fixed;inset:0;background:rgba(5,10,20,.92);backdrop-filter:blur(8px);
  z-index:200;display:flex;align-items:center;justify-content:center;padding:.8rem;
  opacity:0;pointer-events:none;transition:.25s}
.modal-overlay.on{opacity:1;pointer-events:auto}
.image-modal{background:var(--bg2);border:1px solid var(--line);border-radius:16px;
  max-width:1200px;width:100%;max-height:94vh;display:grid;grid-template-columns:1fr 420px;
  overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.7);position:relative;
  transform:scale(.97);transition:.25s}
.modal-overlay.on .image-modal{transform:scale(1)}
@media(max-width:900px){.image-modal{grid-template-columns:1fr;grid-template-rows:1fr auto;max-height:96vh}}
.image-modal .img-side{background:#000;display:flex;align-items:center;justify-content:center;
  overflow:hidden;min-height:400px;position:relative}
.image-modal .img-side img{max-width:100%;max-height:94vh;object-fit:contain;display:block}
.image-modal .info-side{padding:1.2rem;overflow-y:auto;display:flex;flex-direction:column;gap:.8rem}
@media(max-width:900px){.image-modal .img-side img{max-height:50vh}
  .image-modal .info-side{max-height:none}}
.close-x{position:absolute;top:12px;right:12px;width:36px;height:36px;border-radius:50%;
  background:rgba(0,0,0,.7);color:#fff;font-size:18px;z-index:5;
  display:grid;place-items:center;transition:.2s}
.close-x:hover{background:#000;transform:rotate(90deg)}
.img-title{font:800 1.1rem var(--sans);letter-spacing:-.02em;color:var(--ink);line-height:1.2}
.img-sub{font:600 10px var(--mono);color:var(--ink3);text-transform:uppercase;letter-spacing:.08em;margin-top:.2rem}
.prompt-label{font:700 10px var(--mono);color:var(--ink2);text-transform:uppercase;letter-spacing:.12em;
  display:flex;justify-content:space-between;align-items:center}
.prompt-label .count{color:var(--ink3);font-weight:500}
#modalPrompt{width:100%;min-height:180px;background:#050a14;border:1px solid var(--line);
  border-radius:10px;padding:.7rem;font:500 11.5px/1.6 var(--mono);color:var(--ink);
  resize:vertical;outline:none}
#modalPrompt:focus{border-color:var(--accent)}
.action-grid{display:grid;grid-template-columns:1fr 1fr;gap:.4rem}
.act-btn{min-height:38px;padding:.4rem .7rem;border-radius:9px;
  font:700 .65rem var(--mono);letter-spacing:.08em;text-transform:uppercase;
  border:1px solid var(--line);background:var(--bg3);color:var(--ink2);transition:.2s;
  display:flex;align-items:center;justify-content:center;gap:.3rem}
.act-btn:hover:not(:disabled){border-color:var(--accent);color:var(--accent);background:var(--bg2)}
.act-btn:disabled{opacity:.4;cursor:not-allowed}
.act-btn.primary{grid-column:1/-1;background:linear-gradient(135deg,var(--purple),#6d28d9);color:#fff;border:none}
.act-btn.primary:hover:not(:disabled){background:linear-gradient(135deg,#b79cff,#7c3aed);color:#fff}

/* KEY PANEL */
#keyPanel{position:fixed;bottom:0;left:0;right:0;z-index:40;
  background:linear-gradient(180deg,#0d1524,#050a14);border-top:1px solid var(--line)}
#keyHead{padding:.6rem 1.2rem;cursor:pointer;display:flex;align-items:center;gap:.7rem;
  font:600 10px var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--ink2)}
#keyHead:hover{background:rgba(255,255,255,.03)}
#keyHead b{color:var(--accent);letter-spacing:.1em}
#keyHead .count{margin-left:auto;color:var(--gold);font-weight:800}
#keyBody{padding:0 1.2rem 1rem;border-top:1px solid var(--line);display:none}
#keyBody.open{display:block}
#keyBody textarea{width:100%;background:#050a14;border:1px solid var(--line);border-radius:8px;
  padding:.6rem;font:500 11px var(--mono);color:var(--ink);resize:vertical;min-height:90px;outline:none}
#keyBody textarea:focus{border-color:var(--accent)}
.krow{display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap}
.kbtn{padding:.45rem .9rem;border-radius:8px;font:700 .65rem var(--mono);
  text-transform:uppercase;letter-spacing:.06em;border:1px solid var(--line);
  background:var(--bg3);color:var(--ink2);transition:.15s}
.kbtn:hover{border-color:var(--accent);color:var(--accent)}
.kbtn.primary{background:var(--accent);color:#062018;border-color:var(--accent)}
.key-info{margin-top:.5rem;font:500 10px var(--mono);color:var(--ink3)}
.key-info .k{display:flex;justify-content:space-between;padding:.25rem .4rem;
  border-bottom:1px solid rgba(255,255,255,.04)}
.key-info .k .ok{color:var(--green)}
.key-info .k .fail{color:var(--red)}

/* TOAST */
#toast{position:fixed;top:80px;left:50%;transform:translateX(-50%) translateY(-150px);
  background:var(--bg3);border:1px solid var(--line2);color:var(--ink);
  padding:.65rem 1.3rem;border-radius:99px;font:600 11px var(--mono);
  z-index:300;opacity:0;transition:.3s;box-shadow:0 10px 30px rgba(0,0,0,.5);
  max-width:min(600px,92vw);display:flex;align-items:center;gap:.6rem}
#toast::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--accent);flex-shrink:0}
#toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
#toast.ok::before{background:var(--green);box-shadow:0 0 10px var(--green)}
#toast.err::before{background:var(--red);box-shadow:0 0 10px var(--red)}
#toast.warn::before{background:var(--orange)}

/* EMPTY */
.empty{padding:3rem 1rem;text-align:center;font:500 12px var(--mono);color:var(--ink3)}
.empty::before{content:"◌";display:block;font-size:3rem;color:var(--line2);margin-bottom:.5rem}

/* SPINNER */
.spin{display:inline-block;width:12px;height:12px;border:2px solid rgba(77,171,247,.2);
  border-top-color:var(--accent);border-radius:50%;animation:sp .8s linear infinite;vertical-align:middle}
@keyframes sp{to{transform:rotate(360deg)}}

/* FILTER BAR */
.filters{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center}
.filters input,.filters select{background:var(--bg2);border:1px solid var(--line);border-radius:8px;
  padding:.5rem .8rem;font:500 12px var(--sans);color:var(--ink);outline:none;min-height:36px}
.filters input{flex:1;min-width:180px}
.filters select{min-width:140px;flex:0 1 auto}
.filters input:focus,.filters select:focus{border-color:var(--accent)}

/* NOTE READONLY */
.ro-note{background:rgba(255,217,61,.06);border:1px solid rgba(255,217,61,.25);border-radius:10px;
  padding:.6rem .9rem;font:500 11px var(--mono);color:var(--gold);margin-bottom:1rem;line-height:1.6}
</style>
</head>
<body>

<header id="header">
  <div class="top">
    <div class="brand">
      <div class="brand-logo"></div>
      <div><div class="brand-name">Agnes<i>Lab</i></div></div>
      <div class="ro-badge">🔒 DB lecture seule</div>
    </div>
    <div class="header-stats">
      <div class="hs">👥 <b id="hsArtists">0</b> artistes</div>
      <div class="hs done">🖼 <b id="hsImages">0</b> images lab</div>
      <div class="hs gold">⚙️ <b id="hsWorkers">4</b> workers</div>
      <div class="hs">💾 <b id="hsDisk">0</b> MB</div>
    </div>
  </div>

  <div class="lab-bar">
    <div class="prompt-wrap">
      <label>✍️ Ton prompt — sera rendu dans le style de TOUS les artistes <span class="count" id="promptCount">0 car.</span></label>
      <textarea id="userPrompt" spellcheck="false" placeholder="Ex : un dragon endormi sur une montagne de livres anciens, une ville flottante au coucher du soleil, une nature morte avec des fruits lumineux…"></textarea>
    </div>
    <div class="lab-options">
      <div class="opt-row">
        <span class="lbl">Format</span>
        <select id="optRatio">
          <option value="3:4">3:4 portrait</option>
          <option value="1:1">1:1 carré</option>
          <option value="4:3">4:3 paysage</option>
          <option value="16:9">16:9 ciné</option>
          <option value="9:16">9:16 vertical</option>
        </select>
        <select id="optSize">
          <option value="1K">1K</option>
          <option value="2K">2K</option>
          <option value="3K">3K</option>
          <option value="4K">4K</option>
        </select>
        <select id="optWorkers" title="Workers parallèles">
          <option value="2">2 W</option>
          <option value="4" selected>4 W</option>
          <option value="6">6 W</option>
          <option value="8">8 W</option>
        </select>
      </div>
      <div class="opt-row">
        <input type="text" id="optFilterMovement" placeholder="" style="display:none">
        <select id="selMovement"><option value="">🎨 Tous mouvements (filtre de génération)</option></select>
        <label class="check-lbl"><input type="checkbox" id="optRedo"> ↻ Régénérer même si déjà fait</label>
      </div>
      <input type="text" id="globalPrompt2" placeholder="🌐 Prompt global optionnel (ambiance, contexte…) — comme index.php">
      <div class="run-actions">
        <button id="optimizeBtn2">✨ Optimiser le prompt</button>
        <button id="stopBtn">⏹ Arrêter</button>
      </div>
    </div>
    <div class="run-actions" style="flex:1 1 100%">
      <button id="runBtn">Générer 1 image par artiste</button>
    </div>
  </div>

  <div class="progress-wrap">
    <div class="progress-line"><span>📈 Progression du run</span><span id="progLabel">0%</span></div>
    <div class="progress-bar"><div class="progress-fill" id="progFill" style="width:0%"></div></div>
    <div class="progress-line"><span id="runInfo">En attente — saisis un prompt puis lance la génération</span><span id="runCount">0 / 0</span></div>
  </div>

  <div id="tabs">
    <button class="tab active" data-tab="gallery">🖼 Galerie</button>
    <button class="tab" data-tab="live">🔴 Live</button>
  </div>
</header>

<main id="main">

  <section id="tab-gallery">
    <div class="ro-note">
      🔒 <b>Mode lecture seule</b> : cette page lit la base SQLite de index.php sans jamais la modifier.
      Chaque exécution crée <b>1 image par artiste</b> dans le dossier séparé <b>images2/</b> (les images d'index.php dans images/ ne sont pas touchées).
    </div>
    <div class="filters">
      <input type="text" id="filterSearch" placeholder="🔍 Filtrer par nom d'artiste…">
      <select id="filterMovement"><option value="">🎨 Tous mouvements</option></select>
      <select id="filterSort">
        <option value="recent">🕘 Récent (nom)</option>
        <option value="name">🔤 Nom</option>
        <option value="year">📆 Année</option>
        <option value="movement">🖼️ Mouvement</option>
      </select>
      <button class="pbtn" onclick="loadGallery(1)">↻ Actualiser</button>
    </div>
    <div id="galleryInfo" class="progress-line" style="margin-bottom:.6rem">Chargement…</div>
    <div id="galleryView"></div>
    <div class="pagination" id="pagination"></div>
  </section>

  <section id="tab-live" class="hidden">
    <div class="live-log" id="liveLog"></div>
  </section>

</main>

<!-- MODAL IMAGE -->
<div class="modal-overlay" id="imageModal">
  <div class="image-modal">
    <button class="close-x" onclick="closeModal()">✕</button>
    <div class="img-side"><img id="modalImg" src="" alt=""></div>
    <div class="info-side">
      <div>
        <div class="img-title" id="modalTitle">—</div>
        <div class="img-sub" id="modalSub">—</div>
      </div>
      <div class="prompt-label">Prompt utilisé <span class="count" id="modalPromptCount">—</span></div>
      <textarea id="modalPrompt" spellcheck="false" readonly></textarea>
      <div class="action-grid">
        <button class="act-btn" id="copyBtn">📋 Copier</button>
        <button class="act-btn" id="downloadBtn">📥 Télécharger</button>
        <button class="act-btn primary" id="regenOneBtn">🔁 Refaire CETTE image (même artiste)</button>
      </div>
      <div style="font:500 10px var(--mono);color:var(--ink3);line-height:1.6;padding:.5rem;background:var(--bg3);border-radius:8px">
        💡 Le prompt affiché est le prompt final envoyé à Agnes (scene demandée + exigences de style de l'artiste).
        « Refaire cette image » relance la génération pour le même artiste avec le prompt du formulaire.
      </div>
    </div>
  </div>
</div>

<!-- KEY PANEL -->
<div id="keyPanel">
  <div id="keyHead" onclick="toggleKeys()">
    <b>🔑 API KEYS</b>
    <span id="keySummary">Cliquez pour configurer</span>
    <span class="count" id="keyCount">0</span>
  </div>
  <div id="keyBody">
    <textarea id="keyInput" placeholder="sk-xxx&#10;sk-yyy&#10;sk-zzz&#10;(une par ligne) — partagées avec index.php"></textarea>
    <div class="krow">
      <button class="kbtn primary" onclick="saveKeys()">✓ Valider & tester</button>
      <button class="kbtn" onclick="showKeyInfo()">📊 Statut</button>
      <button class="kbtn" onclick="resetKeyStats()">🔄 Reset</button>
      <button class="kbtn" onclick="clearKeys()">🗑 Effacer</button>
    </div>
    <div class="key-info" id="keyInfo"></div>
  </div>
</div>

<div id="toast"></div>

<script>
'use strict';
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const sleep = ms => new Promise(r => setTimeout(r, ms));

function toast(msg, type='') {
  const t = $('toast'); t.textContent = msg; t.className = 'on ' + type;
  clearTimeout(t._tid); t._tid = setTimeout(() => t.className = type, 4000);
}

async function api(action, params = {}, method = 'POST') {
  const url = 'index2.php?action=' + action + (method === 'GET' && Object.keys(params).length ? '&' + new URLSearchParams(params) : '');
  const opts = { method };
  if (method === 'POST') {
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify(params);
  }
  const r = await fetch(url, opts);
  const txt = await r.text();
  let data;
  try { data = JSON.parse(txt); }
  catch(e) {
    if (txt.trim().startsWith('<')) throw new Error('Serveur surchargé — retry');
    throw new Error('Réponse invalide');
  }
  if (!data.ok) throw new Error(data.error || 'Erreur');
  return data;
}

// ═══ ÉTAT ═══
const STATE = {
  running: false, stopped: false,
  session: '',               // identifiant de run (pour grouper)
  totalArtists: 0,           // nb d'artistes ciblés par le run
  done: 0, errors: 0, skipped: 0,
  keys: 0,
  allArtists: [],            // liste complète (init)
};
const GAL = { page: 1, search: '', movement: '', sort: 'recent', view: 'grid' };

// ═══ INIT ═══
async function init() {
  try {
    const r = await api('init', {}, 'GET');
    STATE.allArtists = r.artists;
    STATE.keys = r.stats.keys_usable || 0;
    populateMovements(r.movements);
    $('hsArtists').textContent = r.stats.artists;
    await loadKeysInfo();
    await refreshDisk();
    await loadGallery(1);
    log(`🧪 Agnes Lab prêt — ${r.stats.artists} artistes en base (lecture seule), ${r.stats.keys_usable} clé(s) API active(s)`, 'art');
  } catch (e) {
    toast('Erreur init : ' + e.message, 'err');
    log('❌ Init: ' + e.message, 'err');
  }
}

function populateMovements(movs) {
  const opts = '<option value="">🎨 Tous mouvements</option>' +
    (movs || []).map(m => `<option value="${esc(m)}">${esc(m)}</option>`).join('');
  $('filterMovement').innerHTML = opts;
  $('selMovement').innerHTML = opts;
}

async function refreshDisk() {
  try {
    const d = await api('disk_usage', {}, 'GET');
    $('hsDisk').textContent = d.total_mb;
    $('hsImages').textContent = d.image_count;
  } catch(e) {}
}

// ═══ COMPTEUR PROMPT ═══
$('userPrompt').addEventListener('input', () => {
  $('promptCount').textContent = $('userPrompt').value.length + ' car.';
});

// ═══ OPTIMISER LE PROMPT (comme index.php) ═══
$('optimizeBtn2').onclick = async () => {
  const p = $('userPrompt').value.trim();
  if (!p) { toast('Prompt vide', 'err'); return; }
  if (!STATE.keys) { toast('Configure tes clés', 'err'); toggleKeys(); return; }
  $('optimizeBtn2').disabled = true;
  $('optimizeBtn2').innerHTML = '<span class="spin"></span> Optimisation…';
  try {
    const r = await api('optimize_prompt', { prompt: p });
    $('userPrompt').value = r.prompt;
    $('promptCount').textContent = r.prompt.length + ' car.';
    toast('✨ Prompt optimisé pour Agnes', 'ok');
  } catch(e) { toast('Erreur : ' + e.message, 'err'); }
  finally {
    $('optimizeBtn2').disabled = false;
    $('optimizeBtn2').textContent = '✨ Optimiser le prompt';
  }
};

// ═══ RUN PRINCIPAL : 1 IMAGE PAR ARTISTE ═══
$('runBtn').onclick = async () => {
  if (STATE.running) { requestStop(); return; }
  const prompt = $('userPrompt').value.trim();
  if (!prompt) { toast('Saisis d’abord un prompt !', 'err'); $('userPrompt').focus(); return; }
  if (!STATE.keys) { toast('Configure tes clés API d\'abord', 'err'); toggleKeys(); return; }

  STATE.running = true; STATE.stopped = false;
  STATE.done = 0; STATE.errors = 0; STATE.skipped = 0;
  STATE.session = 'lab' + Date.now().toString(36);
  $('runBtn').classList.add('running');
  $('runBtn').textContent = 'Génération en cours…';

  const workers = parseInt($('optWorkers').value) || 4;
  $('hsWorkers').textContent = workers;
  const movement = $('selMovement').value;
  const includeDone = $('optRedo').checked;

  log('🚀 Nouveau run — prompt : « ' + prompt.slice(0, 90) + (prompt.length > 90 ? '…' : '') + ' »', 'art');
  log(`⚙️ Options : ${workers} workers · ratio ${$('optRatio').value} · taille ${$('optSize').value}` +
      (movement ? ` · mouvement « ${movement} »` : '') +
      (includeDone ? ' · recréer même si déjà fait' : ''), 'info');

  // Estimer le nombre d'artistes ciblés pour la progression
  let targets = STATE.allArtists;
  if (movement) targets = targets.filter(a => a.movement === movement);
  STATE.totalArtists = targets.length;
  updateProgress();

  await Promise.all(Array.from({length: workers}, (_, i) => artistWorker(i + 1, prompt)));

  STATE.running = false;
  $('runBtn').classList.remove('running');
  $('runBtn').textContent = 'Générer 1 image par artiste';
  log(`✅ Run terminé — ${STATE.done} images, ${STATE.errors} erreurs, ${STATE.skipped} ignorées`, 'grow');
  toast(`Run terminé : ${STATE.done} images (${STATE.errors} err.)`, STATE.errors ? 'warn' : 'ok');
  await refreshDisk();
  await loadGallery(1);
};

$('stopBtn').onclick = () => { if (STATE.running) requestStop(); else toast('Aucun run en cours', 'info'); };
function requestStop() {
  STATE.stopped = true;
  toast('Arrêt demandé…', 'warn');
  log('⏹ Arrêt demandé par l’utilisateur', 'warn');
}

async function artistWorker(id, prompt) {
  while (STATE.running && !STATE.stopped) {
    let batch;
    try {
      batch = await api('run_batch', {
        prompt, limit: 3, session: STATE.session,
        include_done: $('optRedo').checked,
      });
    } catch(e) { log(`⚠ [W${id}] batch: ${e.message.slice(0,60)}`, 'warn'); await sleep(3000); continue; }

    if (!batch.jobs.length) {
      log(`🏁 [W${id}] Plus d'artistes à traiter — worker terminé`, 'info');
      return;
    }
    const job = batch.jobs[0];
    if (job.already_done) { STATE.skipped++; continue; }

    try {
      const r = await api('generate_one', {
        artist_id: job.artist_id,
        prompt: prompt,
        global_prompt: $('globalPrompt2').value.trim(),
        size: $('optSize').value,
        ratio: $('optRatio').value,
        session: STATE.session,
      });
      STATE.done++;
      log(`✓ [W${id}] ${r.artist_name}${r.movement ? ' (' + r.movement + ')' : ''} — ${r.file_size_kb}KB · ${(r.elapsed_ms/1000).toFixed(1)}s`, 'ok');
      pushLiveCard(r);
      updateProgress();
      if (STATE.done % 5 === 0) await refreshDisk();
    } catch(e) {
      STATE.errors++;
      STATE.done++; // compté pour la progression (sinon tourne à l'infini)
      log(`✗ [W${id}] ${job.artist_name} : ${e.message.slice(0,70)}`, 'err');
      updateProgress();
      await sleep(1500);
    }
  }
}

function updateProgress() {
  const pct = STATE.totalArtists > 0 ? Math.min(100, Math.round(STATE.done / STATE.totalArtists * 100)) : 0;
  $('progLabel').textContent = pct + '%';
  $('progFill').style.width = pct + '%';
  $('runCount').textContent = `${STATE.done} / ${STATE.totalArtists}`;
  $('runInfo').textContent = STATE.running
    ? `Session ${STATE.session} — ${STATE.errors} erreur(s)`
    : (STATE.done ? 'Dernier run terminé' : 'En attente — saisis un prompt puis lance la génération');
}

// ═══ CARTE LIVE (aperçu instantané pendant le run) ═══
let liveCards = 0;
function pushLiveCard(r) {
  liveCards++;
  if (liveCards <= 60) {
    const grid = ensureLiveGrid();
    const card = document.createElement('div');
    card.className = 'g-card';
    card.innerHTML = `
      <div class="g-imgwrap">
        <span class="g-new">nouveau</span>
        <img src="${esc(r.url)}" loading="lazy" alt="">
      </div>
      <div class="g-foot">
        <div class="g-name">${esc(r.artist_name)}</div>
        <div class="g-meta">${esc(r.movement || '—')} · ${r.birth_year || '?'}</div>
      </div>`;
    card.onclick = () => openModalFromResult(r.url, r.artist_name, r.movement, r.prompt_used);
    grid.prepend(card);
  }
}
function ensureLiveGrid() {
  let g = $('liveGrid');
  if (!g) {
    g = document.createElement('div');
    g.id = 'liveGrid';
    g.className = 'gallery-grid';
    g.style.marginBottom = '1rem';
    $('tab-gallery').appendChild(g);
  }
  return g;
}

// ═══ LOG ═══
function log(msg, type='info') {
  const box = $('liveLog');
  const div = document.createElement('div');
  div.className = type;
  div.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
  box.appendChild(div);
  while (box.children.length > 300) box.removeChild(box.firstChild);
  box.scrollTop = box.scrollHeight;
}

// ═══ TABS ═══
document.querySelectorAll('.tab').forEach(t => {
  t.onclick = () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.toggle('active', x === t));
    ['gallery', 'live'].forEach(id => $('tab-' + id).classList.toggle('hidden', id !== t.dataset.tab));
    if (t.dataset.tab === 'gallery') loadGallery(GAL.page);
  };
});

// ═══ GALERIE (disque + artistes DB lecture seule) ═══
async function loadGallery(page = 1) {
  GAL.page = page;
  GAL.search = $('filterSearch').value;
  GAL.movement = $('filterMovement').value;
  GAL.sort = $('filterSort').value;

  try {
    const r = await api('results_view', {
      page, sort: GAL.sort === 'recent' ? 'name' : GAL.sort,
      movement: GAL.movement, search: GAL.search,
    }, 'GET');

    const view = $('galleryView');
    let html = '';

    // Grille des toutes dernières images (fusion disque)
    const flat = [];
    for (const a of r.artists) for (const im of a.images) flat.push({ ...im, artist: a });
    flat.sort((x, y) => y.mtime.localeCompare(x.mtime));
    const recent = flat.slice(0, 24);
    if (recent.length) {
      html += `<div class="progress-line" style="margin-bottom:.6rem">🕘 Dernières images du lab (${r.disk_images} au total sur disque)</div>`;
      html += '<div class="gallery-grid" style="margin-bottom:1.6rem">' + recent.map(im => `
        <div class="g-card" data-url="${esc(im.url)}" data-artist="${esc(im.artist.name)}" data-mov="${esc(im.artist.movement || '')}">
          <div class="g-imgwrap"><img src="${esc(im.url)}" loading="lazy" alt=""></div>
          <div class="g-foot">
            <div class="g-name">${esc(im.artist.name)}</div>
            <div class="g-meta">${esc(im.artist.movement || '—')} · ${im.artist.birth_year || '?'}</div>
            <div class="g-date">🕘 ${esc(im.mtime)}</div>
          </div>
        </div>`).join('') + '</div>';
    }

    // Blocs par artiste (sessions multiples)
    if (r.artists.length) {
      html += `<div class="progress-line" style="margin-bottom:.6rem">👥 Artistes (${r.total}) — page ${r.page}/${Math.max(1, Math.ceil(r.total / r.per_page))}</div>`;
      html += r.artists.map(a => `
        <div class="artist-block">
          <div class="artist-head">
            <div class="artist-name">${esc(a.name)}</div>
            <div class="artist-meta">${a.birth_year || '?'} · ${esc(a.movement || '—')} · ${esc(a.category || '')}</div>
            <div class="badge ${a.image_count ? 'has' : 'none'}">${a.image_count ? '🖼 ' + a.image_count : '∅ aucune image lab'}</div>
          </div>
          <div class="img-strip">${
            a.images.length
              ? a.images.map(im => `
                <div class="strip-item">
                  <div class="thumb" data-url="${esc(im.url)}" data-artist="${esc(a.name)}" data-mov="${esc(a.movement || '')}">
                    <img src="${esc(im.url)}" loading="lazy" alt="">
                  </div>
                  <div class="cap" title="${esc(im.filename)}">${esc(im.mtime.slice(5,16))}</div>
                </div>`).join('')
              : '<div style="font:500 10px var(--mono);color:var(--ink3);padding:.3rem">Lance un run pour créer la première image de cet artiste.</div>'
          }</div>
        </div>`).join('');
    } else {
      html += '<div class="empty">Aucun artiste dans la base. Ouvre index.php pour initialiser le catalogue.</div>';
    }

    view.innerHTML = html;
    $('galleryInfo').textContent = `${r.total} artiste(s) · ${r.disk_images} image(s) dans images2/`;

    view.querySelectorAll('.g-card, .strip-item .thumb').forEach(el => {
      el.onclick = () => openModalFromResult(el.dataset.url, el.dataset.artist, el.dataset.mov, '');
    });

    const totalPages = Math.max(1, Math.ceil(r.total / r.per_page));
    const pag = $('pagination');
    pag.innerHTML = totalPages > 1 ? `
      <button class="pbtn" ${page<=1?'disabled':''} onclick="loadGallery(${page-1})">← Préc.</button>
      <span class="page-info">Page ${page} / ${totalPages}</span>
      <button class="pbtn" ${page>=totalPages?'disabled':''} onclick="loadGallery(${page+1})">Suiv. →</button>
    ` : '';
  } catch(e) { toast('Erreur galerie : ' + e.message, 'err'); }
}

// ═══ MODAL ═══
let MODAL = null;
function openModalFromResult(url, artistName, movement, fullPrompt) {
  MODAL = { url, artistName, movement, fullPrompt };
  $('modalImg').src = url;
  $('modalTitle').textContent = artistName;
  $('modalSub').textContent = (movement || '—') + ' · Agnes Lab (lecture seule)';
  const p = fullPrompt || '(prompt complet non conservé pour cette image — relance un run pour le voir)';
  $('modalPrompt').value = p;
  $('modalPromptCount').textContent = p.length + ' car.';
  $('imageModal').classList.add('on');
}
function closeModal() { $('imageModal').classList.remove('on'); MODAL = null; }
$('imageModal').onclick = e => { if (e.target === $('imageModal')) closeModal(); };

$('copyBtn').onclick = async () => {
  if (!MODAL) return;
  try { await navigator.clipboard.writeText(MODAL.fullPrompt || ''); toast('Prompt copié', 'ok'); }
  catch(e) { toast('Rien à copier', 'warn'); }
};

$('downloadBtn').onclick = () => {
  if (!MODAL) return;
  const a = document.createElement('a');
  a.href = MODAL.url;
  a.download = (MODAL.artistName + '_lab.jpg').replace(/\s+/g, '_');
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
};

$('regenOneBtn').onclick = async () => {
  if (!MODAL) return;
  const prompt = $('userPrompt').value.trim();
  if (!prompt) { toast('Saisis un prompt dans le formulaire d’abord', 'err'); return; }
  if (!STATE.keys) { toast('Configure tes clés', 'err'); return; }
  const artist = STATE.allArtists.find(a => a.name === MODAL.artistName);
  if (!artist) { toast('Artiste introuvable en base', 'err'); return; }
  $('regenOneBtn').disabled = true;
  $('regenOneBtn').innerHTML = '<span class="spin"></span> Génération…';
  try {
    const r = await api('generate_one', {
      artist_id: artist.id,
      prompt,
      global_prompt: $('globalPrompt2').value.trim(),
      size: $('optSize').value,
      ratio: $('optRatio').value,
      session: STATE.session || ('lab' + Date.now().toString(36)),
    });
    toast(`Image régénérée (${r.file_size_kb}KB)`, 'ok');
    openModalFromResult(r.url, r.artist_name, r.movement, r.prompt_used);
    await refreshDisk();
  } catch(e) { toast('Erreur : ' + e.message, 'err'); }
  finally {
    $('regenOneBtn').disabled = false;
    $('regenOneBtn').textContent = '🔁 Refaire CETTE image (même artiste)';
  }
};

document.addEventListener('keydown', e => {
  if (!$('imageModal').classList.contains('on')) return;
  if (e.key === 'Escape') closeModal();
  if ((e.key === 'c' || e.key === 'C') && !e.target.matches('textarea')) $('copyBtn').click();
});

// ═══ FILTERS ═══
$('filterSearch').addEventListener('input', () => loadGallery(1));
$('filterMovement').addEventListener('change', () => loadGallery(1));
$('filterSort').addEventListener('change', () => loadGallery(1));

// ═══ KEYS PANEL (identique à index.php) ═══
function toggleKeys() { $('keyBody').classList.toggle('open'); }

async function loadKeysInfo() {
  try {
    const r = await api('get_keys_info', {}, 'GET');
    STATE.keys = r.usable;
    $('keyCount').textContent = r.usable + '/' + r.count;
    $('keySummary').textContent = r.count ? `${r.usable} clés actives` : 'Aucune clé';
    return r;
  } catch(e) {}
}

async function saveKeys() {
  const raw = $('keyInput').value.split('\n').map(l => l.trim()).filter(l => l.startsWith('sk-'));
  if (!raw.length) { toast('Aucune clé valide', 'err'); return; }
  toast('Validation de ' + raw.length + ' clé(s)…', 'info');
  try {
    await api('set_keys', { keys: raw });
    await api('reset_key_stats', {});
    const r = await api('test_keys', {});
    const ok = r.results.filter(x => x.ok).length;
    toast(`${ok}/${raw.length} clés validées`, ok ? 'ok' : 'err');
    await loadKeysInfo();
    await showKeyInfo();
  } catch(e) { toast('Erreur : ' + e.message, 'err'); }
}

async function showKeyInfo() {
  try {
    const r = await api('get_keys_info', {}, 'GET');
    $('keyInfo').innerHTML = r.info.map(k => `
      <div class="k">
        <span>${esc(k.prefix)}</span>
        <span class="${k.fails === 0 ? 'ok' : 'fail'}">${k.fails === 0 ? '✓ OK' : '✗ ' + k.fails + ' échec(s)'}</span>
      </div>
    `).join('');
  } catch(e) {}
}

async function resetKeyStats() {
  if (!confirm('Réinitialiser les échecs des clés ?')) return;
  await api('reset_key_stats', {});
  toast('Stats réinitialisées', 'ok');
  await showKeyInfo();
  await loadKeysInfo();
}

async function clearKeys() {
  if (!confirm('Effacer toutes les clés ?')) return;
  await api('set_keys', { keys: [] });
  $('keyInput').value = '';
  await loadKeysInfo();
  toast('Clés effacées', 'info');
}

// ═══ AUTO-REFRESH PENDANT LE RUN ═══
setInterval(async () => {
  if (STATE.running) await refreshDisk();
}, 8000);

// ═══ START ═══
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
</script>
</body>
</html>
