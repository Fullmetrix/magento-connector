<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block;

use Fullmetrix\Connector\Model\ApiClient;
use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\LocalScript;
use Fullmetrix\Connector\Model\SendOrigin;
use Fullmetrix\Connector\Model\TagSettings;
use Fullmetrix\Connector\Plugin\KeeperEndpoint;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Tracker extends Template
{
    public const ELEMENT_ID = 'kvo';
    public const PLATFORM = 'mg';
    public const EXCLUSION_ATTRIBUTES = [
        'data-no-optimize' => '1',
        'data-no-defer' => '1',
        'data-no-minify' => '1',
        'data-cfasync' => 'false',
        'nowprocket' => '1',
        'data-noptimize' => '1',
        'data-ao-skip' => '1',
        'data-pagespeed-no-defer' => '1',
        'data-wpfc-render' => 'false',
    ];

    /**
     * @param Context $context
     * @param Config $config
     * @param ApiClient $apiClient
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Tells whether the tag is rendered, from local settings only.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        try {
            return $this->config->isActive() && $this->apiClient->isTrackerEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns the remote address of the script, built by the module on fullmetrix.com.
     *
     * @return string
     */
    public function getTrackerUrl(): string
    {
        return SendOrigin::fallbackUrl($this->getTagKey());
    }

    /**
     * Returns the opaque key of the tag, the connection code until the bundle sent one.
     *
     * @return string
     */
    public function getTagKey(): string
    {
        try {
            $key = TagSettings::tagKey($this->config->getTagSettings()['tagKey'] ?? null);
        } catch (\Throwable) {
            $key = '';
        }

        return '' !== $key ? $key : $this->getConnectionCode();
    }

    /**
     * Returns the address of the script file served from the store, empty when the remote tag applies.
     *
     * Only the settings already read for this page decide, the file itself is never touched here.
     *
     * @return string
     */
    public function getLocalScriptUrl(): string
    {
        try {
            $state = $this->config->getLocalScript();
            $file = (string) ($state['file'] ?? '');
            if (!empty($state['remote']) || !LocalScript::isScriptPath($file)) {
                return '';
            }
            $mediaUrl = (string) $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            if ('' === $mediaUrl) {
                return '';
            }

            return rtrim($mediaUrl, '/') . '/' . $file;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Returns the origin the script sends to.
     *
     * It is fullmetrix.com unless the published origin is a subdomain of the store.
     *
     * @return string
     */
    public function getSendOrigin(): string
    {
        try {
            $published = $this->config->getTagSettings()['origin'] ?? null;
            $webUrl = (string) $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
            $domain = SendOrigin::declaredDomain($webUrl);
        } catch (\Throwable) {
            return SendOrigin::DEFAULT_ORIGIN;
        }

        return SendOrigin::accepted($published, $domain);
    }

    /**
     * Returns the code that loads the remote script when the local file cannot be loaded.
     *
     * The script element carries no type attribute.
     *
     * A page kept by the full page cache may point at a file that no longer exists.
     *
     * @return string
     */
    public function getRemoteFallbackJs(): string
    {
        $flags = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES;

        $js = 'var s=document.createElement("script");s.async=true;s.src='
            . json_encode($this->getTrackerUrl(), $flags);
        foreach ($this->getTagAttributes() as $name => $value) {
            $js .= ';s.setAttribute(' . json_encode($name, $flags) . ',' . json_encode($value, $flags) . ')';
        }

        return $js . ';document.head.appendChild(s)';
    }

    /**
     * Returns every attribute of the neutral tag.
     *
     * Key, platform, module version, keeper path, sending origin when it is not fullmetrix.com,
     * then the cache plugin exclusions. Nothing comes from the server.
     *
     * @return array
     */
    public function getTagAttributes(): array
    {
        $attributes = [
            'data-kvo' => $this->getTagKey(),
            'data-kvo-p' => self::PLATFORM,
            'data-kvo-v' => Config::VERSION,
        ];
        try {
            $keeper = (bool) ($this->config->getTagSettings()['keeper'] ?? false);
        } catch (\Throwable) {
            $keeper = false;
        }
        if ($keeper) {
            $attributes['data-kvo-k'] = $this->getKeeperPath();
        }
        $origin = $this->getSendOrigin();
        if (SendOrigin::DEFAULT_ORIGIN !== $origin) {
            $attributes['data-kvo-o'] = $origin;
        }

        return $attributes + self::EXCLUSION_ATTRIBUTES;
    }

    /**
     * Returns the tag attributes as escaped markup, one per line, for the template.
     *
     * @param \Magento\Framework\Escaper $escaper
     * @return string
     */
    public function getTagAttributesHtml($escaper): string
    {
        $html = '';
        foreach ($this->getTagAttributes() as $name => $value) {
            $html .= "\n    " . $escaper->escapeHtmlAttr($name)
                . '="' . $escaper->escapeHtmlAttr($value) . '"';
        }

        return $html;
    }

    /**
     * Returns the keeper path below the store base path, read from the store settings already loaded.
     *
     * @return string
     */
    private function getKeeperPath(): string
    {
        try {
            $webUrl = (string) $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $path = (string) (parse_url($webUrl, \PHP_URL_PATH) ?? '');
        } catch (\Throwable) {
            $path = '';
        }
        if (1 !== preg_match('#^[A-Za-z0-9/_.~-]*$#', $path)) {
            $path = '';
        }

        return rtrim($path, '/') . KeeperEndpoint::PATH;
    }

    /**
     * Returns the connection code.
     *
     * @return string
     */
    public function getConnectionCode(): string
    {
        return $this->config->getConnectionCode();
    }
}
