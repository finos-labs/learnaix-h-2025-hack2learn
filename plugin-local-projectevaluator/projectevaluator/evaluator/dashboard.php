<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/accesslib.php');

require_login();
require_capability('local/projectevaluator:view', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/projectevaluator/evaluator/dashboard.php');
$PAGE->set_title(get_string('project_evaluator', 'local_projectevaluator'));
$PAGE->set_heading(get_string('project_evaluator', 'local_projectevaluator'));

// Include custom CSS and JavaScript
$PAGE->requires->css('/local/projectevaluator/evaluator/styles.css');

echo $OUTPUT->header();

// Navigation breadcrumb
echo '<div style="margin: 15px 0; padding: 10px 0; border-bottom: 1px solid #e2e8f0;">';
echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php" style="color: #4299e1; text-decoration: none; font-weight: 500;">← ' . get_string('back_to_services', 'local_projectevaluator') . '</a>';
echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
echo '<span style="color: #2d3748; font-weight: 600;">🎯 AI Project Evaluator</span>';
echo '</div>';

// Get all courses where user is enrolled as teacher
global $USER, $DB;
$all_courses = enrol_get_my_courses();
$teacher_courses = [];

foreach ($all_courses as $course) {
    $context = context_course::instance($course->id);
    if (has_capability('moodle/course:manageactivities', $context)) {
        // Get course statistics with all enrolled students
        $sql = "SELECT COUNT(DISTINCT cm.id) as activity_count,
                       COUNT(DISTINCT s.id) as submission_count
                FROM {course_modules} cm
                LEFT JOIN {assign} a ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
                LEFT JOIN {assign_submission} s ON a.id = s.assignment AND s.status = 'submitted'
                WHERE cm.course = ? AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')";
        
        // Get all enrolled students in this course (excluding teachers and managers)
        $student_sql = "SELECT COUNT(DISTINCT ue.userid) as student_count
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON ue.enrolid = e.id
                        JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
                        JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                        JOIN {role} r ON ra.roleid = r.id
                        WHERE e.courseid = ? 
                        AND r.shortname IN ('student')
                        AND ue.status = 0";
        
        $stats = $DB->get_record_sql($sql, [$course->id]);
        $student_stats = $DB->get_record_sql($student_sql, [$course->id]);
        
        // Combine the statistics
        $stats->student_count = $student_stats->student_count ?: 0;
        
        $teacher_courses[] = [
            'course' => $course,
            'stats' => $stats
        ];
    }
}

?>

<style>
/* Modern Animated Dashboard Styles */
.evaluator-dashboard {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.dashboard-header {
    text-align: center;
    margin-bottom: 40px;
    animation: slideInFromTop 0.8s ease-out;
}

.dashboard-title {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 10px;
}

.dashboard-subtitle {
    color: #718096;
    font-size: 1.2rem;
    margin-bottom: 30px;
}

.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
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
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.stat-icon {
    font-size: 2.5rem;
    margin-bottom: 10px;
}

.stat-number {
    font-size: 2rem;
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

.courses-section {
    animation: fadeInUp 0.8s ease-out 0.4s both;
}

.section-title {
    font-size: 1.8rem;
    color: #2d3748;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 25px;
}

.course-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
    cursor: pointer;
    position: relative;
}

.course-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
}

.course-header {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    padding: 25px;
    position: relative;
    overflow: hidden;
}

.course-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.1);
    transform: rotate(45deg);
    transition: all 0.3s ease;
}

.course-card:hover .course-header::before {
    right: -20%;
}

.course-name {
    font-size: 1.3rem;
    font-weight: bold;
    margin-bottom: 8px;
    position: relative;
    z-index: 2;
}

.course-code {
    opacity: 0.9;
    font-size: 0.9rem;
    position: relative;
    z-index: 2;
}

.course-stats {
    padding: 25px;
    background: #f8f9fa;
}

.stats-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.stats-row:last-child {
    margin-bottom: 0;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #4a5568;
}

.stat-badge {
    background: #e2e8f0;
    color: #2d3748;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.action-button {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 25px;
    border-radius: 25px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
    margin-top: 15px;
    font-size: 0.95rem;
}

.action-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
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

.floating-action {
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    border: none;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    font-size: 1.5rem;
    box-shadow: 0 4px 15px rgba(240, 147, 251, 0.4);
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 1000;
}

.floating-action:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(240, 147, 251, 0.6);
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

