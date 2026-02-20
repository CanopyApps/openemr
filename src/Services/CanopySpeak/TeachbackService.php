<?php

/**
 * TeachbackService - Integration with CanopySpeak for patient Teachback workflows
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Mark Lawley <mark@canopyspeak.com>
 * @copyright Copyright (c) 2026 Mark Lawley
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\CanopySpeak;

use OpenEMR\Common\Http\oeHttp;
use OpenEMR\Common\Logging\SystemLogger;

class TeachbackService
{
    private SystemLogger $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Check if the CanopySpeak integration is enabled and configured.
     */
    public function isEnabled(): bool
    {
        return !empty($GLOBALS['canopyspeak_enable'])
            && !empty($GLOBALS['canopyspeak_api_url'])
            && !empty($GLOBALS['canopyspeak_api_key']);
    }

    /**
     * Initiate a Teachback workflow by calling the CanopySpeak API.
     *
     * @param int    $pid            Patient ID
     * @param int    $encounter      Encounter ID
     * @param int    $clinicalNoteId Clinical note record ID
     * @param string $mode           One of: diagnosis_codes, ai_analysis, manual, all
     * @param string $clinicalNoteText The text of the clinical note
     * @param array  $diagnosisCodes  Array of ICD-10 diagnosis codes
     * @param string $patientLanguage Patient's preferred language
     * @param string $topic          Manual topic selection (if mode is manual)
     * @param string $initiatedBy    Username of the provider who triggered the teachback
     * @return array{success: bool, teachback_id: int|null, canopyspeak_request_id: int|null, error: string|null}
     */
    public function initiateTeachback(
        int $pid,
        int $encounter,
        int $clinicalNoteId,
        string $mode,
        string $clinicalNoteText,
        array $diagnosisCodes,
        string $patientLanguage,
        string $topic,
        string $initiatedBy
    ): array {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'teachback_id' => null,
                'canopyspeak_request_id' => null,
                'error' => 'CanopySpeak integration is not enabled or configured.'
            ];
        }

        $apiUrl = rtrim($GLOBALS['canopyspeak_api_url'], '/');
        $apiKey = $this->decryptApiKey($GLOBALS['canopyspeak_api_key']);

        // Build the callback URL for CanopySpeak to POST results back
        $callbackUrl = $this->buildCallbackUrl();

        // Insert a local record to track the teachback request
        $teachbackId = $this->createTeachbackRecord(
            $pid,
            $encounter,
            $clinicalNoteId,
            $mode,
            $diagnosisCodes,
            $topic,
            $initiatedBy
        );

        // Build the request payload
        $payload = [
            'patientId' => $pid,
            'encounterDate' => date('Y-m-d'),
            'clinicalNoteText' => $clinicalNoteText,
            'diagnosisCodes' => $diagnosisCodes,
            'patientLanguage' => $patientLanguage,
            'mode' => $mode,
            'topicSelection' => $topic,
            'callbackUrl' => $callbackUrl,
            'externalReferenceId' => $teachbackId,
        ];

        try {
            $response = oeHttp::usingHeaders([
                'X-API-Key' => $apiKey,
            ])->asJson()->post(
                $apiUrl . '/api/emr/teachback',
                $payload
            );

            $statusCode = $response->status();
            $body = $response->body();
            $responseData = json_decode($body, true);

            if ($statusCode >= 200 && $statusCode < 300 && !empty($responseData['requestId'])) {
                $canopySpeakRequestId = (int) $responseData['requestId'];

                $this->updateTeachbackRecord($teachbackId, [
                    'canopyspeak_request_id' => $canopySpeakRequestId,
                    'status' => 'sent',
                ]);

                $this->logger->debug("Teachback initiated successfully", [
                    'teachback_id' => $teachbackId,
                    'canopyspeak_request_id' => $canopySpeakRequestId,
                    'pid' => $pid,
                    'encounter' => $encounter,
                ]);

                return [
                    'success' => true,
                    'teachback_id' => $teachbackId,
                    'canopyspeak_request_id' => $canopySpeakRequestId,
                    'error' => null,
                ];
            }

            $errorMsg = $responseData['message'] ?? "HTTP $statusCode from CanopySpeak";
            $this->updateTeachbackRecord($teachbackId, ['status' => 'failed']);
            $this->logger->error("Teachback initiation failed", [
                'status_code' => $statusCode,
                'response' => $body,
                'pid' => $pid,
            ]);

            return [
                'success' => false,
                'teachback_id' => $teachbackId,
                'canopyspeak_request_id' => null,
                'error' => $errorMsg,
            ];
        } catch (\Exception $e) {
            $this->updateTeachbackRecord($teachbackId, ['status' => 'failed']);
            $this->logger->error("Teachback initiation exception: " . $e->getMessage(), [
                'pid' => $pid,
                'encounter' => $encounter,
            ]);

            return [
                'success' => false,
                'teachback_id' => $teachbackId,
                'canopyspeak_request_id' => null,
                'error' => 'Failed to connect to CanopySpeak: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle a callback from CanopySpeak when a teachback workflow completes.
     *
     * @param int    $canopySpeakRequestId The CanopySpeak PatientRequest ID
     * @param string $status               Completion status
     * @param array  $resultSummary        Workflow result data
     * @return bool
     */
    public function handleCallback(int $canopySpeakRequestId, string $status, array $resultSummary): bool
    {
        $record = sqlQuery(
            "SELECT `id` FROM `canopyspeak_teachback` WHERE `canopyspeak_request_id` = ?",
            [$canopySpeakRequestId]
        );

        if (empty($record)) {
            $this->logger->error("Teachback callback received for unknown request", [
                'canopyspeak_request_id' => $canopySpeakRequestId,
            ]);
            return false;
        }

        $mappedStatus = match (strtolower($status)) {
            'completed' => 'completed',
            'in_progress' => 'in_progress',
            'failed' => 'failed',
            default => $status,
        };

        $updates = [
            'status' => $mappedStatus,
            'result_summary' => json_encode($resultSummary),
        ];

        if ($mappedStatus === 'completed') {
            $updates['completed_at'] = date('Y-m-d H:i:s');
        }

        $this->updateTeachbackRecord((int) $record['id'], $updates);

        $this->logger->debug("Teachback callback processed", [
            'teachback_id' => $record['id'],
            'canopyspeak_request_id' => $canopySpeakRequestId,
            'status' => $mappedStatus,
        ]);

        return true;
    }

    /**
     * Get teachback records for a given encounter.
     *
     * @param int $pid       Patient ID
     * @param int $encounter Encounter ID
     * @return array
     */
    public function getTeachbacksForEncounter(int $pid, int $encounter): array
    {
        $results = [];
        $rows = sqlStatement(
            "SELECT * FROM `canopyspeak_teachback` WHERE `pid` = ? AND `encounter` = ? ORDER BY `created_at` DESC",
            [$pid, $encounter]
        );
        while ($row = sqlFetchArray($rows)) {
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Get the diagnosis codes for the current encounter.
     *
     * @param int $encounter Encounter ID
     * @return array Array of ICD-10 code strings
     */
    public function getDiagnosisCodesForEncounter(int $encounter): array
    {
        $codes = [];
        $rows = sqlStatement(
            "SELECT `code` FROM `billing` WHERE `encounter` = ? AND `code_type` = 'ICD10' AND `activity` = 1",
            [$encounter]
        );
        while ($row = sqlFetchArray($rows)) {
            if (!empty($row['code'])) {
                $codes[] = $row['code'];
            }
        }
        return $codes;
    }

    /**
     * Look up a teachback record by the CanopySpeak PatientRequest ID.
     *
     * @param int $canopySpeakRequestId The CanopySpeak PatientRequest ID
     * @return array|null The teachback record, or null if not found
     */
    public function getTeachbackByCanopySpeakRequestId(int $canopySpeakRequestId): ?array
    {
        $record = sqlQuery(
            "SELECT * FROM `canopyspeak_teachback` WHERE `canopyspeak_request_id` = ?",
            [$canopySpeakRequestId]
        );
        return $record ?: null;
    }

    /**
     * Look up a teachback record by its primary key ID.
     *
     * @param int $id The teachback record primary key
     * @return array|null The teachback record, or null if not found
     */
    public function getTeachbackById(int $id): ?array
    {
        $record = sqlQuery(
            "SELECT * FROM `canopyspeak_teachback` WHERE `id` = ?",
            [$id]
        );
        return $record ?: null;
    }

    private function createTeachbackRecord(
        int $pid,
        int $encounter,
        int $clinicalNoteId,
        string $mode,
        array $diagnosisCodes,
        string $topic,
        string $initiatedBy
    ): int {
        return (int) sqlInsert(
            "INSERT INTO `canopyspeak_teachback` " .
            "(`pid`, `encounter`, `clinical_note_id`, `status`, `mode`, `diagnosis_codes`, `topic`, `initiated_by`) " .
            "VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)",
            [
                $pid,
                $encounter,
                $clinicalNoteId,
                $mode,
                json_encode($diagnosisCodes),
                $topic,
                $initiatedBy,
            ]
        );
    }

    private function updateTeachbackRecord(int $id, array $updates): void
    {
        $setClauses = [];
        $params = [];
        foreach ($updates as $column => $value) {
            $setClauses[] = "`$column` = ?";
            $params[] = $value;
        }
        $params[] = $id;
        sqlStatement(
            "UPDATE `canopyspeak_teachback` SET " . implode(', ', $setClauses) . " WHERE `id` = ?",
            $params
        );
    }

    private function buildCallbackUrl(): string
    {
        $siteAddr = $GLOBALS['site_addr_oath'] ?? '';
        if (empty($siteAddr)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $siteAddr = $protocol . '://' . $host;
        }
        return rtrim($siteAddr, '/') . '/apis/default/api/canopyspeak/teachback/callback';
    }

    private function decryptApiKey(string $encryptedKey): string
    {
        return (new \OpenEMR\Common\Crypto\CryptoGen())->decryptStandard($encryptedKey);
    }
}
