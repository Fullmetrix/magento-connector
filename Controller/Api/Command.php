<?php

declare(strict_types=1);

namespace Fullmetrix\Connector\Controller\Api;

use Fullmetrix\Connector\Model\Config;
use Fullmetrix\Connector\Model\ConnectorCommandHandler;
use Fullmetrix\Connector\Model\EntityPaginator;
use Fullmetrix\Connector\Model\EntitySerializer;
use Fullmetrix\Connector\Model\HmacRequestVerifier;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

class Command extends AbstractApiAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param HmacRequestVerifier $verifier
     * @param EntityPaginator $paginator
     * @param EntitySerializer $serializer
     * @param Config $config
     * @param ConnectorCommandHandler $commandHandler
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        HmacRequestVerifier $verifier,
        EntityPaginator $paginator,
        EntitySerializer $serializer,
        Config $config,
        private readonly ConnectorCommandHandler $commandHandler,
    ) {
        parent::__construct($request, $jsonFactory, $verifier, $paginator, $serializer, $config);
    }

    /**
     * Runs the controller action.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        // The connector needs the raw call here, the Magento wrapper does not cover it.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $body = (string) file_get_contents('php://input');
        if (!$this->verifier->verify($this->request, $body)) {
            return $this->unauthorized();
        }

        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || empty($decoded['action'])) {
            return $this->json(['success' => false, 'error' => 'invalid_payload'], 400);
        }

        $action = (string) $decoded['action'];
        $payload = \is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];

        $result = $this->commandHandler->handle($action, $payload);

        return $this->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Creates the csrf validation exception.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate for csrf.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
