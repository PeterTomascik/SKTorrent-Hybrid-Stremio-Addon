<?php
// addon.php
// Autor: Preklad z Node.js pre SKTorrent Stremio Addon

// -----------------------------------------------------------------------------
// I. KONFIGURÁCIA A NASTAVENIA
// -----------------------------------------------------------------------------

const SKT_UID = $_ENV['SKT_UID'] ?? 'greed';
const SKT_PASS = $_ENV['SKT_PASS'] ?? 'kolopolo';
const ADDON_API_KEY = $_ENV['ADDON_API_KEY'] ?? '';
const STREAM_MODE = $_ENV['STREAM_MODE'] ?? 'BOTH';
const SKT_BASE_URL = $_ENV['BASE_URL'] ?? 'https://sktorrent.eu';
const SKT_SEARCH_URL = $_ENV['SEARCH_URL'] ?? 'https://sktorrent.eu/torrent/torrents_v2.php';

// Real-Debrid API
const RD_API_BASE = 'https://api.real-debrid.com/rest/1.0';

// Autentifikácia a Rate Limiting
const SESSION_TTL = 24 * 60 * 60; // 24 hodín v sekundách
const RATE_LIMIT_WINDOW = 3600; // 1 hodina v sekundách
const RATE_LIMIT_MAX = 100;

global $sessions, $rate_limiter;
$sessions = [];
$rate_limiter = [];

// Video a archívne prípony
const VIDEO_EXTENSIONS = ['.mkv', '.mp4', '.avi', '.mov', '.wmv', '.flv', '.webm', '.m4v', '.ts', '.m2ts', '.mpg', '.mpeg'];
const SKIP_EXTENSIONS = ['.rar', '.zip', '.7z', '.tar', '.gz', '.txt', '.nfo', '.srt', '.sub', '.idx', '.par2', '.sfv'];

// -----------------------------------------------------------------------------
// II. POMOCNÉ FUNKCIE (UTILS)
// -----------------------------------------------------------------------------

function log_message(string $level, string $message): void {
    error_log("[$level] " . date('Y-m-d H:i:s') . " " . $message);
}

function log_info(string $message): void {
    log_message('INFO', $message);
}

function log_err(string $message): void {
    log_message('ERROR', $message);
}

function removeDiacritics(string $str): string {
    $map = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
        'í' => 'i', 'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ž' => 'z',
        'Á' => 'A', 'Ä' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E',
        'Í' => 'I', 'Ľ' => 'L', 'Ĺ' => 'L', 'Ň' => 'N', 'Ó' => 'O', 'Ô' => 'O',
        'Ŕ' => 'R', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U',
        'Ý' => 'Y', 'Ž' => 'Z',
    ];
    return strtr($str, $map);
}

function shortenTitle(string $title, int $wordCount = 3): string {
    $words = explode(' ', $title);
    return implode(' ', array_slice($words, 0, $wordCount));
}

function extractQuality(string $title): string {
    $titleLower = strtolower($title);
    if (strpos($titleLower, '2160p') !== false || strpos($titleLower, '4k') !== false) return '4K';
    if (strpos($titleLower, '1080p') !== false) return '1080p';
    if (strpos($titleLower, '720p') !== false) return '720p';
    if (strpos($titleLower, '480p') !== false) return '480p';
    return 'SD';
}

function validateInfoHash(string $infoHash): bool {
    return (bool) preg_match('/^[a-fA-F0-9]{40}$/', $infoHash);
}

function handleApiError(Throwable $error, string $context): void {
    $message = $error->getMessage();
    log_err("$context: $message");
}

