<?php

/**
 * FhirTaskRestController - REST controller for FHIR Task resource
 *
 * Handles FHIR R4 Task interactions for CanopySpeak teachback workflows.
 * Accepts API key authentication (X-API-Key header) rather than OAuth2.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Mark Lawley <mark@canopyspeak.com>
 * @copyright Copyright (c) 2026 Mark Lawley
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\CanopySpeak\TeachbackService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class FhirTaskRestController
{
    private TeachbackService $teachbackService;
    private SystemLogger $logger;

    public function __construct()
    {
        $this->teachbackService = new TeachbackService();
        $this->logger = new SystemLogger();
    }

    /**
     * Handle PUT /fhir/Task/:id
     *
     * Updates a teachback record based on the incoming FHIR Task resource.
     * The :id parameter corresponds to the CanopySpeak PatientRequest ID.
     *
     * @param string $fhirId The FHIR Task ID (CanopySpeak PatientRequest ID)
     * @param array  $fhirJson The parsed FHIR Task JSON body
     * @return JsonResponse
     */
    public function put(string $fhirId, array $fhirJson): JsonResponse
    {
        if (($fhirJson['resourceType'] ?? '') !== 'Task') {
            return new JsonResponse(
                ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'invalid', 'diagnostics' => 'Expected resourceType Task']]],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Extract the CanopySpeak request ID from the identifier
        $canopySpeakRequestId = $this->extractCanopySpeakRequestId($fhirJson);
        if ($canopySpeakRequestId === null) {
            // Fall back to using the URL id parameter
            $canopySpeakRequestId = (int) $fhirId;
        }

        if ($canopySpeakRequestId <= 0) {
            return new JsonResponse(
                ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'required', 'diagnostics' => 'Could not determine CanopySpeak request ID from Task identifier or URL']]],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Look up the existing teachback record by canopyspeak_request_id
        $record = $this->teachbackService->getTeachbackByCanopySpeakRequestId($canopySpeakRequestId);

        // Fallback: look up by OpenEMR teachback ID from the FHIR identifier
        // This handles records created before canopyspeak_request_id was properly stored
        if (empty($record)) {
            $openEmrTeachbackId = $this->extractOpenEmrTeachbackId($fhirJson);
            if ($openEmrTeachbackId !== null) {
                $record = $this->teachbackService->getTeachbackById($openEmrTeachbackId);
            }
        }

        if (empty($record)) {
            return new JsonResponse(
                ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'not-found', 'diagnostics' => "No teachback record found for CanopySpeak request ID: $canopySpeakRequestId"]]],
                Response::HTTP_NOT_FOUND
            );
        }

        $teachbackId = (int) $record['id'];

        // Extract status from the FHIR Task and map to OpenEMR status
        $fhirStatus = $fhirJson['status'] ?? '';
        $openEmrStatus = $this->mapFhirStatusToOpenEmr($fhirStatus);

        // Extract result summary from Task.output if present
        $resultSummary = $this->extractResultSummary($fhirJson);

        // Extract raw CanopySpeak status from businessStatus.text for display
        $canopySpeakDisplayStatus = $fhirJson['businessStatus']['text'] ?? null;
        if ($canopySpeakDisplayStatus) {
            $resultSummary['canopyspeak_status'] = $canopySpeakDisplayStatus;
        }

        // Build the update fields
        $updates = ['status' => $openEmrStatus];
        if (!empty($resultSummary)) {
            $updates['result_summary'] = json_encode($resultSummary);
        }
        if ($openEmrStatus === 'completed') {
            $updates['completed_at'] = date('Y-m-d H:i:s');
        }
        // Self-heal: set canopyspeak_request_id if it was missing
        if (empty($record['canopyspeak_request_id']) && $canopySpeakRequestId > 0) {
            $updates['canopyspeak_request_id'] = $canopySpeakRequestId;
        }

        // Update the record directly
        sqlStatement(
            "UPDATE `canopyspeak_teachback` SET " .
            implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($updates))) .
            " WHERE `id` = ?",
            [...array_values($updates), $teachbackId]
        );

        // Return the updated record as a FHIR Task
        $updatedRecord = $this->teachbackService->getTeachbackById($teachbackId);

        $this->logger->debug("FHIR Task PUT processed", [
            'canopyspeak_request_id' => $canopySpeakRequestId,
            'fhir_status' => $fhirStatus,
            'openemr_status' => $openEmrStatus,
        ]);

        return new JsonResponse($this->toFhirTask($updatedRecord), Response::HTTP_OK);
    }

    /**
     * Handle GET /fhir/Task/:id
     *
     * Returns a teachback record as a FHIR Task resource.
     * The :id parameter corresponds to the CanopySpeak PatientRequest ID.
     *
     * @param string $fhirId The FHIR Task ID (CanopySpeak PatientRequest ID)
     * @return JsonResponse
     */
    public function getOne(string $fhirId): JsonResponse
    {
        $canopySpeakRequestId = (int) $fhirId;
        if ($canopySpeakRequestId <= 0) {
            return new JsonResponse(
                ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'invalid', 'diagnostics' => 'Invalid Task ID']]],
                Response::HTTP_BAD_REQUEST
            );
        }

        $record = $this->teachbackService->getTeachbackByCanopySpeakRequestId($canopySpeakRequestId);
        if (empty($record)) {
            return new JsonResponse(
                ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'not-found', 'diagnostics' => "No teachback record found for ID: $canopySpeakRequestId"]]],
                Response::HTTP_NOT_FOUND
            );
        }

        return new JsonResponse($this->toFhirTask($record), Response::HTTP_OK);
    }

    /**
     * Convert a canopyspeak_teachback database row to a FHIR R4 Task resource array.
     */
    private function toFhirTask(array $record): array
    {
        $task = [
            'resourceType' => 'Task',
            'id' => (string) ($record['canopyspeak_request_id'] ?? $record['id']),
            'identifier' => [
                [
                    'system' => 'https://canopyspeak.com/patient-request-id',
                    'value' => (string) ($record['canopyspeak_request_id'] ?? ''),
                ],
                [
                    'system' => 'https://openemr.org/teachback-id',
                    'value' => (string) ($record['id'] ?? ''),
                ],
            ],
            'status' => $this->mapOpenEmrStatusToFhir($record['status'] ?? 'pending'),
            'intent' => 'order',
            'code' => [
                'coding' => [
                    [
                        'system' => 'https://canopyspeak.com/task-type',
                        'code' => 'teachback',
                        'display' => 'Patient Teachback Education',
                    ],
                ],
                'text' => 'CanopySpeak Teachback: ' . ($record['topic'] ?: $record['mode'] ?? 'Patient Education'),
            ],
            'description' => 'Teachback education session via CanopySpeak',
            'owner' => [
                'display' => 'CanopySpeak',
            ],
        ];

        // Patient reference
        if (!empty($record['pid'])) {
            $task['for'] = ['reference' => 'Patient/' . $record['pid']];
        }

        // Encounter reference
        if (!empty($record['encounter'])) {
            $task['encounter'] = ['reference' => 'Encounter/' . $record['encounter']];
        }

        // AuthoredOn
        if (!empty($record['created_at'])) {
            $task['authoredOn'] = date('c', strtotime($record['created_at']));
        }

        // LastModified
        $task['lastModified'] = date('c');

        // ExecutionPeriod
        if (!empty($record['created_at'])) {
            $task['executionPeriod'] = [
                'start' => date('c', strtotime($record['created_at'])),
            ];
            if (!empty($record['completed_at'])) {
                $task['executionPeriod']['end'] = date('c', strtotime($record['completed_at']));
            }
        }

        // Output (result summary)
        if (!empty($record['result_summary'])) {
            $summary = json_decode($record['result_summary'], true);
            if (is_array($summary)) {
                $outputs = [];
                foreach ($summary as $key => $value) {
                    $output = [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'https://canopyspeak.com/task-output-type',
                                    'code' => $key,
                                ],
                            ],
                        ],
                    ];
                    if (is_bool($value)) {
                        $output['valueBoolean'] = $value;
                    } else {
                        $output['valueString'] = (string) $value;
                    }
                    $outputs[] = $output;
                }
                if (!empty($outputs)) {
                    $task['output'] = $outputs;
                }
            }
        }

        return $task;
    }

    /**
     * Extract the CanopySpeak PatientRequest ID from FHIR Task identifiers.
     */
    private function extractCanopySpeakRequestId(array $fhirJson): ?int
    {
        $identifiers = $fhirJson['identifier'] ?? [];
        foreach ($identifiers as $identifier) {
            if (($identifier['system'] ?? '') === 'https://canopyspeak.com/patient-request-id') {
                $value = (int) ($identifier['value'] ?? 0);
                return $value > 0 ? $value : null;
            }
        }
        return null;
    }

    /**
     * Extract the OpenEMR teachback record ID from FHIR Task identifiers.
     * This is the externalReferenceId that was sent to CanopySpeak when the teachback was initiated.
     */
    private function extractOpenEmrTeachbackId(array $fhirJson): ?int
    {
        $identifiers = $fhirJson['identifier'] ?? [];
        foreach ($identifiers as $identifier) {
            if (($identifier['system'] ?? '') === 'https://openemr.org/teachback-id') {
                $value = (int) ($identifier['value'] ?? 0);
                return $value > 0 ? $value : null;
            }
        }
        return null;
    }

    /**
     * Extract result summary data from FHIR Task output elements.
     */
    private function extractResultSummary(array $fhirJson): array
    {
        $resultSummary = [];
        $outputs = $fhirJson['output'] ?? [];
        foreach ($outputs as $output) {
            $codings = $output['type']['coding'] ?? [];
            $key = !empty($codings) ? ($codings[0]['code'] ?? 'unknown') : 'unknown';
            if (isset($output['valueBoolean'])) {
                $resultSummary[$key] = $output['valueBoolean'];
            } elseif (isset($output['valueString'])) {
                $resultSummary[$key] = $output['valueString'];
            }
        }
        return $resultSummary;
    }

    /**
     * Map FHIR Task status to OpenEMR canopyspeak_teachback status.
     */
    private function mapFhirStatusToOpenEmr(string $fhirStatus): string
    {
        return match ($fhirStatus) {
            'draft', 'requested' => 'sent',
            'in-progress', 'accepted', 'received', 'ready' => 'in_progress',
            'completed' => 'completed',
            'failed', 'cancelled', 'rejected', 'entered-in-error' => 'failed',
            default => $fhirStatus,
        };
    }

    /**
     * Map OpenEMR canopyspeak_teachback status to FHIR Task status.
     */
    private function mapOpenEmrStatusToFhir(string $oemrStatus): string
    {
        return match ($oemrStatus) {
            'pending' => 'requested',
            'sent' => 'requested',
            'in_progress' => 'in-progress',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'in-progress',
        };
    }
}
