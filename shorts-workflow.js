const SHORTS_API_URL = 'api.php';
const SHORTS_FALLBACK_QUERY = 'confident student success';
const SHORTS_PREMIUM_FALLBACK_QUERIES = [
  'motivated student studying',
  'exam success celebration',
  'Canada immigration journey',
  'professional career success',
  'focused learning closeup'
];
const SHORTS_MIN_DURATION_SECONDS = 6;
const SHORTS_ALLOWED_VERTICAL_RATIOS = [
  { width: 1080, height: 1920, label: 'Full HD vertical 1080x1920' },
  { width: 720, height: 1280, label: 'HD vertical 720x1280' },
  { width: 540, height: 960, label: 'Vertical 540x960' }
];

const SHORTS_VOICES = [
  { value: 'Aoede', label: 'Aoede - breezy' },
  { value: 'Sulafat', label: 'Sulafat - warm' },
  { value: 'Achird', label: 'Achird - friendly' },
  { value: 'Callirrhoe', label: 'Callirrhoe - easy-going' },
  { value: 'Despina', label: 'Despina - smooth' },
  { value: 'Puck', label: 'Puck - upbeat' }
];

function normalizeShortsText(text) {
  return String(text || '')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/[ \t]+/g, ' ')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

function createShortsFileSlug(text) {
  return normalizeShortsText(text)
    .normalize('NFC')
    .replace(/[^\p{L}\p{N}]+/gu, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '') || 'TCF-Canada';
}

function createShortsOutputFileName(number, topic) {
  const safeNumber = String(number || '').replace(/\D/g, '');
  if (!safeNumber) {
    return '';
  }

  return `${safeNumber}_${createShortsFileSlug(topic)}.mp4`;
}

function parseShortsTopicLines(text, limit = 500) {
  const maxItems = Math.max(1, Math.min(500, Number(limit || 500)));
  const topics = [];

  String(text || '').split('\n').forEach((rawLine) => {
    if (topics.length >= maxItems) {
      return;
    }

    const line = normalizeShortsText(rawLine);
    if (!line) {
      return;
    }

    const numberedMatch = line.match(/^(\d+)\s*:\s*(.+)$/u);
    if (!numberedMatch) {
      return;
    }

    const number = numberedMatch[1];
    const topic = normalizeShortsText(numberedMatch[2]);

    if (!topic) {
      return;
    }

    topics.push({
      number,
      topic,
      outputFileName: createShortsOutputFileName(number, topic)
    });
  });

  return topics;
}

function countShortsWords(text) {
  return normalizeShortsText(text).split(/\s+/).filter(Boolean).length;
}

function createShortsRunId(prefix = 'shorts') {
  const timestamp = new Date().toISOString().replace(/\D/g, '').slice(0, 14);
  const random = Math.random().toString(36).slice(2, 8);
  return `${prefix}_${timestamp}_${random}`;
}

function escapeShortsHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatShortsDuration(seconds) {
  const numericSeconds = Number(seconds);
  if (!Number.isFinite(numericSeconds)) {
    return '';
  }

  const totalSeconds = Math.round(numericSeconds);
  const minutes = Math.floor(totalSeconds / 60);
  const remainingSeconds = String(totalSeconds % 60).padStart(2, '0');
  return `${minutes}:${remainingSeconds}`;
}

function previewShortsText(text, length = 180) {
  const normalized = normalizeShortsText(text);
  return normalized.length > length ? `${normalized.slice(0, length)}...` : normalized;
}

function getShortsSeoText(plan) {
  if (!plan || !plan.seo) {
    return '';
  }

  const hashtags = Array.isArray(plan.seo.hashtags) ? plan.seo.hashtags.join(' ') : '';
  return [
    `Title: ${plan.seo.title || ''}`,
    '',
    `Description: ${plan.seo.description || ''}`,
    '',
    `Hashtags: ${hashtags}`
  ].join('\n').trim();
}

async function copyShortsText(text) {
  const value = String(text || '').trim();
  if (!value) {
    throw new Error('Aucun contenu à copier.');
  }

  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(value);
    return;
  }

  const textarea = document.createElement('textarea');
  textarea.value = value;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.select();
  const copied = document.execCommand('copy');
  textarea.remove();

  if (!copied) {
    throw new Error('Impossible de copier ce contenu.');
  }
}

function populateShortsVoiceSelect(select, selected = 'Aoede') {
  select.innerHTML = SHORTS_VOICES
    .map((voice) => `<option value="${voice.value}"${voice.value === selected ? ' selected' : ''}>${voice.label}</option>`)
    .join('');
}

