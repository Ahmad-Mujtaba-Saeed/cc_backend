<?php

namespace Modules\Project\Support;

/**
 * MediaBrief — the human-readable half of a media slot.
 *
 * A media slot has always carried `asset_request.description`, but that string
 * has exactly one consumer: it is the AI image PROMPT. It is written to be fed
 * to a diffusion model ("a busy trading floor, flat vector"), not to be read
 * by a person deciding what to go and find.
 *
 * This class adds the two fields the storyboard needs to actually help:
 *
 *   - `search_query`  2-4 plain words that work in a stock search box. Prompt
 *                     language ("a lone figure silhouetted against...") returns
 *                     nothing on Pexels; "person walking city" returns the shot.
 *   - `guidance`      One sentence to the USER: what kind of picture or clip
 *                     this beat wants, and what to avoid.
 *   - `media_kind`    Whether this beat is better served by a still or motion.
 *
 * The planner is asked for all three; everything here is the guarantee that a
 * slot has them even when it is not. Deterministic, no LLM, no network — the
 * validator runs it on every media slot of every scene.
 */
class MediaBrief
{
    /**
     * Words that carry no search signal. Prompt-ese is full of them, and a
     * stock API matches them literally: "a", "the", "showing", "concept".
     */
    private const STOPWORDS = [
        'a', 'an', 'the', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'and', 'or', 'but',
        'from', 'by', 'as', 'into', 'over', 'under', 'that', 'this', 'these', 'those',
        'against', 'through', 'across', 'around', 'behind', 'beside', 'between', 'near',
        'above', 'below', 'inside', 'outside', 'toward', 'towards', 'onto', 'upon',
        'while', 'when', 'where', 'which', 'who', 'what', 'how', 'than', 'then',
        'is', 'are', 'was', 'were', 'be', 'being', 'been', 'it', 'its', 'their', 'his',
        'her', 'our', 'your', 'my', 'one', 'two', 'some', 'each', 'every', 'all', 'no',
        // Prompt filler: describes the picture, not the subject.
        'image', 'picture', 'photo', 'photograph', 'shot', 'scene', 'visual', 'graphic',
        'illustration', 'showing', 'depicting', 'representing', 'symbolising', 'symbolizing',
        'representation', 'concept', 'conceptual', 'abstract', 'style', 'styled', 'flat',
        'vector', 'clean', 'simple', 'minimal', 'minimalist', 'modern', 'background',
        'backdrop', 'closeup', 'close', 'up', 'wide', 'view', 'angle', 'looking', 'seen',
        'clearly', 'very', 'really', 'quite', 'perhaps', 'maybe',
        // Size and quality adjectives. They survive the stopword pass looking
        // like content, and because only the first four words are kept they
        // push the actual subject out of the query: "a lone figure against a
        // vast desert landscape" searched as "lone figure silhouetted vast",
        // which finds nothing, instead of "figure desert landscape sunset".
        'lone', 'vast', 'huge', 'tiny', 'large', 'small', 'big', 'little',
        'great', 'giant', 'massive', 'enormous', 'beautiful', 'stunning',
        'gorgeous', 'striking', 'dramatic', 'single', 'entire', 'whole',
        'various', 'several', 'many', 'much', 'more', 'most', 'less',
    ];

    /**
     * Subjects that read as MOVEMENT. A beat about a thing in motion is
     * better served by four seconds of footage than by a frozen still, and
     * the storyboard should say so before the user hunts for a photo.
     */
    private const MOTION_WORDS = [
        'running', 'walking', 'flowing', 'pouring', 'spinning', 'rotating', 'driving',
        'flying', 'falling', 'rising', 'growing', 'building', 'crowd', 'traffic', 'waves',
        'machine', 'machinery', 'assembly', 'production', 'typing', 'cooking', 'dancing',
        'racing', 'launching', 'exploding', 'burning', 'melting', 'swimming', 'surfing',
        'crashing', 'boiling', 'stirring', 'construction', 'motion', 'movement', 'busy',
        'bustling', 'rushing', 'streaming', 'timelapse', 'time-lapse',
    ];

    /**
     * Subjects a stock library simply does not have. Naming a specific person,
     * a branded product or an invented chart is a request the search box will
     * answer with something wrong and confident — the honest advice is to draw
     * it instead.
     */
    private const UNSTOCKABLE = [
        'logo', 'logos', 'brand', 'branded', 'trademark', 'screenshot', 'dashboard',
        'interface', 'app', 'ui', 'chart', 'graph', 'diagram', 'infographic', 'timeline',
        'equation', 'formula', 'handwriting', 'annotated',
    ];

