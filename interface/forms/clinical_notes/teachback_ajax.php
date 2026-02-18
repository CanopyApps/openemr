<?php

/**
 * AJAX endpoint for initiating CanopySpeak Teachback workflows from clinical notes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Mark Lawley <mark@canopyspeak.com>
 * @copyright Copyright (c) 2026 Mark Lawley
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\CanopySpeak\TeachbackService;

header('Content-Type: application/json');

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token"] ?? '', 'api')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token validation failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$teachbackService = new TeachbackService();

if (!$teachbackService->isEnabled()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'CanopySpeak integration is not enabled']);
    exit;
}

$pid = (int) ($_SESSION['pid'] ?? 0);
$encounter = (int) ($_SESSION['encounter'] ?? 0);

if ($pid <= 0 || $encounter <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No active patient or encounter']);
    exit;
}

$clinicalNoteId = (int) ($_POST['clinical_note_id'] ?? 0);
$mode = $_POST['mode'] ?? 'all';
$clinicalNoteText = $_POST['clinical_note_text'] ?? '';
$topic = $_POST['topic'] ?? '';

// Validate mode
$validModes = ['diagnosis_codes', 'ai_analysis', 'manual', 'all'];
if (!in_array($mode, $validModes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid mode. Must be one of: ' . implode(', ', $validModes)]);
    exit;
}

// Get diagnosis codes from the encounter
$diagnosisCodes = $teachbackService->getDiagnosisCodesForEncounter($encounter);

// Get patient's preferred language
$patientLanguage = sqlQuery(
    "SELECT language FROM patient_data WHERE pid = ?",
    [$pid]
)['language'] ?? 'English';

$result = $teachbackService->initiateTeachback(
    $pid,
    $encounter,
    $clinicalNoteId,
    $mode,
    $clinicalNoteText,
    $diagnosisCodes,
    $patientLanguage,
    $topic,
    $_SESSION['authUser'] ?? 'unknown'
);

if ($result['success']) {
    http_response_code(200);
} else {
    http_response_code(500);
}

echo json_encode($result);
