<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
@set_time_limit(180);

$CONFIG = [
    'base_url'    => 'https://apihub.agnes-ai.com/v1',
    'img_model'   => 'agnes-image-2.5-flash',
    'text_model'  => 'agnes-3.0-flash',
    'data_dir'    => __DIR__ . '/data',
    'images_dir'  => __DIR__ . '/images',
    'db_path'     => __DIR__ . '/data/agnesref.db',
    'keys_file'   => __DIR__ . '/data/keys.json',
    'stats_file'  => __DIR__ . '/data/key_stats.json',
    'offset_file' => __DIR__ . '/data/offset.txt',
    'img_timeout' => 90,
    'chat_timeout'=> 45,
    'max_fail_before_quarantine' => 5,
    'compress_max_width' => 1024,
    'compress_quality'   => 85,
    'goal_artists'       => 4000,
];

foreach ([$CONFIG['data_dir'], $CONFIG['images_dir']] as $d) if (!is_dir($d)) @mkdir($d, 0755, true);
@file_put_contents($CONFIG['data_dir'] . '/.htaccess', "Require all denied\nDeny from all\n");

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

function db(): PDO {
    global $CONFIG;
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . $CONFIG['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA busy_timeout=5000");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE, birth_year INTEGER, movement TEXT, category TEXT,
        batch_id INTEGER DEFAULT 0, created_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS concepts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE, category TEXT, batch_id INTEGER DEFAULT 0,
        is_core INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 99, created_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        artist_id INTEGER, concept_id INTEGER, artist_name TEXT, concept_name TEXT,
        prompt TEXT, filename TEXT, status TEXT, error TEXT,
        rating INTEGER DEFAULT 0, is_favorite INTEGER DEFAULT 0,
        parent_id INTEGER DEFAULT NULL, is_regeneration INTEGER DEFAULT 0,
        gen_time_ms INTEGER DEFAULT 0, created_at TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tests_status ON tests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tests_artist ON tests(artist_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tests_combo ON tests(artist_id, concept_id, status, is_regeneration)");
    return $pdo;
}

// 2 sujets par défaut
$CORE_SUBJECTS = [
    ['Portrait homme et femme', 'personne', 1, "a dual portrait of a man and a woman together, both faces clearly visible, complementary composition"],
    ['Paysage ville', 'lieu', 2, "a cityscape with buildings, streets and urban architecture, detailed urban scenery"],
];
function ensure_core_subjects(): void {
    global $CORE_SUBJECTS;
    $db = db();
    $stmt = $db->prepare("SELECT id FROM concepts WHERE name = ?");
    $ins = $db->prepare("INSERT INTO concepts (name, category, batch_id, is_core, sort_order, created_at) VALUES (?, ?, 100, 1, ?, ?)");
    $upd = $db->prepare("UPDATE concepts SET is_core=1, sort_order=?, category=? WHERE id=?");
    $now = date('Y-m-d H:i:s');
    foreach ($CORE_SUBJECTS as [$name, $cat, $order]) {
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if ($row) $upd->execute([$order, $cat, $row['id']]);
        else $ins->execute([$name, $cat, $order, $now]);
    }
}

$SEED = [
    ['Léonard de Vinci', 1452, 'Renaissance', 'peintre'], ['Michel-Ange', 1475, 'Renaissance', 'peintre'],
    ['Raphaël', 1483, 'Renaissance', 'peintre'], ['Le Caravage', 1571, 'Baroque', 'peintre'],
    ['Rembrandt', 1606, 'Baroque', 'peintre'], ['Vermeer', 1632, 'Baroque', 'peintre'],
    ['Hokusai', 1760, 'Ukiyo-e', 'estampe'], ['Francisco Goya', 1746, 'Romantisme', 'peintre'],
    ['William Turner', 1775, 'Romantisme', 'peintre'], ['Eugène Delacroix', 1798, 'Romantisme', 'peintre'],
    ['Gustave Courbet', 1819, 'Réalisme', 'peintre'], ['Édouard Manet', 1832, 'Impressionnisme', 'peintre'],
    ['Edgar Degas', 1834, 'Impressionnisme', 'peintre'], ['Claude Monet', 1840, 'Impressionnisme', 'peintre'],
    ['Auguste Renoir', 1841, 'Impressionnisme', 'peintre'], ['Paul Cézanne', 1839, 'Post-Impressionnisme', 'peintre'],
    ['Vincent van Gogh', 1853, 'Post-Impressionnisme', 'peintre'], ['Paul Gauguin', 1848, 'Post-Impressionnisme', 'peintre'],
    ['Alfons Mucha', 1860, 'Art Nouveau', 'affichiste'], ['Gustav Klimt', 1862, 'Art Nouveau', 'peintre'],
    ['Edvard Munch', 1863, 'Expressionnisme', 'peintre'], ['Henri de Toulouse-Lautrec', 1864, 'Post-Impressionnisme', 'affichiste'],
    ['Wassily Kandinsky', 1866, 'Expressionnisme abstrait', 'peintre'], ['Henri Matisse', 1869, 'Fauvisme', 'peintre'],
    ['Piet Mondrian', 1872, 'De Stijl', 'peintre'], ['Paul Klee', 1879, 'Bauhaus', 'peintre'],
    ['Pablo Picasso', 1881, 'Cubisme', 'peintre'], ['Egon Schiele', 1890, 'Expressionnisme', 'peintre'],
    ['Man Ray', 1890, 'Dada/Surréalisme', 'photographe'], ['Salvador Dalí', 1904, 'Surréalisme', 'peintre'],
    ['René Magritte', 1898, 'Surréalisme', 'peintre'], ['Ansel Adams', 1902, 'Photographie', 'photographe'],
    ['Hergé', 1907, 'Ligne claire', 'bédéiste'], ['Henri Cartier-Bresson', 1908, 'Photographie', 'photographe'],
    ['Andy Warhol', 1928, 'Pop Art', 'artiste'], ['Roy Lichtenstein', 1923, 'Pop Art', 'peintre'],
    ['Osamu Tezuka', 1928, 'Manga', 'mangaka'], ['Jean Giraud (Mœbius)', 1938, 'BD', 'bédéiste'],
    ['Hayao Miyazaki', 1941, 'Animation', 'animateur'], ['Katsuhiro Otomo', 1954, 'Manga', 'mangaka'],
    ['Akira Toriyama', 1955, 'Manga', 'mangaka'], ['Tim Burton', 1958, 'Cinéma', 'réalisateur'],
    ['Keith Haring', 1958, 'Street Art', 'artiste'], ['Jean-Michel Basquiat', 1960, 'Street Art', 'peintre'],
    ['Wes Anderson', 1969, 'Cinéma', 'réalisateur'], ['Banksy', 1974, 'Street Art', 'artiste'],
    ['JR', 1983, 'Street Art', 'artiste'], ['Zaha Hadid', 1950, 'Déconstructivisme', 'architecte'],
    ['Frank Gehry', 1929, 'Déconstructivisme', 'architecte'], ['Auguste Rodin', 1840, 'Sculpture moderne', 'sculpteur'],
];
function seed_if_empty(): void {
    global $SEED;
    $db = db();
    if ((int)$db->query("SELECT COUNT(*) FROM artists")->fetchColumn() === 0) {
        $stmt = $db->prepare("INSERT INTO artists (name, birth_year, movement, category, batch_id, created_at) VALUES (?,?,?,?,0,?)");
        $now = date('Y-m-d H:i:s');
        foreach ($SEED as $a) $stmt->execute([$a[0], $a[1], $a[2], $a[3], $now]);
    }
}
seed_if_empty();
ensure_core_subjects();

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
 * Construit le prompt final avec le prompt global inséré intelligemment.
 */
function build_final_prompt(string $artist, string $subject, string $subjectHint, string $globalPrompt = ''): string {
    $parts = [];
    $parts[] = "Create {$subjectHint} in the unmistakable visual style of {$artist}.";
    if (trim($globalPrompt) !== '') {
        $parts[] = "ADDITIONAL CREATIVE REQUIREMENTS (apply these to the scene):\n" . trim($globalPrompt);
    }
    $parts[] = "STYLE PRIORITY: The image must be instantly recognizable as the work of {$artist} — their signature technique, brushwork, color palette, lighting philosophy, composition, and creative identity must dominate the image.";
    $parts[] = "OUTPUT: Professional quality, high resolution, highly detailed.";
    return implode("\n\n", $parts);
}

$GROW_CATS = [
    'peintre ancien', 'peintre baroque', 'peintre impressionniste', 'peintre moderne',
    'peintre contemporain', 'peintre abstrait', 'peintre figuratif',
    'photographe de mode', 'photographe de rue', 'photographe de paysage', 'photographe portraitiste',
    'illustrateur', 'bédéiste', 'mangaka', 'concept artist', 'character designer',
    'architecte', 'designer industriel', 'graphiste', 'typographe', 'affichiste',
    'sculpteur', 'céramiste', 'verrier', 'plasticien', 'artiste numérique',
    'street artist', 'graffeur', 'réalisateur', 'directeur artistique',
    'peintre japonais', 'peintre chinois', 'peintre coréen', 'peintre indien',
    'peintre africain', 'peintre brésilien', 'peintre mexicain', 'peintre russe',
    'peintre scandinave', 'peintre italien', 'peintre espagnol', 'peintre allemand',
];

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$body = null;
if ($action) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true);
    if (!is_array($body)) $body = $_POST;

    try {
        switch ($action) {

            case 'init':
                $db = db();
                $artists = $db->query("SELECT * FROM artists ORDER BY birth_year ASC")->fetchAll();
                $subjects = $db->query("SELECT * FROM concepts WHERE is_core=1 ORDER BY sort_order ASC")->fetchAll();
                $stats = [
                    'artists' => count($artists), 'subjects' => count($subjects),
                    'tests_done' => (int)$db->query("SELECT COUNT(*) FROM tests WHERE status='done' AND is_regeneration=0")->fetchColumn(),
                    'favorites' => (int)$db->query("SELECT COUNT(*) FROM tests WHERE is_favorite=1")->fetchColumn(),
                    'keys' => count(get_keys()), 'keys_usable' => count(get_usable_keys()),
                    'goal' => $GLOBALS['CONFIG']['goal_artists'],
                ];
                $movements = $db->query("SELECT DISTINCT movement FROM artists WHERE movement != '' ORDER BY movement")->fetchAll(PDO::FETCH_COLUMN);
                json_out(['ok' => true, 'artists' => $artists, 'subjects' => $subjects, 'stats' => $stats, 'movements' => $movements]);

            case 'status':
                $db = db();
                $nSubj = (int)$db->query("SELECT COUNT(*) FROM concepts WHERE is_core=1")->fetchColumn();
                $nArt = (int)$db->query("SELECT COUNT(*) FROM artists")->fetchColumn();
                $rows = $db->query("SELECT a.id, COUNT(DISTINCT t.concept_id) AS covered FROM artists a
                    LEFT JOIN tests t ON t.artist_id=a.id AND t.status='done' AND t.is_regeneration=0
                        AND t.concept_id IN (SELECT id FROM concepts WHERE is_core=1)
                    GROUP BY a.id")->fetchAll();
                $complete = 0; $partial = 0;
                foreach ($rows as $r) {
                    $c = (int)$r['covered'];
                    if ($c >= $nSubj) $complete++;
                    elseif ($c > 0) $partial++;
                }
                $totalPossible = $nArt * $nSubj;
                $totalDone = (int)$db->query("SELECT COUNT(*) FROM tests WHERE status='done' AND is_regeneration=0")->fetchColumn();
                $pct = $totalPossible > 0 ? round($totalDone / $totalPossible * 100, 1) : 0;
                $goal = $GLOBALS['CONFIG']['goal_artists'];
                json_out(['ok' => true, 'n_artists' => $nArt, 'n_subjects' => $nSubj,
                    'complete' => $complete, 'partial' => $partial, 'empty' => $nArt - $complete - $partial,
                    'total_done' => $totalDone, 'total_possible' => $totalPossible, 'percent' => $pct,
                    'goal' => $goal, 'need_more_artists' => ($nArt < $goal)]);

            case 'next_batch':
                // Récupère les prochains jobs (artistes incomplets)
                $batchSize = max(1, min(20, (int)($body['batch_size'] ?? 5)));
                $db = db();
                $subjects = $db->query("SELECT id, name FROM concepts WHERE is_core=1 ORDER BY sort_order ASC")->fetchAll();
                $subjIds = implode(',', array_map(fn($s) => (int)$s['id'], $subjects));
                $nTarget = count($subjects);
                $artists = $db->query("SELECT a.id, a.name, a.movement, a.birth_year,
                    COUNT(DISTINCT t.concept_id) AS covered
                    FROM artists a
                    LEFT JOIN tests t ON t.artist_id=a.id AND t.status='done' AND t.is_regeneration=0
                        AND t.concept_id IN ($subjIds)
                    GROUP BY a.id HAVING covered < $nTarget
                    ORDER BY covered ASC, RANDOM() LIMIT $batchSize")->fetchAll();
                $jobs = [];
                foreach ($artists as $a) {
                    $stmt = $db->prepare("SELECT c.id, c.name FROM concepts c
                        WHERE c.id IN ($subjIds) AND c.id NOT IN (
                            SELECT concept_id FROM tests WHERE artist_id=? AND status='done' AND is_regeneration=0
                        ) ORDER BY c.sort_order ASC");
                    $stmt->execute([$a['id']]);
                    foreach ($stmt->fetchAll() as $subj) {
                        $jobs[] = [
                            'artist_id' => (int)$a['id'], 'artist_name' => $a['name'],
                            'subject_id' => (int)$subj['id'], 'subject_name' => $subj['name'],
                        ];
                    }
                }
                shuffle($jobs);
                json_out(['ok' => true, 'jobs' => $jobs, 'artists_in_batch' => count($artists)]);

            case 'grow':
                global $GROW_CATS;
                $db = db();
                $existing = $db->query("SELECT name FROM artists")->fetchAll(PDO::FETCH_COLUMN);
                $existingLower = array_map('mb_strtolower', $existing);
                $count = max(40, min(100, (int)($body['count'] ?? 80)));
                $picks = (array)array_rand(array_flip($GROW_CATS), min(3, count($GROW_CATS)));
                $catStr = implode(' + ', $picks);
                $prompt = "Génère {$count} artistes visuels RÉELS et célèbres.\n"
                        . "Catégories à couvrir : {$catStr}.\n"
                        . "Diversifie époques, pays, sous-mouvements, cultures (France, Italie, Espagne, USA, Japon, Chine, Corée, Brésil, Mexique, Russie, Inde, Iran, Égypte, Afrique du Sud, Australie, Allemagne, UK, Scandinavie...).\n\n"
                        . "Évite ABSOLUMENT ces artistes déjà présents : " . implode(', ', array_slice($existing, 0, 400)) . "\n\n"
                        . "Réponds UNIQUEMENT par des lignes au format strict :\nNom | Année de naissance | Mouvement | Catégorie\n"
                        . "Une ligne par artiste.";
                $r = gen_chat([
                    ['role' => 'system', 'content' => 'Tu es un historien d\'art expert. Format strict.'],
                    ['role' => 'user', 'content' => $prompt],
                ], 0.9, 3000);
                if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']]);
                $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $r['content'])), fn($l) => $l && str_contains($l, '|'));
                $maxBatch = (int)$db->query("SELECT COALESCE(MAX(batch_id),0) FROM artists")->fetchColumn() + 1;
                $added = 0; $now = date('Y-m-d H:i:s');
                $stmt = $db->prepare("INSERT OR IGNORE INTO artists (name, birth_year, movement, category, batch_id, created_at) VALUES (?,?,?,?,?,?)");
                foreach ($lines as $line) {
                    $p = array_map('trim', explode('|', $line));
                    if (count($p) < 4) continue;
                    [$name, $year, $mov, $cat] = $p;
                    $y = (int)$year;
                    if (!$name || !$y || in_array(mb_strtolower($name), $existingLower, true)) continue;
                    $stmt->execute([$name, $y, $mov, $cat, $maxBatch, $now]);
                    if ($stmt->rowCount()) $added++;
                }
                json_out(['ok' => true, 'added' => $added, 'batch' => $maxBatch, 'categories' => $catStr]);

            case 'generate':
                $artist_id = (int)($body['artist_id'] ?? 0);
                $concept_id = (int)($body['concept_id'] ?? 0);
                $globalPrompt = trim((string)($body['global_prompt'] ?? ''));
                $size = in_array($body['size'] ?? '1K', ['1K','2K','3K','4K'], true) ? $body['size'] : '1K';
                $ratio = in_array($body['ratio'] ?? '3:4', ['1:1','3:4','4:3','16:9','9:16'], true) ? $body['ratio'] : '3:4';
                $db = db();
                // Skip si déjà fait
                $stmt = $db->prepare("SELECT id, filename FROM tests WHERE artist_id=? AND concept_id=? AND status='done' AND is_regeneration=0 LIMIT 1");
                $stmt->execute([$artist_id, $concept_id]);
                if ($ex = $stmt->fetch()) {
                    json_out(['ok' => true, 'already_exists' => true, 'test_id' => (int)$ex['id'], 'url' => 'images/'.$ex['filename']]);
                }
                $stmt = $db->prepare("SELECT * FROM artists WHERE id=?"); $stmt->execute([$artist_id]); $artist = $stmt->fetch();
                $stmt = $db->prepare("SELECT * FROM concepts WHERE id=?"); $stmt->execute([$concept_id]); $subject = $stmt->fetch();
                if (!$artist || !$subject) json_out(['ok' => false, 'error' => 'Introuvable'], 400);
                $hint = $subject['name'];
                if (stripos($hint, 'portrait') !== false) $hint = "a dual portrait of a man and a woman together, both faces clearly visible";
                elseif (stripos($hint, 'ville') !== false) $hint = "a cityscape with buildings, streets and urban architecture";
                $finalPrompt = build_final_prompt($artist['name'], $subject['name'], $hint, $globalPrompt);
                $stmt = $db->prepare("INSERT INTO tests (artist_id, concept_id, artist_name, concept_name, prompt, status, created_at) VALUES (?,?,?,?,?, 'pending', ?)");
                $stmt->execute([$artist_id, $concept_id, $artist['name'], $subject['name'], $finalPrompt, date('Y-m-d H:i:s')]);
                $test_id = (int)$db->lastInsertId();
                $t0 = microtime(true);
                $r = gen_image($finalPrompt, $size, $ratio);
                $elapsed = (int)((microtime(true) - $t0) * 1000);
                if (!$r['ok']) {
                    $db->prepare("UPDATE tests SET status='error', error=? WHERE id=?")->execute([$r['error'], $test_id]);
                    json_out(['ok' => false, 'error' => $r['error'], 'test_id' => $test_id]);
                }
                $slugA = slugify($artist['name']);
                $slugS = slugify($subject['name']);
                $dir = $CONFIG['images_dir'] . '/' . $slugA;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $comp = compress_image($r['data'], $CONFIG['compress_max_width'], $CONFIG['compress_quality']);
                $fn = $slugS . '.' . $comp['ext'];
                if (@file_put_contents($dir . '/' . $fn, $comp['data']) === false) {
                    $db->prepare("UPDATE tests SET status='error', error='Écriture disque' WHERE id=?")->execute([$test_id]);
                    json_out(['ok' => false, 'error' => 'Écriture disque']);
                }
                $rel = $slugA . '/' . $fn;
                $db->prepare("UPDATE tests SET status='done', filename=?, gen_time_ms=? WHERE id=?")->execute([$rel, $elapsed, $test_id]);
                json_out(['ok' => true, 'test_id' => $test_id, 'url' => 'images/' . $rel,
                    'file_size_kb' => round(strlen($comp['data']) / 1024),
                    'artist_name' => $artist['name'], 'subject_name' => $subject['name']]);

            case 'regenerate':
                $parent_id = (int)($body['parent_id'] ?? 0);
                $newPrompt = trim((string)($body['prompt'] ?? ''));
                $size = in_array($body['size'] ?? '1K', ['1K','2K','3K','4K'], true) ? $body['size'] : '1K';
                $ratio = in_array($body['ratio'] ?? '3:4', ['1:1','3:4','4:3','16:9','9:16'], true) ? $body['ratio'] : '3:4';
                if (!$parent_id || !$newPrompt) json_out(['ok' => false, 'error' => 'Paramètres manquants'], 400);
                $db = db();
                $stmt = $db->prepare("SELECT * FROM tests WHERE id=?"); $stmt->execute([$parent_id]);
                $parent = $stmt->fetch();
                if (!$parent) json_out(['ok' => false, 'error' => 'Parent introuvable'], 404);
                $stmt = $db->prepare("INSERT INTO tests (artist_id, concept_id, artist_name, concept_name, prompt, status, parent_id, is_regeneration, created_at) VALUES (?,?,?,?,?, 'pending', ?, 1, ?)");
                $stmt->execute([$parent['artist_id'], $parent['concept_id'], $parent['artist_name'], $parent['concept_name'], $newPrompt, $parent_id, date('Y-m-d H:i:s')]);
                $new_id = (int)$db->lastInsertId();
                $t0 = microtime(true);
                $r = gen_image($newPrompt, $size, $ratio);
                $elapsed = (int)((microtime(true) - $t0) * 1000);
                if (!$r['ok']) {
                    $db->prepare("UPDATE tests SET status='error', error=? WHERE id=?")->execute([$r['error'], $new_id]);
                    json_out(['ok' => false, 'error' => $r['error'], 'test_id' => $new_id]);
                }
                $slugA = slugify($parent['artist_name']);
                $slugS = slugify($parent['concept_name']);
                $dir = $CONFIG['images_dir'] . '/' . $slugA;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $comp = compress_image($r['data'], $CONFIG['compress_max_width'], $CONFIG['compress_quality']);
                $fn = $slugS . '_r' . $new_id . '.' . $comp['ext'];
                @file_put_contents($dir . '/' . $fn, $comp['data']);
                $rel = $slugA . '/' . $fn;
                $db->prepare("UPDATE tests SET status='done', filename=?, gen_time_ms=? WHERE id=?")->execute([$rel, $elapsed, $new_id]);
                json_out(['ok' => true, 'test_id' => $new_id, 'url' => 'images/' . $rel,
                    'file_size_kb' => round(strlen($comp['data']) / 1024)]);

            case 'optimize_prompt':
                // Optimise le prompt pour le modèle Agnes (structure : Subject → Scene → Style → Lighting → Composition → Quality)
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

            case 'get_test':
                $id = (int)($body['test_id'] ?? 0);
                if (!$id) json_out(['ok' => false, 'error' => 'test_id requis'], 400);
                $stmt = db()->prepare("SELECT * FROM tests WHERE id=?");
                $stmt->execute([$id]);
                $t = $stmt->fetch();
                if (!$t) json_out(['ok' => false, 'error' => 'Introuvable'], 404);
                $t['url'] = 'images/' . $t['filename'];
                json_out(['ok' => true, 'test' => $t]);

            case 'catalog_view':
                $db = db();
                $page = max(1, (int)($_GET['page'] ?? 1));
                $per = 15;
                $offset = ($page - 1) * $per;
                $sortBy = $_GET['sort'] ?? 'completion';
                $filterMov = trim((string)($_GET['movement'] ?? ''));
                $nSubj = (int)$db->query("SELECT COUNT(*) FROM concepts WHERE is_core=1")->fetchColumn();
                $where = []; $params = [];
                if ($filterMov) { $where[] = "a.movement = ?"; $params[] = $filterMov; }
                $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
                $orderSql = match ($sortBy) {
                    'name' => 'a.name ASC', 'year' => 'a.birth_year ASC',
                    'movement' => 'a.movement ASC, a.birth_year ASC',
                    'completion_asc' => 'covered ASC, a.name ASC',
                    default => 'covered DESC, a.name ASC',
                };
                $stmt = $db->prepare("SELECT a.id, a.name, a.birth_year, a.movement, a.category,
                    COUNT(DISTINCT t.concept_id) AS covered FROM artists a
                    LEFT JOIN tests t ON t.artist_id=a.id AND t.status='done' AND t.is_regeneration=0
                        AND t.concept_id IN (SELECT id FROM concepts WHERE is_core=1)
                    $whereSql GROUP BY a.id ORDER BY $orderSql LIMIT $per OFFSET $offset");
                $stmt->execute($params);
                $artists = $stmt->fetchAll();
                $stmt = $db->prepare("SELECT COUNT(*) FROM artists a $whereSql");
                $stmt->execute($params);
                $total = (int)$stmt->fetchColumn();
                $subjList = $db->query("SELECT id, name, sort_order FROM concepts WHERE is_core=1 ORDER BY sort_order ASC")->fetchAll();
                $result = [];
                foreach ($artists as $a) {
                    $stmt = $db->prepare("SELECT t.id, t.concept_id, t.filename, t.rating, t.is_favorite
                        FROM tests t WHERE t.artist_id=? AND t.status='done' AND t.is_regeneration=0
                        AND t.concept_id IN (SELECT id FROM concepts WHERE is_core=1)");
                    $stmt->execute([$a['id']]);
                    $bySubj = [];
                    foreach ($stmt->fetchAll() as $row) {
                        $bySubj[(int)$row['concept_id']] = [
                            'test_id' => (int)$row['id'], 'url' => 'images/' . $row['filename'],
                            'rating' => (int)$row['rating'], 'is_favorite' => (int)$row['is_favorite'],
                        ];
                    }
                    $slots = [];
                    foreach ($subjList as $s) {
                        $slots[] = ['subject_id' => (int)$s['id'], 'subject_name' => $s['name'], 'image' => $bySubj[(int)$s['id']] ?? null];
                    }
                    $a['slots'] = $slots;
                    $a['complete'] = ((int)$a['covered'] >= $nSubj);
                    $result[] = $a;
                }
                json_out(['ok' => true, 'artists' => $result, 'total' => $total, 'page' => $page,
                    'per_page' => $per, 'n_subjects' => $nSubj, 'subjects' => $subjList]);

            case 'rate':
                $id = (int)($body['test_id'] ?? 0);
                $rating = max(0, min(5, (int)($body['rating'] ?? 0)));
                if (!$id) json_out(['ok' => false, 'error' => 'ID requis'], 400);
                db()->prepare("UPDATE tests SET rating=? WHERE id=?")->execute([$rating, $id]);
                json_out(['ok' => true]);

            case 'fav':
                $id = (int)($body['test_id'] ?? 0);
                if (!$id) json_out(['ok' => false, 'error' => 'ID requis'], 400);
                $db = db();
                $stmt = $db->prepare("SELECT is_favorite FROM tests WHERE id=?");
                $stmt->execute([$id]);
                $cur = (int)$stmt->fetchColumn();
                $new = $cur ? 0 : 1;
                $db->prepare("UPDATE tests SET is_favorite=? WHERE id=?")->execute([$new, $id]);
                json_out(['ok' => true, 'is_favorite' => $new]);

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

            case 'get_keys_info':
                $keys = get_keys();
                $stats = load_key_stats();
                $info = [];
                foreach ($keys as $k) $info[] = ['prefix' => substr($k, 0, 8) . '…' . substr($k, -4), 'fails' => (int)($stats[$k] ?? 0)];
                json_out(['ok' => true, 'count' => count($keys), 'usable' => count(get_usable_keys()), 'info' => $info]);

            case 'reset_key_stats':
                if (file_exists($CONFIG['stats_file'])) @unlink($CONFIG['stats_file']);
                json_out(['ok' => true]);

            case 'disk_usage':
                $size = 0; $count = 0;
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($CONFIG['images_dir'], FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && in_array(strtolower($f->getExtension()), ['png','jpg','jpeg','webp'])) { $size += $f->getSize(); $count++; }
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
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agnes Style Atlas</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0f1a;--bg2:#131c2e;--bg3:#1a2740;--line:#2a3a55;--line2:#3d5070;
  --ink:#e6eef8;--ink2:#8ba3c4;--ink3:#5a7292;
  --accent:#4dabf7;--accent2:#7fa6cc;--gold:#ffd93d;--green:#42e8a4;--red:#ff6b6b;--orange:#ff9940;
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
  background:conic-gradient(from 45deg,#4dabf7,#a78bfa,#ffd93d,#42e8a4,#4dabf7);
  box-shadow:0 0 20px rgba(77,171,247,.4)}
.brand-name{font:800 1.1rem var(--sans);letter-spacing:-.02em}
.brand-name i{font-style:normal;color:var(--accent)}
.header-stats{display:flex;gap:.6rem;flex-wrap:wrap;flex:1;justify-content:flex-end}
.hs{background:var(--bg2);border:1px solid var(--line);padding:.35rem .7rem;border-radius:99px;
  font:600 10px var(--mono);letter-spacing:.04em;white-space:nowrap}
.hs b{color:var(--accent);font-weight:800;font-size:12px}
.hs.goal b{color:var(--gold)}
.hs.done b{color:var(--green)}

/* BIG BUTTON */
.run-bar{display:flex;gap:.6rem;align-items:stretch;flex-wrap:wrap}
#runBtn{flex:1;min-height:52px;min-width:220px;padding:.6rem 1.4rem;
  background:linear-gradient(135deg,#42e8a4,#0e8c5a);color:#062018;
  border-radius:12px;font:800 .95rem var(--sans);letter-spacing:.04em;text-transform:uppercase;
  display:flex;align-items:center;justify-content:center;gap:.6rem;
  box-shadow:0 6px 20px rgba(66,232,164,.4);transition:.2s}
#runBtn:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(66,232,164,.5)}
#runBtn.running{background:linear-gradient(135deg,#ff6b6b,#a52f28);color:#fff;
  box-shadow:0 6px 20px rgba(255,107,107,.4)}
#runBtn.running::before{content:"⏹"}
#runBtn:not(.running)::before{content:"🚀";font-size:1.2rem}
.prompt-global-wrap{flex:2;min-width:240px;position:relative;display:flex}
#globalPrompt{width:100%;background:var(--bg2);border:1px solid var(--line);border-radius:12px;
  padding:.65rem 1rem;font:500 13px var(--sans);color:var(--ink);resize:none;min-height:52px;
  outline:none;transition:.2s;line-height:1.5}
#globalPrompt::placeholder{color:var(--ink3)}
#globalPrompt:focus{border-color:var(--accent);background:#0d1524}

/* TABS */
#tabs{display:flex;background:var(--bg2);border-radius:10px;padding:4px;margin:.7rem 0;
  border:1px solid var(--line);overflow-x:auto;gap:2px}
.tab{flex:1;min-width:max-content;padding:.55rem .9rem;border-radius:7px;
  font:700 10px var(--mono);letter-spacing:.1em;text-transform:uppercase;
  color:var(--ink2);transition:.15s;white-space:nowrap}
.tab:hover{color:var(--ink);background:rgba(255,255,255,.04)}
.tab.active{background:var(--accent);color:#062018}

/* PROGRESS MINI */
.progress-wrap{padding:.5rem 0}
.progress-line{display:flex;justify-content:space-between;font:600 10px var(--mono);color:var(--ink2);margin-bottom:.3rem}
.progress-bar{height:6px;background:var(--bg2);border-radius:99px;overflow:hidden;margin-bottom:.4rem}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--green));transition:width .5s}
.progress-fill.artists{background:linear-gradient(90deg,var(--gold),#ff9940)}

/* MAIN */
#main{max-width:1600px;margin:0 auto;padding:0 1.2rem 100px}

/* LIVE LOG */
.live-log{background:#050a14;border:1px solid var(--line);border-radius:10px;padding:.7rem;
  font:500 10px var(--mono);max-height:180px;overflow-y:auto;line-height:1.7;
  scrollbar-width:thin;scrollbar-color:var(--line2) transparent;margin-bottom:1rem}
.live-log .ok{color:var(--green)}
.live-log .err{color:var(--red)}
.live-log .info{color:var(--accent)}
.live-log .grow{color:var(--gold);font-weight:700}
.live-log .warn{color:var(--orange)}

/* CATALOG GRID */
.catalog-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem}
.artist-card{background:var(--bg2);border:1px solid var(--line);border-radius:14px;overflow:hidden;
  transition:.25s;position:relative}
.artist-card:hover{border-color:var(--accent2);transform:translateY(-2px);box-shadow:0 12px 30px -10px rgba(0,0,0,.6)}
.artist-head{padding:.7rem .9rem;background:linear-gradient(135deg,var(--bg3),var(--bg2));
  border-bottom:1px solid var(--line);display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.artist-name{font:800 1rem var(--sans);letter-spacing:-.02em;color:var(--ink);flex:1;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.artist-meta{font:600 9px var(--mono);color:var(--ink3);text-transform:uppercase;letter-spacing:.06em;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:0 1 auto}
.badge{padding:.22rem .55rem;border-radius:99px;font:800 9px var(--mono);
  text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
.badge.done{background:rgba(66,232,164,.2);color:var(--green)}
.badge.partial{background:rgba(255,153,64,.2);color:var(--orange)}
.badge.empty{background:rgba(139,163,196,.15);color:var(--ink3)}
.artist-slots{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;padding:.6rem}
.slot{aspect-ratio:3/4;background:var(--bg3);border-radius:8px;overflow:hidden;position:relative;
  cursor:pointer;transition:.2s;border:1px solid transparent}
.slot:hover{border-color:var(--accent);transform:scale(1.02)}
.slot img{width:100%;height:100%;object-fit:cover;display:block}
.slot .slot-label{position:absolute;bottom:0;left:0;right:0;
  background:linear-gradient(transparent,rgba(5,10,20,.95) 60%);
  padding:1rem .45rem .35rem;font:700 9px var(--mono);text-transform:uppercase;
  letter-spacing:.05em;color:#fff;text-align:center}
.slot .slot-rating{position:absolute;top:5px;right:5px;
  background:rgba(5,10,20,.85);color:var(--gold);padding:2px 6px;border-radius:99px;
  font:700 9px var(--mono);backdrop-filter:blur(4px)}
.slot .slot-fav{position:absolute;top:5px;left:5px;background:var(--orange);color:#fff;
  width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:11px}
.slot.empty{background:repeating-linear-gradient(45deg,var(--bg3) 0 6px,#141e30 6px 12px);
  display:flex;align-items:center;justify-content:center;color:var(--ink3);font:600 10px var(--mono)}
.slot.empty::after{content:"⏳";font-size:1.6rem;opacity:.4}
.slot.empty:hover::after{content:"+";color:var(--accent);opacity:1}

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
.act-btn.primary{grid-column:1/-1;background:linear-gradient(135deg,var(--accent),#1971c2);color:#fff;border:none}
.act-btn.primary:hover:not(:disabled){background:linear-gradient(135deg,#5bb6ff,#1a7bc7);color:#fff}
.act-btn.agnes{background:linear-gradient(135deg,#a78bfa,#7c3aed);color:#fff;border:none}
.act-btn.agnes:hover:not(:disabled){background:linear-gradient(135deg,#b79cff,#8b4cf7);color:#fff}

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
</style>
</head>
<body>

<header id="header">
  <div class="top">
    <div class="brand">
      <div class="brand-logo"></div>
      <div><div class="brand-name">Agnes<i>Atlas</i></div></div>
    </div>
    <div class="header-stats">
      <div class="hs goal">🎯 <b id="hsGoal">4000</b></div>
      <div class="hs">👥 <b id="hsArtists">0</b> artistes</div>
      <div class="hs done">🖼 <b id="hsDone">0</b> images</div>
      <div class="hs">💾 <b id="hsDisk">0</b> MB</div>
    </div>
  </div>

  <div class="run-bar">
    <div class="prompt-global-wrap">
      <textarea id="globalPrompt" placeholder="✍️ Prompt global (optionnel) — appliqué à toutes les générations. Ex: 'atmosphère pluvieuse, tons bleus, ambiance mélancolique'"></textarea>
    </div>
    <button id="runBtn">Lancer le catalogue automatique</button>
  </div>

  <div class="progress-wrap">
    <div class="progress-line"><span>📈 Progression globale</span><span id="progLabel">0%</span></div>
    <div class="progress-bar"><div class="progress-fill" id="progFill" style="width:0%"></div></div>
    <div class="progress-line"><span>👥 Croissance artistes</span><span id="artLabel">0 / 4000</span></div>
    <div class="progress-bar"><div class="progress-fill artists" id="artFill" style="width:0%"></div></div>
  </div>

  <div id="tabs">
    <button class="tab active" data-tab="catalog">🎨 Annuaire</button>
    <button class="tab" data-tab="live">🔴 Live</button>
  </div>
</header>

<main id="main">

  <section id="tab-catalog">
    <div class="filters">
      <input type="text" id="filterSearch" placeholder="🔍 Filtrer par nom d'artiste…">
      <select id="filterMovement"><option value="">🎨 Tous mouvements</option></select>
      <select id="filterSort">
        <option value="completion">📊 Par complétion</option>
        <option value="completion_asc">⏳ Moins avancés</option>
        <option value="name">🔤 Nom</option>
        <option value="year">📆 Année</option>
        <option value="movement">🖼️ Mouvement</option>
      </select>
      <button class="pbtn" onclick="loadCatalog(1)">↻ Actualiser</button>
    </div>
    <div id="catalogInfo" class="progress-line" style="margin-bottom:.6rem">Chargement…</div>
    <div class="catalog-grid" id="catalogGrid"></div>
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
      <div class="prompt-label">Prompt éditable <span class="count" id="promptCount">0</span></div>
      <textarea id="modalPrompt" spellcheck="false"></textarea>
      <div class="action-grid">
        <button class="act-btn" id="copyBtn">📋 Copier</button>
        <button class="act-btn agnes" id="optimizeBtn">✨ Optimiser Agnes</button>
        <button class="act-btn primary" id="regenBtn">🔄 Régénérer</button>
        <button class="act-btn" id="favBtn">⭐ Favori</button>
        <button class="act-btn" id="downloadBtn">📥 Télécharger</button>
      </div>
      <div style="font:500 10px var(--mono);color:var(--ink3);line-height:1.6;padding:.5rem;background:var(--bg3);border-radius:8px">
        💡 <b>Optimiser Agnes</b> : restructure le prompt selon la méthode recommandée (Sujet → Scène → Style → Lumière → Composition → Qualité) pour maximiser le rendu du modèle Agnes.
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
    <textarea id="keyInput" placeholder="sk-xxx&#10;sk-yyy&#10;sk-zzz&#10;(une par ligne)"></textarea>
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
  const url = 'index.php?action=' + action + (method === 'GET' && params ? '&' + new URLSearchParams(params) : '');
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
  imageWorkers: 5,
  totalImages: 0, totalGrown: 0, totalErrors: 0,
  keys: 0,
};
const CAT = { page: 1, search: '', movement: '', sort: 'completion' };

// ═══ INIT ═══
async function init() {
  try {
    const r = await api('init', {}, 'GET');
    STATE.keys = r.stats.keys || 0;
    populateMovements(r.movements);
    updateHeaders(r.stats);
    await loadKeysInfo();
    await refreshStatus();
    await loadCatalog(1);
  } catch (e) {
    toast('Erreur init : ' + e.message, 'err');
  }
}

function updateHeaders(stats) {
  if (!stats) return;
  $('hsGoal').textContent = stats.goal || 4000;
  $('hsArtists').textContent = stats.artists || 0;
  $('hsDone').textContent = stats.tests_done || 0;
}

function populateMovements(movs) {
  $('filterMovement').innerHTML = '<option value="">🎨 Tous mouvements</option>' +
    (movs || []).map(m => `<option value="${esc(m)}">${esc(m)}</option>`).join('');
}

async function refreshStatus() {
  try {
    const s = await api('status', {}, 'GET');
    $('hsArtists').textContent = s.n_artists;
    $('hsGoal').textContent = s.goal;
    $('hsDone').textContent = s.total_done;
    $('progLabel').textContent = s.percent + '%';
    $('progFill').style.width = s.percent + '%';
    const artPct = Math.min(100, s.n_artists / s.goal * 100);
    $('artLabel').textContent = `${s.n_artists} / ${s.goal}`;
    $('artFill').style.width = artPct + '%';
    try {
      const d = await api('disk_usage', {}, 'GET');
      $('hsDisk').textContent = d.total_mb;
    } catch(e) {}
    return s;
  } catch(e) { return null; }
}

// ═══ BOUTON UNIQUE : grower + image workers EN PARALLÈLE ═══
$('runBtn').onclick = async () => {
  if (STATE.running) { STATE.stopped = true; toast('Arrêt demandé…', 'warn'); return; }
  if (!STATE.keys) { toast('Configure tes clés API d\'abord', 'err'); toggleKeys(); return; }
  STATE.running = true; STATE.stopped = false;
  STATE.totalImages = 0; STATE.totalGrown = 0; STATE.totalErrors = 0;
  $('runBtn').classList.add('running');
  $('runBtn').textContent = 'Arrêter';
  log('🚀 Démarrage — 5 workers images + 1 grower IA en parallèle', 'grow');
  log('✍️ Prompt global : ' + ($('globalPrompt').value.trim() ? '« ' + $('globalPrompt').value.trim().slice(0, 80) + '… »' : 'aucun'), 'info');
  
  // Lancer 5 image workers + 1 grower EN MÊME TEMPS
  await Promise.all([
    growerLoop(),
    ...Array.from({length: STATE.imageWorkers}, (_, i) => imageWorker(i + 1)),
  ]);
  
  STATE.running = false;
  $('runBtn').classList.remove('running');
  $('runBtn').textContent = 'Lancer le catalogue automatique';
  log(`✅ Arrêt — ${STATE.totalImages} images, ${STATE.totalGrown} artistes ajoutés, ${STATE.totalErrors} erreurs`, 'grow');
  toast(`Session : ${STATE.totalImages} images · ${STATE.totalGrown} artistes ajoutés`, 'ok');
  await refreshStatus();
  await loadCatalog(CAT.page);
};

// GROWER : tourne en boucle, ajoute des artistes quand il en manque
async function growerLoop() {
  while (STATE.running && !STATE.stopped) {
    try {
      const s = await api('status', {}, 'GET');
      if (s.need_more_artists) {
        log(`📈 Artistes: ${s.n_artists}/${s.goal} → Ajout de 80 via IA…`, 'grow');
        try {
          const g = await api('grow', { count: 80 });
          if (g.added > 0) {
            STATE.totalGrown += g.added;
            log(`🌱 +${g.added} artistes (${g.categories}) · batch ${g.batch}`, 'grow');
            await refreshStatus();
          } else {
            log(`⚠ Aucun nouvel artiste généré`, 'warn');
          }
        } catch(e) { log(`❌ Grow: ${e.message.slice(0,60)}`, 'err'); }
      }
    } catch(e) { /* ignore */ }
    // Attente entre 2 grow
    for (let i = 0; i < 30 && STATE.running && !STATE.stopped; i++) await sleep(1000);
  }
}

// IMAGE WORKER : génère en boucle
async function imageWorker(id) {
  let consecutiveEmpty = 0;
  while (STATE.running && !STATE.stopped) {
    try {
      // Récupérer le prochain job
      let batch;
      try { batch = await api('next_batch', { batch_size: 3 }); }
      catch(e) { log(`⚠ Worker ${id} batch: ${e.message.slice(0,50)}`, 'warn'); await sleep(3000); continue; }
      
      if (!batch.jobs.length) {
        consecutiveEmpty++;
        if (consecutiveEmpty >= 3) {
          // Attendre le grower
          await sleep(5000);
          consecutiveEmpty = 0;
        } else {
          await sleep(2000);
        }
        continue;
      }
      consecutiveEmpty = 0;
      const job = batch.jobs[0];
      
      // Générer avec prompt global
      try {
        const r = await api('generate', {
          artist_id: job.artist_id,
          concept_id: job.subject_id,
          global_prompt: $('globalPrompt').value.trim(),
          size: '1K',
          ratio: '3:4',
        });
        if (r.already_exists) {
          log(`⏭ Déjà fait : ${job.artist_name} × ${job.subject_name}`, 'info');
          continue;
        }
        STATE.totalImages++;
        log(`✓ [W${id}] ${job.artist_name} × ${job.subject_name} (${r.file_size_kb || '?'}KB)`, 'ok');
        // Refresh status toutes les 5 images
        if (STATE.totalImages % 5 === 0) await refreshStatus();
      } catch(e) {
        STATE.totalErrors++;
        log(`✗ [W${id}] ${job.artist_name} × ${job.subject_name} : ${e.message.slice(0,50)}`, 'err');
        await sleep(1000);
      }
    } catch(e) {
      log(`⚠ Worker ${id} : ${e.message.slice(0,60)}`, 'err');
      await sleep(3000);
    }
  }
}

// ═══ LOG ═══
function log(msg, type='info') {
  const box = $('liveLog');
  const div = document.createElement('div');
  div.className = type;
  div.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
  box.appendChild(div);
  while (box.children.length > 200) box.removeChild(box.firstChild);
  box.scrollTop = box.scrollHeight;
}

// ═══ TABS ═══
document.querySelectorAll('.tab').forEach(t => {
  t.onclick = () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.toggle('active', x === t));
    ['catalog', 'live'].forEach(id => $('tab-' + id).classList.toggle('hidden', id !== t.dataset.tab));
    if (t.dataset.tab === 'catalog') loadCatalog(CAT.page);
  };
});

// ═══ CATALOG ═══
async function loadCatalog(page = 1) {
  CAT.page = page;
  CAT.search = $('filterSearch').value;
  CAT.movement = $('filterMovement').value;
  CAT.sort = $('filterSort').value;
  
  try {
    const r = await api('catalog_view', { page, sort: CAT.sort, movement: CAT.movement }, 'GET');
    let artists = r.artists;
    if (CAT.search) {
      const q = CAT.search.toLowerCase();
      artists = artists.filter(a => a.name.toLowerCase().includes(q));
    }
    $('catalogInfo').textContent = `${r.total} artiste(s) · page ${r.page} / ${Math.max(1, Math.ceil(r.total / r.per_page))} · ${r.n_subjects} sujets`;
    
    const grid = $('catalogGrid');
    if (!artists.length) {
      grid.innerHTML = '<div class="empty" style="grid-column:1/-1">Aucun artiste. Lance le catalogue pour commencer.</div>';
      return;
    }
    grid.innerHTML = artists.map(a => renderArtistCard(a, r.n_subjects, r.subjects)).join('');
    
    // Bind clicks
    grid.querySelectorAll('.slot:not(.empty)').forEach(el => {
      el.onclick = () => openModal(parseInt(el.dataset.testId));
    });
    grid.querySelectorAll('.slot.empty').forEach(el => {
      el.onclick = async () => {
        const aid = parseInt(el.dataset.artistId);
        const sid = parseInt(el.dataset.subjectId);
        if (!STATE.keys) { toast('Configure tes clés', 'err'); return; }
        el.innerHTML = '<span class="spin"></span>';
        try {
          await api('generate', { artist_id: aid, concept_id: sid, global_prompt: $('globalPrompt').value.trim(), size: '1K', ratio: '3:4' });
          toast('Image générée', 'ok');
          await loadCatalog(CAT.page);
          await refreshStatus();
        } catch(e) { toast('Erreur : ' + e.message, 'err'); await loadCatalog(CAT.page); }
      };
    });
    
    // Pagination
    const totalPages = Math.max(1, Math.ceil(r.total / r.per_page));
    const pag = $('pagination');
    pag.innerHTML = '';
    if (totalPages > 1) {
      pag.innerHTML = `
        <button class="pbtn" ${page<=1?'disabled':''} onclick="loadCatalog(${page-1})">← Préc.</button>
        <span class="page-info">Page ${page} / ${totalPages}</span>
        <button class="pbtn" ${page>=totalPages?'disabled':''} onclick="loadCatalog(${page+1})">Suiv. →</button>
      `;
    }
  } catch(e) { toast('Erreur catalogue : ' + e.message, 'err'); }
}

function renderArtistCard(a, nSubj, subjects) {
  const status = a.complete ? 'done' : (a.covered > 0 ? 'partial' : 'empty');
  const label = a.complete ? `✓ ${a.covered}/${nSubj}` : `${a.covered}/${nSubj}`;
  const slots = a.slots.map(slot => {
    if (slot.image) {
      return `<div class="slot" data-test-id="${slot.image.test_id}">
        <img src="${esc(slot.image.url)}" loading="lazy" alt="">
        <div class="slot-label">${esc(slot.subject_name)}</div>
        ${slot.image.rating > 0 ? `<div class="slot-rating">★ ${slot.image.rating}</div>` : ''}
        ${slot.image.is_favorite ? `<div class="slot-fav">★</div>` : ''}
      </div>`;
    } else {
      return `<div class="slot empty" data-artist-id="${a.id}" data-subject-id="${slot.subject_id}">
        <div class="slot-label" style="position:absolute;bottom:0;background:none">${esc(slot.subject_name)}</div>
      </div>`;
    }
  }).join('');
  return `<div class="artist-card">
    <div class="artist-head">
      <div class="artist-name">${esc(a.name)}</div>
      <div class="artist-meta">${a.birth_year || '?'} · ${esc(a.movement || '—')}</div>
      <div class="badge ${status}">${label}</div>
    </div>
    <div class="artist-slots">${slots}</div>
  </div>`;
}

// ═══ MODAL ═══
let MODAL = null;
async function openModal(testId) {
  try {
    const r = await api('get_test', { test_id: testId });
    MODAL = r.test;
    $('modalImg').src = r.test.url;
    $('modalTitle').textContent = r.test.artist_name;
    $('modalSub').textContent = `${r.test.concept_name} · #${r.test.id}${r.test.is_regeneration ? ' · 🔄 régénérée' : ''}`;
    $('modalPrompt').value = r.test.prompt;
    updatePromptCount();
    $('favBtn').textContent = r.test.is_favorite ? '⭐ Retirer favori' : '⭐ Favori';
    $('imageModal').classList.add('on');
  } catch(e) { toast('Erreur : ' + e.message, 'err'); }
}
function closeModal() { $('imageModal').classList.remove('on'); MODAL = null; }
$('imageModal').onclick = e => { if (e.target === $('imageModal')) closeModal(); };
function updatePromptCount() { $('promptCount').textContent = $('modalPrompt').value.length + ' car.'; }
$('modalPrompt').addEventListener('input', updatePromptCount);

$('copyBtn').onclick = async () => {
  try { await navigator.clipboard.writeText($('modalPrompt').value); toast('Prompt copié', 'ok'); }
  catch(e) { $('modalPrompt').select(); document.execCommand('copy'); toast('Prompt copié', 'ok'); }
};

$('optimizeBtn').onclick = async () => {
  const p = $('modalPrompt').value.trim();
  if (!p) { toast('Prompt vide', 'err'); return; }
  if (!STATE.keys) { toast('Configure tes clés', 'err'); return; }
  $('optimizeBtn').disabled = true;
  $('optimizeBtn').innerHTML = '<span class="spin"></span> Optimisation…';
  try {
    const r = await api('optimize_prompt', { prompt: p });
    $('modalPrompt').value = r.prompt;
    updatePromptCount();
    toast('✨ Prompt optimisé pour Agnes', 'ok');
  } catch(e) { toast('Erreur : ' + e.message, 'err'); }
  finally {
    $('optimizeBtn').disabled = false;
    $('optimizeBtn').textContent = '✨ Optimiser Agnes';
  }
};

$('regenBtn').onclick = async () => {
  if (!MODAL) return;
  const p = $('modalPrompt').value.trim();
  if (!p) { toast('Prompt vide', 'err'); return; }
  if (!STATE.keys) { toast('Configure tes clés', 'err'); return; }
  $('regenBtn').disabled = true;
  $('regenBtn').innerHTML = '<span class="spin"></span> Régénération…';
  try {
    const r = await api('regenerate', { parent_id: MODAL.id, prompt: p, size: '1K', ratio: '3:4' });
    toast(`Image régénérée (${r.file_size_kb}KB)`, 'ok');
    await openModal(r.test_id);
    await loadCatalog(CAT.page);
  } catch(e) { toast('Erreur : ' + e.message, 'err'); }
  finally {
    $('regenBtn').disabled = false;
    $('regenBtn').textContent = '🔄 Régénérer';
  }
};

$('favBtn').onclick = async () => {
  if (!MODAL) return;
  try {
    const r = await api('fav', { test_id: MODAL.id });
    MODAL.is_favorite = r.is_favorite;
    $('favBtn').textContent = r.is_favorite ? '⭐ Retirer favori' : '⭐ Favori';
    toast(r.is_favorite ? 'Ajouté aux favoris' : 'Retiré', 'ok');
  } catch(e) { toast('Erreur : ' + e.message, 'err'); }
};

$('downloadBtn').onclick = () => {
  if (!MODAL) return;
  const a = document.createElement('a');
  a.href = MODAL.url;
  a.download = `${MODAL.artist_name}_${MODAL.concept_name}.jpg`.replace(/\s+/g, '_');
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
};

document.addEventListener('keydown', e => {
  if (!$('imageModal').classList.contains('on')) return;
  if (e.key === 'Escape') closeModal();
  if ((e.key === 'c' || e.key === 'C') && !e.target.matches('textarea')) $('copyBtn').click();
});

// ═══ FILTERS ═══
$('filterSearch').addEventListener('input', () => loadCatalog(1));
$('filterMovement').addEventListener('change', () => loadCatalog(1));
$('filterSort').addEventListener('change', () => loadCatalog(1));

// ═══ KEYS PANEL ═══
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
  if (STATE.running) {
    await refreshStatus();
    if (STATE.totalImages % 10 === 0 && !$('tab-catalog').classList.contains('hidden')) {
      await loadCatalog(CAT.page);
    }
  }
}, 8000);

// ═══ START ═══
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
</script>
</body>
</html>
