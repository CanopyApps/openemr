<?php

/**
 * AJAX endpoint for polling CanopySpeak Teachback statuses.
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

if (!CsrfUtils::verifyCsrfToken($_GET["csrf_token"] ?? '', 'api')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token validation failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$teachbackService = new TeachbackService();

$pid = (int) ($_SESSION['pid'] ?? 0);
$encounter = (int) ($_SESSION['encounter'] ?? 0);

if ($pid <= 0 || $encounter <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No active patient or encounter']);
    exit;
}

$rows = $teachbackService->getTeachbacksForEncounter($pid, $encounter);

$byNoteId = [];
$latestForEncounter = null;
foreach ($rows as $row) {
    if (!empty($row['clinical_note_id'])) {
        $byNoteId[$row['clinical_note_id']][] = $row;
    }
    if ($latestForEncounter === null) {
        $latestForEncounter = $row;
    }
}

echo json_encode([
    'success' => true,
    'byNoteId' => $byNoteId,
    'latestForEncounter' => $latestForEncounter,
]);
