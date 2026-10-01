<?php

namespace Modules\Support\Traits;

trait EnumTranslatable
{
    /**
     * Resolve an enum instance from a value that may already be a backed-enum
     * instance, or a raw string/int backing value.
     *
     * Eloquent's enum casts hydrate model attributes automatically, so code that
     * reads $model->type and then calls EnumClass::from($model->type) passes an
     * enum instance to from(), which only accepts string|int and throws a TypeError.
     * This method handles all three cases transparently.
     *
     * @param static|string|int $value
     * @return static
     */
    public static function coerce(mixed $value): static
    {
        if ($value instanceof static) {
            return $value;
        }

        // A different BackedEnum slipped in — extract its raw backing value first.
        if ($value instanceof \BackedEnum) {
            return static::from($value->value);
        }

        return static::from($value);
    }


    /**
     * Get enum Arrayable as translation
     *
     * @param array $except
     * @return array
     */
    public static function toArrayTrans(array $except = []): array
    {
        $cases = [];

        $hasIcon = method_exists(self::class, "icon");
        $hasColor = method_exists(self::class, "color");

        foreach (self::values() as $value) {
            $caseObject = self::from($value);
            if (in_array($value, $except)) {
                continue;
            }
            $case = [
                "id" => $value,
                "name" => __(static::getTransKey() . ".$value")
            ];

            if ($hasIcon) {
                $case["icon"] = $caseObject->icon();
            }

            if ($hasColor) {
                $case["color"] = $caseObject->color();
            }

            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * Get enum trans key
     *
     * @return string
     */
    abstract public static function getTransKey(): string;

    /**
     * Get enum as array value with trans
     *
     * @return array
     */
    public function toTrans(): array
    {
        return array_filter([
            "id" => $this->value,
            "name" => $this->trans(),
            "icon" => method_exists($this, "icon") ? $this->icon() : null,
            "color" => method_exists($this, "color") ? $this->color() : null,
        ]);
    }

    /**
     * Trans enum value
     *
     * @param string|null $locale
     * @return string
     */
    public function trans(?string $locale = null): string
    {
        return __($this->transKey(), [], $locale);
    }

    /**
     * Trans key for enum value
     *
     * @return string
     */
    public function transKey(): string
    {
        return static::getTransKey() . ".$this->value";
    }

    /**
     * Return all translations for the enum case
     *
     * @return array
     */
    public function allTrans(): array
    {
        return array_reduce(supportedLocaleKeys(), function ($result, $locale) {
            $result[$locale] = $this->trans($locale);

            return $result;
        });
    }
}
