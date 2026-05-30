# PHP Video Tool

This project is intentionally split into two independent browser modules:

- `page1.html` converts script text into deterministic 25-word, 10-second JSON segments.
- `page2.html` accepts segment JSON with a manually supplied `query` field and downloads matching Pexels videos through `api.php`.
- `page3.html` converts a full French script into short Gemini TTS chunks, then merges them into one MP3 through `api.php`.

## Setup

1. Add your Pexels API key in `api.php`:

   ```php
   const PEXELS_API_KEY = 'YOUR_REAL_KEY';
   ```

2. For voice generation, keep your Gemini API key in the ignored local config file:

   ```php
   // config.local.php
   <?php
   $GEMINI_API_KEY = 'YOUR_REAL_KEY';
   ```

   You can also export it before starting PHP:

   ```sh
   export GEMINI_API_KEY=YOUR_REAL_KEY
   ```

3. Make sure FFmpeg is installed and available from the terminal. It is used to convert Gemini PCM audio into MP3 and merge the chunks.

4. Start the local PHP server from this directory:

   ```sh
   php -S localhost:8000
   ```

5. Open:

   - `http://localhost:8000/page1.html`
   - `http://localhost:8000/page2.html`
   - `http://localhost:8000/page3.html`

## Page 1 Output

`page1.html` produces JSON like:

```json
[
  {
    "start": 0,
    "end": 10,
    "text": "segment text..."
  }
]
```

## Page 2 Input

Before pasting into `page2.html`, add a manual `query` field to each segment:

```json
[
  {
    "start": 0,
    "end": 10,
    "text": "segment text...",
    "query": "city skyline sunrise"
  }
]
```

As soon as valid JSON is detected, the page processes segments sequentially, searches Pexels landscape videos, filters for videos at least 8 seconds long, picks the widest available file, downloads it to `videos/segment_N.mp4`, and shows a local preview.

## Page 3 Output

`page3.html` is built for long French narration. It defaults to:

- Voice: `Sulafat`
- Tempo: slow, warm and natural
- Temperature: natural
- Chunk length: 170 words

The page generates one MP3 per chunk in `audio/`, then merges them into `audio/voice_RUNID_final.mp3`.
