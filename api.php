<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const PEXELS_API_KEY = 'DG6TLHieu8SPvHusg2BcsLVMVSCj9cSapilwMuDLdfxPLf2C6hW5bz6J';
const PEXELS_SEARCH_URL = 'https://api.pexels.com/videos/search';
const VIDEO_DIR = __DIR__ . '/videos';
const VIDEO_PUBLIC_PATH = 'videos';
const OUTPUT_WIDTH = 1920;
const OUTPUT_HEIGHT = 1080;
const OUTPUT_FPS = 30;

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

function buildCommand(array $parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
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

function isValidDownloadUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    return isset($parts['scheme']) && strtolower((string)$parts['scheme']) === 'https';
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
