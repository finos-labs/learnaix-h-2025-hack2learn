<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->libdir . '/accesslib.php');

require_login();
require_capability('local/projectevaluator:view', context_system::instance());

// Get the selected service from URL parameter first
$selected_service = optional_param('service', '', PARAM_ALPHA);

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/projectevaluator/index.php', array('service' => $selected_service));
$PAGE->set_title(get_string('pluginname', 'local_projectevaluator'));
$PAGE->set_heading(get_string('pluginname', 'local_projectevaluator'));

// Service selection interface
function display_service_selection() {
    global $OUTPUT, $CFG;
    
    echo '<div style="max-width: 800px; margin: 20px auto; padding: 20px;">';
    echo '<div style="text-align: center; margin-bottom: 30px;">';
    echo '<h2 style="color: #2d3748; margin-bottom: 10px;">🤖 AI-Project Hub</h2>';
    echo '<p style="color: #718096; font-size: 16px;">Choose from our AI-powered project services</p>';
    echo '</div>';
    
    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px;">';
    
    // Project Generator AI Service
    echo '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 15px; padding: 25px; color: white; text-align: center; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease;" onclick="selectService(\'generator\')" onmouseover="this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 10px 25px rgba(102, 126, 234, 0.3)\'" onmouseout="this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\'">';
    echo '<div style="font-size: 48px; margin-bottom: 15px;">🚀</div>';
    echo '<h3 style="margin-bottom: 15px; font-size: 22px;">' . get_string('project_generator', 'local_projectevaluator') . '</h3>';
    echo '<p style="margin-bottom: 20px; opacity: 0.9; line-height: 1.5;">' . get_string('service_description_generator', 'local_projectevaluator') . '</p>';
    echo '<div style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 25px; display: inline-block; font-weight: bold;">Get Started →</div>';
    echo '</div>';
    
    // Project Evaluator AI Service  
    echo '<div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 15px; padding: 25px; color: white; text-align: center; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease;" onclick="selectService(\'evaluator\')" onmouseover="this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 10px 25px rgba(240, 147, 251, 0.3)\'" onmouseout="this.style.transform=\'scale(1)\'; this.style.boxShadow=\'none\'">';
    echo '<div style="font-size: 48px; margin-bottom: 15px;">🎯</div>';
    echo '<h3 style="margin-bottom: 15px; font-size: 22px;">' . get_string('project_evaluator', 'local_projectevaluator') . '</h3>';
    echo '<p style="margin-bottom: 20px; opacity: 0.9; line-height: 1.5;">' . get_string('service_description_evaluator', 'local_projectevaluator') . '</p>';
    echo '<div style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 25px; display: inline-block; font-weight: bold;">Get Started →</div>';
    echo '</div>';
    
    echo '</div>';
    echo '</div>';
    
    echo '<script>
    function selectService(service) {
        window.location.href = "' . $CFG->wwwroot . '/local/projectevaluator/index.php?service=" + service;
    }
    </script>';
}

// Navigation breadcrumb for services
function display_service_breadcrumb($service_name) {
    global $CFG;
    echo '<div style="margin: 15px 0; padding: 10px 0; border-bottom: 1px solid #e2e8f0;">';
    echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php" style="color: #4299e1; text-decoration: none; font-weight: 500;">← ' . get_string('back_to_services', 'local_projectevaluator') . '</a>';
    echo '<span style="margin: 0 10px; color: #a0aec0;">|</span>';
    echo '<span style="color: #2d3748; font-weight: 600;">' . $service_name . '</span>';
    echo '</div>';
}



