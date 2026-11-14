<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

class TagSettings
{
    public const MIN_MAX_AGE_DAYS = 1;
    public const MAX_MAX_AGE_DAYS = 390;
    public const TAG_KEY_PATTERN = '/^[A-Z2-7]{20}$/';

    private const ORIGIN_SHAPE = '#^https://[a-z0-9.-]{1,253}$#';

    /**
     * Returns the settings used before Fullmetrix ever sent any.
     *
     * @return array
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function defaults(): array
    {
        return [
            'keeper' => true,
            'maxAgeDays' => self::MAX_MAX_AGE_DAYS,
            'tagKey' => '',
            'origin' => SendOrigin::DEFAULT_ORIGIN,
        ];
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
            'tagKey' => self::tagKey($settings['tagKey'] ?? null),
            'origin' => self::origin($settings['origin'] ?? null),
        ];
    }

    /**
     * Reads the keeper, opaque key and sending origin of a bundle, null when the bundle carries none of them.
     *
     * A tagAttributes field sent by an older server is ignored.
     *
     * @param array $bundle
     * @param array $current
     * @return array|null
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function fromBundle(array $bundle, array $current): ?array
    {
        $carried = array_intersect_key($bundle, ['keeper' => 1, 'tagKey' => 1, 'origin' => 1]);
        if ([] === $carried) {
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
        if (\array_key_exists('tagKey', $bundle)) {
            $settings['tagKey'] = self::tagKey($bundle['tagKey']);
        }
        if (\array_key_exists('origin', $bundle)) {
            $settings['origin'] = self::origin($bundle['origin']);
        }

        return $settings;
    }

    /**
     * Returns the opaque key when it has the expected shape, an empty string otherwise.
     *
     * @param mixed $value
     * @return string
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function tagKey(mixed $value): string
    {
        return \is_string($value) && 1 === preg_match(self::TAG_KEY_PATTERN, $value) ? $value : '';
    }

    /**
     * Returns the sending origin as published, fullmetrix.com when it does not even look like an https origin.
     *
     * @param mixed $value
     * @return string
     */
    // Pure helper, nothing to intercept.
    // phpcs:ignore Magento2.Functions.StaticFunction
    private static function origin(mixed $value): string
    {
        return \is_string($value) && 1 === preg_match(self::ORIGIN_SHAPE, $value) ? $value : SendOrigin::DEFAULT_ORIGIN;
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
