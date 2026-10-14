<?php
declare(strict_types=1);

namespace App\Validation;

use App\Http\ApiException;

/**
 * Rules are pipe-separated strings: 'required|string|max:100', 'in:todo,doing,done', 'date'.
 * Fields absent from the input are skipped unless 'required' is given.
 */
final class Validator
{
    /**
     * @param array<string,mixed>  $input
     * @param array<string,string> $rules
     * @return array<string,mixed> only the validated fields
     */
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $clean = [];
        foreach ($rules as $field => $ruleString) {
            $rulesForField = explode('|', $ruleString);
            $present = array_key_exists($field, $input) && $input[$field] !== null;
            if (!$present) {
                if (in_array('required', $rulesForField, true)) {
                    $errors[$field] = 'This field is required.';
                }
                continue;
            }
            $value = $input[$field];
            foreach ($rulesForField as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'string' => is_string($value) ? null : 'Must be text.',
                    'int' => is_int($value) ? null : 'Must be a whole number.',
                    'min' => is_string($value) && mb_strlen(trim($value)) < (int) $arg ? "Must be at least $arg characters." : null,
                    'max' => is_string($value) && mb_strlen($value) > (int) $arg ? "Must be at most $arg characters." : null,
                    'in' => is_scalar($value) && !is_bool($value) && in_array((string) $value, explode(',', (string) $arg), true) ? null : 'Must be one of: ' . $arg . '.',
                    'date' => is_string($value) && self::isDate($value) ? null : 'Must be a date in YYYY-MM-DD format.',
                    default => null,
                };
                if ($error !== null) {
                    $errors[$field] = $error;
                    continue 2;
                }
            }
            $clean[$field] = is_string($value) ? trim($value) : $value;
        }
        if ($errors) {
            throw new ApiException(422, 'Validation failed.', $errors);
        }
        return $clean;
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
