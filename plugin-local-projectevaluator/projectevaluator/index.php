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
            
            // Action buttons (hidden by default)
            echo '<div id="edit-actions" style="display: none; background: #f8f9fa; padding: 15px; border-top: 1px solid #dee2e6; text-align: right;">';
            echo '<button id="save-btn" class="btn btn-success btn-sm" style="margin-right: 10px;">💾 Save Changes</button>';
            echo '<button id="cancel-btn" class="btn btn-outline-secondary btn-sm">❌ Cancel</button>';
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
                
                var isEditing = false;
                var originalContent = descriptionEditor.value;
                var originalHtml = descriptionDisplay.innerHTML;
                
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
                
                function enableEdit() {
                    if (isEditing) return;
                    
                    isEditing = true;
                    descriptionDisplay.style.display = "none";
                    descriptionEditor.style.display = "block";
                    editActions.style.display = "block";
                    editToggleBtn.textContent = "👁️ Preview";
                    editToggleBtn.className = "btn btn-sm btn-outline-info";
                    descriptionContent.style.cursor = "default";
                    
                    // Focus the editor
                    descriptionEditor.focus();
                    
                    // Auto-resize textarea
                    descriptionEditor.style.height = "auto";
                    descriptionEditor.style.height = descriptionEditor.scrollHeight + "px";
                }
                
                function disableEdit() {
                    isEditing = false;
                    descriptionDisplay.style.display = "block";
                    descriptionEditor.style.display = "none";
                    editActions.style.display = "none";
                    editToggleBtn.textContent = "✏️ Edit";
                    editToggleBtn.className = "btn btn-sm btn-outline-primary";
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
                
                // Auto-resize textarea while typing
                descriptionEditor.addEventListener("input", function() {
                    this.style.height = "auto";
                    this.style.height = this.scrollHeight + "px";
                });
                
                // Save button click
                saveBtn.addEventListener("click", function() {
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
                    
                    // Exit edit mode
                    disableEdit();
                    
                    showStatus("✅ Description saved! Changes will be applied when creating the activity.", "success");
                });
                
                // Cancel button click
                cancelBtn.addEventListener("click", function() {
                    descriptionEditor.value = originalContent;
                    descriptionDisplay.innerHTML = originalHtml;
                    disableEdit();
                    showStatus("Changes cancelled", "info");
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
    // Project Evaluator AI service - show courses dropdown/list
    echo $OUTPUT->header();
    display_service_breadcrumb(get_string('project_evaluator', 'local_projectevaluator'));

    // Get all courses the user can access
    require_once($CFG->dirroot . '/lib/enrollib.php');
    $all_courses = enrol_get_my_courses();

    if (empty($all_courses)) {
        echo '<div style="text-align:center; margin-top:40px;">';
        echo '<h3>No courses available</h3>';
        echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php" class="btn btn-primary">← Back to Services</a>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Handle course selection
    $selected_courseid = optional_param('courseid', '', PARAM_INT);

    if (empty($selected_courseid)) {
        // Show courses dropdown
        echo '<div style="max-width: 500px; margin: 40px auto; padding: 30px; background: #f7fafc; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">';
        echo '<h3 style="margin-bottom: 25px; color: #2d3748;">Select a Course to Evaluate</h3>';
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="service" value="evaluator">';
        echo '<select name="courseid" style="width:100%; padding:10px; font-size:16px; border-radius:6px; margin-bottom:20px;" required>';
        echo '<option value="" disabled selected>Select a course...</option>';
        foreach ($all_courses as $course) {
            $context = context_course::instance($course->id);
            if (has_capability('moodle/course:manageactivities', $context)) {
                echo '<option value="' . $course->id . '">' . format_string($course->fullname) . '</option>';
            }
        }
        echo '</select>';
        echo '<button type="submit" class="btn btn-primary" style="width:100%; padding:10px; font-size:16px;">Continue →</button>';
        echo '</form>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Show activities for selected course or submissions for selected activity
    $activityid = optional_param('activityid', '', PARAM_INT);
    $course = $all_courses[$selected_courseid];
    $context = context_course::instance($course->id);
    require_once($CFG->dirroot . '/lib/modinfolib.php');
    $modinfo = get_fast_modinfo($course->id);
    $activities = [];
    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->uservisible && $cm->modname === 'assign') {
            $activities[] = $cm;
        }
    }

    if (empty($activityid)) {
        // Show activities list
        echo '<div style="max-width: 700px; margin: 40px auto; padding: 30px; background: #f7fafc; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">';
        echo '<h3 style="margin-bottom: 25px; color: #2d3748;">Course Selected: ' . format_string($course->fullname) . '</h3>';
        if (empty($activities)) {
            echo '<p>No assignments found in this course.</p>';
        } else {
            echo '<h4 style="margin-bottom: 15px; color: #4a5568;">Select an Assignment to Evaluate</h4>';
            echo '<ul style="list-style:none; padding:0;">';
            foreach ($activities as $cm) {
                echo '<li style="margin-bottom: 15px;">';
                echo '<form method="get" action="" style="display:inline;">';
                echo '<input type="hidden" name="service" value="evaluator">';
                echo '<input type="hidden" name="courseid" value="' . $course->id . '">';
                echo '<input type="hidden" name="activityid" value="' . $cm->id . '">';
                echo '<button type="submit" class="btn btn-outline-primary" style="font-size:16px;">' . format_string($cm->name) . '</button>';
                echo '</form>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php?service=evaluator" class="btn btn-secondary" style="margin-top:20px;">← Back to Courses</a>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Show submissions for selected activity
    $selected_activity = null;
    foreach ($activities as $cm) {
        if ($cm->id == $activityid) {
            $selected_activity = $cm;
            break;
        }
    }
    if (!$selected_activity) {
        echo $OUTPUT->notification('Activity not found', 'error');
        echo $OUTPUT->continue_button(new moodle_url('/local/projectevaluator/index.php?service=evaluator&courseid=' . $course->id));
        echo $OUTPUT->footer();
        exit;
    }

    // Get assignment submissions
    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    $assign = new assign(context_module::instance($selected_activity->id), false, false);
    // Show all submissions for the selected activity (no status filter)
    $submissions = $assign->get_all_submissions(0);

    $submissionid = optional_param('submissionid', '', PARAM_INT);
    if (empty($submissionid)) {
        echo '<div style="max-width: 800px; margin: 40px auto; padding: 30px; background: #f7fafc; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">';
        echo '<h3 style="margin-bottom: 25px; color: #2d3748;">Assignment: ' . format_string($selected_activity->name) . '</h3>';
            if (empty($submissions)) {
                echo '<p>No submitted assignments found for grading.</p>';
            } else {
                echo '<h4 style="margin-bottom: 15px; color: #4a5568;">Student Submissions (Submitted)</h4>';
                echo '<ul style="list-style:none; padding:0;">';
                foreach ($submissions as $submission) {
                    $userid = $submission->userid;
                    $user = $DB->get_record('user', array('id' => $userid));
                    $displayname = fullname($user);
                        // Fetch feedback from both assignfeedback_comments and assign_grades
                        $feedback = '';
                        
                        // First try assignfeedback_comments
                        try {
                            $comment = $DB->get_record('assignfeedback_comments', array('submission' => $submission->id));
                            if ($comment && !empty($comment->commenttext)) {
                                $feedback = $comment->commenttext;
                            }
                        } catch (Exception $e) {
                            // Table might not exist; continue to next source
                        }
                        
                        // If no feedback found, check assign_grades
                        if (empty($feedback)) {
                            $grade_record = $DB->get_record('assign_grades', 
                                array('assignment' => $selected_activity->id, 'userid' => $userid));
                            if ($grade_record && !empty($grade_record->feedbacktext)) {
                                $feedback = $grade_record->feedbacktext;
                            }
                        }
                    echo '<li style="margin-bottom: 15px;">';
                    echo '<form method="get" action="" style="display:inline;">';
                    echo '<input type="hidden" name="service" value="evaluator">';
                    echo '<input type="hidden" name="courseid" value="' . $course->id . '">';
                    echo '<input type="hidden" name="activityid" value="' . $selected_activity->id . '">';
                    echo '<input type="hidden" name="submissionid" value="' . $submission->id . '">';
                    echo '<button type="submit" class="btn btn-outline-success" style="font-size:16px;">' . $displayname . ' (ID: ' . $userid . ')</button>';
                    echo '</form>';
                        // Show feedback if available
                        if (!empty($feedback)) {
                            echo '<div style="margin-top:8px; margin-left:10px; padding:8px; background:#f1f5f9; border-radius:6px; color:#4a5568; font-size:14px;">'
                                . '<strong>Feedback:</strong> ' . htmlspecialchars($feedback) . '</div>';
                        }
                    echo '</li>';
                }
                echo '</ul>';
            }
        echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php?service=evaluator&courseid=' . $course->id . '" class="btn btn-secondary" style="margin-top:20px;">← Back to Activities</a>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Handle grade submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade']) && isset($_POST['feedback'])) {
        $grade_value = floatval($_POST['grade']);
        $feedback_text = trim($_POST['feedback']);
        $submission = $DB->get_record('assign_submission', array('id' => $submissionid));
        $userid = $submission->userid;
        $user = $DB->get_record('user', array('id' => $userid));
        $displayname = fullname($user);
        $assignmentid = $assign->get_instance()->id;

        // Check if grade record exists
        $grade_record = $DB->get_record('assign_grades', array('assignment' => $assignmentid, 'userid' => $userid));
        if ($grade_record) {
            // Update existing grade
            $grade_record->grade = $grade_value;
            $grade_record->feedbacktext = $feedback_text;
            $grade_record->timemodified = time();
            $DB->update_record('assign_grades', $grade_record);
        } else {
            // Insert new grade
            $new_grade = new stdClass();
            $new_grade->assignment = $assignmentid;
            $new_grade->userid = $userid;
            $new_grade->grade = $grade_value;
            $new_grade->feedbacktext = $feedback_text;
            $new_grade->timemodified = time();
            $DB->insert_record('assign_grades', $new_grade);
        }

        // Also upsert assignfeedback_comments so feedback is visible/stored in the assignment feedback plugin
        try {
            global $USER;
            $comment_record = $DB->get_record('assignfeedback_comments', array('submission' => $submissionid));
            if ($comment_record) {
                $comment_record->commenttext = $feedback_text;
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
                $newc->commenttext = $feedback_text;
                if (defined('FORMAT_HTML')) {
                    $newc->commentformat = FORMAT_HTML;
                }
                $newc->timemodified = time();
                $DB->insert_record('assignfeedback_comments', $newc);
            }
        } catch (Exception $e) {
            // Ignore if comments plugin/table not present or DB error
        }

    // Show success message and redirect back to submissions
    echo '<div style="max-width: 700px; margin: 40px auto; padding: 30px; background: #e6fffa; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">';
    echo '<h3 style="color: #2c7a7b;">Grade saved for ' . $displayname . '!</h3>';
    echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php?service=evaluator&courseid=' . $course->id . '&activityid=' . $selected_activity->id . '" class="btn btn-primary" style="margin-top:20px;">← Back to Submissions</a>';
    echo '</div>';
    exit;
    }

    // Show grading interface for selected submission
    $submission = $DB->get_record('assign_submission', array('id' => $submissionid));
    $userid = $submission->userid;
    $user = $DB->get_record('user', array('id' => $userid));
    $displayname = fullname($user);
    $grade_record = $DB->get_record('assign_grades', array('assignment' => $assign->get_instance()->id, 'userid' => $userid));
    $grade = $grade_record ? $grade_record->grade : '';
    
    // Initialize feedback variable
    $feedback = '';
    
    // First try to get feedback from assignfeedback_comments as it's submission-specific
    try {
        $comment = $DB->get_record('assignfeedback_comments', array('submission' => $submissionid));
        if ($comment && !empty($comment->commenttext)) {
            $feedback = $comment->commenttext;
        }
    } catch (Exception $e) {
        // Table might not exist, continue to next source
    }
    
    // If no feedback in comments, check assign_grades table
    if (empty($feedback) && $grade_record && !empty($grade_record->feedbacktext)) {
        $feedback = $grade_record->feedbacktext;
    }
    
    // Ensure feedback is never null
    if ($feedback === null) {
        $feedback = '';
    }

    // Try to extract onlinetext submission if available (fallbacks if not)
    $submission_text = '';
    $onlinetext = $DB->get_record('assignsubmission_onlinetext', array('submission' => $submissionid), 'onlinetext');
    if ($onlinetext && !empty($onlinetext->onlinetext)) {
        $submission_text = $onlinetext->onlinetext;
    } else if (!empty($submission->data1)) {
        $submission_text = $submission->data1;
    } else if (!empty($submission->data)) {
        $submission_text = $submission->data;
    }

    echo '<div style="max-width: 900px; margin: 40px auto; padding: 30px; background: #f7fafc; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">';
    echo '<h3 style="margin-bottom: 25px; color: #2d3748;">Grading Submission for: ' . $displayname . ' (ID: ' . $userid . ')</h3>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="service" value="evaluator">';
    echo '<input type="hidden" name="courseid" value="' . $course->id . '">';
    echo '<input type="hidden" name="activityid" value="' . $selected_activity->id . '">';
    echo '<input type="hidden" name="submissionid" value="' . $submissionid . '">';
    echo '<div style="margin-bottom: 25px;">';
    echo '<label for="grade" style="font-weight:600;">Grade out of 100</label><br>';
    echo '<input type="number" name="grade" id="grade" min="0" max="100" value="' . htmlspecialchars($grade) . '" style="width:120px; padding:8px; font-size:16px; margin-top:8px;">';
    echo '</div>';
    echo '<div style="margin-bottom: 25px;">';
    echo '<label for="feedback" style="font-weight:600;">Feedback comments</label><br>';
    echo '<textarea name="feedback" id="feedback" rows="6" style="width:100%; padding:10px; font-size:16px; border-radius:6px;">' . htmlspecialchars($feedback) . '</textarea>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-success" style="font-size:16px;">Save Grade</button>';
    echo ' <button type="button" id="suggest-grade-btn" class="btn btn-outline-info" style="font-size:16px; margin-left:10px;">Suggest Grade</button>';
    echo '<a href="' . $CFG->wwwroot . '/local/projectevaluator/index.php?service=evaluator&courseid=' . $course->id . '&activityid=' . $selected_activity->id . '" class="btn btn-secondary" style="margin-left:20px; font-size:16px;">← Back to Submissions</a>';
    echo '</form>';
    echo '</div>';
    // Inject submission text into a JS variable for frontend evaluation API call
    $safe_text = json_encode($submission_text);
    $sesskey = sesskey();
    $safe_sess = json_encode($sesskey);
    $safe_activityid = json_encode($selected_activity->id);
    $safe_submissionid = json_encode($submissionid);
    echo '<script>var _pe_submission_text = ' . $safe_text . '; var _pe_sesskey = ' . $safe_sess . '; var _pe_activityid = ' . $safe_activityid . '; var _pe_submissionid = ' . $safe_submissionid . ';</script>';

    // JavaScript to call the backend evaluation endpoint and populate fields
    echo <<<'JS'
<script>
(function(){
    // Add auto-save functionality for feedback
    var feedbackInput = document.getElementById('feedback');
    var gradeInput = document.getElementById('grade');
    if (feedbackInput) {
        var saveTimeout;
        var lastSavedValue = feedbackInput.value;
        
        // Function to save feedback
        function autoSaveFeedback() {
            var currentValue = feedbackInput.value;
            if (currentValue === lastSavedValue) return; // Don't save if unchanged
            
            lastSavedValue = currentValue;
            fetch('save_grade_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    sesskey: _pe_sesskey,
                    submissionid: _pe_submissionid,
                    activityid: _pe_activityid,
                    grade: gradeInput.value || null,
                    feedback: currentValue || null
                })
            }).then(function(resp) {
                return resp.json();
            }).then(function(data) {
                if (data.status === 'ok') {
                    // Optional: Show a subtle "Saved" indicator
                    var savedIndicator = document.createElement('div');
                    savedIndicator.textContent = '✓ Auto-saved';
                    savedIndicator.style.color = '#68D391';
                    savedIndicator.style.fontSize = '12px';
                    savedIndicator.style.position = 'absolute';
                    savedIndicator.style.marginTop = '5px';
                    feedbackInput.parentNode.appendChild(savedIndicator);
                    setTimeout(function() {
                        savedIndicator.remove();
                    }, 2000);
                }
            }).catch(function(error) {
                console.error('Auto-save failed:', error);
            });
        }

        // Add input event listener with debouncing
        feedbackInput.addEventListener('input', function() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(autoSaveFeedback, 1000); // Wait 1 second after typing stops
        });
    }

    var btn = document.getElementById("suggest-grade-btn");
    if (!btn) return;
    btn.addEventListener("click", function(){
        try {
            btn.disabled = true;
            btn.textContent = "Suggesting...";
            var text = _pe_submission_text || "";
            if (!text || text.trim().length === 0) {
                alert("No online text found for this submission to evaluate.");
                btn.disabled = false;
                btn.textContent = "Suggest Grade";
                return;
            }

            fetch("http://localhost:8001/evaluate-submission/", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ text: text, max_grade: 100 })
            }).then(function(resp){ return resp.json(); })
            .then(function(data){
                if (data && data.grade !== undefined) {
                    var gradeInput = document.getElementById('grade');
                    var feedbackInput = document.getElementById('feedback');
                    var suggestedGrade = Math.round(data.grade);
                    var suggestedFeedback = data.feedback || '';
                    if (gradeInput) gradeInput.value = suggestedGrade;
                    if (feedbackInput) feedbackInput.value = suggestedFeedback;

                    // Persist suggested grade & feedback via AJAX to our plugin endpoint
                    try {
                        fetch('save_grade_ajax.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                sesskey: _pe_sesskey,
                                submissionid: _pe_submissionid,
                                activityid: _pe_activityid,
                                grade: suggestedGrade,
                                feedback: suggestedFeedback
                            })
                        }).then(function(resp){ return resp.json(); })
                        .then(function(saveResp){
                            if (saveResp && saveResp.status === 'ok') {
                                console.log('Saved suggested grade successfully');
                            } else {
                                console.warn('Failed to save suggested grade', saveResp);
                            }
                        }).catch(function(err){
                            console.error('Save request failed', err);
                        });
                    } catch (err) {
                        console.error('Error when saving suggested grade', err);
                    }

                } else if (data && data.error) {
                    alert('Evaluation error: ' + data.error);
                } else {
                    alert('Unexpected response from evaluation service');
                }
            }).catch(function(err){
                console.error(err);
                alert('Failed to contact evaluation service: ' + err);
            }).finally(function(){
                btn.disabled = false;
                btn.textContent = 'Suggest Grade';
            });
        } catch (e) {
            console.error(e);
            alert('Error while suggesting grade');
            btn.disabled = false;
            btn.textContent = 'Suggest Grade';
        }
    });
})();
</script>
JS;
    echo $OUTPUT->footer();
    exit;
}

// Should not reach here - redirect to service selection
redirect($CFG->wwwroot . '/local/projectevaluator/index.php');
?>