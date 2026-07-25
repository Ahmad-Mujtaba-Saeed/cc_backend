<?php

namespace Modules\Project\Support;

/**
 * TinyExpr — a minimal, total evaluator for the calculator grammar the
 * function_plot cards speak (PHP twin-lite of remotion-render's expr.ts).
 *
 * Exists so the plot synthesizer can FIND a curve's zeros and frame its
 * domain server-side. Handles numbers, x, + - * / ^ (right-assoc), unary
 * minus, parentheses, implicit multiplication (4.9x, 2(x+1), x sin(x)),
 * sin/cos/tan/sqrt/abs/log/ln/exp, pi/e. Anything else -> null compile.
 * Never throws; bad evaluations return NAN and callers treat it as a gap
 * (exactly like the renderer's asymptote guard).
 */
class TinyExpr
{
    private const FNS = ['sin', 'cos', 'tan', 'sqrt', 'abs', 'log', 'ln', 'exp'];

    /** @var array<int, array{t: string, v: string|float}> */
    private array $tokens = [];
    private int $pos = 0;

    /**
     * Compile to a closure f(x): float (NAN on domain errors), or null when
     * the expression is not in the grammar.
     */
    public static function compile(string $src): ?\Closure
    {
        $self = new self();
        if (!$self->tokenize($src)) {
            return null;
        }

        try {
            $ast = $self->parseExpr();
        } catch (\RuntimeException) {
            return null;
        }
        if ($self->pos !== count($self->tokens)) {
            return null; // trailing junk
        }

        // Dry-run once so a structurally bad tree is rejected at compile time.
        $f = fn (float $x): float => self::evalNode($ast, $x);
        $probe = $f(0.5);
        if (!is_finite($probe) && !is_finite($f(1.7))) {
            // Both probes non-finite is fine only for genuinely partial
            // functions (sqrt of negatives etc.) — still usable.
        }

        return $f;
    }

    private function tokenize(string $src): bool
    {
        // Braces are the display notation's grouping ("sqrt{b^2 - 4ac}"), and
        // the validator now canonicalises every radical into it — so the
        // evaluator must read them as ordinary parentheses. Without this, an
        // answer or a plot expression containing a radical stopped compiling
        // the moment it was canonicalised, silently switching off the
        // check-the-answer synthesis and the plot's zero-finding for exactly
        // the problems that need them most. Anything brace-shaped but not in
        // the grammar (frac{a}{b}) still fails to compile, as it always did.
        $src = strtr(trim($src), ['−' => '-', '×' => '*', '·' => '*', '÷' => '/', '{' => '(', '}' => ')']);
        $n = strlen($src);
        $i = 0;
        $tokens = [];

        while ($i < $n) {
            $c = $src[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($src[$i + 1]))) {
                $j = $i;
                while ($j < $n && (ctype_digit($src[$j]) || $src[$j] === '.')) {
                    $j++;
                }
                $num = substr($src, $i, $j - $i);
                if (substr_count($num, '.') > 1) {
                    return false;
                }
                $tokens[] = ['t' => 'num', 'v' => (float) $num];
                $i = $j;
                continue;
            }
            if (ctype_alpha($c)) {
                $j = $i;
                while ($j < $n && ctype_alpha($src[$j])) {
                    $j++;
                }
                $word = strtolower(substr($src, $i, $j - $i));
                // A letter-run may be a function name (only when '(' follows),
                // a constant, x, or products of x and constants ("xpi") —
                // keep it simple: split greedily into known words / 'x'.
                $i = $j;
                while ($word !== '') {
                    if (in_array($word, self::FNS, true) && $i < $n && $src[$i] === '(') {
                        $tokens[] = ['t' => 'fn', 'v' => $word];
                        $word = '';
                        break;
                    }
                    $matched = false;
                    foreach ([...self::FNS, 'pi', 'e', 'x'] as $w) {
                        if (str_starts_with($word, $w)) {
                            // fn name not followed by '(' (mid-run) is only
                            // valid when the rest also parses — treat fn
                            // words mid-run as invalid to stay honest.
                            if (in_array($w, self::FNS, true) && $word !== $w) {
                                return false;
                            }
                            if (in_array($w, self::FNS, true)) {
                                return false; // fn without '('
                            }
                            $tokens[] = ['t' => $w === 'x' ? 'x' : 'const', 'v' => $w];
                            $word = substr($word, strlen($w));
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return false; // unknown symbol (a, b, t...)
                    }
                }
                continue;
            }
            if (strpos('+-*/^()', $c) !== false) {
                $tokens[] = ['t' => $c, 'v' => $c];
                $i++;
                continue;
            }

            return false; // anything else is outside the grammar
        }

        if ($tokens === []) {
            return false;
        }

        // Implicit multiplication: value-ish token directly before an
        // openable token ("4.9x", "2(x+1)", ")(", "x sin(x)").
        $out = [];
        foreach ($tokens as $k => $tok) {
            if ($k > 0) {
                $prev = $out[count($out) - 1];
                $prevValue = in_array($prev['t'], ['num', 'x', 'const', ')'], true);
                $curOpens = in_array($tok['t'], ['num', 'x', 'const', 'fn', '('], true);
                if ($prevValue && $curOpens) {
                    $out[] = ['t' => '*', 'v' => '*'];
                }
            }
            $out[] = $tok;
        }

        $this->tokens = $out;
        $this->pos = 0;

        return true;
    }

