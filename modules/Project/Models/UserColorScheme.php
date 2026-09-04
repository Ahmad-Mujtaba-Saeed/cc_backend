<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A colour scheme its owner mixed, offered alongside the registry's fourteen.
 *
 * Deliberately shaped exactly like a registry scheme: {@see toTheme()} returns
 * the same seven fields the renderer, the validator and the thumbnail already
 * consume, so a custom palette is not a special case anywhere downstream. The
 * only difference the rest of the app sees is the `custom` flag the storyboard
 * payload adds, which the picker uses to offer a delete.
 */
class UserColorScheme extends Model
{
    /** The colour fields a scheme carries — the registry's shape, exactly. */
    public const COLOR_FIELDS = ['bg_from', 'bg_to', 'accent', 'accent2', 'text', 'muted', 'panel'];

    protected $fillable = [
        'user_id',
        'name',
        'label',
        'bg_from',
        'bg_to',
        'accent',
        'accent2',
        'text',
        'muted',
        'panel',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Modules\User\Models\User::class);
    }

    /**
     * The registry-shaped array. `custom` rides along so the picker can tell
     * a user's own scheme from a built-in one; everything downstream ignores
     * the extra key.
     */
    public function toTheme(): array
    {
        $theme = ['name' => $this->name, 'label' => $this->label];
        foreach (self::COLOR_FIELDS as $field) {
            $theme[$field] = (string) $this->{$field};
        }
        $theme['custom'] = true;

        return $theme;
    }
}