function generateSearchQueries(string $title, ?string $originalTitle, string $type, ?int $season, ?int $episode): array {
    $queries = [];
    $baseTitles = array_unique(array_filter([$title, $originalTitle]));
    
    foreach ($baseTitles as $base) {
        $base = preg_replace('/\s*\(.*?\)\s*/', '', $base);
        $base = preg_replace('/TV (Mini )?Series/i', '', $base);
        $base = trim($base);

        $noDia = removeDiacritics($base);
        $short = shortenTitle($noDia);
        
        $currentQueries = [$base, $noDia, $short];

        if ($type === 'series' && $season && $episode) {
            $epTag = ' S' . str_pad($season, 2, '0', STR_PAD_LEFT) . 'E' . str_pad($episode, 2, '0', STR_PAD_LEFT);
            foreach ($currentQueries as $q) {
                $queries[] = $q . $epTag;
                $queries[] = str_replace([':', '\''], '', $q . $epTag);
            }
        } else {
            foreach ($currentQueries as $q) {
                $queries[] = $q;
                $queries[] = str_replace([':', '\''], '', $q);
            }
        }
    }
    
    return array_unique($queries);
}

// -----------------------------------------------------------------------------
// III. AUTENTIFIKÁCIA
// -----------------------------------------------------------------------------

function createUniqueClientId(string $ip, ?string $userAgent): string {
    $userAgentHash = hash('sha256', $userAgent ?? 'unknown');
    return $ip . '_' . substr($userAgentHash, 0, 16);
}

function getSessionFromRequest(): ?array {
    global $sessions;
    
    $sessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? ($_COOKIE['sessionId'] ?? null);
    $apiKeyDirect = $_GET['api_key'] ?? null;
    $clientId = createUniqueClientId($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null);
    
    if ($sessionId && isset($sessions[$sessionId]) && $sessions[$sessionId]['expires'] > time()) {
        return $sessions[$sessionId];
    }
    
    if ($apiKeyDirect) {
        return ['apiKey' => $apiKeyDirect, 'isLegacy' => true, 'clientId' => $clientId];
    }
    
    // Legacy session keys nie sú implementované v tejto verzii pre jednoduchosť.
    
    return null;
}

function createSession(string $apiKey, string $clientIp, ?string $userAgent): string {
    global $sessions;

    $sessionId = bin2hex(random_bytes(32));
    $uniqueClientId = createUniqueClientId($clientIp, $userAgent);
    
    $sessions[$sessionId] = [
        'apiKey' => $apiKey,
        'ip' => $clientIp,
        'uniqueClientId' => $uniqueClientId,
        'expires' => time() + SESSION_TTL,
        'created' => time(),
    ];
    
    log_info("🔑 Nová session: " . substr($sessionId, 0, 8) . "... pre $uniqueClientId");
    
    return $sessionId;
}

function checkRateLimit(string $ip, int $maxRequests = RATE_LIMIT_MAX): bool {
    global $rate_limiter;
    
    $now = time();
    $userRequests = $rate_limiter[$ip] ?? ['requests' => [], 'lastReset' => $now];
    
    $userRequests['requests'] = array_filter($userRequests['requests'], fn($time) => $now - $time < RATE_LIMIT_WINDOW);
    
    if (count($userRequests['requests']) >= $maxRequests) {
        return false;
    }
    
    $userRequests['requests'][] = $now;
    $rate_limiter[$ip] = $userRequests;
    
    return true;
}

function cleanupExpiredSessions(): void {
    global $sessions, $rate_limiter;

    $now = time();
    $cleanedSessions = 0;
    foreach ($sessions as $id => $session) {
        if ($session['expires'] <= $now) {
            unset($sessions[$id]);
            $cleanedSessions++;
        }
    }

    $cleanedRateLimit = 0;
    foreach ($rate_limiter as $ip => $data) {
        $data['requests'] = array_filter($data['requests'], fn($time) => $now - $time < RATE_LIMIT_WINDOW);
        if (count($data['requests']) === 0) {
            unset($rate_limiter[$ip]);
            $cleanedRateLimit++;
        }
    }
    
    if ($cleanedSessions > 0 || $cleanedRateLimit > 0) {
        log_info("🧹 Auth cleanup: $cleanedSessions sessions, $cleanedRateLimit rate limits");
    }
}

