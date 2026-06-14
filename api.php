<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    require $localConfigPath;
}

const PEXELS_API_KEY = 'DG6TLHieu8SPvHusg2BcsLVMVSCj9cSapilwMuDLdfxPLf2C6hW5bz6J';
const PEXELS_SEARCH_URL = 'https://api.pexels.com/videos/search';
const GEMINI_TEXT_MODEL = 'gemini-2.5-flash';
const GEMINI_TTS_MODEL = 'gemini-2.5-flash-preview-tts';
const GEMINI_TTS_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
const VIDEO_DIR = __DIR__ . '/videos';
const VIDEO_PUBLIC_PATH = 'videos';
const AUDIO_DIR = __DIR__ . '/audio';
const AUDIO_PUBLIC_PATH = 'audio';
const SHORTS_DIR = __DIR__ . '/shorts';
const SHORTS_PUBLIC_PATH = 'shorts';
const OUTPUT_WIDTH = 1920;
const OUTPUT_HEIGHT = 1080;
const OUTPUT_FPS = 30;
const SHORTS_OUTPUT_WIDTH = 1080;
const SHORTS_OUTPUT_HEIGHT = 1920;
const SHORTS_OUTPUT_FPS = 30;
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

    if ($method === 'GET' && $action === 'shorts_search') {
        handleShortsSearch();
        exit;
    }

    if ($method === 'POST' && $action === 'download') {
        handleDownload();
        exit;
    }

    if ($method === 'POST' && $action === 'shorts_generate_plan') {
        handleShortsGeneratePlan();
        exit;
    }

    if ($method === 'POST' && $action === 'shorts_download_asset') {
        handleShortsDownloadAsset();
        exit;
    }

    if ($method === 'POST' && $action === 'shorts_render_video') {
        handleShortsRenderVideo();
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

function handleShortsSearch(): void
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
        'orientation' => 'portrait',
        'per_page' => 12,
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

function handleShortsGeneratePlan(): void
{
    extendExecutionTime(240);

    $payload = readJsonBody();
    $topic = normalizePromptText((string)($payload['topic'] ?? ''));
    $module = normalizePromptText((string)($payload['module'] ?? 'auto'));
    $platform = normalizePromptText((string)($payload['platform'] ?? 'TikTok, Instagram Reels et YouTube Shorts'));
    $duration = filter_var($payload['duration'] ?? 35, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 15, 'max_range' => 75],
    ]);
    $angle = normalizePromptText((string)($payload['angle'] ?? 'auto'));
    $audience = normalizePromptText((string)($payload['audience'] ?? 'candidats TCF Canada'));
    $tone = normalizePromptText((string)($payload['tone'] ?? 'clair, humain, motivant'));
    $apiKey = resolveGeminiApiKey();

    if ($topic === '') {
        respondError('Missing TCF Canada topic.', 400);
    }

    $duration = $duration ?: 35;
    $prompt = buildShortsPlanPrompt($topic, $module, $platform, $duration, $angle, $audience, $tone);
    $rawText = requestGeminiText($prompt, 0.55, $apiKey, GEMINI_TEXT_MODEL);
    $plan = decodeGeminiJsonObject($rawText);

    if (!is_array($plan)) {
        respondError('Gemini did not return a valid JSON object.', 502);
    }

    $plan = normalizeShortsPlan($plan, $topic, inferShortsModuleFromTopic($topic), $duration);

    respondJson([
        'ok' => true,
        'plan' => $plan,
        'model' => GEMINI_TEXT_MODEL,
    ]);
}

