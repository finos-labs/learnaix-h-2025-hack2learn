<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
require_capability('local/projectevaluator:view', context_system::instance());

// Handle AJAX grade saving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_grade') {
    $assignmentid = required_param('assignment_id', PARAM_INT);
    $userid = required_param('user_id', PARAM_INT);
    $grade = required_param('grade', PARAM_FLOAT);
    $feedback = optional_param('feedback', '', PARAM_RAW);
    
    try {
        // Validate assignment and permissions
        if (!$assignment = $DB->get_record('assign', array('id' => $assignmentid))) {
            throw new moodle_exception('Assignment not found');
        }
        
        $context = context_course::instance($assignment->course);
        require_capability('moodle/course:manageactivities', $context);
        
        // Check if grade record exists
        $graderecord = $DB->get_record('assign_grades', array('assignment' => $assignmentid, 'userid' => $userid));
        
        if ($graderecord) {
            // Update existing grade
            $graderecord->grade = $grade;
            $graderecord->grader = $USER->id;
            $graderecord->timemodified = time();
            $DB->update_record('assign_grades', $graderecord);
        } else {
            // Create new grade record
            $graderecord = new stdClass();
            $graderecord->assignment = $assignmentid;
            $graderecord->userid = $userid;
            $graderecord->grade = $grade;
            $graderecord->grader = $USER->id;
            $graderecord->timemodified = time();
            $graderecord->timecreated = time();
            $graderecord->attemptnumber = 0;
            $DB->insert_record('assign_grades', $graderecord);
        }
        
        // Save feedback if provided
        if (!empty($feedback)) {
            // You would implement feedback saving here based on your Moodle setup
            // This is a simplified version
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Grade saved successfully']);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX grade publishing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_grade') {
    $assignmentid = required_param('assignment_id', PARAM_INT);
    $userid = required_param('user_id', PARAM_INT);
    $grade = required_param('grade', PARAM_FLOAT);
    $feedback = optional_param('feedback', '', PARAM_RAW);
    
    try {
        // Validate assignment and permissions
        if (!$assignment = $DB->get_record('assign', array('id' => $assignmentid))) {
            throw new moodle_exception('Assignment not found');
        }
        
        $context = context_course::instance($assignment->course);
        require_capability('moodle/course:manageactivities', $context);
        
        // Get course module for this assignment
        $cm = $DB->get_record('course_modules', array(
            'instance' => $assignmentid, 
            'module' => $DB->get_field('modules', 'id', array('name' => 'assign'))
        ));
        
        if (!$cm) {
            throw new moodle_exception('Course module not found');
        }
        
        // Check if grade record exists in assign_grades table
        $graderecord = $DB->get_record('assign_grades', array('assignment' => $assignmentid, 'userid' => $userid));
        
        if ($graderecord) {
            // Update existing grade
            $graderecord->grade = $grade;
            $graderecord->grader = $USER->id;
            $graderecord->timemodified = time();
            $DB->update_record('assign_grades', $graderecord);
            $gradeid = $graderecord->id;
        } else {
            // Create new grade record
            $graderecord = new stdClass();
            $graderecord->assignment = $assignmentid;
            $graderecord->userid = $userid;
            $graderecord->grade = $grade;
            $graderecord->grader = $USER->id;
            $graderecord->timemodified = time();
            $graderecord->timecreated = time();
            $graderecord->attemptnumber = 0;
            $gradeid = $DB->insert_record('assign_grades', $graderecord);
        }
        
        // Add feedback if provided
        if (!empty($feedback)) {
            // Check if feedback comment record exists
            $feedbackrecord = $DB->get_record('assignfeedback_comments', array('grade' => $gradeid));
            
            if ($feedbackrecord) {
                // Update existing feedback
                $feedbackrecord->commenttext = $feedback;
                $feedbackrecord->commentformat = FORMAT_HTML;
                $DB->update_record('assignfeedback_comments', $feedbackrecord);
            } else {
                // Create new feedback record
                $feedbackrecord = new stdClass();
                $feedbackrecord->assignment = $assignmentid;
                $feedbackrecord->grade = $gradeid;
                $feedbackrecord->commenttext = $feedback;
                $feedbackrecord->commentformat = FORMAT_HTML;
                $DB->insert_record('assignfeedback_comments', $feedbackrecord);
            }
        }
        
        // Update the main gradebook using Moodle's grade API
        require_once($CFG->libdir . '/gradelib.php');
        
        // Create grade item parameters
        $grade_item = array(
            'itemname' => $assignment->name,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => $assignment->grade,
            'grademin' => 0
        );
        
        // Update grade in gradebook
        $grade_data = array(
            'userid' => $userid,
            'rawgrade' => $grade,
            'feedback' => $feedback,
            'feedbackformat' => FORMAT_HTML,
            'usermodified' => $USER->id,
            'dategraded' => time()
        );
        
        grade_update('mod/assign', $assignment->course, 'mod', 'assign', $assignmentid, 0, $grade_data, $grade_item);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Grade published to student gradebook successfully']);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Publishing error: ' . $e->getMessage()]);
        exit;
    }
}

$submissionid = required_param('id', PARAM_INT);
$assignmentid = required_param('assignment', PARAM_INT);
$userid = required_param('user', PARAM_INT);

// Validate submission exists and user has permission
if (!$submission = $DB->get_record('assign_submission', array('id' => $submissionid))) {
    throw new moodle_exception('Submission not found');
}

if (!$assignment = $DB->get_record('assign', array('id' => $assignmentid))) {
    throw new moodle_exception('Assignment not found');
}

if (!$course = $DB->get_record('course', array('id' => $assignment->course))) {
    throw new moodle_exception('Course not found');
}

if (!$user = $DB->get_record('user', array('id' => $userid))) {
    throw new moodle_exception('User not found');
}

// Get the course module ID for this assignment
$cmid = $DB->get_field('course_modules', 'id', array(
    'instance' => $assignmentid, 
    'module' => $DB->get_field('modules', 'id', array('name' => 'assign'))
));

