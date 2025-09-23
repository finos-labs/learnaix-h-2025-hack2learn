<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/accesslib.php');

require_login();
require_capability('local/projectevaluator:view', context_system::instance());

$courseid = required_param('id', PARAM_INT);

// Validate course exists and user has permission
if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    throw new moodle_exception('invalidcourseid');
}

$context = context_course::instance($courseid);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/projectevaluator/evaluator/course.php', array('id' => $courseid));
$PAGE->set_title(get_string('project_evaluator', 'local_projectevaluator') . ' - ' . format_string($course->fullname));
$PAGE->set_heading(get_string('project_evaluator', 'local_projectevaluator'));

echo $OUTPUT->header();

// Navigation breadcrumb
echo '<div style="margin: 15px 0; padding: 10px 0; border-bottom: 1px solid #e2e8f0;">';
echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php" style="color: #4299e1; text-decoration: none; font-weight: 500;">← Services</a>';
echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/evaluator/dashboard.php" style="color: #4299e1; text-decoration: none; font-weight: 500;">Dashboard</a>';
echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
echo '<span style="color: #2d3748; font-weight: 600;">' . format_string($course->fullname) . '</span>';
echo '</div>';

// Get all assignments in this course with submission statistics
$sql = "SELECT a.id, a.name, a.intro, a.duedate, a.allowsubmissionsfromdate, cm.id as cmid,
               COUNT(DISTINCT s.userid) as submitted_count,
               COUNT(DISTINCT s.id) as submission_count,
               COUNT(DISTINCT u.id) as enrolled_count,
               MAX(s.timemodified) as latest_submission
        FROM {assign} a
        JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
        LEFT JOIN {assign_submission} s ON a.id = s.assignment AND s.status = 'submitted'
        LEFT JOIN {user_enrolments} ue ON ue.userid = s.userid
        LEFT JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = a.course
        LEFT JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
        WHERE a.course = ? AND cm.visible = 1
        GROUP BY a.id, a.name, a.intro, a.duedate, a.allowsubmissionsfromdate, cm.id
        ORDER BY a.duedate DESC, a.name ASC";

$activities = $DB->get_records_sql($sql, [$courseid]);

// Get total enrolled students count for this course
$enrolled_sql = "SELECT COUNT(DISTINCT u.id) as total_enrolled
                 FROM {user_enrolments} ue
                 JOIN {enrol} e ON e.id = ue.enrolid
                 JOIN {user} u ON u.id = ue.userid
                 WHERE e.courseid = ? AND u.deleted = 0 AND ue.status = 0";
$total_enrolled = $DB->get_field_sql($enrolled_sql, [$courseid]);

?>

<style>
/* Course Activities Page Styles */
.course-activities {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.course-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
    position: relative;
    overflow: hidden;
    animation: slideInFromTop 0.8s ease-out;
}

