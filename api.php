<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    require $localConfigPath;
}

const PEXELS_API_KEY = 'DG6TLHieu8SPvHusg2BcsLVMVSCj9cSapilwMuDLdfxPLf2C6hW5bz6J';
const PEXELS_SEARCH_URL = 'https://api.pexels.com/videos/search';
const GEMINI_TTS_MODEL = 'gemini-2.5-flash-preview-tts';
const GEMINI_TTS_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
const VIDEO_DIR = __DIR__ . '/videos';
const VIDEO_PUBLIC_PATH = 'videos';
const AUDIO_DIR = __DIR__ . '/audio';
const AUDIO_PUBLIC_PATH = 'audio';
const OUTPUT_WIDTH = 1920;
const OUTPUT_HEIGHT = 1080;
const OUTPUT_FPS = 30;
const TTS_SAMPLE_RATE = 24000;
const TTS_CHANNELS = 1;
const TTS_MAX_CHUNK_CHARS = 9000;
const TTS_RETRY_ATTEMPTS = 3;
const TTS_REQUEST_TIMEOUT_SECONDS = 240;
const TTS_EXECUTION_WINDOW_SECONDS = 900;

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $method === 'POST'
        ? (readJsonBody()['action'] ?? '')
        : ($_GET['action'] ?? '');

    if ($method === 'GET' && $action === 'search') {
        handleSearch();
        exit;
    }

    if ($method === 'POST' && $action === 'download') {
        handleDownload();
        exit;
    }

    if ($method === 'POST' && $action === 'tts_chunk') {
        handleTtsChunk();
        exit;
    }

    if ($method === 'POST' && $action === 'tts_merge') {
        handleTtsMerge();
        exit;
    }

    respondError('Unsupported action.', 400);
} catch (Throwable $error) {
    respondError($error->getMessage(), 500);
}

function handleSearch(): void
{
    $query = trim((string)($_GET['query'] ?? ''));

    if ($query === '') {
        respondError('Missing search query.', 400);
    }

    if (PEXELS_API_KEY === 'PEXELS_API_KEY_HERE' || PEXELS_API_KEY === '') {
        respondError('Set your Pexels API key in api.php before searching.', 500);
    }

    $url = PEXELS_SEARCH_URL . '?' . http_build_query([
        'query' => $query,
        'orientation' => 'landscape',
        'per_page' => 10,
    ]);

    $response = requestPexels($url);
    respondJson([
        'ok' => true,
        'videos' => $response['videos'] ?? [],
    ]);
}

