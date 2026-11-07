<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Block;

use Fullmetrix\Connector\Model\ApiClient;
use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\LocalScript;
use Fullmetrix\Connector\Model\TagSettings;
use Fullmetrix\Connector\Model\TrackerScriptSync;
use Fullmetrix\Connector\Plugin\KeeperEndpoint;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Tracker extends Template
{
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
     * Tells whether the tracker tag is rendered, from local settings only.
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
     * Returns the tracker url.
     *
     * @return string
     */
    public function getTrackerUrl(): string
    {
        $cacheBucket = (int) floor(time() / 300);

        return $this->config->getAppOrigin() . '/t.js?ver=' . Config::VERSION . '.' . $cacheBucket;
    }

    /**
     * Returns the address of the tracker file served from the store, empty when the remote tag applies.
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
     * Returns the Fullmetrix origin the local tracker file sends to.
     *
     * @return string
     */
    public function getTrackerOrigin(): string
    {
        try {
            $origin = (string) ($this->config->getLocalScript()['origin'] ?? '');
        } catch (\Throwable) {
            $origin = '';
        }

        return 1 === preg_match(TrackerScriptSync::ORIGIN_PATTERN, $origin) ? $origin : 'https://fullmetrix.com';
    }

    /**
     * Returns the code that loads the remote tag when the local file cannot be loaded.
     *
     * A page kept by the full page cache may point at a file that no longer exists.
     *
     * @return string
     */
    public function getRemoteFallbackJs(): string
    {
        $flags = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES;

        $js = 'var s=document.createElement("script");s.async=true;s.src=' . json_encode($this->getTrackerUrl(), $flags)
            . ';s.setAttribute("data-key",' . json_encode($this->getConnectionCode(), $flags) . ')';
        foreach ($this->getExtraAttributes() as $name => $value) {
            $js .= ';s.setAttribute(' . json_encode($name, $flags) . ',' . json_encode($value, $flags) . ')';
        }

        return $js . ';document.head.appendChild(s)';
    }

    /**
     * Returns the attributes added after data-key: the cookie keeper path, then the attributes Fullmetrix pushed.
     *
     * @return array
     */
    public function getExtraAttributes(): array
    {
        try {
            $settings = $this->config->getTagSettings();
        } catch (\Throwable) {
            return [];
        }
        $attributes = [];
        if ($settings['keeper']) {
            $attributes['data-fm-keeper'] = $this->getKeeperPath();
        }

        return $attributes + TagSettings::attributes($settings['attributes']);
    }

    /**
     * Returns the extra tag attributes as escaped markup, one per line, for the tracker template.
     *
     * @param \Magento\Framework\Escaper $escaper
     * @return string
     */
    public function getExtraAttributesHtml($escaper): string
    {
        $html = '';
        foreach ($this->getExtraAttributes() as $name => $value) {
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