async function postShortsJson(payload, signal) {
  const response = await fetch(SHORTS_API_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(payload),
    signal
  });

  let body;
  try {
    body = await response.json();
  } catch (error) {
    throw new Error('The server returned an invalid response.');
  }

  if (!response.ok || !body.ok) {
    throw new Error(body.error || 'The server returned an error.');
  }

  return body;
}

async function generateShortsPlan(settings, signal) {
  const response = await postShortsJson({
    action: 'shorts_generate_plan',
    topic: settings.topic,
    platform: settings.platform,
    duration: Number(settings.duration || 35),
    audience: settings.audience,
    tone: settings.tone
  }, signal);

  return response.plan;
}

function splitShortsScript(text, maxWords = 155) {
  const normalized = normalizeShortsText(text);
  if (!normalized) {
    return [];
  }

  const sentences = normalized.match(/[^.!?;:\u2026]+[.!?;:\u2026]+["')\]]*|[^.!?;:\u2026]+$/g) || [normalized];
  const chunks = [];
  let current = [];
  let currentWords = 0;

  function flushCurrent() {
    if (current.length === 0) {
      return;
    }

    chunks.push(current.join(' ').replace(/\s+/g, ' ').trim());
    current = [];
    currentWords = 0;
  }

  sentences.map((value) => value.trim()).filter(Boolean).forEach((sentence) => {
    const sentenceWords = countShortsWords(sentence);

    if (sentenceWords > maxWords) {
      flushCurrent();
      const words = sentence.split(/\s+/).filter(Boolean);
      for (let index = 0; index < words.length; index += maxWords) {
        chunks.push(words.slice(index, index + maxWords).join(' '));
      }
      return;
    }

    if (currentWords > 0 && currentWords + sentenceWords > maxWords) {
      flushCurrent();
    }

    current.push(sentence);
    currentWords += sentenceWords;
  });

  flushCurrent();
  return chunks;
}

async function generateShortsVoice(script, options, signal, onChunk) {
  const chunks = splitShortsScript(script, Number(options.maxWords || 155));
  if (chunks.length === 0) {
    throw new Error('No script text is available for voice generation.');
  }

  const runId = options.runId || createShortsRunId('voice');
  const generatedFiles = [];

  for (let index = 0; index < chunks.length; index += 1) {
    const chunkNumber = index + 1;
    if (onChunk) {
      onChunk({ chunkNumber, totalChunks: chunks.length, text: chunks[index], state: 'active' });
    }

    const result = await postShortsJson({
      action: 'tts_chunk',
      runId,
      chunkIndex: chunkNumber,
      totalChunks: chunks.length,
      text: chunks[index],
      voice: options.voice || 'Aoede',
      pacing: options.pacing || 'dynamic',
      temperature: Number(options.temperature || 0.75),
      purpose: 'tcf_shorts'
    }, signal);

    generatedFiles.push(result.path);
    if (onChunk) {
      onChunk({ chunkNumber, totalChunks: chunks.length, text: chunks[index], state: 'done', path: result.path, duration: result.duration });
    }
  }

  const finalResult = await postShortsJson({
    action: 'tts_merge',
    runId,
    files: generatedFiles
  }, signal);

  return {
    runId,
    chunks,
    files: generatedFiles,
    path: finalResult.path,
    duration: finalResult.duration
  };
}

async function searchShortsPexels(query, signal) {
  const params = new URLSearchParams({ action: 'shorts_search', query });
  const response = await fetch(`${SHORTS_API_URL}?${params.toString()}`, { signal });
  const payload = await response.json();

  if (!response.ok || !payload.ok) {
    throw new Error(payload.error || `Search failed for "${query}".`);
  }

  return Array.isArray(payload.videos) ? payload.videos : [];
}

function getShortsFileScore(file) {
  const width = Number(file.width || 0);
  const height = Number(file.height || 0);
  const ratio = height > 0 ? width / height : 1;
  const verticalRatio = 9 / 16;
  const ratioPenalty = Math.abs(ratio - verticalRatio) * 10000;
  return (height * width) - ratioPenalty;
}

function isUsefulVerticalShortsFile(file) {
  const width = Number(file.width || 0);
  const height = Number(file.height || 0);
  return file.link && width > 0 && height > 0 && height > width;
}

function selectBestShortsFile(videos) {
  const files = videos.flatMap((video) => {
    const duration = Number(video.duration || 0);
    if (duration < SHORTS_MIN_DURATION_SECONDS) {
      return [];
    }

    const videoFiles = Array.isArray(video.video_files) ? video.video_files : [];
    return videoFiles
      .filter(isUsefulVerticalShortsFile)
      .map((file) => {
        const width = Number(file.width || 0);
        const height = Number(file.height || 0);
        const known = SHORTS_ALLOWED_VERTICAL_RATIOS.find((size) => size.width === width && size.height === height);
        return {
          link: file.link,
          width,
          height,
          duration,
          label: known ? known.label : `${width}x${height}`,
          score: getShortsFileScore(file)
        };
      });
  });

  files.sort((a, b) => b.score - a.score || b.height - a.height);
  return files[0] || null;
}

async function findShortsClip(query, signal) {
  const cleanedQuery = normalizeShortsText(query);
  const attempts = [
    cleanedQuery,
    cleanedQuery.split(/\s+/).slice(0, 4).join(' '),
    SHORTS_FALLBACK_QUERY,
    ...SHORTS_PREMIUM_FALLBACK_QUERIES
  ].filter((value, index, list) => value && list.indexOf(value) === index);

  for (const attempt of attempts) {
    const videos = await searchShortsPexels(attempt, signal);
    const file = selectBestShortsFile(videos);

    if (file) {
      return { query: attempt, file };
    }
  }

  return null;
}

async function downloadShortsAsset(runId, segmentIndex, videoUrl, signal) {
  const response = await postShortsJson({
    action: 'shorts_download_asset',
    runId,
    segment: segmentIndex,
    url: videoUrl
  }, signal);

  return response.path;
}

async function downloadShortsClips(runId, segments, signal, onSegment) {
  const clips = [];

  for (let index = 0; index < segments.length; index += 1) {
    const segment = segments[index];
    const segmentNumber = index + 1;
    const query = segment.visualQuery || segment.query || SHORTS_FALLBACK_QUERY;

    if (onSegment) {
      onSegment({ segmentNumber, segment, state: 'active', detail: `Searching: ${query}` });
    }

    const selected = await findShortsClip(query, signal);
    if (!selected) {
      throw new Error(`No vertical clip found for segment ${segmentNumber}.`);
    }

    if (onSegment) {
      onSegment({
        segmentNumber,
        segment,
        state: 'active',
        detail: `Downloading ${selected.file.label} from "${selected.query}"`
      });
    }

    const path = await downloadShortsAsset(runId, segmentNumber, selected.file.link, signal);
    clips.push(path);

    if (onSegment) {
      onSegment({ segmentNumber, segment, state: 'done', detail: `Saved ${path}`, path });
    }
  }

  return clips;
}

async function renderShortsVideo(runId, plan, audioPath, clips, signal, outputFileName = '') {
  const result = await postShortsJson({
    action: 'shorts_render_video',
    runId,
    audioPath,
    clips,
    segments: plan.segments || [],
    subtitles: plan.subtitles || [],
    title: (plan.seo && plan.seo.title) || plan.topic || 'TCF Canada short',
    outputFileName
  }, signal);

  return result;
}

async function createCompleteShortsVideo(settings, callbacks = {}, signal) {
  const runId = settings.runId || createShortsRunId('short');

  callbacks.onStep?.('plan', 'Generating TCF Canada script, subtitles and SEO...');
  const plan = settings.plan || await generateShortsPlan(settings, signal);
  callbacks.onPlan?.(plan);

  callbacks.onStep?.('voice', 'Generating Google AI voice...');
  const voice = await generateShortsVoice(plan.script, {
    runId,
    voice: settings.voice || 'Aoede',
    pacing: settings.pacing || 'dynamic',
    temperature: Number(settings.temperature || 0.75),
    maxWords: Number(settings.maxWords || 155)
  }, signal, callbacks.onVoiceChunk);
  callbacks.onVoice?.(voice);

  callbacks.onStep?.('clips', 'Searching and preparing vertical 9:16 clips...');
  const clips = await downloadShortsClips(runId, plan.segments || [], signal, callbacks.onClip);
  callbacks.onClips?.(clips);

  callbacks.onStep?.('render', 'Rendering the final 9:16 video with animated subtitles...');
  const video = await renderShortsVideo(
    runId,
    plan,
    voice.path,
    clips,
    signal,
    settings.outputFileName || ''
  );
  callbacks.onVideo?.(video);
  callbacks.onStep?.('done', 'Done.');

  return {
    runId,
    plan,
    voice,
    clips,
    video
  };
}
