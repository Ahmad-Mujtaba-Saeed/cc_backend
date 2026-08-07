<?php

namespace Modules\Project\Contracts;

/**
 * Common contract for background-music sources so Pixabay and Jamendo are
 * fully interchangeable. Callers depend on this interface and resolve a
 * concrete provider via MusicProviderFactory, which honours the
 * admin-configured `music_provider` setting.
 *
 * Both implementations follow the same shape:
 *   - templates ask for a CATEGORY from the shared vocabulary below;
 *   - the provider searches its own catalogue and maps the category onto
 *     whatever that service actually calls it;
 *   - a track is downloaded ONCE into public storage and the relative path is
 *     returned, so render pipelines keep consuming local files;
 *   - anything unavailable returns null / an empty list, never an exception,
 *     so every caller can fall back to the local library or render silent.
 */
interface MusicProviderInterface
{
    /**
     * The search vocabulary exposed to template settings and the storyboard's
     * music panel. It lives on the CONTRACT, not on either implementation:
     * the categories are the app's own language, and each provider maps them
     * onto its native tags. Projects store one of these strings, so it must
     * stay stable when an admin switches provider — a video set to
     * "documentary" means the same thing on Jamendo as on Pixabay.
     */
    public const CATEGORIES = [
        'horror', 'cinematic', 'emotional', 'sad', 'happy', 'vlog',
        'technology', 'corporate', 'gaming', 'adventure', 'documentary', 'relaxing',
    ];

    /**
     * Default mix level for a music bed — quiet, because the renderer ducks it
     * under narration on top of this. One source of truth for the renderer,
     * the controller and the editor's slider.
     */
    public const DEFAULT_VOLUME = 0.09;

    /** Does this provider have at least one usable credential? */
    public function isConfigured(): bool;

    /**
     * Pick and locally cache a track for a category, returning the public-disk
     * RELATIVE path (or null when unavailable — callers fall back).
     *
     * $seed makes the pick deterministic per project so re-renders keep the
     * same track; $trackId is a user's explicit choice and wins when it still
     * resolves. A $trackId is never treated as a path — it is only ever
     * matched against this provider's own listing.
     */
    public function pickTrack(string $category, int $seed = 0, ?string $trackId = null): ?string;

    /**
     * Auditionable tracks for one category, for the create flow and the
     * storyboard's music panel. `url` is a streamable URL and the ids are the
     * same ones pickTrack() resolves, so what the user hears is what the
     * render can use.
     *
     * @return array{source: string, tracks: array<int, array{id: string, title: string, duration: int, url: string}>}
     */
    public function browseTracks(string $category): array;
}
