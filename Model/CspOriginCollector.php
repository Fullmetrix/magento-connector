<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Model;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class CspOriginCollector implements PolicyCollectorInterface
{
    private const POLICIES = ['script-src', 'connect-src'];

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Adds the host the script sends to, read only from the settings already in cache.
     *
     * Only a store subdomain is added: fullmetrix.com is already allowed by csp_whitelist.xml.
     * Any failure leaves the policies untouched.
     *
     * @param array $defaultPolicies
     * @return array
     */
    public function collect(array $defaultPolicies = []): array
    {
        try {
            $published = $this->config->getTagSettings()['origin'] ?? null;
            $webUrl = (string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
            $domain = SendOrigin::declaredDomain($webUrl);
            $origin = SendOrigin::accepted($published, $domain);
            if (SendOrigin::DEFAULT_ORIGIN === $origin) {
                return $defaultPolicies;
            }
            foreach (self::POLICIES as $id) {
                $defaultPolicies[] = new FetchPolicy($id, false, [$origin]);
            }
        } catch (\Throwable) {
            return $defaultPolicies;
        }

        return $defaultPolicies;
    }
}