.course-header::before {
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

.course-title {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 10px;
    position: relative;
    z-index: 2;
}

.course-meta {
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

.activities-container {
    animation: fadeInUp 0.8s ease-out 0.2s both;
}

.section-header {
    display: flex;
    justify-content: between;
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

.view-toggle {
    display: flex;
    background: #f7fafc;
    border-radius: 25px;
    padding: 4px;
    border: 1px solid #e2e8f0;
}

.toggle-btn {
    padding: 8px 20px;
    border: none;
    background: transparent;
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 500;
}

.toggle-btn.active {
    background: white;
    color: #667eea;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.activities-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 25px;
}

.activities-list {
    display: none;
    flex-direction: column;
    gap: 15px;
}

.activity-card {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
    cursor: pointer;
    position: relative;
}

.activity-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 25px rgba(0, 0, 0, 0.15);
}

.activity-header {
    padding: 20px;
    border-bottom: 1px solid #f1f5f9;
    position: relative;
}

.activity-title {
    font-size: 1.2rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 8px;
    line-height: 1.3;
}

.activity-meta {
    display: flex;
    gap: 15px;
    font-size: 0.85rem;
    color: #718096;
    flex-wrap: wrap;
}

.meta-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    background: #f8f9fa;
    padding: 4px 10px;
    border-radius: 12px;
}

.activity-stats {
    padding: 20px;
    background: #f8f9fa;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 15px;
}

.stat-box {
    text-align: center;
    padding: 12px;
    background: white;
    border-radius: 10px;
    transition: all 0.3s ease;
}

.stat-box:hover {
    transform: scale(1.05);
}

.stat-number {
    font-size: 1.5rem;
    font-weight: bold;
    color: #4a5568;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.progress-bar {
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 15px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    transition: width 0.8s ease;
    border-radius: 3px;
}

.action-buttons {
    display: flex;
    gap: 10px;
}

.btn-primary {
    flex: 1;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 25px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.btn-secondary {
    background: #f7fafc;
    color: #4a5568;
    border: 1px solid #e2e8f0;
    padding: 10px 15px;
    border-radius: 20px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.btn-secondary:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.status-indicator {
    position: absolute;
    top: 15px;
    right: 15px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.status-active {
    background: #48bb78;
}

.status-upcoming {
    background: #ed8936;
}

.status-past {
    background: #e53e3e;
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

/* List view styles */
.activity-card.list-view {
    border-radius: 10px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
}

.activity-card.list-view .activity-header {
    flex: 1;
    padding: 0;
    border: none;
}

.activity-card.list-view .activity-stats {
    background: transparent;
    padding: 0;
    min-width: 300px;
}

.activity-card.list-view .stats-grid {
    grid-template-columns: repeat(3, 1fr);
    margin-bottom: 0;
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

@keyframes shimmer {
    0%, 100% { transform: translateX(-100%) rotate(45deg); }
    50% { transform: translateX(100%) rotate(45deg); }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Responsive Design */
@media (max-width: 768px) {
    .activities-grid {
        grid-template-columns: 1fr;
    }
    
    .course-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .activity-card.list-view {
        flex-direction: column;
        align-items: stretch;
    }
    
    .activity-card.list-view .activity-stats {
        min-width: auto;
    }
}

@media (max-width: 480px) {
    .course-activities {
        padding: 15px;
    }
    
    .course-header {
        padding: 20px;
    }
    
    .course-title {
        font-size: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="course-activities">
    <!-- Course Header -->
    <div class="course-header">
        <h1 class="course-title"><?php echo format_string($course->fullname); ?></h1>
        <div class="course-meta">
            <div class="meta-item">
                <span>📚</span>
                <span><?php echo $course->shortname; ?></span>
            </div>
            <div class="meta-item">
                <span>👥</span>
                <span><?php echo $total_enrolled; ?> Students</span>
            </div>
            <div class="meta-item">
                <span>📋</span>
                <span><?php echo count($activities); ?> Activities</span>
            </div>
        </div>
    </div>

    <!-- Activities Container -->
    <div class="activities-container">
        <div class="section-header">
            <h2 class="section-title">
                <span>📋</span>
                Assignment Activities
            </h2>
            <div class="view-toggle">
                <button class="toggle-btn active" onclick="switchView('grid')">Grid View</button>
                <button class="toggle-btn" onclick="switchView('list')">List View</button>
            </div>
        </div>

        <?php if (empty($activities)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h3>No assignments found</h3>
                <p>This course doesn't have any assignment activities yet.</p>
                <button onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/course/modedit.php?add=assign&type=&course=<?php echo $courseid; ?>&return=0&sr=0'" class="btn-primary" style="margin-top: 20px;">
                    Create First Assignment
                </button>
            </div>
        <?php else: ?>
            <div class="activities-grid" id="activities-grid">
                <?php foreach ($activities as $activity): 
                    $submission_rate = $total_enrolled > 0 ? ($activity->submitted_count / $total_enrolled) * 100 : 0;
                    $due_date = $activity->duedate ? userdate($activity->duedate, '%d %B %Y') : 'No due date';
                    $latest_submission = $activity->latest_submission ? userdate($activity->latest_submission, '%d %b, %H:%M') : 'No submissions';
                    
                    // Determine status
                    $status = 'active';
                    $status_text = 'Active';
                    if ($activity->duedate) {
                        if ($activity->duedate < time()) {
                            $status = 'past';
                            $status_text = 'Past Due';
                        } elseif ($activity->allowsubmissionsfromdate > time()) {
                            $status = 'upcoming';
                            $status_text = 'Upcoming';
                        }
                    }
                ?>
                    <div class="activity-card" onclick="navigateToActivity(<?php echo $activity->id; ?>, <?php echo $activity->cmid; ?>)">
                        <div class="status-indicator status-<?php echo $status; ?>" title="<?php echo $status_text; ?>"></div>
                        
                        <div class="activity-header">
                            <h3 class="activity-title"><?php echo format_string($activity->name); ?></h3>
                            <div class="activity-meta">
                                <div class="meta-badge">
                                    <span>📅</span>
                                    <span><?php echo $due_date; ?></span>
                                </div>
                                <div class="meta-badge">
                                    <span>🕒</span>
                                    <span><?php echo $latest_submission; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="activity-stats">
                            <div class="stats-grid">
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo $activity->submitted_count ?: 0; ?></div>
                                    <div class="stat-label">Submitted</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo $activity->submission_count ?: 0; ?></div>
                                    <div class="stat-label">Total Sub.</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo number_format($submission_rate, 0); ?>%</div>
                                    <div class="stat-label">Rate</div>
                                </div>
                            </div>
                            
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $submission_rate; ?>%;"></div>
                            </div>
                            
                            <div class="action-buttons">
                                <button class="btn-primary" onclick="event.stopPropagation(); navigateToActivity(<?php echo $activity->id; ?>, <?php echo $activity->cmid; ?>)">
                                    Evaluate Submissions
                                </button>
                                <button class="btn-secondary" onclick="event.stopPropagation(); window.open('<?php echo $CFG->wwwroot; ?>/mod/assign/view.php?id=<?php echo $activity->cmid; ?>', '_blank')">
                                    View
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="activities-list" id="activities-list" style="display: none;">
                <?php foreach ($activities as $activity): 
                    $submission_rate = $total_enrolled > 0 ? ($activity->submitted_count / $total_enrolled) * 100 : 0;
                    $due_date = $activity->duedate ? userdate($activity->duedate, '%d %B %Y') : 'No due date';
                    $latest_submission = $activity->latest_submission ? userdate($activity->latest_submission, '%d %b, %H:%M') : 'No submissions';
                    
                    // Determine status
                    $status = 'active';
                    if ($activity->duedate) {
                        if ($activity->duedate < time()) {
                            $status = 'past';
                        } elseif ($activity->allowsubmissionsfromdate > time()) {
                            $status = 'upcoming';
                        }
                    }
                ?>
                    <div class="activity-card list-view" onclick="navigateToActivity(<?php echo $activity->id; ?>, <?php echo $activity->cmid; ?>)">
                        <div class="status-indicator status-<?php echo $status; ?>"></div>
                        
                        <div class="activity-header">
                            <h3 class="activity-title"><?php echo format_string($activity->name); ?></h3>
                            <div class="activity-meta">
                                <div class="meta-badge">
                                    <span>📅</span>
                                    <span><?php echo $due_date; ?></span>
                                </div>
                                <div class="meta-badge">
                                    <span>🕒</span>
                                    <span><?php echo $latest_submission; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="activity-stats">
                            <div class="stats-grid">
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo $activity->submitted_count ?: 0; ?></div>
                                    <div class="stat-label">Submitted</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo $activity->submission_count ?: 0; ?></div>
                                    <div class="stat-label">Total Sub.</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo number_format($submission_rate, 0); ?>%</div>
                                    <div class="stat-label">Rate</div>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button class="btn-primary" onclick="event.stopPropagation(); navigateToActivity(<?php echo $activity->id; ?>, <?php echo $activity->cmid; ?>)">
                                Evaluate
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// View switching functionality
function switchView(view) {
    const gridView = document.getElementById('activities-grid');
    const listView = document.getElementById('activities-list');
    const buttons = document.querySelectorAll('.toggle-btn');
    
    buttons.forEach(btn => btn.classList.remove('active'));
    
    if (view === 'grid') {
        gridView.style.display = 'grid';
        listView.style.display = 'none';
        buttons[0].classList.add('active');
    } else {
        gridView.style.display = 'none';
        listView.style.display = 'flex';
        buttons[1].classList.add('active');
    }
    
    // Add animation
    const activeView = view === 'grid' ? gridView : listView;
    activeView.style.opacity = '0';
    activeView.style.transform = 'translateY(20px)';
    
    setTimeout(() => {
        activeView.style.opacity = '1';
        activeView.style.transform = 'translateY(0)';
    }, 50);
}

// Navigation function
function navigateToActivity(assignmentId, cmId) {
    // Add loading animation
    const card = event.currentTarget;
    card.style.opacity = '0.7';
    card.style.transform = 'scale(0.98)';
    
    setTimeout(() => {
        window.location.href = '<?php echo $CFG->wwwroot; ?>/local/projectevaluator/evaluator/activity.php?id=' + assignmentId + '&cmid=' + cmId;
    }, 200);
}

// Add animations on page load
document.addEventListener('DOMContentLoaded', function() {
    // Stagger animation for activity cards
    const cards = document.querySelectorAll('.activity-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${0.3 + (index * 0.1)}s`;
        card.style.animation = 'fadeInUp 0.8s ease-out both';
    });
    
    // Add ripple effect to cards
    cards.forEach(card => {
        card.addEventListener('click', function(e) {
            const ripple = document.createElement('div');
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            ripple.style.position = 'absolute';
            ripple.style.borderRadius = '50%';
            ripple.style.background = 'rgba(102, 126, 234, 0.6)';
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
    
    // Animate progress bars
    setTimeout(() => {
        document.querySelectorAll('.progress-fill').forEach(fill => {
            const width = fill.style.width;
            fill.style.width = '0%';
            setTimeout(() => {
                fill.style.width = width;
            }, 100);
        });
    }, 600);
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