if (!$cmid) {
    throw new moodle_exception('Course module not found for this assignment');
}

$context = context_course::instance($assignment->course);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/projectevaluator/evaluator/submission.php', array('id' => $submissionid, 'assignment' => $assignmentid, 'user' => $userid));
$PAGE->set_title(get_string('project_evaluator', 'local_projectevaluator') . ' - ' . fullname($user));
$PAGE->set_heading(get_string('project_evaluator', 'local_projectevaluator'));

echo $OUTPUT->header();

// Navigation breadcrumb
echo '<div style="margin: 15px 0; padding: 10px 0; border-bottom: 1px solid #e2e8f0;">';
echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php" style="color: #4299e1; text-decoration: none; font-weight: 500;">← Services</a>';
echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/evaluator/dashboard.php" style="color: #4299e1; text-decoration: none; font-weight: 500;">Dashboard</a>';
echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/evaluator/course.php?id=' . $assignment->course . '" style="color: #4299e1; text-decoration: none; font-weight: 500;">' . format_string($course->shortname) . '</a>';
echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/evaluator/activity.php?id=' . $assignmentid . '&cmid=' . $cmid . '" style="color: #4299e1; text-decoration: none; font-weight: 500;">' . format_string($assignment->name) . '</a>';
echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
echo '<span style="color: #2d3748; font-weight: 600;">' . fullname($user) . '</span>';
echo '</div>';

// Get submission files
$fs = get_file_storage();
$files = $fs->get_area_files(context_module::instance($cmid)->id, 'assignsubmission_file', 'submission_files', $submissionid, 'filename', false);

// Get submission text
$submission_text = '';
$text_submission = $DB->get_record('assignsubmission_onlinetext', array('submission' => $submissionid));
if ($text_submission) {
    $submission_text = $text_submission->onlinetext;
}

// Get current grade
$grade = $DB->get_record('assign_grades', array('assignment' => $assignmentid, 'userid' => $userid));

?>

<style>
/* Submission Evaluation Page Styles */
.submission-evaluation {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.evaluation-layout {
    display: grid;
    grid-template-columns: 2fr 3fr;
    gap: 30px;
    margin-top: 20px;
}

.submission-panel {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.evaluation-panel {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    min-height: 600px;
}

.student-header {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    padding: 25px;
    position: relative;
    overflow: hidden;
    animation: slideInFromTop 0.8s ease-out;
}

.student-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.1);
    transform: rotate(45deg);
    animation: shimmer 3s ease-in-out infinite;
}

.student-info {
    display: flex;
    align-items: center;
    gap: 20px;
    position: relative;
    z-index: 2;
}

.student-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 1.8rem;
    flex-shrink: 0;
    border: 3px solid rgba(255, 255, 255, 0.3);
}

.student-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.student-details h1 {
    font-size: 1.8rem;
    margin-bottom: 8px;
    font-weight: bold;
}

.student-meta {
    display: flex;
    gap: 20px;
    font-size: 0.9rem;
    opacity: 0.9;
    flex-wrap: wrap;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.submission-content {
    padding: 30px;
}

.content-section {
    margin-bottom: 30px;
}

.section-title {
    font-size: 1.3rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.files-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.file-card {
    background: #f8f9fa;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.file-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    border-color: #4facfe;
}

.file-icon {
    font-size: 2.5rem;
    margin-bottom: 10px;
    text-align: center;
}

.file-name {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 8px;
    word-break: break-word;
}

.file-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
    color: #718096;
}

.file-size {
    background: #e2e8f0;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.75rem;
}

.text-submission {
    background: #f8f9fa;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    min-height: 200px;
    line-height: 1.6;
}

.no-content {
    text-align: center;
    color: #718096;
    font-style: italic;
    padding: 40px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #cbd5e0;
}

.evaluation-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.evaluation-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -20%;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.1);
    transform: rotate(-45deg);
    animation: shimmer 3s ease-in-out infinite reverse;
}

.evaluation-title {
    font-size: 1.5rem;
    font-weight: bold;
    margin-bottom: 8px;
    position: relative;
    z-index: 2;
}

.evaluation-subtitle {
    opacity: 0.9;
    font-size: 0.9rem;
    position: relative;
    z-index: 2;
}

.evaluation-body {
    padding: 25px;
}

.ai-analysis {
    background: #f0f8ff;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    position: relative;
}

.analysis-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    font-weight: bold;
    color: #1e40af;
}

.analysis-content {
    line-height: 1.6;
    color: #374151;
}

.loading-analysis {
    text-align: center;
    padding: 30px;
    color: #718096;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #e2e8f0;
    border-top: 4px solid #4facfe;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 15px;
}

.evaluation-actions {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-bottom: 25px;
}

.btn-ai-evaluate {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    border: none;
    padding: 15px 25px;
    border-radius: 25px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-size: 1rem;
}

.btn-ai-evaluate:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(79, 172, 254, 0.4);
}

.btn-ai-evaluate:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.grade-section {
    background: #f8f9fa;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.grade-input {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 15px;
}

.grade-field {
    flex: 1;
    padding: 10px;
    border: 1px solid #cbd5e0;
    border-radius: 8px;
    font-size: 1rem;
}

.grade-btn {
    background: #48bb78;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.grade-btn:hover {
    background: #38a169;
    transform: translateY(-1px);
}

.publish-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
    width: 100%;
    font-size: 0.95rem;
}

.publish-btn:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.publish-btn:disabled {
    background: #e2e8f0;
    color: #a0aec0;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.modal-content {
    background: white;
    border-radius: 15px;
    padding: 30px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    transform: translateY(-30px);
    transition: transform 0.3s ease;
}

.modal-overlay.active .modal-content {
    transform: translateY(0);
}

.modal-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e2e8f0;
}

.modal-title {
    font-size: 1.3rem;
    font-weight: bold;
    color: #2d3748;
}

.modal-body {
    margin-bottom: 25px;
}

.modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.modal-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}

