<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class TagSettings
{
    public const MIN_MAX_AGE_DAYS = 1;
    public const MAX_MAX_AGE_DAYS = 390;
    public const ALLOWED_ATTRIBUTES = [
        'type',
        'data-cookieconsent',
        'data-category',
        'data-cookiescript',
        'data-cmp-ab',
    ];

    private const VALUE_PATTERN = '#^[A-Za-z0-9 _./-]{1,64}$#';

    /**
     * Returns the settings used before Fullmetrix ever sent any.
     *
     * @return array
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function defaults(): array
    {
        return ['keeper' => true, 'maxAgeDays' => self::MAX_MAX_AGE_DAYS, 'attributes' => []];
    }

    /**
     * Brings stored or received settings back within their bounds.
     *
     * @param mixed $settings
     * @return array
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function normalize(mixed $settings): array
    {
        $defaults = self::defaults();
        if (!\is_array($settings)) {
            return $defaults;
        }

        return [
            'keeper' => \is_bool($settings['keeper'] ?? null) ? $settings['keeper'] : $defaults['keeper'],
            'maxAgeDays' => self::boundedDays($settings['maxAgeDays'] ?? null),
            'attributes' => self::attributes($settings['attributes'] ?? null),
        ];
    }

    /**
     * Reads the keeper and tag attribute fields of a bundle, null when the bundle carries neither.
     *
     * @param array $bundle
     * @param array $current
     * @return array|null
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function fromBundle(array $bundle, array $current): ?array
    {
        if (!\array_key_exists('keeper', $bundle) && !\array_key_exists('tagAttributes', $bundle)) {
            return null;
        }
        $settings = self::normalize($current);
        if (\array_key_exists('keeper', $bundle)) {
            $keeper = $bundle['keeper'];
            $valid = \is_array($keeper) && \is_bool($keeper['enabled'] ?? null);
            $settings['keeper'] = $valid ? $keeper['enabled'] : true;
            $settings['maxAgeDays'] = $valid
                ? self::boundedDays($keeper['maxAgeDays'] ?? null)
                : self::MAX_MAX_AGE_DAYS;
        }
        if (\array_key_exists('tagAttributes', $bundle)) {
            $settings['attributes'] = self::attributes($bundle['tagAttributes']);
        }

        return $settings;
    }

    /**
     * Keeps only the allowed attribute names with safe values.
     *
     * @param mixed $attributes
     * @return array
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function attributes(mixed $attributes): array
    {
        if (!\is_array($attributes)) {
            return [];
        }
        $kept = [];
        foreach ($attributes as $name => $value) {
            if (\in_array($name, self::ALLOWED_ATTRIBUTES, true)
                && \is_string($value)
                && 1 === preg_match(self::VALUE_PATTERN, $value)
            ) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /**
     * Returns a cookie lifetime in days within 1 and 390, 390 when unreadable.
     *
     * @param mixed $days
     * @return int
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    private static function boundedDays(mixed $days): int
    {
        if (!\is_int($days)) {
            return self::MAX_MAX_AGE_DAYS;
        }

        return max(self::MIN_MAX_AGE_DAYS, min(self::MAX_MAX_AGE_DAYS, $days));
    }
}