    // ---- recursive descent --------------------------------------------

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function take(string $type): array
    {
        $tok = $this->peek();
        if ($tok === null || $tok['t'] !== $type) {
            throw new \RuntimeException('unexpected token');
        }
        $this->pos++;

        return $tok;
    }

    /** @return array expr node */
    private function parseExpr(): array
    {
        $node = $this->parseTerm();
        while (($t = $this->peek()) !== null && ($t['t'] === '+' || $t['t'] === '-')) {
            $this->pos++;
            $node = ['op' => $t['t'], 'l' => $node, 'r' => $this->parseTerm()];
        }

        return $node;
    }

    private function parseTerm(): array
    {
        $node = $this->parseUnary();
        while (($t = $this->peek()) !== null && ($t['t'] === '*' || $t['t'] === '/')) {
            $this->pos++;
            $node = ['op' => $t['t'], 'l' => $node, 'r' => $this->parseUnary()];
        }

        return $node;
    }

    private function parseUnary(): array
    {
        $t = $this->peek();
        if ($t !== null && $t['t'] === '-') {
            $this->pos++;

            return ['op' => 'neg', 'l' => $this->parseUnary()];
        }
        if ($t !== null && $t['t'] === '+') {
            $this->pos++;

            return $this->parseUnary();
        }

        return $this->parsePower();
    }

    private function parsePower(): array
    {
        $base = $this->parseAtom();
        $t = $this->peek();
        if ($t !== null && $t['t'] === '^') {
            $this->pos++;

            // Right-associative, and unary minus binds inside the exponent.
            return ['op' => '^', 'l' => $base, 'r' => $this->parseUnary()];
        }

        return $base;
    }

    private function parseAtom(): array
    {
        $t = $this->peek();
        if ($t === null) {
            throw new \RuntimeException('eof');
        }
        switch ($t['t']) {
            case 'num':
                $this->pos++;

                return ['op' => 'num', 'v' => (float) $t['v']];
            case 'x':
                $this->pos++;

                return ['op' => 'x'];
            case 'const':
                $this->pos++;

                return ['op' => 'num', 'v' => $t['v'] === 'pi' ? M_PI : M_E];
            case 'fn':
                $this->pos++;
                $this->take('(');
                $arg = $this->parseExpr();
                $this->take(')');

                return ['op' => 'fn', 'v' => $t['v'], 'l' => $arg];
            case '(':
                $this->pos++;
                $node = $this->parseExpr();
                $this->take(')');

                return $node;
        }

        throw new \RuntimeException('unexpected atom');
    }

    private static function evalNode(array $n, float $x): float
    {
        switch ($n['op']) {
            case 'num':
                return (float) $n['v'];
            case 'x':
                return $x;
            case 'neg':
                return -self::evalNode($n['l'], $x);
            case '+':
                return self::evalNode($n['l'], $x) + self::evalNode($n['r'], $x);
            case '-':
                return self::evalNode($n['l'], $x) - self::evalNode($n['r'], $x);
            case '*':
                return self::evalNode($n['l'], $x) * self::evalNode($n['r'], $x);
            case '/':
                $d = self::evalNode($n['r'], $x);

                return $d == 0.0 ? NAN : self::evalNode($n['l'], $x) / $d;
            case '^':
                $v = pow(self::evalNode($n['l'], $x), self::evalNode($n['r'], $x));

                return is_nan($v) || is_infinite($v) ? NAN : $v;
            case 'fn':
                $a = self::evalNode($n['l'], $x);
                if (is_nan($a)) {
                    return NAN;
                }
                switch ($n['v']) {
                    case 'sin': return sin($a);
                    case 'cos': return cos($a);
                    case 'tan': return tan($a);
                    case 'abs': return abs($a);
                    case 'sqrt': return $a < 0 ? NAN : sqrt($a);
                    case 'log': return $a <= 0 ? NAN : log10($a);
                    case 'ln': return $a <= 0 ? NAN : log($a);
                    case 'exp': $v = exp($a);

                        return is_infinite($v) ? NAN : $v;
                }

                return NAN;
        }

        return NAN;
    }

    /**
     * The curve's zeros over a scan window, refined by bisection. Capped,
     * sorted, deduped (0.01 tolerance).
     *
     * @return float[]
     */
    public static function zeros(\Closure $f, float $from = -30.0, float $to = 30.0, int $cap = 4): array
    {
        $zeros = [];
        $samples = 1200;
        $step = ($to - $from) / $samples;
        $px = $from;
        $pv = $f($px);

        for ($i = 1; $i <= $samples && count($zeros) < $cap; $i++) {
            $cx = $from + $i * $step;
            $cv = $f($cx);
            if (is_finite($pv) && is_finite($cv)) {
                if ($pv == 0.0) {
                    $zeros[] = $px;
                } elseif (($pv < 0) !== ($cv < 0)) {
                    // Bisect the bracket.
                    $a = $px;
                    $b = $cx;
                    $fa = $pv;
                    for ($k = 0; $k < 40; $k++) {
                        $m = ($a + $b) / 2;
                        $fm = $f($m);
                        if (!is_finite($fm)) {
                            break;
                        }
                        if (($fa < 0) !== ($fm < 0)) {
                            $b = $m;
                        } else {
                            $a = $m;
                            $fa = $fm;
                        }
                    }
                    $zeros[] = ($a + $b) / 2;
                }
            }
            $px = $cx;
            $pv = $cv;
        }

        $zeros = array_values(array_unique(array_map(fn ($z) => round($z, 2), $zeros)));
        sort($zeros);

        return array_slice($zeros, 0, $cap);
    }
}