/* Responsive Design */
@media (max-width: 768px) {
    .dashboard-title {
        font-size: 2rem;
    }
    
    .courses-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-overview {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .floating-action {
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
        bottom: 20px;
        right: 20px;
    }
}

@media (max-width: 480px) {
    .stats-overview {
        grid-template-columns: 1fr;
    }
    
    .course-card {
        margin: 0 10px;
    }
}

/* Loading animation */
.loading-skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>

<div class="evaluator-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <h1 class="dashboard-title">🎯 AI Project Evaluator</h1>
        <p class="dashboard-subtitle">Intelligent assessment and feedback for student projects</p>
    </div>

    <?php
    // Calculate total statistics
    $total_courses = count($teacher_courses);
    $total_activities = array_sum(array_column(array_column($teacher_courses, 'stats'), 'activity_count'));
    $total_submissions = array_sum(array_column(array_column($teacher_courses, 'stats'), 'submission_count'));
    
    // Get distinct total students across all teacher courses
    if (!empty($teacher_courses)) {
        $course_ids = array_column(array_column($teacher_courses, 'course'), 'id');
        $course_ids_placeholder = implode(',', array_fill(0, count($course_ids), '?'));
        
        $distinct_students_sql = "SELECT COUNT(DISTINCT ue.userid) as total_distinct_students
                                  FROM {user_enrolments} ue
                                  JOIN {enrol} e ON ue.enrolid = e.id
                                  JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
                                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                                  JOIN {role} r ON ra.roleid = r.id
                                  WHERE e.courseid IN ($course_ids_placeholder)
                                  AND r.shortname IN ('student')
                                  AND ue.status = 0";
        
        $total_students_result = $DB->get_record_sql($distinct_students_sql, $course_ids);
        $total_students = $total_students_result->total_distinct_students ?: 0;
    } else {
        $total_students = 0;
    }
    ?>

    <!-- Statistics Overview -->
    <div class="stats-overview">
        <div class="stat-card">
            <div class="stat-icon">📚</div>
            <div class="stat-number"><?php echo $total_courses; ?></div>
            <div class="stat-label">Courses</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <div class="stat-number"><?php echo $total_activities; ?></div>
            <div class="stat-label">Activities</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📄</div>
            <div class="stat-number"><?php echo $total_submissions; ?></div>
            <div class="stat-label">Submissions</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-number"><?php echo $total_students; ?></div>
            <div class="stat-label">Students</div>
        </div>
    </div>

    <!-- Courses Section -->
    <div class="courses-section">
        <h2 class="section-title">
            <span>📖</span>
            Your Courses
        </h2>

        <?php if (empty($teacher_courses)): ?>
            <div class="empty-state">
                <div class="empty-icon">📚</div>
                <h3>No courses found</h3>
                <p>You don't have any courses with teacher privileges yet.</p>
            </div>
        <?php else: ?>
            <div class="courses-grid">
                <?php foreach ($teacher_courses as $course_data): 
                    $course = $course_data['course'];
                    $stats = $course_data['stats'];
                ?>
                    <div class="course-card" onclick="navigateToCourse(<?php echo $course->id; ?>)">
                        <div class="course-header">
                            <div class="course-name"><?php echo format_string($course->fullname); ?></div>
                            <div class="course-code"><?php echo $course->shortname; ?></div>
                        </div>
                        <div class="course-stats">
                            <div class="stats-row">
                                <div class="stat-item">
                                    <span>📋</span>
                                    <span>Activities</span>
                                </div>
                                <div class="stat-badge"><?php echo $stats->activity_count ?: 0; ?></div>
                            </div>
                            <div class="stats-row">
                                <div class="stat-item">
                                    <span>📄</span>
                                    <span>Submissions</span>
                                </div>
                                <div class="stat-badge"><?php echo $stats->submission_count ?: 0; ?></div>
                            </div>
                            <div class="stats-row">
                                <div class="stat-item">
                                    <span>👥</span>
                                    <span>Students</span>
                                </div>
                                <div class="stat-badge"><?php echo $stats->student_count ?: 0; ?></div>
                            </div>
                            <button class="action-button" onclick="event.stopPropagation(); navigateToCourse(<?php echo $course->id; ?>)">
                                Evaluate Projects →
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Floating Action Button -->
<button class="floating-action" onclick="refreshData()" title="Refresh Data">
    🔄
</button>

<script>
// Navigation functions
function navigateToCourse(courseId) {
    // Add loading animation
    const card = event.currentTarget;
    card.style.opacity = '0.7';
    card.style.transform = 'scale(0.98)';
    
    setTimeout(() => {
        window.location.href = '<?php echo $CFG->wwwroot; ?>/local/projectevaluator/evaluator/course.php?id=' + courseId;
    }, 200);
}

function refreshData() {
    // Add rotation animation to the refresh button
    const btn = event.currentTarget;
    btn.style.transform = 'scale(1.1) rotate(360deg)';
    
    setTimeout(() => {
        location.reload();
    }, 500);
}

// Add hover effects and animations on page load
document.addEventListener('DOMContentLoaded', function() {
    // Stagger animation for course cards
    const cards = document.querySelectorAll('.course-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${0.6 + (index * 0.1)}s`;
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
            ripple.style.background = 'rgba(255, 255, 255, 0.6)';
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

// Add smooth scrolling for better UX
document.documentElement.style.scrollBehavior = 'smooth';

// Add intersection observer for scroll animations
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

// Observe all animated elements
document.querySelectorAll('.course-card, .stat-card').forEach(el => {
    observer.observe(el);
});
</script>

<?php
echo $OUTPUT->footer();
?>