function handleDownload(): void
{
    $payload = readJsonBody();
    $segment = filter_var($payload['segment'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $url = trim((string)($payload['url'] ?? ''));

    if (!$segment) {
        respondError('Missing or invalid segment number.', 400);
    }

    if (!isValidDownloadUrl($url)) {
        respondError('Missing or invalid video URL.', 400);
    }

    ensureVideoDirectory();

    $fileName = sprintf('segment_%d.mp4', $segment);
    $destination = VIDEO_DIR . '/' . $fileName;
    $sourcePath = VIDEO_DIR . '/' . sprintf('segment_%d_source.tmp', $segment);

    downloadFile($url, $sourcePath);
    reencodeVideoForHitFilm($sourcePath, $destination);
    @unlink($sourcePath);

    respondJson([
        'ok' => true,
        'path' => VIDEO_PUBLIC_PATH . '/' . $fileName,
        'codec' => 'libx264',
        'resolution' => OUTPUT_WIDTH . 'x' . OUTPUT_HEIGHT,
        'fps' => OUTPUT_FPS,
        'frameRateMode' => 'CFR',
    ]);
}

function handleTtsChunk(): void
{
    extendExecutionTime(TTS_EXECUTION_WINDOW_SECONDS);

    $payload = readJsonBody();
    $chunkIndex = filter_var($payload['chunkIndex'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $totalChunks = filter_var($payload['totalChunks'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $text = normalizePromptText((string)($payload['text'] ?? ''));
    $voice = normalizeGeminiVoice((string)($payload['voice'] ?? 'Sulafat'));
    $pacing = normalizeTtsPacing((string)($payload['pacing'] ?? 'slow_warm'));
    $temperature = normalizeTemperature($payload['temperature'] ?? 0.75);
    $runId = sanitizeRunId((string)($payload['runId'] ?? 'voice'));
    $apiKey = resolveGeminiApiKey();

    if (!$chunkIndex || !$totalChunks || $chunkIndex > $totalChunks) {
        respondError('Missing or invalid chunk number.', 400);
    }

    if ($text === '') {
        respondError('Missing script text for this chunk.', 400);
    }

    if (mb_strlen($text, 'UTF-8') > TTS_MAX_CHUNK_CHARS) {
        respondError('Chunk is too long. Split the script into smaller parts.', 400);
    }

    ensureAudioDirectory();

    $prompt = buildTtsPrompt($text, $pacing, $chunkIndex, $totalChunks);
    $pcm = requestGeminiTts($prompt, $voice, $temperature, $apiKey, GEMINI_TTS_MODEL);

    $baseName = sprintf('voice_%s_%03d', $runId, $chunkIndex);
    $pcmPath = AUDIO_DIR . '/' . $baseName . '.pcm';
    $mp3Path = AUDIO_DIR . '/' . $baseName . '.mp3';

    if (file_put_contents($pcmPath, $pcm) === false) {
        respondError('Unable to save generated PCM audio.', 500);
    }

    convertPcmToMp3($pcmPath, $mp3Path);
    @unlink($pcmPath);

    respondJson([
        'ok' => true,
        'path' => AUDIO_PUBLIC_PATH . '/' . basename($mp3Path),
        'chunkIndex' => $chunkIndex,
        'totalChunks' => $totalChunks,
        'voice' => $voice,
        'duration' => probeAudioDuration($mp3Path),
    ]);
}

function handleTtsMerge(): void
{
    extendExecutionTime(TTS_EXECUTION_WINDOW_SECONDS);

    $payload = readJsonBody();
    $runId = sanitizeRunId((string)($payload['runId'] ?? 'voice'));
    $files = $payload['files'] ?? [];

    if (!is_array($files) || count($files) === 0) {
        respondError('Missing chunk files to merge.', 400);
    }

    ensureAudioDirectory();

    $localFiles = [];
    foreach ($files as $file) {
        $localFiles[] = resolveAudioPublicPath((string)$file);
    }

    $outputPath = AUDIO_DIR . '/' . sprintf('voice_%s_final.mp3', $runId);
    mergeMp3Files($localFiles, $outputPath);

    respondJson([
        'ok' => true,
        'path' => AUDIO_PUBLIC_PATH . '/' . basename($outputPath),
        'chunks' => count($localFiles),
        'duration' => probeAudioDuration($outputPath),
    ]);
}

function readJsonBody(): array
{
    static $cachedPayload = null;

    if ($cachedPayload !== null) {
        return $cachedPayload;
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody === false ? '' : $rawBody, true);

    if (!is_array($payload)) {
        respondError('Request body must be valid JSON.', 400);
    }

    $cachedPayload = $payload;
    return $cachedPayload;
}

function requestPexels(string $url): array
{
    $curl = curl_init($url);

    if ($curl === false) {
        respondError('Unable to initialize Pexels request.', 500);
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . PEXELS_API_KEY],
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
        respondError('Pexels request failed: ' . $curlError, 502);
    }

    $decoded = json_decode((string)$body, true);

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($decoded) && isset($decoded['error']) ? (string)$decoded['error'] : 'Pexels API returned an error.';
        respondError($message, $statusCode);
    }

    if (!is_array($decoded)) {
        respondError('Pexels API returned invalid JSON.', 502);
    }

    return $decoded;
}

function requestGeminiTts(string $prompt, string $voice, float $temperature, string $apiKey, string $model): string
{
    $url = sprintf(GEMINI_TTS_API_URL, rawurlencode($model));
    $payload = [
        'contents' => [[
            'parts' => [[
                'text' => $prompt,
            ]],
        ]],
        'generationConfig' => [
            'responseModalities' => ['AUDIO'],
            'temperature' => $temperature,
            'speechConfig' => [
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [
                        'voiceName' => $voice,
                    ],
                ],
            ],
        ],
        'model' => $model,
    ];

    $lastError = '';

    for ($attempt = 1; $attempt <= TTS_RETRY_ATTEMPTS; $attempt += 1) {
        $curl = curl_init($url);

        if ($curl === false) {
            respondError('Unable to initialize Gemini TTS request.', 500);
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => TTS_REQUEST_TIMEOUT_SECONDS,
        ]);

        extendExecutionTime(TTS_REQUEST_TIMEOUT_SECONDS + 60);
        $body = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $decoded = json_decode((string)$body, true);

        if ($body !== false && $statusCode >= 200 && $statusCode < 300 && is_array($decoded)) {
            $audioData = extractGeminiAudioData($decoded);
            if ($audioData !== '') {
                $pcm = base64_decode($audioData, true);

                if ($pcm !== false && $pcm !== '') {
                    return $pcm;
                }

                $lastError = 'Gemini returned invalid base64 audio data.';
            } else {
                $lastError = extractGeminiErrorMessage($decoded, 'Gemini returned no audio data.');
            }
        } else {
            $lastError = $curlError !== ''
                ? 'Gemini TTS request failed: ' . $curlError
                : extractGeminiErrorMessage(is_array($decoded) ? $decoded : [], 'Gemini API returned HTTP status ' . $statusCode . '.');
        }

        if ($attempt < TTS_RETRY_ATTEMPTS) {
            sleep($attempt);
        }
    }

    respondError($lastError, 502);
}

function extractGeminiAudioData(array $decoded): string
{
    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];

    if (!is_array($parts)) {
        return '';
    }

    foreach ($parts as $part) {
        if (isset($part['inlineData']['data']) && is_string($part['inlineData']['data'])) {
            return $part['inlineData']['data'];
        }

        if (isset($part['inline_data']['data']) && is_string($part['inline_data']['data'])) {
            return $part['inline_data']['data'];
        }
    }

    return '';
}

function extractGeminiErrorMessage(array $decoded, string $fallback): string
{
    if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
        return $decoded['error']['message'];
    }

    $finishReason = $decoded['candidates'][0]['finishReason'] ?? '';
    if (is_string($finishReason) && $finishReason !== '') {
        return $fallback . ' Finish reason: ' . $finishReason . '.';
    }

    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (is_string($text) && $text !== '') {
        return $fallback . ' Text returned instead: ' . mb_substr($text, 0, 220, 'UTF-8');
    }

    return $fallback;
}