.modal-btn-cancel {
    background: #f7fafc;
    color: #4a5568;
    border: 1px solid #e2e8f0;
}

.modal-btn-cancel:hover {
    background: #edf2f7;
}

.modal-btn-confirm {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.modal-btn-confirm:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.current-grade {
    text-align: center;
    padding: 15px;
    background: #e6fffa;
    border: 1px solid #81e6d9;
    border-radius: 8px;
    margin-bottom: 15px;
}

.feedback-section {
    margin-top: 20px;
}

.feedback-textarea {
    width: 100%;
    min-height: 120px;
    padding: 15px;
    border: 1px solid #cbd5e0;
    border-radius: 8px;
    resize: vertical;
    font-family: inherit;
    line-height: 1.5;
}

.quick-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 20px;
}

.quick-btn {
    padding: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    text-align: center;
}

.quick-btn:hover {
    background: #f7fafc;
    border-color: #cbd5e0;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 25px;
}

.metric-card {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    border: 1px solid #cbd5e0;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
}

.metric-value {
    font-size: 1.8rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 8px;
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.metric-label {
    font-size: 0.8rem;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

/* Animations */
@keyframes slideInFromTop {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes shimmer {
    0%, 100% { transform: translateX(-100%) rotate(45deg); }
    50% { transform: translateX(100%) rotate(45deg); }
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive Design */
@media (max-width: 1400px) {
    .evaluation-layout {
        grid-template-columns: 1fr 1fr;
        gap: 25px;
    }
}

@media (max-width: 1200px) {
    .evaluation-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .evaluation-panel {
        position: static;
        max-height: none;
    }
}

@media (max-width: 768px) {
    .submission-evaluation {
        padding: 15px;
    }
    
    .student-info {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .student-meta {
        justify-content: center;
    }
    
    .files-grid {
        grid-template-columns: 1fr;
    }
    
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    
    .quick-actions {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .student-header,
    .submission-content,
    .evaluation-body {
        padding: 20px;
    }
    
    .student-details h1 {
        font-size: 1.5rem;
    }
    
    .section-title {
        font-size: 1.1rem;
    }
}
</style>

<div class="submission-evaluation">
    <!-- Student Header -->
    <div class="student-header">
        <div class="student-info">
            <div class="student-avatar">
                <?php if ($user->picture): ?>
                    <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/<?php echo $user->id; ?>/f1.jpg" alt="<?php echo $user->imagealt; ?>">
                <?php else: ?>
                    <?php echo substr($user->firstname, 0, 1) . substr($user->lastname, 0, 1); ?>
                <?php endif; ?>
            </div>
            <div class="student-details">
                <h1><?php echo fullname($user); ?></h1>
                <div class="student-meta">
                    <div class="meta-item">
                        <span>📧</span>
                        <span><?php echo $user->email; ?></span>
                    </div>
                    <div class="meta-item">
                        <span>📅</span>
                        <span>Submitted: <?php echo userdate($submission->timemodified, '%d %B %Y, %H:%M'); ?></span>
                    </div>
                    <div class="meta-item">
                        <span>🔢</span>
                        <span>Attempt <?php echo $submission->attemptnumber + 1; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="evaluation-layout">
        <!-- Submission Panel -->
        <div class="submission-panel">
            <div class="submission-content">
                <?php if (!empty($files) || !empty($submission_text)): ?>
                    
                    <?php if (!empty($files)): ?>
                        <!-- Files Section -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <span>📎</span>
                                Submitted Files (<?php echo count($files); ?>)
                            </h2>
                            <div class="files-grid">
                                <?php foreach ($files as $file): 
                                    $filename = $file->get_filename();
                                    $filesize = display_size($file->get_filesize());
                                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                    
                                    // Get appropriate icon
                                    $icon = '📄';
                                    switch ($extension) {
                                        case 'pdf': $icon = '📕'; break;
                                        case 'doc':
                                        case 'docx': $icon = '📘'; break;
                                        case 'txt': $icon = '📝'; break;
                                        case 'zip':
                                        case 'rar': $icon = '🗜️'; break;
                                        case 'jpg':
                                        case 'jpeg':
                                        case 'png':
                                        case 'gif': $icon = '🖼️'; break;
                                        case 'mp4':
                                        case 'avi':
                                        case 'mov': $icon = '🎬'; break;
                                        default: $icon = '📄';
                                    }
                                ?>
                                    <div class="file-card" onclick="downloadFile('<?php echo s($filename); ?>')">
                                        <div class="file-icon"><?php echo $icon; ?></div>
                                        <div class="file-name"><?php echo s($filename); ?></div>
                                        <div class="file-info">
                                            <span><?php echo strtoupper($extension); ?></span>
                                            <span class="file-size"><?php echo $filesize; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($submission_text)): ?>
                        <!-- Text Submission Section -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <span>📝</span>
                                Online Text Submission
                            </h2>
                            <div class="text-submission" id="text-submission">
                                <?php echo format_text($submission_text, FORMAT_HTML); ?>
                            </div>
                            
                            <!-- GitHub Detection Banner -->
                            <div class="github-detection" id="github-banner" style="display: none;">
                                <div style="background: linear-gradient(135deg, #24292e 0%, #2f363d 100%); color: white; padding: 20px; border-radius: 12px; margin-top: 15px; position: relative; overflow: hidden;">
                                    <div style="position: absolute; top: -50%; right: -20%; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.1); transform: rotate(45deg); animation: shimmer 3s ease-in-out infinite;"></div>
                                    <div style="position: relative; z-index: 2;">
                                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                            <span style="font-size: 2rem;">🐙</span>
                                            <div>
                                                <h3 style="margin: 0; font-size: 1.2rem; font-weight: bold;">GitHub Repository Detected!</h3>
                                                <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 0.9rem;">We found a GitHub repository link in the submission</p>
                                            </div>
                                        </div>
                                        <div style="background: rgba(255, 255, 255, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                            <p style="margin: 0; font-weight: 600; color: #58a6ff;">Repository URL:</p>
                                            <p style="margin: 5px 0 0 0; font-family: monospace; background: rgba(0, 0, 0, 0.3); padding: 8px; border-radius: 4px; word-break: break-all;" id="github-url"></p>
                                        </div>
                                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                            <button onclick="window.open(document.getElementById('github-url').textContent, '_blank')" style="background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid rgba(255, 255, 255, 0.3); padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                                                � Open Repository
                                            </button>
                                            <button onclick="document.getElementById('github-banner').style.display='none'; isGitHubRepo=false; updateEvaluateButton();" style="background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                                                ❌ Ignore GitHub
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="no-content">
                        <p>No content submitted</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Evaluation Panel -->
        <div class="evaluation-panel">
            <div class="evaluation-header">
                <h2 class="evaluation-title">🤖 AI Evaluation</h2>
                <p class="evaluation-subtitle">Intelligent assessment and feedback</p>
            </div>
            
            <div class="evaluation-body">
                <!-- Current Grade Display -->
                <?php if ($grade && $grade->grade !== null): ?>
                    <div class="current-grade">
                        <strong>Current Grade: <?php echo number_format($grade->grade, 1); ?>/100</strong>
                        <br>
                        <small>Graded on <?php echo userdate($grade->timemodified, '%d %b %Y'); ?></small>
                    </div>
                <?php endif; ?>

                <!-- AI Analysis Section -->
                <div class="ai-analysis" id="ai-analysis" style="display: none;">
                    <div class="analysis-header">
                        <span>🎯</span>
                        AI Analysis Results
                    </div>
                    <div class="analysis-content" id="analysis-content">
                        <!-- AI analysis will be populated here -->
                    </div>
                </div>

                <!-- Loading State -->
                <div class="loading-analysis" id="loading-analysis" style="display: none;">
                    <div class="loading-spinner"></div>
                    <p>AI is analyzing the submission...</p>
                    <small>This may take a few moments</small>
                </div>

                <!-- Evaluation Actions -->
                <div class="evaluation-actions">
                    <button class="btn-ai-evaluate" id="evaluate-btn" onclick="startEvaluation()">
                        <span>🤖</span>
                        Start AI Evaluation
                    </button>
                </div>

                <!-- Metrics Grid -->
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-value" id="overall-score">-</div>
                        <div class="metric-label">Overall Score</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="code-quality">-</div>
                        <div class="metric-label">Code Quality</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="functionality-score">-</div>
                        <div class="metric-label">Functionality</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" id="documentation-score">-</div>
                        <div class="metric-label">Documentation</div>
                    </div>
                </div>

                <!-- Grade Assignment -->
                <div class="grade-section">
                    <h3 style="margin-bottom: 15px; color: #2d3748;">💯 Assign Grade</h3>
                    <div class="grade-input">
                        <input type="number" class="grade-field" id="grade-input" placeholder="Grade (0-100)" min="0" max="100" step="0.1">
                        <button class="grade-btn" onclick="assignGrade()">Save</button>
                    </div>
                </div>
                
                <!-- Feedback Section -->
                <div class="feedback-section">
                    <h3 style="margin-bottom: 15px; color: #2d3748;">💬 Feedback</h3>
                    <textarea class="feedback-textarea" id="feedback-text" placeholder="Provide detailed feedback for the student..."></textarea>
                </div>
                
                <div style="margin-top: 15px;">
                    <button class="publish-btn" id="publish-btn" onclick="showPublishModal()" disabled>
                        📤 Publish to Gradebook
                    </button>
                </div>
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <button class="quick-btn" onclick="window.open('<?php echo $CFG->wwwroot; ?>/mod/assign/view.php?id=<?php echo $cmid; ?>&rownum=0&action=grader&userid=<?php echo $userid; ?>', '_blank')">
                        📝 Open in Moodle
                    </button>
                    <button class="quick-btn" onclick="startEvaluation()">
                        🔄 Regenerate
                    </button>
                    <button class="quick-btn" onclick="openGradeReport()">
                        📊 Grade Report
                    </button>
                    <button class="quick-btn" onclick="viewAllSubmissions()">
                        � All Submissions
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Publish Modal -->
<div class="modal-overlay" id="publish-modal">
    <div class="modal-content">
        <div class="modal-header">
            <span>📤</span>
            <h3 class="modal-title">Publish Grade to Student Gradebook</h3>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 15px; color: #4a5568;">You are about to publish this grade and feedback to the student's gradebook. Please review:</p>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                <p><strong>Student:</strong> <?php echo fullname($user); ?></p>
                <p><strong>Assignment:</strong> <?php echo format_string($assignment->name); ?></p>
                <p><strong>Grade:</strong> <span id="modal-grade">--</span>/100</p>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2d3748;">Final Feedback (editable):</label>
                <textarea id="modal-feedback" style="width: 100%; min-height: 120px; padding: 12px; border: 1px solid #cbd5e0; border-radius: 8px; resize: vertical; font-family: inherit;" placeholder="Add or edit feedback before publishing..."></textarea>
            </div>
            
            <div style="background: #fef5e7; border: 1px solid #f6ad55; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <p style="margin: 0; font-size: 0.9rem; color: #744210;">
                    <strong>⚠️ Important:</strong> Once published, the grade and feedback will be visible to the student and recorded in the gradebook.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-cancel" onclick="closePublishModal()">Cancel</button>
            <button class="modal-btn modal-btn-confirm" onclick="confirmPublish()">Publish Grade</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script>
// Global variables
let evaluationData = null;
let isEvaluating = false;
let isGitHubRepo = false;
let gitHubUrl = '';

// GitHub URL detection
function detectGitHubRepo() {
    const textSubmission = document.getElementById('text-submission');
    if (!textSubmission) return false;
    
    const text = textSubmission.textContent || textSubmission.innerText;
    const githubRegex = /https?:\/\/(www\.)?github\.com\/[\w\-._~:/?#[\]@!$&'()*+,;=]+/gi;
    const matches = text.match(githubRegex);
    
    if (matches && matches.length > 0) {
        isGitHubRepo = true;
        gitHubUrl = matches[0];
        
        // Show GitHub detection banner
        document.getElementById('github-banner').style.display = 'block';
        document.getElementById('github-url').textContent = gitHubUrl;
        
        // Update the main evaluate button
        updateEvaluateButton();
        
        return true;
    }
    
    return false;
}

// Update the main evaluate button based on GitHub detection
function updateEvaluateButton() {
    const evaluateBtn = document.getElementById('evaluate-btn');
    if (isGitHubRepo) {
        evaluateBtn.innerHTML = '<span>🐙</span> Evaluate GitHub Repository';
        evaluateBtn.style.background = 'linear-gradient(135deg, #238636 0%, #2ea043 100%)';
    } else {
        evaluateBtn.innerHTML = '<span>🤖</span> Start AI Evaluation';
        evaluateBtn.style.background = 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)';
    }
}

// Main evaluation function that routes to appropriate method
async function startEvaluation() {
    if (isGitHubRepo) {
        await evaluateGitHubRepo();
    } else {
        await startAIEvaluation();
    }
}

// GitHub Repository Evaluation
async function evaluateGitHubRepo() {
    if (isEvaluating) return;
    
    isEvaluating = true;
    const btn = document.getElementById('evaluate-btn');
    const loading = document.getElementById('loading-analysis');
    const analysisDiv = document.getElementById('ai-analysis');
    
    // Show loading state
    btn.disabled = true;
    btn.innerHTML = '<span>🔄</span> Analyzing Repository...';
    loading.style.display = 'block';
    analysisDiv.style.display = 'none';
    
    try {
        // Prepare form data for GitHub evaluation
        const formData = new FormData();
        
        // Add project criteria
        const criteria = `
Assignment: <?php echo addslashes($assignment->name); ?>
Description: <?php echo addslashes(strip_tags($assignment->intro)); ?>
Course: <?php echo addslashes($course->fullname); ?>

GitHub Repository Evaluation Criteria:
- Code Quality and Structure (35 points)
- Functionality and Correctness (45 points)  
- Documentation and Comments (20 points)
- Repository Management (commit history, README, etc.)
- Best Practices Implementation
        `;
        
        formData.append('criteria', criteria);
        formData.append('github_url', gitHubUrl);
        formData.append('assignment_id', <?php echo $assignmentid; ?>);
        formData.append('user_id', <?php echo $userid; ?>);
        
        // Call GitHub evaluation endpoint
        const response = await fetch('http://localhost:8001/evaluate-github-repo/', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
        }
        
        const result = await response.json();
        evaluationData = result.evaluation;
        
        // Display results with GitHub-specific information
        displayGitHubEvaluationResults(result.evaluation);
        
        // Enable publish button
        document.getElementById('publish-btn').disabled = false;
        
    } catch (error) {
        console.error('GitHub evaluation error:', error);
        
        // Show error state
        loading.style.display = 'none';
        analysisDiv.style.display = 'block';
        document.getElementById('analysis-content').innerHTML = `
            <div style="color: #e53e3e; text-align: center; padding: 20px;">
                <strong>⚠️ GitHub Repository Evaluation Failed</strong><br>
                <small>Error: ${error.message}</small><br>
                <small>Please check if the repository is public and the backend is running</small>
            </div>
        `;
    } finally {
        isEvaluating = false;
        btn.disabled = false;
        updateEvaluateButton();
        loading.style.display = 'none';
    }
}

// Start AI evaluation
async function startAIEvaluation() {
    if (isEvaluating) return;
    
    isEvaluating = true;
    const btn = document.getElementById('evaluate-btn');
    const loading = document.getElementById('loading-analysis');
    const analysisDiv = document.getElementById('ai-analysis');
    
    // Show loading state
    btn.disabled = true;
    btn.innerHTML = '<span>🔄</span> Evaluating...';
    loading.style.display = 'block';
    analysisDiv.style.display = 'none';
    
    try {
        // Create a ZIP file with submission content
        const zip = new JSZip();
        
        // Add project criteria
        const criteria = `
Assignment: <?php echo addslashes($assignment->name); ?>
Description: <?php echo addslashes(strip_tags($assignment->intro)); ?>
Course: <?php echo addslashes($course->fullname); ?>

Evaluation Criteria:
- Code Quality and Structure (35 points)
- Functionality and Correctness (45 points)  
- Documentation and Comments (20 points)
- Best Practices Implementation
        `;
        
        // Add submission content to ZIP
        <?php if (!empty($submission_text)): ?>
            zip.file("submission_text.txt", <?php echo json_encode($submission_text); ?>);
        <?php endif; ?>
        
        <?php if (!empty($files)): ?>
            // For files, we need to fetch the actual content
            let filePromises = [];
            <?php foreach ($files as $file): ?>
                filePromises.push(
                    fetch('<?php echo $CFG->wwwroot; ?>/pluginfile.php/<?php echo context_module::instance($cmid)->id; ?>/assignsubmission_file/submission_files/<?php echo $submissionid; ?>/<?php echo rawurlencode($file->get_filename()); ?>')
                    .then(response => response.blob())
                    .then(blob => {
                        zip.file("<?php echo $file->get_filename(); ?>", blob);
                    })
                    .catch(error => {
                        console.warn('Could not fetch file <?php echo $file->get_filename(); ?>, adding placeholder');
                        zip.file("<?php echo $file->get_filename(); ?>", "File content could not be retrieved: <?php echo $file->get_filename(); ?>\nSize: <?php echo $file->get_filesize(); ?> bytes");
                    })
                );
            <?php endforeach; ?>
            
            // Wait for all files to be added
            await Promise.all(filePromises);
        <?php else: ?>
            zip.file("no_files.txt", "No files were submitted with this assignment.");
        <?php endif; ?>
        
        // Generate ZIP blob
        const zipBlob = await zip.generateAsync({type: "blob"});
        
        // Prepare form data for file upload
        const formData = new FormData();
        formData.append('criteria', criteria);
        formData.append('file', zipBlob, 'submission.zip');
        
        // Call AI evaluation endpoint
        const response = await fetch('http://localhost:8001/evaluate-project/', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
        }
        
        const result = await response.json();
        evaluationData = result.evaluation;
        
        // Display results
        displayEvaluationResults(result.evaluation);
        
        // Enable publish button
        document.getElementById('publish-btn').disabled = false;
        
    } catch (error) {
        console.error('Evaluation error:', error);
        
        // Show error state
        loading.style.display = 'none';
        analysisDiv.style.display = 'block';
        document.getElementById('analysis-content').innerHTML = `
            <div style="color: #e53e3e; text-align: center; padding: 20px;">
                <strong>⚠️ Evaluation Failed</strong><br>
                <small>Error: ${error.message}</small><br>
                <small>Please check if the Python backend is running on port 8001</small>
            </div>
        `;
    } finally {
        isEvaluating = false;
        btn.disabled = false;
        btn.innerHTML = '<span>🔄</span> Re-evaluate';
        loading.style.display = 'none';
    }
}

// Display evaluation results
function displayEvaluationResults(evaluation) {
    const analysisDiv = document.getElementById('ai-analysis');
    const analysisContent = document.getElementById('analysis-content');
    
    // Update metrics with the new structure
    if (evaluation.overall_score !== undefined) {
        document.getElementById('overall-score').textContent = evaluation.overall_score;
    }
    
    if (evaluation.scores) {
        document.getElementById('code-quality').textContent = evaluation.scores.code_quality || '-';
        document.getElementById('functionality-score').textContent = evaluation.scores.functionality_correctness || '-';
        document.getElementById('documentation-score').textContent = evaluation.scores.documentation || '-';
    }
    
    // Create detailed analysis content
    let analysisHTML = `
        <div style="margin-bottom: 25px;">
            <h4 style="color: #1e40af; margin-bottom: 15px; font-size: 1.1rem;">📊 Evaluation Scores</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 15px;">
                <div style="background: #f0f8ff; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #bfdbfe;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #1e40af;">${evaluation.overall_score || 0}</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Overall</div>
                </div>
                <div style="background: #f0fff4; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #86efac;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #059669;">${evaluation.scores?.code_quality || 0}/35</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Code Quality</div>
                </div>
                <div style="background: #fffbeb; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #fcd34d;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #d97706;">${evaluation.scores?.functionality_correctness || 0}/45</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Functionality</div>
                </div>
                <div style="background: #fdf2f8; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #f9a8d4;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #be185d;">${evaluation.scores?.documentation || 0}/20</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Documentation</div>
                </div>
            </div>
        </div>
    `;
    
    if (evaluation.report) {
        // Add strengths section
        if (evaluation.report.strengths && evaluation.report.strengths.length > 0) {
            analysisHTML += `
                <div style="margin-bottom: 25px;">
                    <h4 style="color: #059669; margin-bottom: 15px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <span>✅</span> Key Strengths
                    </h4>
                    <div style="background: #f0fff4; border: 1px solid #86efac; border-radius: 10px; padding: 20px;">
                        <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
            `;
            evaluation.report.strengths.forEach(strength => {
                analysisHTML += `<li style="margin-bottom: 8px;">${strength}</li>`;
            });
            analysisHTML += `
                        </ul>
                    </div>
                </div>
            `;
        }
        
        // Add areas of improvement section
        if (evaluation.report.areas_of_improvement && evaluation.report.areas_of_improvement.length > 0) {
            analysisHTML += `
                <div style="margin-bottom: 25px;">
                    <h4 style="color: #dc2626; margin-bottom: 15px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <span>🔧</span> Areas for Improvement
                    </h4>
                    <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 20px;">
                        <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
            `;
            evaluation.report.areas_of_improvement.forEach(improvement => {
                analysisHTML += `<li style="margin-bottom: 12px;">${improvement}</li>`;
            });
            analysisHTML += `
                        </ul>
                    </div>
                </div>
            `;
        }
        
        // Add summary section
        if (evaluation.report.summary) {
            analysisHTML += `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #1e40af; margin-bottom: 15px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <span>�</span> Summary & Recommendations
                    </h4>
                    <div style="background: #f8fafc; border: 1px solid #cbd5e0; border-radius: 10px; padding: 20px; border-left: 4px solid #4facfe;">
                        <p style="margin: 0; line-height: 1.7; color: #374151;">${evaluation.report.summary}</p>
                    </div>
                </div>
            `;
        }
    }
    
    // Add action buttons
    analysisHTML += `
        <div style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
            <button onclick="populateGradeFromAI()" style="background: #059669; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; flex: 1; min-width: 120px; font-size: 0.9rem; font-weight: 500;">
                📊 Use AI Score
            </button>
            <button onclick="populateFeedbackFromAI()" style="background: #7c3aed; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; flex: 1; min-width: 120px; font-size: 0.9rem; font-weight: 500;">
                💬 Use AI Feedback
            </button>
        </div>
    `;
    
    analysisContent.innerHTML = analysisHTML;
    analysisDiv.style.display = 'block';
}

// Display GitHub evaluation results
function displayGitHubEvaluationResults(evaluation) {
    const analysisDiv = document.getElementById('ai-analysis');
    const analysisContent = document.getElementById('analysis-content');
    
    // Update metrics with the new structure
    if (evaluation.overall_score !== undefined) {
        document.getElementById('overall-score').textContent = evaluation.overall_score;
    }
    
    if (evaluation.scores) {
        document.getElementById('code-quality').textContent = evaluation.scores.code_quality || '-';
        document.getElementById('functionality-score').textContent = evaluation.scores.functionality_correctness || '-';
        document.getElementById('documentation-score').textContent = evaluation.scores.documentation || '-';
    }
    
    // Create GitHub-specific analysis content
    let analysisHTML = `
        <div style="margin-bottom: 25px;">
            <h4 style="color: #1e40af; margin-bottom: 15px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                <span>🐙</span> GitHub Repository Analysis
            </h4>
            <div style="background: #f8fafc; border: 1px solid #cbd5e0; border-radius: 10px; padding: 20px; margin-bottom: 15px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px;">
                    <div style="text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: bold; color: #24292e;">${evaluation.repo_stats?.commits || 'N/A'}</div>
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Commits</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: bold; color: #24292e;">${evaluation.repo_stats?.files || 'N/A'}</div>
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Files</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: bold; color: #24292e;">${evaluation.repo_stats?.languages || 'N/A'}</div>
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Languages</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1.2rem; font-weight: bold; color: #24292e;">${evaluation.repo_stats?.size || 'N/A'}</div>
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Size</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="margin-bottom: 25px;">
            <h4 style="color: #1e40af; margin-bottom: 15px; font-size: 1.1rem;">📊 Evaluation Scores</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 15px;">
                <div style="background: #f0f8ff; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #bfdbfe;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #1e40af;">${evaluation.overall_score || 0}</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Overall</div>
                </div>
                <div style="background: #f0fff4; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #86efac;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #059669;">${evaluation.scores?.code_quality || 0}/35</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Code Quality</div>
                </div>
                <div style="background: #fffbeb; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #fcd34d;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #d97706;">${evaluation.scores?.functionality_correctness || 0}/45</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Functionality</div>
                </div>
                <div style="background: #fdf2f8; padding: 12px; border-radius: 8px; text-align: center; border: 1px solid #f9a8d4;">
                    <div style="font-size: 1.4rem; font-weight: bold; color: #be185d;">${evaluation.scores?.documentation || 0}/20</div>
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Documentation</div>
                </div>
            </div>
        </div>
    `;
    
    if (evaluation.report) {
        // Add strengths section
        if (evaluation.report.strengths && evaluation.report.strengths.length > 0) {
            analysisHTML += `
                <div style="margin-bottom: 25px;">
                    <h4 style="color: #059669; margin-bottom: 15px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <span>✅</span> Repository Strengths
                    </h4>
                    <div style="background: #f0fff4; border: 1px solid #86efac; border-radius: 10px; padding: 20px;">
                        <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
            `;
            evaluation.report.strengths.forEach(strength => {
                analysisHTML += `<li style="margin-bottom: 8px;">${strength}</li>`;
            });
            analysisHTML += `
                        </ul>
                    </div>
                </div>
            `;
        }
        
        // Add areas of improvement section
        if (evaluation.report.areas_of_improvement && evaluation.report.areas_of_improvement.length > 0) {
            analysisHTML += `
                <div style="margin-bottom: 25px;">
                    <h4 style="color: #dc2626; margin-bottom: 15px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <span>🔧</span> Areas for Improvement
                    </h4>
                    <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 20px;">
                        <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
            `;
            evaluation.report.areas_of_improvement.forEach(improvement => {
                analysisHTML += `<li style="margin-bottom: 12px;">${improvement}</li>`;
            });
            analysisHTML += `
                        </ul>
                    </div>
                </div>
            `;
        }
        
        // Add summary section
        if (evaluation.report.summary) {
            analysisHTML += `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #1e40af; margin-bottom: 15px; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <span>📝</span> Repository Summary & Recommendations
                    </h4>
                    <div style="background: #f8fafc; border: 1px solid #cbd5e0; border-radius: 10px; padding: 20px; border-left: 4px solid #4facfe;">
                        <p style="margin: 0; line-height: 1.7; color: #374151;">${evaluation.report.summary}</p>
                    </div>
                </div>
            `;
        }
    }
    
    // Add action buttons
    analysisHTML += `
        <div style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
            <button onclick="populateGradeFromAI()" style="background: #059669; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; flex: 1; min-width: 120px; font-size: 0.9rem; font-weight: 500;">
                📊 Use AI Score
            </button>
            <button onclick="populateFeedbackFromAI()" style="background: #7c3aed; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; flex: 1; min-width: 120px; font-size: 0.9rem; font-weight: 500;">
                💬 Use AI Feedback
            </button>
            <button onclick="window.open('${gitHubUrl}', '_blank')" style="background: #24292e; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; flex: 1; min-width: 120px; font-size: 0.9rem; font-weight: 500;">
                🐙 View Repository
            </button>
        </div>
    `;
    
    analysisContent.innerHTML = analysisHTML;
    analysisDiv.style.display = 'block';
}

// New helper functions
function populateGradeFromAI() {
    if (evaluationData && evaluationData.overall_score) {
        document.getElementById('grade-input').value = evaluationData.overall_score;
        // Enable publish button since we have a grade
        document.getElementById('publish-btn').disabled = false;
    }
}

function populateFeedbackFromAI() {
    if (evaluationData && evaluationData.report) {
        let feedback = '';
        
        if (evaluationData.report.summary) {
            feedback += evaluationData.report.summary + '\n\n';
        }
        
        if (evaluationData.report.strengths && evaluationData.report.strengths.length > 0) {
            feedback += 'Strengths:\n';
            evaluationData.report.strengths.forEach(strength => {
                feedback += '• ' + strength + '\n';
            });
            feedback += '\n';
        }
        
        if (evaluationData.report.areas_of_improvement && evaluationData.report.areas_of_improvement.length > 0) {
            feedback += 'Areas for Improvement:\n';
            evaluationData.report.areas_of_improvement.forEach(improvement => {
                feedback += '• ' + improvement + '\n';
            });
        }
        
        document.getElementById('feedback-text').value = feedback;
    }
}

// Assign grade function
async function assignGrade() {
    const grade = document.getElementById('grade-input').value;
    const feedback = document.getElementById('feedback-text').value;
    
    if (!grade || grade < 0 || grade > 100) {
        alert('Please enter a valid grade between 0 and 100');
        return;
    }
    
    const gradeBtn = document.querySelector('.grade-btn');
    const originalText = gradeBtn.textContent;
    gradeBtn.disabled = true;
    gradeBtn.textContent = 'Saving...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'save_grade');
        formData.append('assignment_id', <?php echo $assignmentid; ?>);
        formData.append('user_id', <?php echo $userid; ?>);
        formData.append('grade', grade);
        formData.append('feedback', feedback);
        formData.append('sesskey', '<?php echo sesskey(); ?>');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Grade saved successfully!');
            
            // Enable publish button
            document.getElementById('publish-btn').disabled = false;
            
            // Update the current grade display
            const currentGradeDiv = document.querySelector('.current-grade');
            if (currentGradeDiv) {
                currentGradeDiv.innerHTML = `<strong>Current Grade: ${grade}/100</strong><br><small>Just saved</small>`;
            } else {
                // Create new current grade display
                const gradeSection = document.querySelector('.grade-section');
                const newCurrentGrade = document.createElement('div');
                newCurrentGrade.className = 'current-grade';
                newCurrentGrade.innerHTML = `<strong>Current Grade: ${grade}/100</strong><br><small>Just saved</small>`;
                gradeSection.insertBefore(newCurrentGrade, gradeSection.firstChild);
            }
        } else {
            alert('Error saving grade: ' + result.message);
        }
    } catch (error) {
        console.error('Error saving grade:', error);
        alert('Error saving grade. Please try again.');
    } finally {
        gradeBtn.disabled = false;
        gradeBtn.textContent = originalText;
    }
}

// Generate feedback function
function generateFeedback() {
    if (!evaluationData) {
        alert('Please run AI evaluation first');
        return;
    }
    
    populateFeedbackFromAI();
}

// Publishing Modal Functions
function showPublishModal() {
    const grade = document.getElementById('grade-input').value;
    const feedback = document.getElementById('feedback-text').value;
    
    if (!grade || grade < 0 || grade > 100) {
        alert('Please enter a valid grade between 0 and 100 before publishing');
        return;
    }
    
    // Populate modal with current values
    document.getElementById('modal-grade').textContent = grade;
    document.getElementById('modal-feedback').value = feedback;
    
    // Show modal
    document.getElementById('publish-modal').classList.add('active');
}

function closePublishModal() {
    document.getElementById('publish-modal').classList.remove('active');
}

async function confirmPublish() {
    const grade = document.getElementById('modal-grade').textContent;
    const feedback = document.getElementById('modal-feedback').value;
    
    const publishBtn = document.querySelector('.modal-btn-confirm');
    const originalText = publishBtn.textContent;
    publishBtn.disabled = true;
    publishBtn.textContent = 'Publishing...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'publish_grade');
        formData.append('assignment_id', <?php echo $assignmentid; ?>);
        formData.append('user_id', <?php echo $userid; ?>);
        formData.append('grade', grade);
        formData.append('feedback', feedback);
        formData.append('sesskey', '<?php echo sesskey(); ?>');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Grade successfully published to student gradebook! 🎉');
            closePublishModal();
            
            // Update UI to show published status
            const publishBtn = document.getElementById('publish-btn');
            publishBtn.innerHTML = '✅ Published';
            publishBtn.disabled = true;
            publishBtn.style.background = '#48bb78';
            
            // Update current grade display
            const currentGradeDiv = document.querySelector('.current-grade');
            if (currentGradeDiv) {
                currentGradeDiv.innerHTML = `<strong>Published Grade: ${grade}/100</strong><br><small>Published to gradebook</small>`;
                currentGradeDiv.style.background = '#f0fff4';
                currentGradeDiv.style.borderColor = '#86efac';
            }
        } else {
            alert('Error publishing grade: ' + result.message);
        }
    } catch (error) {
        console.error('Error publishing grade:', error);
        alert('Error publishing grade. Please try again.');
    } finally {
        publishBtn.disabled = false;
        publishBtn.textContent = originalText;
    }
}

// Export evaluation function
function exportEvaluation() {
    if (!evaluationData) {
        alert('Please run AI evaluation first');
        return;
    }
    
    alert('Export functionality would generate a PDF report with detailed analysis and recommendations.');
}

// Share evaluation function
function shareEvaluation() {
    alert('Share functionality would allow sending evaluation results to colleagues or administrators.');
}

// Navigation functions
function openGradeReport() {
    // Open gradebook for this assignment
    window.open('<?php echo $CFG->wwwroot; ?>/grade/report/grader/index.php?id=<?php echo $assignment->course; ?>', '_blank');
}

function viewAllSubmissions() {
    // Go back to activity submissions view
    window.location.href = '<?php echo $CFG->wwwroot; ?>/local/projectevaluator/evaluator/activity.php?id=<?php echo $assignmentid; ?>&cmid=<?php echo $cmid; ?>';
}

// Download file function - updated to use proper Moodle file URLs
function downloadFile(filename) {
    // Use the proper Moodle file serving URL
    const fileUrl = '<?php echo $CFG->wwwroot; ?>/pluginfile.php/<?php echo context_module::instance($cmid)->id; ?>/assignsubmission_file/submission_files/<?php echo $submissionid; ?>/' + encodeURIComponent(filename);
    window.open(fileUrl, '_blank');
}

// Navigation functions using Moodle queries (similar to course.php approach)
function openGradeReport() {
    // Navigate to course gradebook using direct query
    window.open('<?php echo $CFG->wwwroot; ?>/grade/report/grader/index.php?id=<?php echo $assignment->course; ?>', '_blank');
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Add animations
    document.querySelector('.submission-panel').style.animation = 'fadeInUp 0.8s ease-out';
    document.querySelector('.evaluation-panel').style.animation = 'fadeInUp 0.8s ease-out 0.2s both';
    
    // Detect GitHub repository on page load
    detectGitHubRepo();
    
    // Close modal when clicking outside
    document.getElementById('publish-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePublishModal();
        }
    });
    
    // Handle ESC key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('publish-modal').classList.contains('active')) {
            closePublishModal();
        }
    });
});

// Add fadeInUp animation
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
`;
document.head.appendChild(style);
</script>

<?php
echo $OUTPUT->footer();
?>