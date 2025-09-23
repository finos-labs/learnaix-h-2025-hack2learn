<?php
require_once(__DIR__ . '/../../config.php');
require_login();

header('Content-Type: application/json; charset=utf-8');

// Basic capability check - adjust if you want stricter control
require_capability('local/projectevaluator:view', context_system::instance());

// Read JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$submissionid = isset($data['submissionid']) ? intval($data['submissionid']) : 0;
$activityid = isset($data['activityid']) ? intval($data['activityid']) : 0; // course module id (cmid)
$grade = isset($data['grade']) ? floatval($data['grade']) : null;
$feedback = isset($data['feedback']) ? trim($data['feedback']) : '';
$sesskey = isset($data['sesskey']) ? $data['sesskey'] : '';

if (!confirm_sesskey($sesskey)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid sesskey']);
    exit;
}

if (!$submissionid || !$activityid || $grade === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

global $DB;
global $USER;

$submission = $DB->get_record('assign_submission', array('id' => $submissionid));
if (!$submission) {
    http_response_code(404);
    echo json_encode(['error' => 'Submission not found']);
    exit;
}

// Resolve assignment id from course module id (course module id -> instance)
try {
    $cm = get_coursemodule_from_id('assign', $activityid, 0, false, MUST_EXIST);
    $assignmentid = $cm->instance;
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid activity id']);
    exit;
}

$userid = $submission->userid;

// Upsert assign_grades record (grade + feedback)
try {
    $grade_record = $DB->get_record('assign_grades', array('assignment' => $assignmentid, 'userid' => $userid));
    if ($grade_record) {
        $grade_record->grade = $grade;
        $grade_record->feedbacktext = $feedback;
        $grade_record->timemodified = time();
        $DB->update_record('assign_grades', $grade_record);
    } else {
        $new = new stdClass();
        $new->assignment = $assignmentid;
        $new->userid = $userid;
        $new->grade = $grade;
        $new->feedbacktext = $feedback;
        $new->timemodified = time();
        $DB->insert_record('assign_grades', $new);
    }
    // Also try to upsert the assignfeedback_comments table so feedback is visible
    // in the assignment feedback UI if that plugin/table exists.
    try {
        $comment_record = $DB->get_record('assignfeedback_comments', array('submission' => $submissionid));
        if ($comment_record) {
            $comment_record->commenttext = $feedback;
            if (defined('FORMAT_HTML')) {
                $comment_record->commentformat = FORMAT_HTML;
            }
            $comment_record->timemodified = time();
            $comment_record->grader = isset($USER->id) ? $USER->id : 0;
            $DB->update_record('assignfeedback_comments', $comment_record);
        } else {
            $newc = new stdClass();
            $newc->assignment = $assignmentid;
            $newc->submission = $submissionid;
            $newc->userid = $userid;
            $newc->grader = isset($USER->id) ? $USER->id : 0;
            $newc->commenttext = $feedback;
            if (defined('FORMAT_HTML')) {
                $newc->commentformat = FORMAT_HTML;
            }
            $newc->timemodified = time();
            $DB->insert_record('assignfeedback_comments', $newc);
        }
    } catch (Exception $e) {
        // Table may not exist or other DB errors; ignore and continue.
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    exit;
}

// Return the saved values for client-side verification
echo json_encode(['status' => 'ok', 'assignment' => $assignmentid, 'userid' => $userid, 'saved_feedback' => $feedback]);
exit;

?>
