# PHP Video Tool

This project is intentionally split into two independent browser modules:

- `page1.html` converts script text into deterministic 25-word, 10-second JSON segments.
- `page2.html` accepts segment JSON with a manually supplied `query` field and downloads matching Pexels videos through `api.php`.

## Setup

1. Add your Pexels API key in `api.php`:

   ```php
   const PEXELS_API_KEY = 'YOUR_REAL_KEY';
   ```

2. Start the local PHP server from this directory:

   ```sh
   php -S localhost:8000
   ```

3. Open:

   - `http://localhost:8000/page1.html`
   - `http://localhost:8000/page2.html`

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