function handleShortsDownloadAsset(): void
{
    extendExecutionTime(240);

    $payload = readJsonBody();
    $segment = filter_var($payload['segment'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $url = trim((string)($payload['url'] ?? ''));
    $runId = sanitizeRunId((string)($payload['runId'] ?? 'shorts'));

    if (!$segment) {
        respondError('Missing or invalid segment number.', 400);
    }

    if (!isValidDownloadUrl($url)) {
        respondError('Missing or invalid video URL.', 400);
    }

    ensureShortsDirectory();

    $fileName = sprintf('short_%s_clip_%03d.mp4', $runId, $segment);
    $destination = SHORTS_DIR . '/' . $fileName;
    $sourcePath = SHORTS_DIR . '/' . sprintf('short_%s_clip_%03d_source.tmp', $runId, $segment);

    downloadFile($url, $sourcePath);
    reencodeVideoForShorts($sourcePath, $destination);
    @unlink($sourcePath);

    respondJson([
        'ok' => true,
        'path' => SHORTS_PUBLIC_PATH . '/' . $fileName,
        'codec' => 'libx264',
        'resolution' => SHORTS_OUTPUT_WIDTH . 'x' . SHORTS_OUTPUT_HEIGHT,
        'fps' => SHORTS_OUTPUT_FPS,
        'frameRateMode' => 'CFR',
    ]);
}

function handleShortsRenderVideo(): void
{
    extendExecutionTime(900);

    $payload = readJsonBody();
    $runId = sanitizeRunId((string)($payload['runId'] ?? 'shorts'));
    $audioPath = resolveGeneratedAudioPublicPath((string)($payload['audioPath'] ?? ''));
    $clipPaths = $payload['clips'] ?? [];
    $segments = $payload['segments'] ?? [];
    $subtitles = $payload['subtitles'] ?? [];
    $title = normalizePromptText((string)($payload['title'] ?? 'TCF Canada short'));
    $requestedFileName = (string)($payload['outputFileName'] ?? '');

    if (!is_array($clipPaths) || count($clipPaths) === 0) {
        respondError('Missing vertical video clips.', 400);
    }

    ensureShortsDirectory();

    $localClips = array_map(
        static fn (string $publicPath): string => resolveShortsClipPublicPath($publicPath),
        array_values(array_filter($clipPaths, 'is_string'))
    );

    if (count($localClips) === 0) {
        respondError('No valid vertical video clips were provided.', 400);
    }

    $duration = probeAudioDuration($audioPath);
    if ($duration === null || $duration <= 0) {
        $duration = estimateSubtitleDuration(is_array($subtitles) ? $subtitles : []);
    }

    $duration = max(6.0, min(90.0, (float)$duration));
    $cues = normalizeSubtitleCues(is_array($subtitles) ? $subtitles : [], $duration);

    if (count($cues) === 0) {
        respondError('Missing subtitle cues.', 400);
    }

    $baseVideo = createShortsBaseVideo(
        $localClips,
        $duration,
        $runId,
        is_array($segments) ? $segments : []
    );
    $assPath = SHORTS_DIR . '/' . sprintf('short_%s_subtitles.ass', $runId);
    writeAssSubtitles($cues, $assPath);

    $safeTitle = sanitizeOutputTitle($title);
    $fileName = $requestedFileName !== ''
        ? sanitizeShortsOutputFileName($requestedFileName)
        : sprintf('short_%s_%s.mp4', $runId, $safeTitle);
    $destination = SHORTS_DIR . '/' . $fileName;
    $temporaryOutput = $destination . '.encoding.mp4';

    if (is_file($temporaryOutput)) {
        @unlink($temporaryOutput);
    }

    if (is_file($destination)) {
        @unlink($destination);
    }

    $ffmpeg = findFfmpegBinary();
    $subtitleFilter = 'subtitles=' . escapeFfmpegFilterValue($assPath) . ',format=yuv420p';
    $command = buildCommand([
        $ffmpeg,
        '-y',
        '-i',
        $baseVideo,
        '-i',
        $audioPath,
        '-t',
        number_format($duration, 3, '.', ''),
        '-map',
        '0:v:0',
        '-map',
        '1:a:0',
        '-vf',
        $subtitleFilter,
        '-c:v',
        'libx264',
        '-preset',
        'medium',
        '-crf',
        '20',
        '-pix_fmt',
        'yuv420p',
        '-r',
        (string)SHORTS_OUTPUT_FPS,
        '-fps_mode',
        'cfr',
        '-c:a',
        'aac',
        '-b:a',
        '192k',
        '-shortest',
        '-movflags',
        '+faststart',
        $temporaryOutput,
    ]);

    $output = [];
    $statusCode = 0;
    exec($command . ' 2>&1', $output, $statusCode);

    if ($statusCode !== 0 || !is_file($temporaryOutput) || filesize($temporaryOutput) === 0) {
        @unlink($temporaryOutput);
        $detail = trim(implode("\n", array_slice($output, -10)));
        respondError('Shorts render failed.' . ($detail !== '' ? ' ' . $detail : ''), 500);
    }

    rename($temporaryOutput, $destination);

    respondJson([
        'ok' => true,
        'path' => SHORTS_PUBLIC_PATH . '/' . basename($destination),
        'duration' => round($duration, 2),
        'resolution' => SHORTS_OUTPUT_WIDTH . 'x' . SHORTS_OUTPUT_HEIGHT,
        'subtitles' => SHORTS_PUBLIC_PATH . '/' . basename($assPath),
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
    $purpose = normalizePromptText((string)($payload['purpose'] ?? 'long_form'));
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

    $prompt = $purpose === 'tcf_shorts'
        ? buildShortsTtsPrompt($text, $pacing, $chunkIndex, $totalChunks)
        : buildTtsPrompt($text, $pacing, $chunkIndex, $totalChunks);
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

function requestGeminiText(string $prompt, float $temperature, string $apiKey, string $model): string
{
    $url = sprintf(GEMINI_TTS_API_URL, rawurlencode($model));
    $payload = [
        'contents' => [[
            'parts' => [[
                'text' => $prompt,
            ]],
        ]],
        'generationConfig' => [
            'temperature' => $temperature,
            'responseMimeType' => 'application/json',
            'maxOutputTokens' => 8192,
        ],
        'model' => $model,
    ];

    $lastError = '';

    for ($attempt = 1; $attempt <= TTS_RETRY_ATTEMPTS; $attempt += 1) {
        $curl = curl_init($url);

        if ($curl === false) {
            respondError('Unable to initialize Gemini text request.', 500);
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
            CURLOPT_TIMEOUT => 180,
        ]);

        extendExecutionTime(240);
        $body = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $decoded = json_decode((string)$body, true);

        if ($body !== false && $statusCode >= 200 && $statusCode < 300 && is_array($decoded)) {
            $text = extractGeminiTextData($decoded);
            if ($text !== '') {
                return $text;
            }

            $lastError = extractGeminiErrorMessage($decoded, 'Gemini returned no text data.');
        } else {
            $lastError = $curlError !== ''
                ? 'Gemini text request failed: ' . $curlError
                : extractGeminiErrorMessage(is_array($decoded) ? $decoded : [], 'Gemini API returned HTTP status ' . $statusCode . '.');
        }

        if ($attempt < TTS_RETRY_ATTEMPTS) {
            sleep($attempt);
        }
    }

    respondError($lastError, 502);
}

function extractGeminiTextData(array $decoded): string
{
    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];

    if (!is_array($parts)) {
        return '';
    }

    $texts = [];
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $texts[] = $part['text'];
        }
    }

    return trim(implode("\n", $texts));
}

function decodeGeminiJsonObject(string $rawText): ?array
{
    $text = trim($rawText);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $decoded = json_decode($text, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
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

function reencodeVideoForShorts(string $sourcePath, string $destination): void
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
        'scale=%d:%d:force_original_aspect_ratio=increase:flags=lanczos,crop=%d:%d,fps=%d,format=yuv420p',
        SHORTS_OUTPUT_WIDTH,
        SHORTS_OUTPUT_HEIGHT,
        SHORTS_OUTPUT_WIDTH,
        SHORTS_OUTPUT_HEIGHT,
        SHORTS_OUTPUT_FPS
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
        '4.2',
        '-pix_fmt',
        'yuv420p',
        '-r',
        (string)SHORTS_OUTPUT_FPS,
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
        respondError('FFmpeg vertical re-encode failed.' . ($detail !== '' ? ' ' . $detail : ''), 500);
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

function probeVideoDuration(string $path): ?float
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

function createShortsBaseVideo(array $clipPaths, float $duration, string $runId, array $segments = []): string
{
    ensureShortsDirectory();

    $targetDuration = max(6.0, $duration);
    $clipDurations = calculateShortsClipDurations($segments, count($clipPaths), $targetDuration);
    $transitionDuration = count($clipPaths) > 1 ? 0.18 : 0.0;
    $baseVideo = SHORTS_DIR . '/' . sprintf('short_%s_base.mp4', $runId);
    $temporaryOutput = $baseVideo . '.encoding.mp4';

    if (is_file($temporaryOutput)) {
        @unlink($temporaryOutput);
    }

    if (is_file($baseVideo)) {
        @unlink($baseVideo);
    }

    $ffmpeg = findFfmpegBinary();
    $commandParts = [$ffmpeg, '-y'];
    $filterParts = [];

    foreach ($clipPaths as $index => $clipPath) {
        if (!is_file($clipPath) || filesize($clipPath) === 0) {
            respondError('Missing shorts clip: ' . basename($clipPath), 400);
        }

        $commandParts[] = '-stream_loop';
        $commandParts[] = '-1';
        $commandParts[] = '-i';
        $commandParts[] = $clipPath;

        $trimDuration = $clipDurations[$index] + ($index < count($clipPaths) - 1 ? $transitionDuration : 0.0);
        $filterParts[] = sprintf(
            '[%d:v]trim=duration=%.3f,setpts=PTS-STARTPTS,scale=%d:%d:force_original_aspect_ratio=increase:flags=lanczos,crop=%d:%d,fps=%d,eq=contrast=1.04:saturation=1.08,format=yuv420p[v%d]',
            $index,
            $trimDuration,
            SHORTS_OUTPUT_WIDTH,
            SHORTS_OUTPUT_HEIGHT,
            SHORTS_OUTPUT_WIDTH,
            SHORTS_OUTPUT_HEIGHT,
            SHORTS_OUTPUT_FPS,
            $index
        );
    }

    $finalVideoLabel = 'v0';
    if (count($clipPaths) > 1) {
        $transitions = ['fadefast', 'smoothleft', 'slideleft', 'zoomin', 'dissolve'];
        $cumulativeOffset = $clipDurations[0];

        for ($index = 1; $index < count($clipPaths); $index += 1) {
            $inputLabel = $index === 1 ? 'v0' : 'vx' . ($index - 1);
            $outputLabel = 'vx' . $index;
            $transition = $transitions[($index - 1) % count($transitions)];
            $filterParts[] = sprintf(
                '[%s][v%d]xfade=transition=%s:duration=%.3f:offset=%.3f[%s]',
                $inputLabel,
                $index,
                $transition,
                $transitionDuration,
                $cumulativeOffset,
                $outputLabel
            );
            $finalVideoLabel = $outputLabel;
            $cumulativeOffset += $clipDurations[$index];
        }
    }

    $commandParts = array_merge($commandParts, [
        '-filter_complex',
        implode(';', $filterParts),
        '-map',
        '[' . $finalVideoLabel . ']',
        '-an',
        '-t',
        number_format($targetDuration, 3, '.', ''),
        '-c:v',
        'libx264',
        '-preset',
        'veryfast',
        '-crf',
        '22',
        '-pix_fmt',
        'yuv420p',
        '-r',
        (string)SHORTS_OUTPUT_FPS,
        '-fps_mode',
        'cfr',
        '-movflags',
        '+faststart',
        $temporaryOutput,
    ]);

    $command = buildCommand($commandParts);
    $output = [];
    $statusCode = 0;
    exec($command . ' 2>&1', $output, $statusCode);

    if ($statusCode !== 0 || !is_file($temporaryOutput) || filesize($temporaryOutput) === 0) {
        @unlink($temporaryOutput);
        $detail = trim(implode("\n", array_slice($output, -8)));
        respondError('Shorts base video creation failed.' . ($detail !== '' ? ' ' . $detail : ''), 500);
    }

    rename($temporaryOutput, $baseVideo);
    return $baseVideo;
}

function calculateShortsClipDurations(array $segments, int $clipCount, float $targetDuration): array
{
    if ($clipCount <= 0) {
        return [];
    }

    $rawDurations = [];
    for ($index = 0; $index < $clipCount; $index += 1) {
        $segment = $segments[$index] ?? null;
        $start = is_array($segment)
            ? filter_var($segment['start'] ?? null, FILTER_VALIDATE_FLOAT)
            : false;
        $end = is_array($segment)
            ? filter_var($segment['end'] ?? null, FILTER_VALIDATE_FLOAT)
            : false;

        $rawDurations[] = $start !== false && $end !== false && (float)$end > (float)$start
            ? max(1.2, (float)$end - (float)$start)
            : 1.0;
    }

    $rawTotal = array_sum($rawDurations);
    if ($rawTotal <= 0) {
        return array_fill(0, $clipCount, $targetDuration / $clipCount);
    }

    $scale = $targetDuration / $rawTotal;
    $durations = array_map(
        static fn (float $value): float => max(0.8, $value * $scale),
        $rawDurations
    );

    $scaledTotal = array_sum($durations);
    if ($scaledTotal > 0 && abs($scaledTotal - $targetDuration) > 0.01) {
        $correction = $targetDuration / $scaledTotal;
        $durations = array_map(
            static fn (float $value): float => $value * $correction,
            $durations
        );
    }

    $rounded = array_map(
        static fn (float $value): float => round($value, 3),
        $durations
    );
    $rounded[$clipCount - 1] += round($targetDuration - array_sum($rounded), 3);

    return $rounded;
}

function estimateSubtitleDuration(array $subtitles): float
{
    $maxEnd = 0.0;

    foreach ($subtitles as $subtitle) {
        if (!is_array($subtitle)) {
            continue;
        }

        $end = filter_var($subtitle['end'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($end !== false) {
            $maxEnd = max($maxEnd, (float)$end);
        }
    }

    return $maxEnd > 0 ? $maxEnd : 35.0;
}

function normalizeSubtitleCues(array $subtitles, float $targetDuration): array
{
    $rawCues = [];
    $maxEnd = 0.0;

    foreach ($subtitles as $subtitle) {
        if (!is_array($subtitle)) {
            continue;
        }

        $start = filter_var($subtitle['start'] ?? null, FILTER_VALIDATE_FLOAT);
        $end = filter_var($subtitle['end'] ?? null, FILTER_VALIDATE_FLOAT);
        $text = normalizePromptText((string)($subtitle['text'] ?? ''));

        if ($start === false || $end === false || $text === '') {
            continue;
        }

        $start = max(0.0, (float)$start);
        $end = max($start + 0.4, (float)$end);
        $maxEnd = max($maxEnd, $end);
        $rawCues[] = [
            'start' => $start,
            'end' => $end,
            'text' => $text,
        ];
    }

    if (count($rawCues) === 0) {
        return [];
    }

    $scale = $maxEnd > 0 ? $targetDuration / $maxEnd : 1.0;
    $cues = [];

    foreach ($rawCues as $cue) {
        $start = max(0.0, min($targetDuration, $cue['start'] * $scale));
        $end = max($start + 0.35, min($targetDuration, $cue['end'] * $scale));
        $cues[] = [
            'start' => round($start, 2),
            'end' => round($end, 2),
            'text' => $cue['text'],
        ];
    }

    return $cues;
}

function writeAssSubtitles(array $cues, string $destination): void
{
    $lines = [
        '[Script Info]',
        'ScriptType: v4.00+',
        'PlayResX: ' . SHORTS_OUTPUT_WIDTH,
        'PlayResY: ' . SHORTS_OUTPUT_HEIGHT,
        'WrapStyle: 0',
        'ScaledBorderAndShadow: yes',
        '',
        '[V4+ Styles]',
        'Format: Name,Fontname,Fontsize,PrimaryColour,SecondaryColour,OutlineColour,BackColour,Bold,Italic,Underline,StrikeOut,ScaleX,ScaleY,Spacing,Angle,BorderStyle,Outline,Shadow,Alignment,MarginL,MarginR,MarginV,Encoding',
        'Style: Hook,Arial,82,&H004DD8FF,&H000000FF,&H00000000,&HA0000000,-1,0,0,0,100,100,0,0,1,6,1,2,75,75,270,1',
        'Style: ShortMain,Arial,72,&H00FFFFFF,&H000000FF,&H00000000,&H96000000,-1,0,0,0,100,100,0,0,1,5,1,2,90,90,255,1',
        'Style: CTA,Arial,76,&H00525F1F,&H000000FF,&H00FFFFFF,&HA0000000,-1,0,0,0,100,100,0,0,1,4,1,2,80,80,245,1',
        '',
        '[Events]',
        'Format: Layer,Start,End,Style,Name,MarginL,MarginR,MarginV,Effect,Text',
    ];

    $lastIndex = count($cues) - 1;
    foreach ($cues as $index => $cue) {
        $start = formatAssTime((float)$cue['start']);
        $end = formatAssTime((float)$cue['end']);
        $text = escapeAssText(wrapSubtitleText((string)$cue['text']));
        $style = $index === 0 ? 'Hook' : ($index === $lastIndex ? 'CTA' : 'ShortMain');
        $animated = '{\\fad(60,100)\\t(0,120,\\fscx110\\fscy110)\\t(120,260,\\fscx100\\fscy100)}' . $text;
        $lines[] = "Dialogue: 0,{$start},{$end},{$style},,0,0,0,,{$animated}";
    }

    if (file_put_contents($destination, implode("\n", $lines) . "\n") === false) {
        respondError('Unable to write animated subtitles.', 500);
    }
}

function wrapSubtitleText(string $text): string
{
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if (mb_strlen($candidate, 'UTF-8') > 24 && $current !== '') {
            $lines[] = $current;
            $current = $word;
            continue;
        }

        $current = $candidate;
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return implode("\\N", array_slice($lines, 0, 3));
}

function escapeAssText(string $text): string
{
    $text = str_replace(['{', '}'], ['\{', '\}'], $text);
    $text = str_replace(["\r\n", "\r", "\n"], ['\N', '\N', '\N'], $text);
    return $text;
}

function formatAssTime(float $seconds): string
{
    $seconds = max(0.0, $seconds);
    $hours = (int)floor($seconds / 3600);
    $minutes = (int)floor(($seconds - ($hours * 3600)) / 60);
    $wholeSeconds = (int)floor($seconds) % 60;
    $centiseconds = (int)floor(($seconds - floor($seconds)) * 100);

    return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $wholeSeconds, $centiseconds);
}

function escapeFfmpegFilterValue(string $value): string
{
    return str_replace(
        ['\\', ':', "'", ','],
        ['\\\\', '\\:', "\\'", '\\,'],
        $value
    );
}

function sanitizeOutputTitle(string $title): string
{
    $safe = strtolower($title);
    $safe = preg_replace('/[^a-z0-9]+/i', '_', $safe) ?? '';
    $safe = trim($safe, '_');

    return $safe !== '' ? substr($safe, 0, 42) : 'tcf_canada';
}

function sanitizeShortsOutputFileName(string $fileName): string
{
    $baseName = pathinfo(basename($fileName), PATHINFO_FILENAME);
    $safe = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $baseName) ?? '';
    $safe = preg_replace('/-+/', '-', $safe) ?? '';
    $safe = trim($safe, '-_');

    if ($safe === '') {
        $safe = 'TCF-Canada';
    }

    if (function_exists('mb_substr')) {
        $safe = mb_substr($safe, 0, 180, 'UTF-8');
    } else {
        $safe = substr($safe, 0, 180);
    }

    return $safe . '.mp4';
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

function ensureShortsDirectory(): void
{
    if (is_dir(SHORTS_DIR)) {
        return;
    }

    if (!mkdir(SHORTS_DIR, 0775, true) && !is_dir(SHORTS_DIR)) {
        respondError('Unable to create shorts directory.', 500);
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

function resolveGeneratedAudioPublicPath(string $publicPath): string
{
    $prefix = AUDIO_PUBLIC_PATH . '/';

    if (!str_starts_with($publicPath, $prefix)) {
        respondError('Invalid generated audio path.', 400);
    }

    $fileName = basename($publicPath);

    if (!preg_match('/^voice_[a-zA-Z0-9_-]+_(?:\d{3}|final)\.mp3$/', $fileName)) {
        respondError('Invalid generated audio file name.', 400);
    }

    $path = realpath(AUDIO_DIR . '/' . $fileName);
    $audioDir = realpath(AUDIO_DIR);

    if ($path === false || $audioDir === false || !str_starts_with($path, $audioDir . DIRECTORY_SEPARATOR)) {
        respondError('Generated audio is not available.', 400);
    }

    return $path;
}

function resolveShortsClipPublicPath(string $publicPath): string
{
    $prefix = SHORTS_PUBLIC_PATH . '/';

    if (!str_starts_with($publicPath, $prefix)) {
        respondError('Invalid shorts clip path.', 400);
    }

    $fileName = basename($publicPath);

    if (!preg_match('/^short_[a-zA-Z0-9_-]+_clip_\d{3}\.mp4$/', $fileName)) {
        respondError('Invalid shorts clip file name.', 400);
    }

    $path = realpath(SHORTS_DIR . '/' . $fileName);
    $shortsDir = realpath(SHORTS_DIR);

    if ($path === false || $shortsDir === false || !str_starts_with($path, $shortsDir . DIRECTORY_SEPARATOR)) {
        respondError('Shorts clip is not available.', 400);
    }

    return $path;
}

function buildShortsPlanPrompt(
    string $topic,
    string $module,
    string $platform,
    int $duration,
    string $angle,
    string $audience,
    string $tone
): string {
    return <<<PROMPT
Tu es un directeur creatif senior specialise en videos virales, marketing de contenu, SEO et conversion pour tcf-canada.net.

MISSION PRIORITAIRE
Creer une video verticale 9:16 ultra engageante pour TikTok, Instagram Reels et YouTube Shorts afin de promouvoir TCF Canada et convertir les spectateurs vers tcf-canada.net.
La priorite absolue est la croissance organique: vues, retention, replays, partages, commentaires, abonnements, clics et conversions.

CONTEXTE STRICT
- Domaine: TCF Canada seulement.
- Modules autorises: comprehension orale, comprehension ecrite, expression orale, expression ecrite, vocabulaire, grammaire, immigration, conseils d'examen.
- Sujet: {$topic}
- Module fourni: {$module}. Si la valeur est "auto", deduis le module TCF le plus pertinent uniquement a partir du sujet.
- Angle fourni: {$angle}. Si la valeur est "auto", choisis automatiquement l'angle marketing le plus puissant pour ce sujet.
- Audience: {$audience}
- Ton: {$tone}
- Plateformes: {$platform}
- Duree cible: {$duration} secondes.

REGLES DE CONTENU NON NEGOCIABLES
- Analyse d'abord le sujet pour identifier le module, la tache, le niveau, le probleme, le risque et le desir principal du spectateur.
- Le sujet est la source de verite: n'exige aucune autre information pour produire la video complete.
- Ne cree jamais de contenu generique, previsible, scolaire, lent ou ennuyeux.
- Choisis un angle qui declenche au moins une emotion forte: curiosite, urgence, motivation, ambition, soulagement ou peur realiste de l'echec.
- Le hook doit interrompre le scroll des la premiere phrase et produire son impact dans les 3 premieres secondes.
- Le hook peut utiliser une erreur couteuse, un risque cache, une promesse concrete, une contradiction, un chiffre, un secret utile ou une question irresistible.
- Apporte une valeur immediate dans les 5 premieres secondes, puis maintiens une progression rapide avec une nouvelle idee, preuve ou tension toutes les 2 a 4 secondes.
- Utilise des phrases courtes, orales, memorables et faciles a comprendre.
- Evite les introductions, salutations, definitions evidentes, remplissage, repetitions et promesses irrealisables.
- Fais sentir ce que le spectateur risque de perdre ou peut gagner, sans manipulation trompeuse.
- Termine toujours par un appel a l'action clair, naturel et direct vers tcf-canada.net.
- Le CTA doit expliquer la prochaine action: visiter tcf-canada.net pour s'entrainer, progresser ou acceder a une preparation complete.

REGLES SEO ET CONVERSION
- Genere un titre court, recherchable et puissant contenant naturellement TCF Canada et le benefice ou probleme principal.
- La description doit resumer la valeur, integrer des mots-cles SEO et inclure tcf-canada.net.
- Genere des hashtags melant mots-cles majeurs, intention de recherche, immigration Canada, apprentissage du francais et module TCF concerne.
- Encourage subtilement les commentaires, sauvegardes ou partages lorsqu'une question naturelle peut etre posee.
- Optimise le script pour convertir sans ressembler a une publicite agressive.

DIRECTION VISUELLE ET MONTAGE
- Prevois un rythme rapide: changement visuel toutes les 2 a 5 secondes.
- Chaque segment doit correspondre exactement a l'idee prononcee et renforcer l'emotion.
- Les visualQuery doivent etre en anglais, uniques, concretes, professionnelles et ideales pour trouver une video stock premium.
- Utilise 2 a 5 mots par visualQuery. Evite les recherches vagues comme "people talking", "business background" ou "french exam study".
- Favorise des images fortes montrant selon le contexte: confident student, exam stress, study success, Canada city, Canadian flag, immigration journey, career success, university campus, focused learning, achievement celebration, professional growth, motivated student.
- Recherche des scenes avec mouvement, expressions humaines visibles, cadrages cinematographiques, lumiere premium, progression et succes.
- Alterne gros plans emotionnels, actions dynamiques et plans de contexte pour soutenir des transitions modernes.
- Les sous-titres doivent etre percutants, tres courts, animes, lisibles et decoupes selon le rythme oral. Mets les mots importants au centre de chaque cue.

SORTIE
Retourne uniquement un objet JSON valide, sans markdown.

SCHEMA EXACT
{
  "topic": "string",
  "module": "string",
  "platforms": ["TikTok", "Instagram Reels", "YouTube Shorts"],
  "duration": number,
  "hook": "string",
  "script": "string",
  "segments": [
    {
      "start": number,
      "end": number,
      "text": "string",
      "visualQuery": "string"
    }
  ],
  "subtitles": [
    {
      "start": number,
      "end": number,
      "text": "string"
    }
  ],
  "seo": {
    "title": "string",
    "description": "string",
    "hashtags": ["#TCFCanada", "#TCFCanadaPreparation"]
  },
  "cta": "string"
}

CONTRAINTES JSON
- 7 a 12 segments maximum pour assurer un rythme visuel rapide.
- 10 a 18 sous-titres courts maximum.
- Les start/end doivent couvrir toute la duree cible.
- Le premier segment et le premier sous-titre doivent porter le hook.
- Chaque segment doit durer idealement 2 a 5 secondes.
- Les sous-titres doivent contenir idealement 2 a 7 mots, etre faciles a lire et synchronises avec le script.
- Le script doit etre lisible tel quel par une voix off Google AI.
- Le script doit tenir dans la duree cible avec un debit dynamique mais intelligible.
- Le CTA final doit mentionner exactement tcf-canada.net.
- Les hashtags doivent inclure des variantes SEO pertinentes: TCF Canada, preparation TCF, immigration Canada, francais Canada, ainsi que le module choisi.
PROMPT;
}

function inferShortsModuleFromTopic(string $topic): string
{
    $value = mb_strtolower($topic, 'UTF-8');
    $rules = [
        'expression écrite' => ['expression écrite', 'expression ecrite', 'écriture', 'ecriture', 'texte argumentatif', 'courriel', 'lettre'],
        'expression orale' => ['expression orale', 'parler', 'oral', 'prononciation', 'examinateur'],
        'compréhension orale' => ['compréhension orale', 'comprehension orale', 'écoute', 'ecoute', 'audio'],
        'compréhension écrite' => ['compréhension écrite', 'comprehension ecrite', 'lecture', 'lire', 'texte à comprendre'],
        'vocabulaire' => ['vocabulaire', 'mots', 'expression française', 'lexique'],
        'grammaire' => ['grammaire', 'conjugaison', 'accord', 'temps verbal'],
        'immigration' => ['immigration', 'nclc', 'crs', 'résidence permanente', 'canada'],
    ];

    foreach ($rules as $module => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($value, $keyword)) {
                return $module;
            }
        }
    }

    return 'conseils d’examen';
}

function normalizeShortsPlan(array $plan, string $fallbackTopic, string $fallbackModule, int $fallbackDuration): array
{
    $script = normalizePromptText((string)($plan['script'] ?? ''));
    $duration = filter_var($plan['duration'] ?? $fallbackDuration, FILTER_VALIDATE_FLOAT);
    $duration = $duration === false ? (float)$fallbackDuration : max(15.0, min(90.0, (float)$duration));

    $segments = normalizeShortsTimedItems($plan['segments'] ?? [], $duration, true);
    $subtitles = normalizeShortsTimedItems($plan['subtitles'] ?? [], $duration, false);

    if (count($segments) === 0 && $script !== '') {
        $segments = buildFallbackTimedItems($script, $duration, true);
    }

    if (count($subtitles) === 0 && $script !== '') {
        $subtitles = buildFallbackTimedItems($script, $duration, false);
    }

    $seo = is_array($plan['seo'] ?? null) ? $plan['seo'] : [];
    $hashtags = $seo['hashtags'] ?? [];
    if (!is_array($hashtags)) {
        $hashtags = [];
    }

    $hashtags = array_values(array_unique(array_filter(array_map(static function (mixed $tag): string {
        $value = trim((string)$tag);
        if ($value === '') {
            return '';
        }

        return str_starts_with($value, '#') ? $value : '#' . preg_replace('/\s+/', '', $value);
    }, $hashtags))));

    foreach (['#TCFCanada', '#PreparationTCF', '#ImmigrationCanada', '#FrancaisCanada'] as $requiredTag) {
        if (!in_array($requiredTag, $hashtags, true)) {
            $hashtags[] = $requiredTag;
        }
    }

    return [
        'topic' => normalizePromptText((string)($plan['topic'] ?? $fallbackTopic)),
        'module' => normalizePromptText((string)($plan['module'] ?? $fallbackModule)),
        'platforms' => ['TikTok', 'Instagram Reels', 'YouTube Shorts'],
        'duration' => $duration,
        'hook' => normalizePromptText((string)($plan['hook'] ?? '')),
        'script' => $script,
        'segments' => $segments,
        'subtitles' => $subtitles,
        'seo' => [
            'title' => normalizePromptText((string)($seo['title'] ?? ('TCF Canada: ' . $fallbackTopic))),
            'description' => normalizePromptText((string)($seo['description'] ?? 'Preparez votre TCF Canada avec une methode claire et pratique sur tcf-canada.net.')),
            'hashtags' => array_slice($hashtags, 0, 16),
        ],
        'cta' => normalizePromptText((string)($plan['cta'] ?? 'Retrouvez la preparation complete sur tcf-canada.net.')),
    ];
}

function normalizeShortsTimedItems(mixed $items, float $duration, bool $withVisualQuery): array
{
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $start = filter_var($item['start'] ?? null, FILTER_VALIDATE_FLOAT);
        $end = filter_var($item['end'] ?? null, FILTER_VALIDATE_FLOAT);
        $text = normalizePromptText((string)($item['text'] ?? ''));

        if ($start === false || $end === false || $text === '') {
            continue;
        }

        $start = max(0.0, min($duration, (float)$start));
        $end = max($start + 0.4, min($duration, (float)$end));
        $entry = [
            'start' => round($start, 2),
            'end' => round($end, 2),
            'text' => $text,
        ];

        if ($withVisualQuery) {
            $query = normalizePromptText((string)($item['visualQuery'] ?? $item['visual_query'] ?? $item['query'] ?? 'confident student success'));
            $entry['visualQuery'] = sanitizeShortsVisualQuery($query);
        }

        $normalized[] = $entry;
    }

    return $normalized;
}

function sanitizeShortsVisualQuery(string $query): string
{
    $cleaned = preg_replace('/[^a-zA-Z0-9\s-]/', ' ', $query) ?? '';
    $words = preg_split('/\s+/', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = array_slice($words, 0, 5);

    if (count($words) === 0) {
        return 'confident student success';
    }

    if (count($words) === 1) {
        $words[] = 'cinematic';
    }

    return strtolower(implode(' ', $words));
}

function buildFallbackTimedItems(string $script, float $duration, bool $withVisualQuery): array
{
    $sentences = preg_split('/(?<=[.!?])\s+/u', $script, -1, PREG_SPLIT_NO_EMPTY) ?: [$script];
    $sentences = array_values(array_filter(array_map('trim', $sentences)));
    $count = max(1, min($withVisualQuery ? 7 : 12, count($sentences)));
    $step = $duration / $count;
    $items = [];

    for ($index = 0; $index < $count; $index += 1) {
        $text = $sentences[$index] ?? $sentences[count($sentences) - 1];
        $entry = [
            'start' => round($index * $step, 2),
            'end' => round(min($duration, ($index + 1) * $step), 2),
            'text' => normalizePromptText($text),
        ];

        if ($withVisualQuery) {
            $entry['visualQuery'] = 'confident student success';
        }

        $items[] = $entry;
    }

    return $items;
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

function buildShortsTtsPrompt(string $text, string $pacing, int $chunkIndex, int $totalChunks): string
{
    $profile = getTtsPacingProfiles()[$pacing];

    return <<<PROMPT
# AUDIO PROFILE: Voix virale TCF Canada pour TikTok, Reels et Shorts

## OBJECTIF
Transformer le transcript en une performance vocale courte, captivante et tres humaine qui arrete le scroll, maintient la retention et donne envie de visiter tcf-canada.net.

### DIRECTION ARTISTIQUE
Hook: prononce la toute premiere phrase avec une intention forte et immediate. Le spectateur doit sentir en moins de 3 secondes qu'il risque de manquer une information importante.
Energie: dynamique, motivee, confiante et chaleureuse. Garde une tension positive du debut a la fin.
Rythme: {$profile['pacing']} Utilise des phrases nettes, des micro-pauses strategiques et des changements subtils d'intensite. Ne ralentis pas entre les idees.
Emotions: fais ressortir naturellement la curiosite, l'urgence, la motivation, l'ambition ou la peur realiste de l'echec selon les mots du transcript.
Valeur: souligne vocalement les erreurs, chiffres, mots-cles, benefices et actions concretes.
CTA: prononce tcf-canada.net clairement, avec assurance et naturel. Le CTA final doit sembler utile et evident, jamais agressif.
Style: conversation directe avec une seule personne. Son moderne, credible, energique et authentique, jamais robotique, monotone, scolaire ou publicitaire.
Accent: francais clair, naturel et facile a comprendre pour un public international qui prepare le TCF Canada.
Continuite: ceci est la partie {$chunkIndex} sur {$totalChunks}. Ne lis jamais le numero de partie. Conserve exactement la meme voix, le meme rythme et la meme energie entre les parties.
Important: lis uniquement le transcript. Ne lis aucune instruction, balise, note de direction ou titre.

### TRANSCRIPT
[high-retention, energetic, confident, warm, conversational]
{$text}
PROMPT;
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
