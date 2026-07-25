<?php

namespace Modules\Project\Support;

/**
 * MathRuleLibrary — deterministic recognition of the named rule a chain of
 * working is applying, from the shape of its step expressions alone.
 *
 * The rule panel is where a maths video says WHY a move was legal, and the
 * model fills it only sometimes (v19 made it possible, v21 observed it is
 * model-variant: 3 panels one run, 0 the next). Prompting harder does not
 * bind — so the moves with unmistakable written signatures are recognised
 * here and their panels injected when the model left them empty. Zero LLM.
 *
 * Precision over recall: a wrong rule panel teaches a lie, so every
 * signature is something that essentially cannot appear outside its rule.
 * Expressions arrive post-linearize (+- for ±, sqrt for √, ^ powers).
 */
class MathRuleLibrary
{
    /**
     * The first rule the chain provably applies, or null.
     *
     * @param string[] $exprs The card's step expressions, in order.
     * @return array{name: string, formula: string, why: string}|null
     */
    public static function detect(array $exprs): ?array
    {
        $all = implode("\n", array_map('strval', $exprs));
        if (trim($all) === '') {
            return null;
        }

        foreach (self::rules() as $rule) {
            if (($rule['match'])($all)) {
                return [
                    'name' => $rule['name'],
                    'formula' => $rule['formula'],
                    'why' => $rule['why'],
                ];
            }
        }

        return null;
    }

    /**
     * Ordered by specificity — the quadratic formula also contains a "/ (2"
     * divisor, so it must be recognised before the bare vertex formula.
     *
     * @return array<int, array{name: string, formula: string, why: string, match: callable(string): bool}>
     */
    private static function rules(): array
    {
        return [
            [
                'name' => 'Quadratic Formula',
                'formula' => 'x = (-b +- sqrt(b^2 - 4ac)) / (2a)',
                'why' => 'Solves any ax^2 + bx + c = 0 — the discriminant under the root decides how many real roots exist.',
                // The ± √ shape, or the applied discriminant sqrt(... - 4 * ...).
                'match' => fn (string $s): bool => (str_contains($s, 'sqrt') && (str_contains($s, '+-') || str_contains($s, '-+')))
                    || (bool) preg_match('/sqrt\(\s*[^)]*-\s*4\s*\*/', $s),
            ],
            [
                'name' => 'Zero-Product Property',
                'formula' => 'If A * B = 0, then A = 0 or B = 0',
                'why' => 'A product is zero only when one of its factors is — split the factors and solve each on its own.',
                // Adjacent bracketed factors equal to zero: "(x + 8)(x - 3) = 0".
                'match' => fn (string $s): bool => (bool) preg_match('/\)\s*\(\s*[^)]{1,24}\)\s*=\s*0/', $s),
            ],
            [
                'name' => 'Product Rule of Logarithms',
                'formula' => 'log_b(m) + log_b(n) = log_b(m * n)',
                'why' => 'Adding two logs multiplies their arguments — two logs collapse into one.',
                'match' => fn (string $s): bool => (bool) preg_match('/log[_a-z0-9]*\s*\([^)]*\)\s*\+\s*log/i', $s),
            ],
            [
                'name' => 'Power Rule of Logarithms',
                'formula' => 'log_b(m^k) = k * log_b(m)',
                'why' => 'An exponent inside a log slides out front as a plain multiplier.',
                'match' => fn (string $s): bool => (bool) preg_match('/log[_a-z0-9]*\s*\(\s*[^)]*\^/i', $s),
            ],
            [
                'name' => 'Pythagorean Theorem',
                'formula' => 'a^2 + b^2 = c^2',
                'why' => 'In a right triangle, the squares on the two legs exactly fill the square on the hypotenuse.',
                // Two squared terms summed against an equality, either side —
                // or the applied form sqrt(p^2 + q^2).
                'match' => fn (string $s): bool => (bool) preg_match('/(\^2\s*\+\s*[a-z0-9.() ]{1,14}\^2\s*=)|(=\s*[a-z0-9.() ]{1,14}\^2\s*\+\s*[a-z0-9.() ]{1,14}\^2)|(sqrt\(\s*[^)]*\^2\s*\+\s*[^)]*\^2)/i', $s),
            ],
            [
                'name' => 'Slope Formula',
                'formula' => 'm = (y2 - y1) / (x2 - x1)',
                'why' => 'Slope is rise over run — how much y changes for every step taken in x.',
                'match' => fn (string $s): bool => (bool) preg_match('/\(\s*y_?2\s*-\s*y_?1\s*\)\s*\/\s*\(\s*x_?2\s*-\s*x_?1\s*\)/i', $s)
                    || (bool) preg_match('/Δ\s*y\s*\/\s*Δ\s*x/iu', $s),
            ],
            [
                'name' => 'Vertex of a Parabola',
                'formula' => 't = -b / (2a)',
                'why' => 'A parabola turns exactly halfway between its roots — the peak sits at t = -b / (2a).',
                // "-b / (2a)" in general or applied "-28 / (2 * -4.9)" form —
                // but never when a root is present (that is the full formula).
                'match' => fn (string $s): bool => !str_contains($s, 'sqrt')
                    && ((bool) preg_match('/-\s*b\s*\/\s*\(?\s*2\s*\*?\s*a/i', $s)
                        || (bool) preg_match('/\/\s*\(\s*2\s*\*\s*-?[0-9a-z.]/i', $s)),
            ],
            [
                'name' => 'Distance Formula',
                'formula' => 'd = v * t',
                'why' => 'Distance is a speed held for a time — multiply how fast by how long.',
                'match' => fn (string $s): bool => (bool) preg_match('/\bd\s*=\s*v\s*\*?\s*t\b/i', $s),
            ],
        ];
    }
}