class project_form extends moodleform {
    public function definition() {
        global $USER, $DB;
        $mform = $this->_form;

        // Add hidden service field to maintain state
        $mform->addElement('hidden', 'service', 'generator');
        $mform->setType('service', PARAM_ALPHA);

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

        // Add hidden service field to maintain state
        $mform->addElement('hidden', 'service', 'generator');
        $mform->setType('service', PARAM_ALPHA);
        
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

// Handle service routing
if (empty($selected_service)) {
    // Show service selection interface
    echo $OUTPUT->header();
    display_service_selection();
    echo $OUTPUT->footer();
    exit;
} else if ($selected_service === 'generator') {
    // Handle Project Generator AI service
    if ($mform->is_cancelled()) {
        redirect($CFG->wwwroot . '/local/projectevaluator/index.php');
    } else if ($data = $mform->get_data()) {
        // Existing project generation logic continues here...
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
            
            
             echo '<div style="display: none;">';
            echo $OUTPUT->box(get_string('generated_project', 'local_projectevaluator') . ':<br><br>' . $formatted_html);
            echo '</div>';
            // Inline editing functionality - edit directly in the description box
            echo '<div style="margin: 20px 0; position: relative;">';
            echo '<div style="background: #f8f9fa; border: 2px solid #dee2e6; border-radius: 8px; padding: 0; overflow: hidden;">';
            
            // Header with title and edit button
            echo '<div style="background: #e9ecef; padding: 15px; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center;">';
            echo '<h4 style="margin: 0; color: #495057;">📋 Generated Project Description - Review & Edit</h4>';
            echo '<button id="edit-toggle-btn" class="btn btn-sm btn-outline-primary" style="font-size: 12px;">✏️ Edit</button>';
            echo '</div>';
            
            // Content area that becomes editable
            echo '<div id="description-content" style="padding: 20px; background: white; min-height: 200px; cursor: text; position: relative;" onclick="enableEdit()">';
            echo '<div id="description-display">' . $formatted_html . '</div>';
            echo '<textarea id="description-editor" style="display: none; width: 100%; height: 300px; border: none; resize: vertical; padding: 0; font-family: monospace; background: transparent; outline: none;">' . 
                 htmlspecialchars($project_description, ENT_QUOTES, 'UTF-8') . '</textarea>';
            echo '</div>';
            
            // Action buttons (always visible)
            echo '<div id="edit-actions" style="background: #f8f9fa; padding: 15px; border-top: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center;">';
            echo '<div>';
            echo '<button id="save-btn" class="btn btn-success btn-sm" style="margin-right: 10px;" disabled>💾 Save Changes</button>';
            echo '<button id="cancel-btn" class="btn btn-outline-secondary btn-sm">🔄 Reset</button>';
            echo '</div>';
            echo '<div style="color: #6c757d; font-size: 12px;" id="edit-status">No changes made</div>';
            echo '</div>';
            
            // Status message
            echo '<div id="status-message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px;"></div>';
            echo '</div>';
            echo '</div>';
            
            echo '<script>
            (function() {
                var editToggleBtn = document.getElementById("edit-toggle-btn");
                var descriptionContent = document.getElementById("description-content");
                var descriptionDisplay = document.getElementById("description-display");
                var descriptionEditor = document.getElementById("description-editor");
                var editActions = document.getElementById("edit-actions");
                var saveBtn = document.getElementById("save-btn");
                var cancelBtn = document.getElementById("cancel-btn");
                var statusMessage = document.getElementById("status-message");
                var editStatus = document.getElementById("edit-status");
                
                var isEditing = false;
                var originalContent = descriptionEditor.value;
                var originalHtml = descriptionDisplay.innerHTML;
                var hasChanges = false;
                
                // Simple markdown to HTML converter
                function markdownToHtml(text) {
                    var html = text
                        .replace(/\n\n/g, "</p><p>")
                        .replace(/\n/g, "<br>")
                        .replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>")
                        .replace(/\*(.*?)\*/g, "<em>$1</em>")
                        .replace(/^# (.*$)/gim, "<h1>$1</h1>")
                        .replace(/^## (.*$)/gim, "<h2>$1</h2>")
                        .replace(/^### (.*$)/gim, "<h3>$1</h3>")
                        .replace(/^#### (.*$)/gim, "<h4>$1</h4>")
                        .replace(/`(.*?)`/g, "<code>$1</code>")
                        .replace(/^- (.*$)/gim, "<li>$1</li>");
                    
                    // Wrap in paragraphs if not already formatted
                    if (!html.includes("<h") && !html.includes("<ul") && !html.includes("<p>")) {
                        html = "<p>" + html + "</p>";
                    }
                    
                    // Fix lists
                    html = html.replace(/(<li>.*?<\/li>)/gs, function(match) {
                        return match.replace(/<br>/g, "");
                    });
                    html = html.replace(/(<li>.*<\/li>)/s, "<ul>$1</ul>");
                    
                    return html;
                }
                
                function showStatus(message, type) {
                    statusMessage.style.display = "block";
                    statusMessage.textContent = message;
                    statusMessage.className = type === "success" ? "alert alert-success" : 
                                            type === "error" ? "alert alert-danger" : "alert alert-info";
                    setTimeout(function() {
                        statusMessage.style.display = "none";
                    }, 3000);
                }
                
                function updateEditStatus() {
                    var currentContent = descriptionEditor.value.trim();
                    hasChanges = currentContent !== originalContent;
                    
                    if (hasChanges) {
                        saveBtn.disabled = false;
                        saveBtn.className = "btn btn-success btn-sm";
                        saveBtn.style.opacity = "1";
                        editStatus.textContent = "Unsaved changes";
                        editStatus.style.color = "#dc3545";
                    } else {
                        saveBtn.disabled = true;
                        saveBtn.className = "btn btn-outline-success btn-sm";
                        saveBtn.style.opacity = "0.6";
                        editStatus.textContent = "No changes made";
                        editStatus.style.color = "#6c757d";
                    }
                }
                
                function enableEdit() {
                    if (isEditing) return;
                    
                    isEditing = true;
                    descriptionDisplay.style.display = "none";
                    descriptionEditor.style.display = "block";
                    editToggleBtn.textContent = "👁️ Preview";
                    editToggleBtn.className = "btn btn-sm btn-outline-info";
                    editToggleBtn.title = "Switch to preview mode";
                    descriptionContent.style.cursor = "default";
                    
                    // Focus the editor
                    descriptionEditor.focus();
                    
                    // Auto-resize textarea
                    descriptionEditor.style.height = "auto";
                    descriptionEditor.style.height = descriptionEditor.scrollHeight + "px";
                    
                    updateEditStatus();
                }
                
                function disableEdit() {
                    isEditing = false;
                    descriptionDisplay.style.display = "block";
                    descriptionEditor.style.display = "none";
                    editToggleBtn.textContent = "✏️ Edit";
                    editToggleBtn.className = "btn btn-sm btn-outline-primary";
                    editToggleBtn.title = "Switch to edit mode";
                    descriptionContent.style.cursor = "text";
                }
                
                // Edit toggle button click
                editToggleBtn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    if (isEditing) {
                        // Show preview
                        var currentText = descriptionEditor.value.trim();
                        if (currentText) {
                            var previewHtml = markdownToHtml(currentText);
                            descriptionDisplay.innerHTML = previewHtml;
                        }
                        disableEdit();
                    } else {
                        enableEdit();
                    }
                });
                
                // Auto-resize textarea and check for changes while typing
                descriptionEditor.addEventListener("input", function() {
                    this.style.height = "auto";
                    this.style.height = this.scrollHeight + "px";
                    updateEditStatus();
                });
                
                // Save button click
                saveBtn.addEventListener("click", function() {
                    if (!hasChanges) return;
                    
                    var newContent = descriptionEditor.value.trim();
                    if (newContent === "") {
                        showStatus("Description cannot be empty", "error");
                        return;
                    }
                    
                    // Convert to HTML
                    var htmlContent = markdownToHtml(newContent);
                    
                    // Update the display
                    descriptionDisplay.innerHTML = htmlContent;
                    
                    // Update the hidden form field
                    var hiddenField = document.querySelector("input[name=\'project_description\']");
                    if (hiddenField) {
                        hiddenField.value = htmlContent;
                    }
                    
                    // Store new content as original
                    originalContent = newContent;
                    originalHtml = htmlContent;
                    
                    // Update status
                    updateEditStatus();
                    
                    showStatus("✅ Description saved! Changes will be applied when creating the activity.", "success");
                });
                
                // Cancel/Reset button click
                cancelBtn.addEventListener("click", function() {
                    descriptionEditor.value = originalContent;
                    descriptionDisplay.innerHTML = originalHtml;
                    updateEditStatus();
                    if (isEditing) {
                        disableEdit();
                    }
                    showStatus("Changes reset to original", "info");
                });
                
                // Click anywhere in content area to edit
                window.enableEdit = enableEdit;
                
                // Prevent clicks on display from bubbling when editing
                descriptionDisplay.addEventListener("click", function(e) {
                    if (!isEditing) {
                        enableEdit();
                    }
                });
                
                // Add hover effect
                descriptionContent.addEventListener("mouseenter", function() {
                    if (!isEditing) {
                        this.style.background = "#f8f9fa";
                        this.style.borderColor = "#007bff";
                    }
                });
                
                descriptionContent.addEventListener("mouseleave", function() {
                    if (!isEditing) {
                        this.style.background = "white";
                        this.style.borderColor = "#dee2e6";
                    }
                });
                
                // Initialize the status
                updateEditStatus();
            })();
            </script>';

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
        // Handle activity creation within the generator service
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot.'/course/lib.php');
        require_once($CFG->dirroot.'/mod/assign/lib.php');

        echo $OUTPUT->header();
        display_service_breadcrumb(get_string('project_generator', 'local_projectevaluator'));
        
        // Validate and get the course
        if (!$course = $DB->get_record('course', array('id' => $activitydata->courseid))) {
            echo $OUTPUT->notification('Course not found', 'error');
            echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php?service=generator'));
            echo $OUTPUT->footer();
            exit;
        }

        // Check permissions
        $context = context_course::instance($course->id);
        require_capability('moodle/course:manageactivities', $context);

        // Check if activity name already exists in this course
        if ($DB->record_exists('assign', array('course' => $course->id, 'name' => $activitydata->title))) {
            echo $OUTPUT->notification('An assignment with this name already exists in the course', 'error');
            echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php?service=generator'));
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
            echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php?service=generator'));
        }
        
        echo $OUTPUT->footer();
        exit;
    } else {
        // Show the project generator form
        echo $OUTPUT->header();
        display_service_breadcrumb(get_string('project_generator', 'local_projectevaluator'));
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }
} else if ($selected_service === 'evaluator') {
    // Redirect to evaluator dashboard
    redirect($CFG->wwwroot . '/local/projectevaluator/evaluator/dashboard.php');
}

// Should not reach here - redirect to service selection
redirect($CFG->wwwroot . '/local/projectevaluator/index.php');
?>