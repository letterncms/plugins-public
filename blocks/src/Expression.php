<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * Evaluates the simple script expressions people write in the builder
 * (open, !open, tab === 'a', count > 2 && ready) against initial state — so the
 * canvas can show elements as they first appear. Anything more complex is
 * "unknown" (null) and the element is shown.
 */
final class Expression
{
    public static function evaluate(string $expression, array $data): ?bool
    {
        $value = self::value(trim($expression), $data);

        return $value === self::UNKNOWN ? null : (bool) $value;
    }

    private const string UNKNOWN = "\0unknown";

    private static function value(string $e, array $data): mixed
    {
        $e = trim($e);

        if (preg_match('/^\((.*)\)$/s', $e, $m) && self::balanced($m[1])) {
            return self::value($m[1], $data);
        }

        foreach (['||', '&&'] as $op) {
            $parts = self::split($e, $op);

            if (count($parts) > 1) {
                $values = array_map(static fn ($p) => self::value($p, $data), $parts);

                if (in_array(self::UNKNOWN, $values, true)) {
                    return self::UNKNOWN;
                }

                return $op === '||' ? array_reduce($values, static fn ($c, $v) => $c || $v, false) : array_reduce($values, static fn ($c, $v) => $c && $v, true);
            }
        }

        if (preg_match('/^(.+?)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/s', $e, $m)) {
            [$a, $b] = [self::value($m[1], $data), self::value($m[3], $data)];

            if ($a === self::UNKNOWN || $b === self::UNKNOWN) {
                return self::UNKNOWN;
            }

            return match ($m[2]) {
                '===', '==' => $a == $b,
                '!==', '!=' => $a != $b,
                '>=' => $a >= $b,
                '<=' => $a <= $b,
                '>' => $a > $b,
                '<' => $a < $b,
            };
        }

        if (str_starts_with($e, '!')) {
            $v = self::value(substr($e, 1), $data);

            return $v === self::UNKNOWN ? $v : ! $v;
        }

        return match (true) {
            $e === 'true' => true,
            $e === 'false' => false,
            $e === 'null' => null,
            is_numeric($e) => $e + 0,
            (bool) preg_match('/^\'([^\']*)\'$|^"([^"]*)"$/', $e, $q) => ($q[1] ?? '') !== '' ? $q[1] : ($q[2] ?? ''),
            (bool) preg_match('/^[a-zA-Z_$][\w$]*(\.[a-zA-Z_$][\w$]*)*$/', $e) => self::path($e, $data),
            default => self::UNKNOWN,
        };
    }

    private static function path(string $path, array $data): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return self::UNKNOWN;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /** @return list<string> */
    private static function split(string $e, string $op): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';

        for ($i = 0, $n = strlen($e); $i < $n; $i++) {
            $c = $e[$i];

            if ($quote) {
                $quote = $c === $quote ? null : $quote;
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($depth === 0 && substr($e, $i, 2) === $op) {
                $parts[] = $current;
                $current = '';
                $i++;
                continue;
            }

            $current .= $c;
        }

        $parts[] = $current;

        return $parts;
    }

    private static function balanced(string $e): bool
    {
        $depth = 0;

        foreach (str_split($e) as $c) {
            $depth += $c === '(' ? 1 : ($c === ')' ? -1 : 0);

            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }
}