// -----------------------------------------------------------------------------
// IV. KLIENT PRE REAL-DEBRID API
// -----------------------------------------------------------------------------

function createRDClient(string $apiKey): CurlHandle {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'User-Agent: SKTorrent-Hybrid/1.0.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    return $ch;
}

function unrestrictLink(string $apiKey, string $link): ?string {
    log_info("🔓 Unrestrict link: " . substr($link, 0, 50) . "...");
    $ch = createRDClient($apiKey);
    
    curl_setopt_array($ch, [
        CURLOPT_URL => RD_API_BASE . '/unrestrict/link',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['link' => $link]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        log_err("❌ Unrestrict chyba: HTTP $httpCode - " . ($response ? json_decode($response)->error : ''));
        return null;
    }
    
    $data = json_decode($response, true);
    log_info("✅ Link unrestricted: " . $data['filename']);
    return $data['download'] ?? null;
}

function selectVideoFile(array $files): ?array {
    log_info("🎬 Analyzujem " . count($files) . " súborov pre výber videa:");

    foreach ($files as $index => $file) {
        $sizeGB = round($file['bytes'] / (1024 * 1024 * 1024), 2);
        log_info("📁 Súbor " . ($index + 1) . ": " . $file['path'] . " (" . $sizeGB . " GB) - ID: " . $file['id']);
    }

    $videoFiles = array_filter($files, function($file) {
        $fileName = strtolower($file['path']);
        $isVideo = false;
        foreach (VIDEO_EXTENSIONS as $ext) {
            if (str_ends_with($fileName, $ext)) {
                $isVideo = true;
                break;
            }
        }
        $isSkip = false;
        foreach (SKIP_EXTENSIONS as $ext) {
            if (str_ends_with($fileName, $ext)) {
                $isSkip = true;
                break;
            }
        }
        return $isVideo && !$isSkip;
    });

    log_info("🎥 Nájdených " . count($videoFiles) . " video súborov");
    
    if (empty($videoFiles)) {
        log_err("⚠️ Žiadne video súbory nenájdené.");
        return null;
    }
    
    if (count($videoFiles) === 1) {
        log_info("✅ Jeden video súbor nájdený: " . $videoFiles[0]['path']);
        return reset($videoFiles);
    }
    
    $largest = array_reduce($videoFiles, function(?array $largest, array $file) {
        return ($largest === null || $file['bytes'] > $largest['bytes']) ? $file : $largest;
    });
    
    if ($largest) {
        log_info("✅ Vybírám najväčší video súbor: " . $largest['path']);
    }
    
    return $largest;
}

// Táto funkcia je opravená
function selectTorrentFiles(string $apiKey, string $torrentId): ?array {
    $ch = createRDClient($apiKey);
    
    log_info("🔧 Analyzujem súbory pre $torrentId");
    
    sleep(5); // Čakaj, kým súbory budú dostupné
    
    curl_setopt($ch, CURLOPT_URL, RD_API_BASE . "/torrents/info/$torrentId");
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    
    if (!isset($data['files'])) {
        log_err('Torrent nemá dostupné súbory');
        return null;
    }
    
    $selectedFile = selectVideoFile($data['files']);
    
    if (!$selectedFile) {
        return null;
    }
    
    $fileId = $selectedFile['id'];
    log_info("🎯 Vybírám video súbor: ID=$fileId, " . $selectedFile['path']);
    
    curl_setopt_array($ch, [
        CURLOPT_URL => RD_API_BASE . "/torrents/selectFiles/$torrentId",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['files' => $fileId]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    
    curl_exec($ch);
    curl_close($ch);
    
    log_info("✅ Video súbor úspešne vybratý (ID: $fileId)");
    
    return $selectedFile;
}

// -----------------------------------------------------------------------------
// V. HLAVNÉ FUNKCIE PRE PRÁCU S TORRENTMI
// -----------------------------------------------------------------------------

function addTorrentFile(string $apiKey, string $torrentData, string $infoHash): ?array {
    log_info("📥 Pridávam torrent súbor do RD pre $infoHash...");
    
    $ch = createRDClient($apiKey);
    
    $tmpFile = tmpfile();
    fwrite($tmpFile, $torrentData);
    fseek($tmpFile, 0);

    curl_setopt_array($ch, [
        CURLOPT_URL => RD_API_BASE . '/torrents/addTorrent',
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $torrentData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-bittorrent',
            'Content-Length: ' . strlen($torrentData),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 201) {
        log_err("❌ addTorrentFile chyba: HTTP $httpCode - " . ($response ? json_decode($response)->error : ''));
        return null;
    }

    $data = json_decode($response, true);
    log_info("✅ Torrent úspešne pridaný: " . $data['id']);

    return ['id' => $data['id'], 'uri' => $data['uri']];
}

function addMagnetLink(string $apiKey, string $magnetLink): ?array {
    log_info("🧲 Pridávam magnet link: " . substr($magnetLink, 0, 50) . "...");
    
    $ch = createRDClient($apiKey);
    curl_setopt_array($ch, [
        CURLOPT_URL => RD_API_BASE . '/torrents/addMagnet',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['magnet' => $magnetLink]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 201) {
        log_err("❌ addMagnetLink chyba: HTTP $httpCode - " . ($response ? json_decode($response)->error : ''));
        return null;
    }

    $data = json_decode($response, true);
    log_info("✅ Magnet úspešne pridaný: " . $data['id']);

    return ['id' => $data['id'], 'uri' => $data['uri']];
}

function waitForTorrentCompletion(string $apiKey, string $torrentId): ?array {
    log_info("⏳ Čekám na spracovanie $torrentId...");
    $ch = createRDClient($apiKey);
    
    $downloadingLink = ['url' => 'https://torrentio.strem.fun/videos/downloading_v2.mp4', 'cacheDuration' => 60 * 1000];

    for ($i = 0; $i < 24; $i++) {
        sleep(15);
        curl_setopt($ch, CURLOPT_URL, RD_API_BASE . "/torrents/info/$torrentId");
        $response = curl_exec($ch);
        $torrent = json_decode($response, true);

        log_info("📊 Status: " . ($torrent['status'] ?? 'neznámy') . " (" . ($torrent['progress'] ?? 0) . "%)");

        if (($torrent['status'] ?? '') === 'downloaded' && !empty($torrent['links'])) {
            log_info("✅ Torrent dokončený s " . count($torrent['links']) . " linkmi.");
            $unrestrictedLinks = [];
            foreach ($torrent['links'] as $link) {
                if ($directLink = unrestrictLink($apiKey, $link)) {
                    $unrestrictedLinks[] = ['url' => $directLink];
                }
            }
            if (!empty($unrestrictedLinks)) {
                return $unrestrictedLinks;
            }
        }
        
        if (($torrent['status'] ?? '') === 'downloading') {
            return [$downloadingLink];
        }

        if (($torrent['status'] ?? '') === 'error') {
            log_err("Torrent error: " . ($torrent['status'] ?? ''));
            return null;
        }
    }
    
    log_err("Timeout pri čakaní na spracovanie (6 minút).");
    return null;
}

// -----------------------------------------------------------------------------
// VI. HLAVNÁ APLIKÁCIA (ROUTING)
// -----------------------------------------------------------------------------

function serveManifest(): void {
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'sk-torrent.stremio-addon',
        'version' => '1.0.0',
        'name' => 'SK Torrent Addon',
        'description' => 'SK Torrent Stremio Addon s podporou Real-Debrid. Vyhľadáva slovenské a české torrenty.',
        'resources' => ['stream', 'catalog'],
        'types' => ['movie', 'series'],
        'idPrefixes' => ['tt', 'tt0'],
        'catalogs' => [
            ['type' => 'movie', 'id' => 'sk_torrent_movies', 'name' => 'SKTorrent Filmy'],
            ['type' => 'series', 'id' => 'sk_torrent_series', 'name' => 'SKTorrent Seriály'],
        ],
        'addon_api_key' => ADDON_API_KEY,
    ]);
}

function serveCatalog(): void {
    header('Content-Type: application/json');
    echo json_encode(['metas' => []]);
}

function serveStream(string $type, string $id): void {
    header('Content-Type: application/json');
    log_info("Stream požiadavka pre typ: $type, ID: $id");

    $apiKey = $_GET['apiKey'] ?? ''; // Predpokladáme API kľúč z požiadavky
    if (empty($apiKey) || $apiKey !== ADDON_API_KEY) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    
    $infoHash = explode(':', $id)[0];
    if (!validateInfoHash($infoHash)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid infoHash']);
        return;
    }

    try {
        $ch = createRDClient($apiKey);
        curl_setopt($ch, CURLOPT_URL, RD_API_BASE . '/torrents');
        $response = curl_exec($ch);
        $existingTorrents = json_decode($response, true);
        
        $existing = array_values(array_filter($existingTorrents, fn($t) => ($t['hash'] ?? '') === $infoHash));
        $torrentId = $existing[0]['id'] ?? null;
        $torrentStatus = $existing[0]['status'] ?? null;
        
        if ($torrentStatus === 'downloading') {
            log_info("⬇️ Stahování probíhá...");
            echo json_encode(['streams' => [['url' => 'https://torrentio.strem.fun/videos/downloading_v2.mp4', 'title' => 'Stahování...']]]);
            return;
        }

        if ($torrentStatus === 'downloaded' && !empty($existing[0]['links'])) {
            log_info("✅ Torrent již existuje a je dokončený.");
            $unrestrictedLinks = [];
            foreach ($existing[0]['links'] as $link) {
                if ($directLink = unrestrictLink($apiKey, $link)) {
                    $unrestrictedLinks[] = ['url' => $directLink];
                }
            }
            if (!empty($unrestrictedLinks)) {
                echo json_encode(['streams' => $unrestrictedLinks]);
                return;
            }
        }
        
        // Ak torrent neexistuje alebo nie je dokončený, je potrebné ho pridať.
        // Pre jednoduchosť predpokladáme, že .torrent súbor je dostupný.
        // V reálnej aplikácii by ste ho stiahli z SKTorrentu.
        log_info("ℹ️ Torrent neexistuje, pridávam ho. (Táto časť kódu je zjednodušená)");
        
        // *******************************************************************
        // POZNÁMKA: Tu by ste museli implementovať stiahnutie .torrent súboru
        // zo SKTorrentu pomocou infoHashu. Pre túto ukážku to preskočíme.
        // Vytvorte .torrent dáta ručne pre ukážku:
        $exampleTorrentData = ''; // Nahraďte skutočnými binárnymi dátami .torrent súboru
        // *******************************************************************
        
        $addResult = addTorrentFile($apiKey, $exampleTorrentData, $infoHash);
        $torrentId = $addResult['id'] ?? null;

        if ($torrentId) {
            selectTorrentFiles($apiKey, $torrentId);
            $links = waitForTorrentCompletion($apiKey, $torrentId);
            echo json_encode(['streams' => $links]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add torrent to Real-Debrid']);
        }
        
    } catch (Throwable $e) {
        log_err("Celková chyba pri stream požiadavke: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
}


function routeRequest(): void {
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    
    if ($path === '/manifest.json') {
        serveManifest();
    } elseif (preg_match('/^\/catalog\/([a-z0-9_]+)\.json$/', $path, $matches)) {
        serveCatalog();
    } elseif (preg_match('/^\/stream\/([a-z]+)\/(tt[0-9]+):([a-f0-9]+)\.json$/', $path, $matches)) {
        $type = $matches[1];
        $id = $matches[2];
        $infoHash = $matches[3];
        serveStream($type, "$id:$infoHash");
    } else {
        http_response_code(404);
        echo 'Not Found';
    }
}

// Spustenie aplikácie
routeRequest();

?>
