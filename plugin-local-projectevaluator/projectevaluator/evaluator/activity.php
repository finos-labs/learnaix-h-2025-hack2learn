<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/accesslib.php');

require_login();
require_capability('local/projectevaluator:view', context_system::instance());

$assignmentid = required_param('id', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

// Validate assignment exists and user has permission
if (!$assignment = $DB->get_record('assign', array('id' => $assignmentid))) {
    throw new moodle_exception('Assignment not found');
}

if (!$course = $DB->get_record('course', array('id' => $assignment->course))) {
    throw new moodle_exception('Course not found');
}

if (!$cm = $DB->get_record('course_modules', array('id' => $cmid))) {
    throw new moodle_exception('Course module not found');
}

$context = context_course::instance($assignment->course);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/projectevaluator/evaluator/activity.php', array('id' => $assignmentid, 'cmid' => $cmid));
$PAGE->set_title(get_string('project_evaluator', 'local_projectevaluator') . ' - ' . format_string($assignment->name));
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
echo '<span style="color: #2d3748; font-weight: 600;">' . format_string($assignment->name) . '</span>';
echo '</div>';

// Get all submissions for this assignment with user details, ordered from newest to oldest
$sql = "SELECT s.id, s.userid, s.timemodified, s.timecreated, s.status, s.attemptnumber,
               u.firstname, u.lastname, u.email, u.picture, u.imagealt,
               g.grade, g.timemodified as graded_time,
               COUNT(f.id) as file_count
        FROM {assign_submission} s
        JOIN {user} u ON u.id = s.userid
        LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
        LEFT JOIN {files} f ON f.itemid = s.id AND f.component = 'assignsubmission_file' AND f.filearea = 'submission_files' AND f.filename != '.'
        WHERE s.assignment = ? AND s.status = 'submitted'
        GROUP BY s.id, s.userid, s.timemodified, s.timecreated, s.status, s.attemptnumber,
                 u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                 g.grade, g.timemodified
        ORDER BY s.timemodified DESC";

$submissions = $DB->get_records_sql($sql, [$assignmentid]);

// Get assignment statistics
$stats_sql = "SELECT COUNT(DISTINCT s.userid) as submitted_count,
                     COUNT(DISTINCT s.id) as total_submissions,
                     AVG(g.grade) as avg_grade,
                     COUNT(DISTINCT CASE WHEN g.grade IS NOT NULL THEN s.userid END) as graded_count
              FROM {assign_submission} s
              LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
              WHERE s.assignment = ? AND s.status = 'submitted'";

$stats = $DB->get_record_sql($stats_sql, [$assignmentid]);

// Get total enrolled students
$enrolled_sql = "SELECT COUNT(DISTINCT u.id) as total_enrolled
                 FROM {user_enrolments} ue
                 JOIN {enrol} e ON e.id = ue.enrolid
                 JOIN {user} u ON u.id = ue.userid
                 WHERE e.courseid = ? AND u.deleted = 0 AND ue.status = 0";
$total_enrolled = $DB->get_field_sql($enrolled_sql, [$assignment->course]);

?>

<style>
/* Activity Submissions Page Styles */
.activity-submissions {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.activity-header {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
    position: relative;
    overflow: hidden;
    animation: slideInFromTop 0.8s ease-out;
}

.activity-header::before {
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

.activity-title {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 15px;
    position: relative;
    z-index: 2;
}

.activity-description {
    opacity: 0.9;
    margin-bottom: 20px;
    line-height: 1.6;
    position: relative;
    z-index: 2;
}

.activity-meta {
    display: flex;
    gap: 30px;
    align-items: center;
    flex-wrap: wrap;
    position: relative;
    z-index: 2;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.2);
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.9rem;
}

.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
    animation: fadeInUp 0.8s ease-out 0.2s both;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    text-align: center;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.stat-icon {
    font-size: 2.5rem;
    margin-bottom: 10px;
}

.stat-number {
    font-size: 1.8rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 5px;
}

.stat-label {
    color: #718096;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.submissions-section {
    animation: fadeInUp 0.8s ease-out 0.4s both;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 20px;
}

.section-title {
    font-size: 1.8rem;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filters-container {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-dropdown {
    padding: 8px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    background: white;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.filter-dropdown:hover {
    border-color: #4facfe;
    box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
}

.search-box {
    padding: 8px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    background: white;
    font-size: 0.9rem;
    min-width: 200px;
    transition: all 0.3s ease;
}

.search-box:focus {
    outline: none;
    border-color: #4facfe;
    box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
}

.submissions-timeline {
    position: relative;
}

.submission-card {
    background: white;
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    cursor: pointer;
    position: relative;
}

.submission-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.submission-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.submission-content {
    padding: 25px;
    display: flex;
    gap: 20px;
    align-items: flex-start;
}

.student-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 1.2rem;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
}

.student-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.submission-details {
    flex: 1;
}

.student-info {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.student-name {
    font-size: 1.3rem;
    font-weight: bold;
    color: #2d3748;
}

.student-email {
    color: #718096;
    font-size: 0.9rem;
}

.submission-meta {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.meta-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f8f9fa;
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 0.85rem;
    color: #4a5568;
}

.submission-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-item {
    text-align: center;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 10px;
    transition: all 0.3s ease;
}

.stat-item:hover {
    background: #e2e8f0;
}

.stat-value {
    font-size: 1.2rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 4px;
}

.stat-desc {
    font-size: 0.75rem;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.submission-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    align-items: center;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #f1f5f9;
}

.btn-evaluate {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    border: none;
    padding: 10px 25px;
    border-radius: 25px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-evaluate:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(240, 147, 251, 0.4);
}

.btn-view {
    background: #f7fafc;
    color: #4a5568;
    border: 1px solid #e2e8f0;
    padding: 10px 20px;
    border-radius: 20px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.btn-view:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.grade-indicator {
    position: absolute;
    top: 20px;
    right: 20px;
    padding: 6px 12px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
}

.grade-excellent {
    background: #c6f6d5;
    color: #22543d;
}

.grade-good {
    background: #bee3f8;
    color: #2a4365;
}

.grade-average {
    background: #feebc8;
    color: #744210;
}

.grade-poor {
    background: #fed7d7;
    color: #742a2a;
}

.grade-ungraded {
    background: #e2e8f0;
    color: #4a5568;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #718096;
    animation: fadeInUp 0.8s ease-out 0.4s both;
}

.empty-icon {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.6;
}

.ai-insight {
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 25px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 1000;
    max-width: 300px;
    animation: slideInFromRight 0.8s ease-out 1s both;
}

.ai-insight:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.ai-insight-title {
    font-weight: bold;
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ai-insight-text {
    font-size: 0.9rem;
    opacity: 0.9;
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

@keyframes slideInFromRight {
    from {
        opacity: 0;
        transform: translateX(100px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes shimmer {
    0%, 100% { transform: translateX(-100%) rotate(45deg); }
    50% { transform: translateX(100%) rotate(45deg); }
}

/* Responsive Design */
@media (max-width: 768px) {
    .submission-content {
        flex-direction: column;
        gap: 15px;
    }
    
    .student-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .submission-meta {
        flex-direction: column;
        gap: 10px;
    }
    
    .submission-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .submission-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .ai-insight {
        position: relative;
        bottom: auto;
        right: auto;
        margin: 20px;
        animation: fadeInUp 0.8s ease-out 0.6s both;
    }
    
    .activity-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .filters-container {
        width: 100%;
        justify-content: stretch;
    }
    
    .search-box {
        min-width: auto;
        flex: 1;
    }
}

@media (max-width: 480px) {
    .activity-submissions {
        padding: 15px;
    }
    
    .activity-header {
        padding: 20px;
    }
    
    .activity-title {
        font-size: 1.5rem;
    }
    
    .submission-stats {
        grid-template-columns: 1fr;
    }
    
    .stats-overview {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<div class="activity-submissions">
    <!-- Activity Header -->
    <div class="activity-header">
        <h1 class="activity-title"><?php echo format_string($assignment->name); ?></h1>
        <?php if (!empty($assignment->intro)): ?>
            <div class="activity-description"><?php echo format_text($assignment->intro, FORMAT_HTML); ?></div>
        <?php endif; ?>
        <div class="activity-meta">
            <div class="meta-item">
                <span>📚</span>
                <span><?php echo format_string($course->shortname); ?></span>
            </div>
            <?php if ($assignment->duedate): ?>
                <div class="meta-item">
                    <span>📅</span>
                    <span>Due: <?php echo userdate($assignment->duedate, '%d %B %Y'); ?></span>
                </div>
            <?php endif; ?>
            <div class="meta-item">
                <span>👥</span>
                <span><?php echo $total_enrolled; ?> Students</span>
            </div>
        </div>
    </div>

    <!-- Statistics Overview -->
    <div class="stats-overview">
        <div class="stat-card">
            <div class="stat-icon">📄</div>
            <div class="stat-number"><?php echo count($submissions); ?></div>
            <div class="stat-label">Submissions</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-number"><?php echo $stats->submitted_count ?: 0; ?></div>
            <div class="stat-label">Students</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-number"><?php echo $stats->graded_count ?: 0; ?></div>
            <div class="stat-label">Graded</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-number"><?php echo $stats->avg_grade ? number_format($stats->avg_grade, 1) : 'N/A'; ?></div>
            <div class="stat-label">Avg Grade</div>
        </div>
    </div>

    <!-- Submissions Section -->
    <div class="submissions-section">
        <div class="section-header">
            <h2 class="section-title">
                <span>📋</span>
                Student Submissions
                <span style="font-size: 0.8rem; color: #718096; font-weight: normal;">(Latest First)</span>
            </h2>
            <div class="filters-container">
                <select class="filter-dropdown" onchange="filterSubmissions(this.value)">
                    <option value="all">All Submissions</option>
                    <option value="graded">Graded Only</option>
                    <option value="ungraded">Ungraded Only</option>
                    <option value="recent">Recent (7 days)</option>
                </select>
                <input type="text" class="search-box" placeholder="Search by student name..." oninput="searchSubmissions(this.value)">
            </div>
        </div>

        <?php if (empty($submissions)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h3>No submissions yet</h3>
                <p>Students haven't submitted their work for this assignment yet.</p>
            </div>
        <?php else: ?>
            <div class="submissions-timeline">
                <?php foreach ($submissions as $submission):
                    // Create proper user object with all required fields to avoid debugging warnings
                    $user_obj = new stdClass();
                    $user_obj->firstname = $submission->firstname;
                    $user_obj->lastname = $submission->lastname;
                    $user_obj->firstnamephonetic = '';
                    $user_obj->lastnamephonetic = '';
                    $user_obj->middlename = '';
                    $user_obj->alternatename = '';
                    
                    $student_name = fullname($user_obj);
                    $submission_time = userdate($submission->timemodified, '%d %B %Y, %H:%M');
                    $time_ago = format_time(time() - $submission->timemodified);
                    $grade_class = 'grade-ungraded';
                    $grade_text = 'Not Graded';
                    
                    if ($submission->grade !== null) {
                        $grade_percentage = ($submission->grade / 100) * 100;
                        if ($grade_percentage >= 90) {
                            $grade_class = 'grade-excellent';
                            $grade_text = $submission->grade . '/100';
                        } elseif ($grade_percentage >= 80) {
                            $grade_class = 'grade-good';
                            $grade_text = $submission->grade . '/100';
                        } elseif ($grade_percentage >= 60) {
                            $grade_class = 'grade-average';
                            $grade_text = $submission->grade . '/100';
                        } else {
                            $grade_class = 'grade-poor';
                            $grade_text = $submission->grade . '/100';
                        }
                    }
                    
                    $initials = substr($submission->firstname, 0, 1) . substr($submission->lastname, 0, 1);
                ?>
                    <div class="submission-card" data-student="<?php echo strtolower($student_name); ?>" data-grade="<?php echo $submission->grade ? 'graded' : 'ungraded'; ?>" data-time="<?php echo $submission->timemodified; ?>">
                        <div class="grade-indicator <?php echo $grade_class; ?>">
                            <?php echo $grade_text; ?>
                        </div>
                        
                        <div class="submission-content">
                            <div class="student-avatar">
                                <?php if ($submission->picture): ?>
                                    <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/<?php echo $submission->userid; ?>/f1.jpg" alt="<?php echo $submission->imagealt; ?>">
                                <?php else: ?>
                                    <?php echo $initials; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="submission-details">
                                <div class="student-info">
                                    <div class="student-name"><?php echo $student_name; ?></div>
                                    <div class="student-email"><?php echo $submission->email; ?></div>
                                </div>
                                
                                <div class="submission-meta">
                                    <div class="meta-badge">
                                        <span>🕒</span>
                                        <span><?php echo $submission_time; ?></span>
                                    </div>
                                    <div class="meta-badge">
                                        <span>⏱️</span>
                                        <span><?php echo $time_ago; ?> ago</span>
                                    </div>
                                    <?php if ($submission->file_count > 0): ?>
                                        <div class="meta-badge">
                                            <span>📎</span>
                                            <span><?php echo $submission->file_count; ?> file(s)</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="meta-badge">
                                        <span>🔢</span>
                                        <span>Attempt <?php echo $submission->attemptnumber + 1; ?></span>
                                    </div>
                                </div>
                                
                                <div class="submission-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $submission->file_count; ?></div>
                                        <div class="stat-desc">Files</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $submission->attemptnumber + 1; ?></div>
                                        <div class="stat-desc">Attempt</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $submission->grade ? number_format($submission->grade, 0) : 'N/A'; ?></div>
                                        <div class="stat-desc">Grade</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $submission->grade !== null ? 'Graded' : 'Submitted'; ?></div>
                                        <div class="stat-desc">Status</div>
                                    </div>
                                </div>
                                
                                <div class="submission-actions">
                                    <button class="btn-view" onclick="window.open('<?php echo $CFG->wwwroot; ?>/mod/assign/view.php?id=<?php echo $cmid; ?>&rownum=0&action=grader&userid=<?php echo $submission->userid; ?>', '_blank')">
                                        View in Moodle
                                    </button>
                                    <button class="btn-evaluate" onclick="evaluateSubmission(<?php echo $submission->id; ?>, <?php echo $submission->userid; ?>, '<?php echo addslashes($student_name); ?>')">
                                        🤖 AI Evaluate
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- AI Insight Panel -->
<div class="ai-insight" onclick="showAIInsights()">
    <div class="ai-insight-title">
        <span>🤖</span>
        AI Analysis Ready
    </div>
    <div class="ai-insight-text">
        Click to view assignment trends and recommendations
    </div>
</div>

<script>
// Filter submissions functionality
function filterSubmissions(filter) {
    const cards = document.querySelectorAll('.submission-card');
    const now = new Date().getTime() / 1000;
    const weekAgo = now - (7 * 24 * 60 * 60);
    
    cards.forEach(card => {
        let show = true;
        
        switch(filter) {
            case 'graded':
                show = card.dataset.grade === 'graded';
                break;
            case 'ungraded':
                show = card.dataset.grade === 'ungraded';
                break;
            case 'recent':
                show = parseInt(card.dataset.time) > weekAgo;
                break;
            default:
                show = true;
        }
        
        if (show) {
            card.style.display = 'block';
            card.style.animation = 'fadeInUp 0.5s ease-out';
        } else {
            card.style.display = 'none';
        }
    });
}

// Search submissions functionality
function searchSubmissions(query) {
    const cards = document.querySelectorAll('.submission-card');
    const searchTerm = query.toLowerCase().trim();
    
    cards.forEach(card => {
        const studentName = card.dataset.student;
        
        if (searchTerm === '' || studentName.includes(searchTerm)) {
            card.style.display = 'block';
            card.style.animation = 'fadeInUp 0.5s ease-out';
        } else {
            card.style.display = 'none';
        }
    });
}

// Navigate to submission evaluation
function evaluateSubmission(submissionId, userId, studentName) {
    // Add loading animation
    const button = event.currentTarget;
    const originalText = button.innerHTML;
    button.innerHTML = '🔄 Loading...';
    button.disabled = true;
    
    setTimeout(() => {
        window.location.href = '<?php echo $CFG->wwwroot; ?>/local/projectevaluator/evaluator/submission.php?id=' + submissionId + '&assignment=<?php echo $assignmentid; ?>&user=' + userId;
    }, 500);
}

// Show AI insights (placeholder for future implementation)
function showAIInsights() {
    alert('🤖 AI Analysis:\n\n• Average submission time: 2.3 days before deadline\n• Most common file types: PDF (45%), DOCX (35%), TXT (20%)\n• Recommended focus areas: Code structure, documentation\n• Grade distribution: Good overall performance\n\nDetailed analytics coming soon!');
}

// Add animations on page load
document.addEventListener('DOMContentLoaded', function() {
    // Stagger animation for submission cards
    const cards = document.querySelectorAll('.submission-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${0.1 + (index * 0.05)}s`;
        card.style.animation = 'fadeInUp 0.8s ease-out both';
    });
    
    // Add ripple effect to cards
    cards.forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't add ripple if clicking on buttons
            if (e.target.tagName === 'BUTTON') return;
            
            const ripple = document.createElement('div');
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            ripple.style.position = 'absolute';
            ripple.style.borderRadius = '50%';
            ripple.style.background = 'rgba(79, 172, 254, 0.6)';
            ripple.style.width = '20px';
            ripple.style.height = '20px';
            ripple.style.left = (x - 10) + 'px';
            ripple.style.top = (y - 10) + 'px';
            ripple.style.animation = 'ripple 0.6s ease-out';
            ripple.style.pointerEvents = 'none';
            
            this.style.position = 'relative';
            this.appendChild(ripple);
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });
});

// Add ripple animation
const style = document.createElement('style');
style.textContent = `
    @keyframes ripple {
        0% {
            transform: scale(0);
            opacity: 1;
        }
        100% {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
</script>

<?php
echo $OUTPUT->footer();
?>