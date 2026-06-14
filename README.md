Title: TCF Canada : Évitez ces 3 erreurs en Expression Orale pour Réussir !

Description: Découvrez les 3 erreurs fréquentes en expression orale TCF Canada et nos conseils pratiques pour les éviter. Préparez-vous efficacement à l'examen de français pour l'immigration Canada et augmentez vos chances de succès. Visitez tcf-canada.net pour plus de ressources.

Hashtags: #TCFCanada #TCFCanadaPreparation #ImmigrationCanada #FrancaisCanada #ExpressionOrale #ExamenFrancais #TCFReussite #ApprendreFrancais #PreparationTCF




# PHP Video Tool

This project is intentionally split into independent browser modules:

- `page1.html` converts script text into deterministic 25-word, 10-second JSON segments.
- `page2.html` accepts segment JSON with a manually supplied `query` field and downloads matching Pexels videos through `api.php`.
- `page3.html` converts a full French script into short Gemini TTS chunks, then merges them into one MP3 through `api.php`.
- `shorts-studio.html` creates one complete TCF Canada vertical Short/Reel/TikTok video from a topic.
- `shorts-bulk.html` processes a large queue of TCF Canada topics for bulk SEO content production.

The Reels TikTok section is specialized for TCF Canada content: comprehension orale, comprehension ecrite, expression orale, expression ecrite, vocabulaire, grammaire, immigration and exam advice.

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

3. Make sure FFmpeg is installed and available from the terminal. It is used to convert Gemini PCM audio into MP3, merge chunks, re-encode clips, and render final 9:16 Shorts with animated subtitles.

4. Start the local PHP server from this directory:

   ```sh
   php -S localhost:8000
   ```

5. Open:

   - `http://localhost:8000/page1.html`
   - `http://localhost:8000/page2.html`
   - `http://localhost:8000/page3.html`
   - `http://localhost:8000/shorts-studio.html`
   - `http://localhost:8000/shorts-bulk.html`

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

- Voice: `Aoede`
- Tempo: natural conversation
- Temperature: natural
- Chunk length: 170 words

The page generates one MP3 per chunk in `audio/`, then merges them into `audio/voice_RUNID_final.mp3`.

## TCF Canada Shorts Studio

`shorts-studio.html` generates:

- a TCF Canada script from a topic using Gemini text generation
- Google AI voice using the same Gemini TTS flow as the existing voice page
- vertical Pexels clips re-encoded to 1080x1920
- fast visual pacing with short clip durations and modern cross-fade, slide, dissolve and zoom transitions
- animated ASS subtitles synchronized to the generated voice duration, with distinct hook and CTA styles
- SEO title, description and hashtags for TikTok, Instagram Reels and YouTube Shorts
- final MP4 output in `shorts/`

The Shorts prompts prioritize strong three-second hooks, immediate value, emotional tension, premium stock-video queries, organic growth and a clear conversion CTA to `tcf-canada.net`.

## Bulk Shorts

`shorts-bulk.html` accepts one topic per line and processes them sequentially. It is built for large batches, including hundreds of videos, while keeping one active generation at a time so API calls and FFmpeg rendering stay predictable.

After generation, use the CSV export to collect each topic, final video path, title, description and hashtags.


# Prompt - Reel Automation
Agis comme un expert SEO et marketing viral (pour YouTube Shorts, TikTok et Instagram Reels).
Génère 28 idées de vidéos courtes à fort potentiel viral pour promouvoir tcf-canada.net.

Règles :
* Ne propose jamais de sujets génériques comme "Comment réussir le TCF Canada".
* Privilégie les sujets qui créent la curiosité, l'urgence, la motivation, la surprise ou la peur de l'échec.
* Base-toi sur les problèmes réels des candidats TCF Canada.
* Inspire-toi des sujets qui performent le mieux sur TikTok, Reels et Shorts.
* Les titres doivent donner envie de cliquer immédiatement.
* Chaque sujet doit pouvoir être traité dans une vidéo de 30 à 60 secondes.
* Priorité aux sujets liés à l'immigration, aux scores TCF Canada, aux erreurs fréquentes, aux nouvelles règles, aux refus, aux pièges de l'examen, aux stratégies pour obtenir plus de points et aux histoires de réussite.
* Favorise les sujets qui peuvent générer des commentaires, partages et sauvegardes.
* Évite toute idée ennuyeuse ou trop académique.
* L'objectif principal est d'attirer des visiteurs vers tcf-canada.net et de convertir les candidats en abonnés ou clients.

retourne:
1. Titre viral utilisée (curiosité, urgence, motivation, peur, surprise, etc.).
2. Retourne uniquement les 28 meilleures idées.



1. 






2. add copy each section: Titres, description et hashtags SEO
in both shorts and bulk
