<?php

/**
 * REST Controller for receiving CanopySpeak Teachback webhook callbacks.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Mark Lawley <mark@canopyspeak.com>
 * @copyright Copyright (c) 2026 Mark Lawley
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\CanopySpeak\TeachbackService;

class CanopySpeakTeachbackRestController
{
    private TeachbackService $teachbackService;
    private SystemLogger $logger;

    public function __construct()
    {
        $this->teachbackService = new TeachbackService();
        $this->logger = new SystemLogger();
    }

    /**
     * Handle a callback from CanopySpeak when a teachback workflow completes.
     *
     * Expected POST body:
     * {
     *   "requestId": 123,
     *   "status": "COMPLETED",
     *   "completedAt": "2026-02-17T10:30:00Z",
     *   "externalReferenceId": 456,
     *   "resultSummary": { ... }
     * }
     *
     * @param array $data The parsed JSON body
     * @return array Response data
     */
    public function handleCallback(array $data): array
    {
        // Validate API key from the request headers
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $configuredApiKey = $GLOBALS['canopyspeak_api_key'] ?? '';

        if (empty($apiKey) || $apiKey !== $configuredApiKey) {
            $this->logger->error("CanopySpeak callback: Invalid API key");
            return [
                'status' => 'error',
                'message' => 'Unauthorized',
            ];
        }

        $canopySpeakRequestId = (int) ($data['requestId'] ?? 0);
        $status = $data['status'] ?? '';
        $resultSummary = $data['resultSummary'] ?? [];

        if ($canopySpeakRequestId <= 0 || empty($status)) {
            $this->logger->error("CanopySpeak callback: Missing required fields", [
                'requestId' => $canopySpeakRequestId,
                'status' => $status,
            ]);
            return [
                'status' => 'error',
                'message' => 'Missing required fields: requestId, status',
            ];
        }

        $success = $this->teachbackService->handleCallback($canopySpeakRequestId, $status, $resultSummary);

        if ($success) {
            $this->logger->debug("CanopySpeak callback processed successfully", [
                'requestId' => $canopySpeakRequestId,
                'status' => $status,
            ]);
            return [
                'status' => 'success',
                'message' => 'Callback processed',
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Failed to process callback - unknown request ID',
        ];
    }
}
