# AI-Project Hub for Moodle

A comprehensive AI-powered system that generates project assignments and evaluates student submissions for Moodle courses. Built with FastAPI backend and Snowflake Cortex LLM integration.

## Features

### 🚀 Project Generator AI
- **Smart Project Creation**: Teachers specify topics and complexity levels (Easy/Medium/Hard)
- **Document Context Support**: Upload reference materials (PDF, DOCX, TXT) to inform project generation
- **AI-Powered Content**: Uses Snowflake Cortex LLM (Claude 3.5 Sonnet) for intelligent project descriptions
- **Live Markdown Editor**: Real-time editing with preview functionality for generated projects
- **One-Click Activity Creation**: Instantly create Moodle assignments with generated project details

### 🎯 Project Evaluator AI
- **Automated Code Assessment**: Upload ZIP/TAR files containing student projects for AI evaluation
- **GitHub Repository Evaluation**: Direct evaluation of GitHub repositories via URL
- **Comprehensive Scoring System**: Multi-dimensional scoring across:
  - Code Quality (35 points)
  - Functionality & Correctness (45 points) 
  - Documentation (20 points)
- **Detailed Feedback Reports**: Structured JSON responses with strengths, improvement areas, and actionable recommendations
- **Repository Analytics**: Commit history, language detection, and file structure analysis

### 🔧 Advanced Features
- **Service Selection Interface**: Modern UI with separate services for generation and evaluation
- **Navigation Integration**: Seamlessly integrated into Moodle's main navigation bar
- **Permission-Based Access**: Teacher-only access with proper capability checking
- **Archive Processing**: Supports ZIP, TAR, and TAR.GZ with nested archive handling
- **Real-time Editing**: Inline markdown editor with live preview and auto-save

## Directory Structure

```
local/projectevaluator/           	# Moodle plugin directory
├── index.php                    	# Main entry point with service router
├── lib.php                      	# Navigation integration and library functions
├── version.php                  	# Plugin metadata and versioning
├── settings.php                 	# Plugin settings (minimal)
├── evaluator/                   	# Project evaluator service files
│   ├── dashboard.php           	# Main evaluator dashboard
│   ├── activity.php            	# Activity-based evaluation interface
│   ├── course.php              	# Course-level evaluation management
│   └── submission.php          	# Individual submission evaluation
├── lang/en/                    	# English language strings
│   └── local_projectevaluator.php
└── db/                         	# Database definitions
    └── access.php              	# Capability definitions

python/                          	# FastAPI backend
├── app.py                      	# Main FastAPI application
├── requirements.txt            	# Python dependencies
└── .env.example               		# Environment configuration template
```
## Installation

### Prerequisites
- Moodle 5.0.2+ installation
- Python 3.9+
- Snowflake account with Cortex LLM access
- Web server with FastAPI support

### Moodle Plugin Installation

1. **Deploy Plugin Files**
   ```bash
   # Copy to Moodle plugins directory
   cp -r projectevaluator /path/to/moodle/local/
   ```

2. **Install Plugin**
   - Visit **Site Administration → Notifications** 
   - Click "Upgrade Moodle database now"
   - Confirm plugin installation

3. **Configure Permissions**
   - Navigate to **Site Administration → Users → Permissions → Define roles**
   - Ensure teachers have `local/projectevaluator:view` capability
   - The plugin automatically integrates into the main navigation bar

### FastAPI Backend Setup

1. **Environment Setup**
   ```bash
   cd python
   python -m venv venv
   source venv/bin/activate  # Windows: venv\Scripts\activate
   pip install -r requirements.txt
   ```

2. **Environment Configuration**
   ```bash
   cp .env.example .env
   # Edit .env with your Snowflake credentials
   ```

   ```env
   SNOWFLAKE_ACCOUNT=your_account_identifier
   SNOWFLAKE_USER=your_username
   SNOWFLAKE_PASSWORD=your_password
   SNOWFLAKE_DATABASE=your_database
   SNOWFLAKE_SCHEMA=your_schema
   SNOWFLAKE_ROLE=your_role
   SNOWFLAKE_WAREHOUSE=your_warehouse
   GITHUB_TOKEN=your_github_token
   ```