    /**
     * Normalize (and complete) the media half of a slot.
     *
     * @param  array  $assetRequest  The slot's asset_request, as the model left it
     * @param  string $contentType   'image' | 'video'
     * @param  string $aspectRatio   The project aspect, for the framing advice
     * @return array{description: string, search_query: string, guidance: string, media_kind: string}
     */
    public static function build(array $assetRequest, string $contentType, string $aspectRatio = '16:9'): array
    {
        $description = trim((string) ($assetRequest['description'] ?? ''));

        $query = self::cleanQuery((string) ($assetRequest['search_query'] ?? ''));
        if ($query === '') {
            $query = self::deriveQuery($description);
        }

        $kind = (string) ($assetRequest['media_kind'] ?? '');
        if (!in_array($kind, ['image', 'video', 'either'], true)) {
            $kind = self::deriveKind($description, $contentType);
        }
        // A video slot is a video slot: the planner may not downgrade it to a
        // still through this field.
        if ($contentType === 'video') {
            $kind = 'video';
        }

        $guidance = trim((string) ($assetRequest['guidance'] ?? ''));
        if ($guidance === '') {
            $guidance = self::deriveGuidance($description, $kind, $aspectRatio);
        }

        return [
            'description' => $description,
            'search_query' => $query,
            'guidance' => self::trimToWord($guidance, 320),
            'media_kind' => $kind,
        ];
    }

    /**
     * Tidy a query the model supplied: no punctuation, no prompt filler, at
     * most four words. A model that answers "a wide cinematic shot of a busy
     * newsroom" gets "busy newsroom" — which is what actually searches well.
     */
    public static function cleanQuery(string $raw): string
    {
        $raw = trim(mb_strtolower($raw));
        if ($raw === '') {
            return '';
        }

        return self::deriveQuery($raw);
    }

    /**
     * Pull the searchable subject out of a prompt-shaped description.
     */
    public static function deriveQuery(string $description): string
    {
        $text = mb_strtolower(trim($description));
        if ($text === '') {
            return '';
        }

        // Quoted glyph requests ("the words 'GAME OVER'") never search well
        // and are already stripped from the image prompt for the same reason.
        $text = preg_replace('/["\x{2018}\x{2019}\x{201C}\x{201D}\'][^"\x{2018}\x{2019}\x{201C}\x{201D}\']*["\x{2018}\x{2019}\x{201C}\x{201D}\']/u', ' ', $text) ?? $text;
        // Only the first clause is the subject; what follows is framing.
        $text = preg_split('/[,;:]/u', $text)[0] ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text) ?? $text;

        $words = [];
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $word = trim($word, "- \t");
            if ($word === '' || mb_strlen($word) < 3) {
                continue;
            }
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            if (in_array($word, $words, true)) {
                continue;
            }
            $words[] = $word;
            if (count($words) >= 4) {
                break;
            }
        }

        // Everything was filler — fall back to the raw opening words rather
        // than an empty box, so the user has something to edit.
        if ($words === []) {
            $words = array_slice(preg_split('/\s+/u', trim($text)) ?: [], 0, 3);
        }

        return trim(implode(' ', $words));
    }

    /** Still or motion? */
    private static function deriveKind(string $description, string $contentType): string
    {
        if ($contentType === 'video') {
            return 'video';
        }
        $words = self::words($description);
        foreach (self::MOTION_WORDS as $motion) {
            if (in_array($motion, $words, true)) {
                return 'either';
            }
        }

        return 'image';
    }

    /**
     * The sentence the user reads. It says three things: what to look for,
     * what form suits the beat, and the one framing rule this card imposes.
     */
    private static function deriveGuidance(string $description, string $kind, string $aspectRatio): string
    {
        $subject = trim($description) !== ''
            ? rtrim(trim($description), '.')
            : 'something that shows what this beat is about';

        $form = match ($kind) {
            'video' => 'A short clip (4-8 seconds) of ' . $subject . ' — motion carries this beat better than a still.',
            'either' => 'Either a photo or a 4-8 second clip of ' . $subject . '; this beat has movement in it, so footage will feel more alive.',
            default => 'A single clear photo of ' . $subject . '.',
        };

        $framing = $aspectRatio === '9:16'
            ? ' Portrait or a shot with room to crop tall — the subject must survive a vertical crop.'
            : ' Landscape, with the subject off-centre or with clear space around it.';

        $avoid = ' Avoid on-image text, logos and watermarks';
        if (self::isUnstockable($description)) {
            $avoid = ' A stock library will not have this exactly — "Generate with AI" is usually the better route here';
        }

        return $form . $framing . $avoid . '.';
    }

    /**
     * Clamp on a word boundary. A sentence of advice cut mid-word ("Avoid
     * on-imag") reads as a bug, and this string is shown to the user.
     */
    private static function trimToWord(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, " ,;-") . '.';
    }

    /**
     * The meaning-bearing words of a description, with the filler removed.
     *
     * {@see deriveQuery()} keeps only the first clause and the first four
     * words, because that is what a stock search box wants. Anything COMPARING
     * two descriptions needs all of them: "a red sports car on a coastal road"
     * and "a coastal road with a red sports car" are the same picture, and the
     * four-word query hides that.
     *
     * @return string[] de-duplicated, lower-cased, in no particular order
     */
    public static function significantWords(string $description): array
    {
        $out = [];
        foreach (self::words($description) as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $out[$word] = true;
        }

        return array_keys($out);
    }

    /** Would a stock search plausibly find this at all? */
    public static function isUnstockable(string $description): bool
    {
        $words = self::words($description);
        foreach (self::UNSTOCKABLE as $term) {
            if (in_array($term, $words, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] lower-cased word list */
    private static function words(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text) ?? $text;

        return array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: []));
    }
}