function downloadFile(string $url, string $destination): void
{
    if (is_file($destination)) {
        @unlink($destination);
    }

    $fileHandle = fopen($destination, 'wb');

    if ($fileHandle === false) {
        respondError('Unable to create local video file.', 500);
    }

    $curl = curl_init($url);

    if ($curl === false) {
        fclose($fileHandle);
        respondError('Unable to initialize video download.', 500);
    }

    curl_setopt_array($curl, [
        CURLOPT_FILE => $fileHandle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);

    $success = curl_exec($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    fclose($fileHandle);

    if ($success === false || $statusCode < 200 || $statusCode >= 300) {
        @unlink($destination);
        $detail = $curlError !== '' ? $curlError : 'HTTP status ' . $statusCode;
        respondError('Video download failed: ' . $detail, 502);
    }

    if (!is_file($destination) || filesize($destination) === 0) {
        @unlink($destination);
        respondError('Downloaded video file is empty.', 502);
    }
}

function reencodeVideoForHitFilm(string $sourcePath, string $destination): void
{
    if (!is_file($sourcePath) || filesize($sourcePath) === 0) {
        respondError('Downloaded source video is missing.', 500);
    }

    $ffmpeg = findFfmpegBinary();
    $temporaryOutput = $destination . '.encoding.mp4';

    if (is_file($temporaryOutput)) {
        @unlink($temporaryOutput);
    }

    if (is_file($destination)) {
        @unlink($destination);
    }

    $videoFilter = sprintf(
        'scale=%d:%d:flags=lanczos,fps=%d,format=yuv420p',
        OUTPUT_WIDTH,
        OUTPUT_HEIGHT,
        OUTPUT_FPS
    );

    $command = buildCommand([
        $ffmpeg,
        '-y',
        '-i',
        $sourcePath,
        '-map',
        '0:v:0',
        '-an',
        '-vf',
        $videoFilter,
        '-c:v',
        'libx264',
        '-preset',
        'medium',
        '-crf',
        '20',
        '-profile:v',
        'high',
        '-level',
        '4.1',
        '-pix_fmt',
        'yuv420p',
        '-r',
        (string)OUTPUT_FPS,
        '-fps_mode',
        'cfr',
        '-movflags',
        '+faststart',
        '-tag:v',
        'avc1',
        $temporaryOutput,
    ]);

    $output = [];
    $statusCode = 0;
    exec($command . ' 2>&1', $output, $statusCode);

    if ($statusCode !== 0 || !is_file($temporaryOutput) || filesize($temporaryOutput) === 0) {
        @unlink($temporaryOutput);
        $detail = trim(implode("\n", array_slice($output, -8)));
        respondError('FFmpeg re-encode failed.' . ($detail !== '' ? ' ' . $detail : ''), 500);
    }

    rename($temporaryOutput, $destination);
}

function convertPcmToMp3(string $sourcePath, string $destination): void
{
    extendExecutionTime(TTS_EXECUTION_WINDOW_SECONDS);

    if (!is_file($sourcePath) || filesize($sourcePath) === 0) {
        respondError('Generated PCM audio is missing.', 500);
    }

    $ffmpeg = findFfmpegBinary();
    $temporaryOutput = $destination . '.encoding.mp3';

    if (is_file($temporaryOutput)) {
        @unlink($temporaryOutput);
    }

    if (is_file($destination)) {
        @unlink($destination);
    }

    $command = buildCommand([
        $ffmpeg,
        '-y',
        '-f',
        's16le',
        '-ar',
        (string)TTS_SAMPLE_RATE,
        '-ac',
        (string)TTS_CHANNELS,
        '-i',
        $sourcePath,
        '-vn',
        '-c:a',
        'libmp3lame',
        '-b:a',
        '192k',
        $temporaryOutput,
    ]);

    $output = [];
    $statusCode = 0;
    exec($command . ' 2>&1', $output, $statusCode);

    if ($statusCode !== 0 || !is_file($temporaryOutput) || filesize($temporaryOutput) === 0) {
        @unlink($temporaryOutput);
        $detail = trim(implode("\n", array_slice($output, -8)));
        respondError('FFmpeg MP3 conversion failed.' . ($detail !== '' ? ' ' . $detail : ''), 500);
    }

    rename($temporaryOutput, $destination);
}

function mergeMp3Files(array $sourcePaths, string $destination): void
{
    extendExecutionTime(TTS_EXECUTION_WINDOW_SECONDS);

    $ffmpeg = findFfmpegBinary();
    $concatFile = AUDIO_DIR . '/' . pathinfo($destination, PATHINFO_FILENAME) . '_concat.txt';
    $temporaryOutput = $destination . '.encoding.mp3';
    $lines = [];

    foreach ($sourcePaths as $path) {
        if (!is_file($path) || filesize($path) === 0) {
            respondError('Missing audio chunk: ' . basename($path), 400);
        }

        $lines[] = "file '" . str_replace("'", "'\\''", $path) . "'";
    }

    if (file_put_contents($concatFile, implode("\n", $lines) . "\n") === false) {
        respondError('Unable to create FFmpeg concat list.', 500);
    }

    if (is_file($temporaryOutput)) {
        @unlink($temporaryOutput);
    }

    if (is_file($destination)) {
        @unlink($destination);
    }

    $command = buildCommand([
        $ffmpeg,
        '-y',
        '-f',
        'concat',
        '-safe',
        '0',
        '-i',
        $concatFile,
        '-vn',
        '-c:a',
        'libmp3lame',
        '-b:a',
        '192k',
        $temporaryOutput,
    ]);

    $output = [];
    $statusCode = 0;
    exec($command . ' 2>&1', $output, $statusCode);
    @unlink($concatFile);

    if ($statusCode !== 0 || !is_file($temporaryOutput) || filesize($temporaryOutput) === 0) {
        @unlink($temporaryOutput);
        $detail = trim(implode("\n", array_slice($output, -8)));
        respondError('FFmpeg audio merge failed.' . ($detail !== '' ? ' ' . $detail : ''), 500);
    }

    rename($temporaryOutput, $destination);
}

function findFfmpegBinary(): string
{
    $candidates = [
        '/opt/homebrew/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/usr/bin/ffmpeg',
        'ffmpeg',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'ffmpeg' || is_executable($candidate)) {
            return $candidate;
        }
    }

    respondError('FFmpeg is required for H.264 CFR re-encoding but was not found.', 500);
}

function probeAudioDuration(string $path): ?float
{
    $ffprobe = findFfprobeBinary();

    if ($ffprobe === '') {
        return null;
    }

    $command = buildCommand([
        $ffprobe,
        '-v',
        'error',
        '-show_entries',
        'format=duration',
        '-of',
        'default=noprint_wrappers=1:nokey=1',
        $path,
    ]);

    $output = [];
    $statusCode = 0;
    exec($command . ' 2>&1', $output, $statusCode);

    if ($statusCode !== 0 || !isset($output[0])) {
        return null;
    }

    $duration = (float)$output[0];
    return $duration > 0 ? round($duration, 2) : null;
}

function findFfprobeBinary(): string
{
    $candidates = [
        '/opt/homebrew/bin/ffprobe',
        '/usr/local/bin/ffprobe',
        '/usr/bin/ffprobe',
        'ffprobe',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'ffprobe' || is_executable($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function buildCommand(array $parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function extendExecutionTime(int $seconds): void
{
    if ($seconds <= 0) {
        return;
    }

    @ini_set('max_execution_time', (string)$seconds);

    if (function_exists('set_time_limit')) {
        @set_time_limit($seconds);
    }
}

function ensureVideoDirectory(): void
{
    if (is_dir(VIDEO_DIR)) {
        return;
    }

    if (!mkdir(VIDEO_DIR, 0775, true) && !is_dir(VIDEO_DIR)) {
        respondError('Unable to create videos directory.', 500);
    }
}

function ensureAudioDirectory(): void
{
    if (is_dir(AUDIO_DIR)) {
        return;
    }

    if (!mkdir(AUDIO_DIR, 0775, true) && !is_dir(AUDIO_DIR)) {
        respondError('Unable to create audio directory.', 500);
    }
}

function isValidDownloadUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    return isset($parts['scheme']) && strtolower((string)$parts['scheme']) === 'https';
}

function resolveGeminiApiKey(): string
{
    global $GEMINI_API_KEY;

    $environmentKey = getenv('GEMINI_API_KEY');
    $apiKey = is_string($environmentKey) ? trim($environmentKey) : '';

    if ($apiKey === '' && isset($GEMINI_API_KEY)) {
        $apiKey = trim((string)$GEMINI_API_KEY);
    }

    if ($apiKey === '' && defined('GEMINI_API_KEY')) {
        $apiKey = trim(GEMINI_API_KEY);
    }

    if ($apiKey === '') {
        respondError('Set the Gemini API key in config.local.php or export GEMINI_API_KEY before starting PHP.', 400);
    }

    return $apiKey;
}

function normalizePromptText(string $text): string
{
    return trim(preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $text)) ?? '');
}

function normalizeGeminiVoice(string $voice): string
{
    $allowedVoices = [
        'Zephyr',
        'Puck',
        'Charon',
        'Kore',
        'Fenrir',
        'Leda',
        'Orus',
        'Aoede',
        'Callirrhoe',
        'Autonoe',
        'Enceladus',
        'Iapetus',
        'Umbriel',
        'Algieba',
        'Despina',
        'Erinome',
        'Algenib',
        'Rasalgethi',
        'Laomedeia',
        'Achernar',
        'Alnilam',
        'Schedar',
        'Gacrux',
        'Pulcherrima',
        'Achird',
        'Zubenelgenubi',
        'Vindemiatrix',
        'Sadachbia',
        'Sadaltager',
        'Sulafat',
    ];

    return in_array($voice, $allowedVoices, true) ? $voice : 'Sulafat';
}

function normalizeTtsPacing(string $pacing): string
{
    $profiles = getTtsPacingProfiles();
    return array_key_exists($pacing, $profiles) ? $pacing : 'slow_warm';
}

function normalizeTemperature(mixed $value): float
{
    $temperature = filter_var($value, FILTER_VALIDATE_FLOAT);

    if ($temperature === false) {
        return 0.75;
    }

    return max(0.2, min(1.4, (float)$temperature));
}

function sanitizeRunId(string $runId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $runId) ?? '';
    return $safe !== '' ? substr($safe, 0, 64) : date('YmdHis');
}

function resolveAudioPublicPath(string $publicPath): string
{
    $prefix = AUDIO_PUBLIC_PATH . '/';

    if (!str_starts_with($publicPath, $prefix)) {
        respondError('Invalid audio chunk path.', 400);
    }

    $fileName = basename($publicPath);

    if (!preg_match('/^voice_[a-zA-Z0-9_-]+_\d{3}\.mp3$/', $fileName)) {
        respondError('Invalid audio chunk file name.', 400);
    }

    $path = realpath(AUDIO_DIR . '/' . $fileName);
    $audioDir = realpath(AUDIO_DIR);

    if ($path === false || $audioDir === false || !str_starts_with($path, $audioDir . DIRECTORY_SEPARATOR)) {
        respondError('Audio chunk is not available.', 400);
    }

    return $path;
}

function getTtsPacingProfiles(): array
{
    return [
        'slow_warm' => [
            'label' => 'Slow, warm and natural',
            'pacing' => 'Speak a little slower than a typical YouTube narration, with relaxed breathing and small pauses after important ideas. Keep enough forward motion so the listener wants to continue.',
            'tag' => '[warm, friendly, conversational, slightly slow]',
        ],
        'natural' => [
            'label' => 'Natural conversation',
            'pacing' => 'Use an easy conversational pace, as if explaining something useful to a close friend. Keep the delivery human and grounded.',
            'tag' => '[friendly, conversational]',
        ],
        'dynamic' => [
            'label' => 'Dynamic but not rushed',
            'pacing' => 'Use a more energetic pace with clear emphasis on key ideas. Keep it authentic and never salesy or overly polished.',
            'tag' => '[engaged, warm, dynamic]',
        ],
    ];
}

function buildTtsPrompt(string $text, string $pacing, int $chunkIndex, int $totalChunks): string
{
    $profile = getTtsPacingProfiles()[$pacing];

    return <<<PROMPT
# AUDIO PROFILE: Createur de contenu francophone accessible

## THE SCENE
Tu parles directement a une seule personne, comme a un ami qui t'ecoute avec attention. Le ton est naturel, chaleureux, proche et dynamique. L'auditeur doit se sentir confortable, compris et motive a continuer la video jusqu'a la fin.

### DIRECTOR'S NOTES
Style: naturel, chaleureux, humain et authentique. Evite la voix off robotique, trop parfaite, trop lisse ou publicitaire.
Pacing: {$profile['pacing']}
Accent: francais clair et facile a comprendre. Garde une prononciation naturelle pour un public francophone.
Connection: donne l'impression d'une vraie conversation, avec une presence calme, encourageante et complice.
Continuity: ceci est la partie {$chunkIndex} sur {$totalChunks}. Ne lis jamais le numero de partie. Garde exactement la meme energie que les autres parties.
Important: lis seulement le texte du transcript. Ne lis pas les titres, les notes, les instructions ou les balises de direction.

### TRANSCRIPT
{$profile['tag']}
{$text}
PROMPT;
}

function respondJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function respondError(string $message, int $statusCode): void
{
    respondJson([
        'ok' => false,
        'error' => $message,
    ], $statusCode);
}