3. **Start Backend Service**
   ```bash
   uvicorn app:app --host 0.0.0.0 --port 8001 --reload
   ```

4. **Update Moodle Configuration**
   - Ensure the FastAPI backend URL is accessible from your Moodle server
   - Default: `http://localhost:8001`

## Usage Guide

### Accessing AI-Project Hub
1. Log in as a teacher or admin
2. Look for "🤖 AI-Project Hub" in the main navigation bar
3. Select from available AI services

### Project Generator Service

1. **Service Selection**
   - Click on "🚀 Project Generator" from the hub dashboard
   - Specify target course and section

2. **Project Configuration**
   - Enter project topics (e.g., "FastAPI, REST APIs, Database Integration")
   - Select complexity level:
     - **Easy**: Core concepts, minimal external research
     - **Medium**: Multiple integrated concepts, moderate complexity  
     - **Hard**: Novel applications, extensive problem-solving
   - Upload reference documents (optional)

3. **Content Generation & Editing**
   - Click "Generate Project" to create AI-powered description
   - Use the inline editor to modify content:
     - Click anywhere in the description to start editing
     - Real-time markdown preview
     - Auto-save functionality
   - Review generated project structure, objectives, and requirements

4. **Assignment Creation**
   - Select course section for deployment
   - Set assignment title and due date
   - Click "Create Activity" to generate Moodle assignment

### Project Evaluator Service

1. **Access Evaluator Dashboard**
   - Select "🎯 Project Evaluator" from the hub dashboard
   - Upload project criteria and requirements

2. **Submission Methods**
   - **File Upload**: Submit ZIP/TAR archives of student code
   - **GitHub Integration**: Provide repository URLs for direct evaluation

3. **Review Results**
   - Comprehensive scoring breakdown
   - Detailed feedback with specific file references
   - Actionable improvement recommendations
   - Repository statistics and analysis

## API Documentation

### Core Endpoints

#### Project Generation
```http
POST /generate-project/
Content-Type: application/json

{
  "topics": "Web Development, JavaScript, Node.js",
  "complexity": "Medium",
  "documents": [
    {
      "filename": "syllabus.pdf", 
      "content": "<base64_encoded_content>",
      "type": "pdf"
    }
  ]
}
```

**Response:**
```json
{
  "project_description": "**Project Title:** Full-Stack Web Application...",
  "documents_processed": 1,
  "document_names": ["syllabus.pdf"]
}
```

#### File-Based Evaluation
```http
POST /evaluate-project/
Content-Type: multipart/form-data

criteria: <detailed_project_requirements>
file: <zip_or_tar_archive>
```

#### GitHub Repository Evaluation
```http
POST /evaluate-github-repo/
Content-Type: multipart/form-data

criteria: <project_requirements>
github_url: https://github.com/username/repo
assignment_id: 123
user_id: 456
```

**Evaluation Response:**
```json
{
  "evaluation": {
    "overall_score": 87,
    "scores": {
      "code_quality": 32,
      "functionality_correctness": 38,
      "documentation": 17
    },
    "repo_stats": {
      "commits": 24,
      "files": 18,
      "languages": 4,
      "size": "2.1 MB"
    },
    "report": {
      "strengths": [
        "Well-structured modular design",
        "Comprehensive error handling implemented",
        "Clear and detailed README documentation"
      ],
      "areas_of_improvement": [
        "Add unit tests for core functions (main.py)",
        "Improve code comments in utils.py",
        "Consider implementing input validation"
      ],
      "summary": "Strong implementation with good architecture. Focus on testing and documentation improvements for production readiness."
    }
  }
}
```

## Technical Specifications

### Supported File Types

**Document Processing:**
- PDF (PyPDF2 extraction)
- Microsoft Word (.doc, .docx)
- Plain text files (.txt)
- Archives (ZIP, TAR, TAR.GZ)

**Code Evaluation:**
- **Languages**: Python, JavaScript, Java, C++, C#, PHP, Ruby, Go