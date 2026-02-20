<?php

/**
 * ApiKeyAuthorizationStrategy - Authorizes requests using X-API-Key header
 *
 * Handles authentication for external system integrations (e.g., CanopySpeak)
 * that use API key authentication instead of OAuth2 Bearer tokens.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Mark Lawley <mark@canopyspeak.com>
 * @copyright Copyright (c) 2026 Mark Lawley
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\Authorization;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\SystemLoggerAwareTrait;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class ApiKeyAuthorizationStrategy implements IAuthorizationStrategy
{
    use SystemLoggerAwareTrait;

    /** @var string[] Route prefixes that accept API key authentication */
    private array $apiKeyRoutes = [];

    public function addApiKeyRoute(string $routePrefix): void
    {
        $this->apiKeyRoutes[] = $routePrefix;
    }

    public function shouldProcessRequest(HttpRestRequest $request): bool
    {
        // Only process requests that have an X-API-Key header
        $apiKey = $request->headers->get('X-API-Key');
        if (empty($apiKey)) {
            return false;
        }

        // Check if the request path matches a registered API key route prefix
        $pathInfo = $request->getPathInfo();
        $sitePath = "/" . $request->getRequestSite();
        if (str_starts_with($pathInfo, $sitePath)) {
            $pathInfo = substr($pathInfo, strlen($sitePath));
        }

        foreach ($this->apiKeyRoutes as $routePrefix) {
            if (str_starts_with($pathInfo, $routePrefix)) {
                return true;
            }
        }

        return false;
    }

    public function authorizeRequest(HttpRestRequest $request): bool
    {
        $apiKey = $request->headers->get('X-API-Key');
        $encryptedConfiguredKey = $GLOBALS['canopyspeak_api_key'] ?? '';

        if (empty($encryptedConfiguredKey)) {
            $this->getSystemLogger()->error("ApiKeyAuthorizationStrategy: No API key configured in globals");
            throw new UnauthorizedHttpException("X-API-Key", "API key authentication is not configured.");
        }

        $configuredKey = (new CryptoGen())->decryptStandard($encryptedConfiguredKey);

        if ($apiKey !== $configuredKey) {
            $this->getSystemLogger()->error("ApiKeyAuthorizationStrategy: Invalid API key provided");
            throw new UnauthorizedHttpException("X-API-Key", "Invalid API key.");
        }

        // Mark the request as authorized, bypassing further security checks
        $request->attributes->set('skipAuthorization', true);
        $request->setRequestUserRole('users');

        $this->getSystemLogger()->debug("ApiKeyAuthorizationStrategy: Request authorized via API key", [
            'path' => $request->getPathInfo(),
        ]);

        return true;
    }
}
