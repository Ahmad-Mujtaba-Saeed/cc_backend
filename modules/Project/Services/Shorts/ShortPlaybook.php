<?php

namespace Modules\Project\Services\Shorts;

/**
 * How to edit a short for the KIND of video it came from.
 *
 * A podcast clip, a gaming-stream clip and an IRL clip go viral for different
 * reasons, and one generic "make it punchy" prompt edited them all the same.
 * Each playbook tells the director what the payoff of that format is, where
 * the beats belong, what a hook sounds like, and when a context card earns
 * its place. The format comes from the whole-video brief (VideoBriefService);
 * the clip's own scene type overrides it when the footage clearly says
 * otherwise (a webcam over a game is a stream, whatever the title says).
 */
class ShortPlaybook
{
    public static function key(string $sourceFormat, string $sceneType, bool $hasWebcam): string
    {
        if ($hasWebcam || $sceneType === 'gameplay_facecam') {
            return 'stream';
        }
        return match (true) {
            in_array($sourceFormat, ['podcast', 'interview'], true), in_array($sceneType, ['podcast', 'interview'], true) => 'podcast',
            $sourceFormat === 'livestream_gaming', $sceneType === 'gameplay' => 'stream',
            in_array($sourceFormat, ['livestream_irl', 'vlog'], true), $sceneType === 'vlog' => 'irl',
            $sourceFormat === 'reaction', $sceneType === 'reaction' => 'reaction',
            $sourceFormat === 'sports', $sceneType === 'sports' => 'sports',
            in_array($sourceFormat, ['tutorial'], true), $sceneType === 'screen_recording' => 'tutorial',
            in_array($sourceFormat, ['educational', 'keynote', 'news'], true), $sceneType === 'presentation' => 'explainer',
            $sourceFormat === 'comedy' => 'comedy',
            default => 'general',
        };
    }

    public static function text(string $key): string
    {
        return match ($key) {
            'stream' => <<<TXT
PLAYBOOK — LIVESTREAM CLIP. A streamer on webcam playing a game, both on screen.
Stream clips go viral on the STREAMER'S REACTION to the game: the rage, the panic,
the disbelief, the trash talk, the clutch. Edit around him, not the game.
 - beats land on his reactions (the loud moments are usually him)
 - stickers/emojis react to HIS reaction ("HE'S CRASHING OUT", "BRO PANICKED")
 - hook sets up the situation from the viewer's side: "He was NOT ready for this
   boss", "Chat told him not to do it" — never "streamer plays game"
 - a card only adds stakes the viewer cannot see ("third attempt at this boss").
   If the transcript does not tell you the stakes, write NO cards.
TXT,
            'podcast' => <<<TXT
PLAYBOOK — PODCAST / INTERVIEW CLIP. The payoff is ONE idea landing: a hot take,
a surprising fact, a story's punchline, a disagreement, a laugh.
 - the hook is the claim or the question, in the viewer's words: "He thinks
   college is a scam", "The one habit that made him a millionaire"
 - few beats: a punch-in on the key line, maybe one on the other person's
   reaction. No shakes, glitches or meme stickers unless the moment is a joke
 - key_words = the claim's nouns and numbers; captions carry this format
 - a card names who is speaking and why they matter, only if the transcript
   says it ("former NASA engineer"). Never a card that restates the claim.
TXT,
            'irl' => <<<TXT
PLAYBOOK — IRL / VLOG CLIP. The payoff is WHAT HAPPENS: an encounter, a stunt,
a crowd, a situation going wrong. The viewer must understand the setup fast.
 - the hook is the situation: "He tried to order in Japanese", "The whole
   street started chasing him"
 - beats on the turn (when it goes wrong / gets crazy) and on reactions
 - one early card is often worth it if the setup is not obvious from the
   footage ("this taxi has no driver") — only from what is seen or said
TXT,
            'reaction' => <<<TXT
PLAYBOOK — REACTION CLIP. Someone reacting to other content. The payoff is the
reaction to one specific moment.
 - hook names what they are reacting to and teases the reaction
 - beats on the reaction face, not on the content being watched
 - a card can set up what they are watching if it is not visible
TXT,
            'sports' => <<<TXT
PLAYBOOK — SPORTS CLIP. The payoff is the play or the moment around it.
 - hook states the stakes or the result tease ("last second, down by two")
 - beats on the play and the celebration/reaction; slow motion suits the play
 - cards give score/time/stakes only if said or shown
TXT,
            'tutorial' => <<<TXT
PLAYBOOK — TUTORIAL / SCREEN CLIP. The payoff is one useful trick or result.
 - hook promises the result ("Do this before you upload your next video")
 - very few beats; clarity beats energy. Never cover the screen content
 - key_words = the tool names, numbers and the action verbs
TXT,
            'explainer' => <<<TXT
PLAYBOOK — TALK / KEYNOTE / EDUCATIONAL CLIP. The payoff is one insight said well.
 - hook is the insight or the surprising number
 - light beats: a punch-in on the key sentence; no meme stickers
 - a card can name the speaker and context if the transcript/title says it
TXT,
            'comedy' => <<<TXT
PLAYBOOK — COMEDY CLIP. The payoff is the punchline. Do not step on the setup.
 - hook teases without giving the joke away
 - beats ONLY on and right after the punchline (a zoom, a sticker, an emoji)
 - no cards during the setup
TXT,
            default => <<<TXT
PLAYBOOK — GENERAL. Find the one moment this clip is about; build the hook,
beats and key words around it. Fewer, better beats beat many small ones.
TXT,
        };
    }
}
