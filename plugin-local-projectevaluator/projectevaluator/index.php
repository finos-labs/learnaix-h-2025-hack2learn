<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->libdir . '/accesslib.php');

require_login();
require_capability('local/projectevaluator:view', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/projectevaluator/index.php');
$PAGE->set_title(get_string('pluginname', 'local_projectevaluator'));
$PAGE->set_heading(get_string('pluginname', 'local_projectevaluator'));



class project_form extends moodleform {
    public function definition() {
        global $USER, $DB;
        $mform = $this->_form;

        // Course selector.
        $all_courses = enrol_get_my_courses();
        $courseoptions = [];
        foreach ($all_courses as $course) {
            $context = context_course::instance($course->id);
            if (has_capability('moodle/course:manageactivities', $context)) {
                $courseoptions[$course->id] = format_string($course->fullname);
            }
        }
        $mform->addElement('select', 'courseid', get_string('course'), $courseoptions);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $mform->addElement('text', 'topics', get_string('topics', 'local_projectevaluator'));
        $mform->setType('topics', PARAM_TEXT);
        $mform->addRule('topics', null, 'required', null, 'client');

        $mform->addElement('filemanager', 'topic_docs', get_string('topic_docs', 'local_projectevaluator'), null,
            array('subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 5,
                  'accepted_types' => array('.doc', '.docx', '.pdf', '.txt')));
        
        $mform->addElement('select', 'complexity', get_string('complexity', 'local_projectevaluator'),
            array('Easy' => 'Easy', 'Medium' => 'Medium', 'Hard' => 'Hard'));

        $this->add_action_buttons(true, get_string('generate_project', 'local_projectevaluator'));
    }
}

class activity_form extends moodleform {
    public function definition() {
        global $DB, $CFG;
        require_once($CFG->dirroot.'/course/lib.php');
        
        $mform = $this->_form;

        $mform->addElement('hidden', 'project_description');
        $mform->setType('project_description', PARAM_RAW);
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        // Get the course ID from the form data
        $courseid = optional_param('courseid', 0, PARAM_INT);
        if (!empty($courseid)) {
            // Validate course exists and user has permission
            if (!$course = $DB->get_record('course', array('id' => $courseid))) {
                throw new moodle_exception('invalidcourseid');
            }
            
            $context = context_course::instance($courseid);
            require_capability('moodle/course:manageactivities', $context);
            
            // Get all sections in the course
            $modinfo = get_fast_modinfo($course);
            $sections = $modinfo->get_section_info_all();
            
            $section_options = array();
            foreach ($sections as $section) {
                $section_name = get_section_name($course, $section);
                $section_options[$section->section] = $section_name;
            }
            
            // Add section selector
            $mform->addElement('select', 'section', get_string('section', 'local_projectevaluator'), $section_options);
            $mform->addHelpButton('section', 'section', 'local_projectevaluator');
            $mform->setDefault('section', 0);
        }

        $mform->addElement('text', 'title', get_string('activity_title', 'local_projectevaluator'));
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', null, 'maxlength', 255, 'client');
        
        $mform->addElement('date_time_selector', 'duedate', get_string('duedate', 'local_projectevaluator'));

        $this->add_action_buttons(true, get_string('create_activity', 'local_projectevaluator'));
    }
}

$mform = new project_form();
$activityform = new activity_form();

if ($mform->is_cancelled()) {
    redirect($CFG->wwwroot);
} else if ($data = $mform->get_data()) {
    $topics = $data->topics;
    $complexity = $data->complexity;
    $courseid = $data->courseid;

    // Validate course permissions
    $context = context_course::instance($courseid);
    require_capability('moodle/course:manageactivities', $context);

    // Process uploaded files if any
    $file_contents = [];
    if (!empty($data->topic_docs)) {
        $fs = get_file_storage();
        $context = context_user::instance($USER->id);
        $files = $fs->get_area_files($context->id, 'user', 'draft', $data->topic_docs, 'filename', false);
        
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $content = $file->get_content();
            
            // Process different file types
            $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $extracted_content = '';
            
            switch ($file_extension) {
                case 'txt':
                    $extracted_content = $content;
                    break;
                case 'pdf':
                    // For PDF, we'll send the content as base64 and let backend handle it
                    $extracted_content = base64_encode($content);
                    break;
                case 'doc':
                case 'docx':
                    // For Word docs, we'll send as base64 and let backend handle it
                    $extracted_content = base64_encode($content);
                    break;
                default:
                    $extracted_content = $content; // Try to read as text
            }
            
            if (!empty($extracted_content)) {
                $file_contents[] = [
                    'filename' => $filename,
                    'content' => $extracted_content,
                    'type' => $file_extension
                ];
            }
        }
    }

    // Call the FastAPI backend
    $curl = curl_init();
    $url = 'http://localhost:8001/generate-project/';
    $post_data = json_encode([
        'topics' => $topics, 
        'complexity' => $complexity,
        'documents' => $file_contents
    ]);

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($curl, CURLOPT_TIMEOUT, 120);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);

    echo $OUTPUT->header();

    if ($curl_error) {
        echo $OUTPUT->notification("Connection error: " . $curl_error, 'error');
        debugging("Backend connection error: " . $curl_error, DEBUG_DEVELOPER);
        echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php'));
        echo $OUTPUT->footer();
        exit;
    }

    if ($httpcode == 200 && $response) {
        $response_data = json_decode($response, true);
        if (isset($response_data['project_description'])) {
            $project_description = $response_data['project_description'];
            
            // Use Moodle's built-in markdown processor to convert to HTML
            $formatted_html = format_text($project_description, FORMAT_MARKDOWN, array(
                'noclean' => true,
                'para' => true,
                'newlines' => true,
                'filter' => true
            ));
            
            echo $OUTPUT->box(get_string('generated_project', 'local_projectevaluator') . ':<br><br>' . $formatted_html);

            // Pre-fill and display the activity form
            $form_data = new stdClass();
            $form_data->project_description = $formatted_html; // Store HTML version
            $form_data->courseid = $courseid;
            $activityform->set_data($form_data);
            $activityform->display();

        } else {
            echo $OUTPUT->notification(get_string('error_parsing_response', 'local_projectevaluator'), 'error');
        }
    } else {
        echo $OUTPUT->notification(get_string('error_contacting_backend', 'local_projectevaluator') . ' (HTTP ' . $httpcode . ')', 'error');
        debugging("Backend HTTP error: " . $httpcode . ", Response: " . $response, DEBUG_DEVELOPER);
    }

    echo $OUTPUT->footer();
    exit;

} else if ($activitydata = $activityform->get_data()) {
    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');
    require_once($CFG->dirroot.'/mod/assign/lib.php');

    echo $OUTPUT->header();
    
    // Validate and get the course
    if (!$course = $DB->get_record('course', array('id' => $activitydata->courseid))) {
        echo $OUTPUT->notification('Course not found', 'error');
        echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php'));
        echo $OUTPUT->footer();
        exit;
    }

    // Check permissions
    $context = context_course::instance($course->id);
    require_capability('moodle/course:manageactivities', $context);

    // Check if activity name already exists in this course
    if ($DB->record_exists('assign', array('course' => $course->id, 'name' => $activitydata->title))) {
        echo $OUTPUT->notification('An assignment with this name already exists in the course', 'error');
        echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php'));
        echo $OUTPUT->footer();
        exit;
    }
    
    try {
        // Get the module record
        if (!$module = $DB->get_record('modules', array('name' => 'assign'))) {
            throw new moodle_exception('Assignment module is not installed');
        }
        
        // Create module info
        $moduleinfo = new stdClass();
        
        // Required fields
        $moduleinfo->modulename = 'assign';
        $moduleinfo->module = $module->id;
        $moduleinfo->course = $course->id;
        $moduleinfo->coursemodule = 0;
        $moduleinfo->section = $activitydata->section;
        $moduleinfo->instance = 0;
        $moduleinfo->add = 'assign';
        
        // Basic settings
        $moduleinfo->name = clean_param($activitydata->title, PARAM_TEXT);
        // Store HTML content directly - it's already converted from markdown
        $moduleinfo->intro = clean_param($activitydata->project_description, PARAM_RAW);
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->groupmode = NOGROUPS;
        $moduleinfo->groupingid = 0;
        
        // Assignment settings
        $moduleinfo->duedate = !empty($activitydata->duedate) ? $activitydata->duedate : 0;
        $moduleinfo->allowsubmissionsfromdate = time();
        $moduleinfo->cutoffdate = 0;
        $moduleinfo->gradingduedate = 0;
        $moduleinfo->grade = 100;
        $moduleinfo->gradecat = 0;
        $moduleinfo->timemodified = time();
        
        // Simple submission settings
        $moduleinfo->submissiondrafts = 0;
        $moduleinfo->requiresubmissionstatement = 0;
        $moduleinfo->sendnotifications = 0;
        $moduleinfo->sendlatenotifications = 0;
        $moduleinfo->teamsubmission = 0;
        $moduleinfo->requireallteammemberssubmit = 0;
        $moduleinfo->teamsubmissiongroupingid = 0;
        $moduleinfo->blindmarking = 0;
        $moduleinfo->hidegrader = 0;
        $moduleinfo->markingworkflow = 0;
        $moduleinfo->markingallocation = 0;
        $moduleinfo->preventsubmissionnotingroup = 0;
        
        // Enable basic submission types
        $moduleinfo->assignsubmission_onlinetext_enabled = 1;
        $moduleinfo->assignsubmission_onlinetext_wordlimit = 0;
        $moduleinfo->assignsubmission_onlinetext_wordlimitenabled = 0;
        $moduleinfo->assignsubmission_file_enabled = 1;
        $moduleinfo->assignsubmission_file_maxfiles = 10;
        $moduleinfo->assignsubmission_file_maxsizebytes = 1048576;
        $moduleinfo->assignsubmission_file_filetypes = '';
        
        // Enable basic feedback
        $moduleinfo->assignfeedback_comments_enabled = 1;
        $moduleinfo->assignfeedback_comments_commentinline = 0;
        $moduleinfo->assignfeedback_file_enabled = 0;
        
        // Completion settings
        $moduleinfo->completionexpected = 0;
        $moduleinfo->completionunlocked = 0;
        $moduleinfo->completiongradeitemnumber = null;
        $moduleinfo->availability = null;
        $moduleinfo->showavailability = 0;
        
        // Create the assignment
        $result = add_moduleinfo($moduleinfo, $course);
        
        if (!$result || !isset($result->coursemodule)) {
            throw new moodle_exception('Failed to create assignment');
        }
        
        // Rebuild course cache
        rebuild_course_cache($course->id, true);

        // Show success message
        echo $OUTPUT->notification('Assignment "' . s($moduleinfo->name) . '" created successfully!', 'success');
        
        // Create links
        $viewurl = new moodle_url('/mod/assign/view.php', array('id' => $result->coursemodule));
        $courseurl = new moodle_url('/course/view.php', array('id' => $course->id));
        
        echo html_writer::div(
            html_writer::link($viewurl, 'View Assignment', array('class' => 'btn btn-primary')) . ' ' .
            html_writer::link($courseurl, 'Return to Course', array('class' => 'btn btn-secondary')),
            'mt-3'
        );
        
    } catch (Exception $e) {
        echo $OUTPUT->notification('Error creating assignment: ' . $e->getMessage(), 'error');
        echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php'));
    }
    
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
?>