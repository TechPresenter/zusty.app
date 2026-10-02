<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Rule-string validator.
 *
 *   $data = Validator::validate($request->all(), [
 *       'email'    => 'required|email|max:191|unique:users,email',
 *       'password' => 'required|string|min:8|password',
 *       'amount'   => 'required|numeric|min_value:1000',
 *       'platform' => 'required|in:instagram,youtube,tiktok',
 *   ]);
 *
 * `validate()` throws a ValidationException (rendered as HTTP 422) on
 * failure and returns only the validated keys on success — so a mass
 * assignment can never smuggle an extra column through.
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $messages field.rule => custom message
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = []
    ) {
    }

    /**
     * Validate and return the sanitised subset, or throw.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,string> $rules
     * @param  array<string,string> $messages
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, array $messages = []): array
    {
        $validator = new self($data, $rules, $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Validate without throwing.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,string> $rules
     * @return array{0:bool,1:array<string,list<string>>,2:array<string,mixed>}
     */
    public static function check(array $data, array $rules, array $messages = []): array
    {
        $validator = new self($data, $rules, $messages);
        $passes    = !$validator->fails();

        return [$passes, $validator->errors(), $validator->validated()];
    }

    public function fails(): bool
    {
        $this->errors    = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules    = explode('|', $ruleString);
            $value    = $this->valueOf($field);
            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);

            $isMissing = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($isMissing) {
                if ($required) {
                    $this->addError($field, 'required', $this->label($field) . ' is required.');
                    continue;
                }

                if ($nullable || !$this->hasKey($field)) {
                    // Preserve an explicit null so a caller can clear a column.
                    if ($this->hasKey($field)) {
                        $this->validated[$field] = null;
                    }
                    continue;
                }
            }

            $passedAll = true;

            foreach ($rules as $rule) {
                if ($rule === '' || $rule === 'required' || $rule === 'nullable' || $rule === 'sometimes') {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                if (!$this->applyRule($field, $value, $name, $parameter)) {
                    $passedAll = false;
                    break; // One message per field keeps the API response tidy.
                }
            }

            if ($passedAll) {
                $this->validated[$field] = $this->cast($value, $rules);
            }
        }

        return $this->errors !== [];
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    // -----------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------

    private function applyRule(string $field, mixed $value, string $rule, ?string $parameter): bool
    {
        $label = $this->label($field);

        return match ($rule) {
            'string' => is_string($value)
                ? true
                : $this->addError($field, $rule, $label . ' must be text.'),

            // A JSON float with no fractional part IS a whole number. JSON draws
            // no line between 4 and 4.0, and several clients cannot: Dart's
            // `double` encodes 4 as `4.0`, so every star rating the app sent was
            // rejected as "must be a whole number".
            //
            // 4.5 is still refused — this accepts integral values in any
            // notation, not fractional ones.
            'integer', 'int' => (
                is_int($value)
                || (is_float($value) && floor($value) === $value && is_finite($value))
                || (is_string($value) && preg_match('/^-?\d+(\.0+)?$/', $value) === 1)
            )
                ? true
                : $this->addError($field, $rule, $label . ' must be a whole number.'),

            'numeric' => is_numeric($value)
                ? true
                : $this->addError($field, $rule, $label . ' must be a number.'),

            'boolean', 'bool' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)
                ? true
                : $this->addError($field, $rule, $label . ' must be true or false.'),

            'array' => is_array($value)
                ? true
                : $this->addError($field, $rule, $label . ' must be a list.'),

            'email' => (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 191)
                ? true
                : $this->addError($field, $rule, $label . ' must be a valid email address.'),

            'url' => (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                      && preg_match('#^https?://#i', $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' must be a valid http(s) URL.'),

            // E.164-ish: 8-15 digits, optional leading +.
            'phone' => (is_string($value) && preg_match('/^\+?[1-9]\d{7,14}$/', preg_replace('/[\s\-()]/', '', $value) ?? '') === 1)
                ? true
                : $this->addError($field, $rule, $label . ' must be a valid phone number.'),

            'date' => $this->isValidDate((string) $value)
                ? true
                : $this->addError($field, $rule, $label . ' must be a valid date (YYYY-MM-DD).'),

            'datetime' => $this->isValidDateTime((string) $value)
                ? true
                : $this->addError($field, $rule, $label . ' must be a valid date and time.'),

            'min' => (mb_strlen((string) $value) >= (int) $parameter)
                ? true
                : $this->addError($field, $rule, $label . ' must be at least ' . $parameter . ' characters.'),

            'max' => (mb_strlen((string) $value) <= (int) $parameter)
                ? true
                : $this->addError($field, $rule, $label . ' may not exceed ' . $parameter . ' characters.'),

            'min_value' => (is_numeric($value) && (float) $value >= (float) $parameter)
                ? true
                : $this->addError($field, $rule, $label . ' must be at least ' . $parameter . '.'),

            'max_value' => (is_numeric($value) && (float) $value <= (float) $parameter)
                ? true
                : $this->addError($field, $rule, $label . ' may not be greater than ' . $parameter . '.'),

            'size' => (mb_strlen((string) $value) === (int) $parameter)
                ? true
                : $this->addError($field, $rule, $label . ' must be exactly ' . $parameter . ' characters.'),

            'between' => $this->between($value, (string) $parameter)
                ? true
                : $this->addError($field, $rule, $label . ' must be between ' . str_replace(',', ' and ', (string) $parameter) . '.'),

            'in' => in_array((string) $value, explode(',', (string) $parameter), true)
                ? true
                : $this->addError($field, $rule, $label . ' must be one of: ' . str_replace(',', ', ', (string) $parameter) . '.'),

            'not_in' => !in_array((string) $value, explode(',', (string) $parameter), true)
                ? true
                : $this->addError($field, $rule, $label . ' has an unsupported value.'),

            'in_array' => (is_array($value) && $this->allIn($value, explode(',', (string) $parameter)))
                ? true
                : $this->addError($field, $rule, $label . ' contains an unsupported value.'),

            'regex' => (is_string($value) && @preg_match((string) $parameter, $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' has an invalid format.'),

            'alpha' => (is_string($value) && preg_match('/^[\p{L}\s]+$/u', $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' may only contain letters.'),

            'alpha_num' => (is_string($value) && preg_match('/^[\p{L}\p{N}]+$/u', $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' may only contain letters and numbers.'),

            'alpha_dash' => (is_string($value) && preg_match('/^[\p{L}\p{N}_-]+$/u', $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' may only contain letters, numbers, dashes and underscores.'),

            'slug' => (is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' must be a lowercase slug.'),

            'handle' => (is_string($value) && preg_match('/^[a-z0-9](?:[a-z0-9._]{1,58})[a-z0-9]$/', $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' may only contain lowercase letters, numbers, dots and underscores.'),

            'json' => $this->isJson($value)
                ? true
                : $this->addError($field, $rule, $label . ' must be valid JSON.'),

            'hex_color' => (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1)
                ? true
                : $this->addError($field, $rule, $label . ' must be a hex colour such as #B80049.'),

            'password' => $this->isStrongPassword((string) $value)
                ? true
                : $this->addError(
                    $field,
                    $rule,
                    $label . ' must be at least ' . Config::settingInt('security', 'password_min_length', 8)
                        . ' characters and include an uppercase letter, a lowercase letter and a number.'
                ),

            'confirmed' => ((string) $value === (string) ($this->data[$field . '_confirmation'] ?? null))
                ? true
                : $this->addError($field, $rule, $label . ' confirmation does not match.'),

            'same' => ((string) $value === (string) ($this->data[(string) $parameter] ?? null))
                ? true
                : $this->addError($field, $rule, $label . ' must match ' . $this->label((string) $parameter) . '.'),

            'different' => ((string) $value !== (string) ($this->data[(string) $parameter] ?? null))
                ? true
                : $this->addError($field, $rule, $label . ' must be different from ' . $this->label((string) $parameter) . '.'),

            'after' => $this->compareDates($value, (string) $parameter, '>')
                ? true
                : $this->addError($field, $rule, $label . ' must be after ' . $this->label((string) $parameter) . '.'),

            'after_or_equal' => $this->compareDates($value, (string) $parameter, '>=')
                ? true
                : $this->addError($field, $rule, $label . ' must be on or after ' . $this->label((string) $parameter) . '.'),

            'before' => $this->compareDates($value, (string) $parameter, '<')
                ? true
                : $this->addError($field, $rule, $label . ' must be before ' . $this->label((string) $parameter) . '.'),

            'unique' => $this->isUnique((string) $parameter, $value)
                ? true
                : $this->addError($field, $rule, $label . ' is already taken.'),

            'exists' => $this->recordExists((string) $parameter, $value)
                ? true
                : $this->addError($field, $rule, 'The selected ' . strtolower($label) . ' is invalid.'),

            default => true, // Unknown rules are ignored rather than failing closed.
        };
    }

    // -----------------------------------------------------------------
    // Rule helpers
    // -----------------------------------------------------------------

    /**
     * `unique:table,column[,ignoreValue[,ignoreColumn]]`
     */
    private function isUnique(string $parameter, mixed $value): bool
    {
        $parts        = explode(',', $parameter);
        $table        = $parts[0] ?? '';
        $column       = $parts[1] ?? 'id';
        $ignoreValue  = $parts[2] ?? null;
        $ignoreColumn = $parts[3] ?? 'id';

        if ($table === '') {
            return true;
        }

        $sql      = 'SELECT 1 FROM ' . Database::quoteIdentifier($table)
                  . ' WHERE ' . Database::quoteIdentifier($column) . ' = :value';
        $bindings = ['value' => $value];

        if ($ignoreValue !== null && $ignoreValue !== '' && $ignoreValue !== 'NULL') {
            $sql .= ' AND ' . Database::quoteIdentifier($ignoreColumn) . ' <> :ignore';
            $bindings['ignore'] = $ignoreValue;
        }

        // Respect soft deletes when the table has them.
        if ($this->hasColumn($table, 'deleted_at')) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        return Database::scalar($sql . ' LIMIT 1', $bindings) === null;
    }

    /** `exists:table,column` */
    private function recordExists(string $parameter, mixed $value): bool
    {
        [$table, $column] = array_pad(explode(',', $parameter), 2, 'id');

        if ($table === '') {
            return true;
        }

        $sql = 'SELECT 1 FROM ' . Database::quoteIdentifier($table)
             . ' WHERE ' . Database::quoteIdentifier((string) $column) . ' = :value';

        if ($this->hasColumn($table, 'deleted_at')) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        return Database::scalar($sql . ' LIMIT 1', ['value' => $value]) !== null;
    }

    /** @var array<string,list<string>> */
    private static array $columnCache = [];

    private function hasColumn(string $table, string $column): bool
    {
        if (!isset(self::$columnCache[$table])) {
            try {
                /** @var list<string> $columns */
                $columns = Database::column('SHOW COLUMNS FROM ' . Database::quoteIdentifier($table));
                self::$columnCache[$table] = $columns;
            } catch (\Throwable) {
                self::$columnCache[$table] = [];
            }
        }

        return in_array($column, self::$columnCache[$table], true);
    }

    private function isStrongPassword(string $password): bool
    {
        $min = Config::settingInt('security', 'password_min_length', 8);

        return strlen($password) >= $min
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isValidDateTime(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function compareDates(mixed $value, string $otherField, string $operator): bool
    {
        $other = $this->data[$otherField] ?? $otherField;

        try {
            $a = new \DateTimeImmutable((string) $value);
            $b = new \DateTimeImmutable((string) $other);
        } catch (\Throwable) {
            return false;
        }

        return match ($operator) {
            '>'     => $a > $b,
            '>='    => $a >= $b,
            '<'     => $a < $b,
            '<='    => $a <= $b,
            default => false,
        };
    }

    private function between(mixed $value, string $parameter): bool
    {
        [$min, $max] = array_pad(explode(',', $parameter), 2, null);

        $comparable = is_numeric($value) ? (float) $value : (float) mb_strlen((string) $value);

        return $comparable >= (float) $min && $comparable <= (float) $max;
    }

    /** @param array<mixed> $values @param list<string> $allowed */
    private function allIn(array $values, array $allowed): bool
    {
        foreach ($values as $value) {
            if (!in_array((string) $value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function isJson(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** Supports dot notation for nested payloads: `address.city`. */
    private function valueOf(string $field): mixed
    {
        if (array_key_exists($field, $this->data)) {
            return is_string($this->data[$field]) ? trim($this->data[$field]) : $this->data[$field];
        }

        $cursor = $this->data;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return is_string($cursor) ? trim($cursor) : $cursor;
    }

    private function hasKey(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    /** @param list<string> $rules */
    private function cast(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true) || in_array('int', $rules, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }

        if (in_array('boolean', $rules, true) || in_array('bool', $rules, true)) {
            return in_array($value, [true, 1, '1', 'true'], true);
        }

        if (in_array('email', $rules, true) && is_string($value)) {
            return strtolower(trim($value));
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function addError(string $field, string $rule, string $fallback): bool
    {
        $key                  = $field . '.' . $rule;
        $this->errors[$field][] = $this->messages[$key] ?? $this->messages[$field] ?? $fallback;

        return false;
    }

    private function label(string $field): string
    {
        $segments = explode('.', $field);
        $last     = end($segments) ?: $field;

        return ucfirst(str_replace('_', ' ', $last));
    }
}
