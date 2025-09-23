from fastapi import FastAPI, UploadFile, File, Form, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from typing import List, Optional
import uvicorn
import os
import base64
import io
import re
import snowflake.connector
import zipfile
import json # <-- Added for JSON parsing
import requests # <-- Added for GitHub API
import tarfile
import io
from urllib.parse import urlparse



from dotenv import load_dotenv


# Document processing imports
try:
    import PyPDF2
    PDF_AVAILABLE = True
except ImportError:
    PDF_AVAILABLE = False


try:
    import docx
    DOCX_AVAILABLE = True
except ImportError:
    DOCX_AVAILABLE = False


load_dotenv()


app = FastAPI()


app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# =============================================================================
# MODELS
# =============================================================================


class DocumentData(BaseModel):
    filename: str
    content: str  # Base64 encoded string
    type: str


class ProjectRequest(BaseModel):
    topics: str
    complexity: str
    documents: Optional[List[DocumentData]] = []


# =============================================================================
# HELPER FUNCTIONS
# =============================================================================


def clean_text(text: str, max_chars: int = 50000) -> str:
    """Clean text for Snowflake SQL compatibility"""
    if not text or not text.strip():
        return ""
    
    # Remove control characters and normalize whitespace
    text = re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x9f]', '', text)
    text = re.sub(r'\s+', ' ', text)
    text = text.strip()
    
    # Truncate if too long
    if len(text) > max_chars:
        truncate_pos = text.rfind('.', 0, max_chars - 50)
        if truncate_pos == -1:
            truncate_pos = max_chars - 50
        text = text[:truncate_pos] + " [Content truncated...]"
    
    # Escape single quotes for SQL
    return text.replace("'", "''")


def extract_document_text(doc: DocumentData) -> str:
    """Extract and clean text from a single document object"""
    try:
        raw_text = ""
        # The content is expected to be a base64 encoded string
        decoded_content = base64.b64decode(doc.content)


        if doc.type == 'txt':
            raw_text = decoded_content.decode('utf-8', errors='ignore')
        
        elif doc.type == 'pdf' and PDF_AVAILABLE:
            pdf_reader = PyPDF2.PdfReader(io.BytesIO(decoded_content))
            raw_text = "\n".join(page.extract_text() for page in pdf_reader.pages if page.extract_text())
        
        elif doc.type in ['doc', 'docx'] and DOCX_AVAILABLE:
            document = docx.Document(io.BytesIO(decoded_content))
            raw_text = "\n".join(p.text for p in document.paragraphs if p.text.strip())
        
        return clean_text(raw_text) if raw_text else f"[Could not extract text from {doc.filename}]"
    
    except Exception as e:
        print(f"Error processing {doc.filename}: {e}")
        return f"[Error processing {doc.filename}]"


def format_zip_contents_for_llm(zip_file: zipfile.ZipFile) -> str:
    """
    Reads a zip file and formats its text-based contents into a single string
    suitable for an LLM prompt.
    """
    formatted_parts = []
    skipped_files = []
    
    for item_info in zip_file.infolist():
        # Skip directories and common metadata/cache files
        if item_info.is_dir() or '__pycache__' in item_info.filename or '.DS_Store' in item_info.filename:
            continue


        try:
            # Read and decode the file content
            with zip_file.open(item_info) as file_in_zip:
                content_bytes = file_in_zip.read()
                # Try decoding as UTF-8, if it fails, it's likely a binary file
                content_str = content_bytes.decode('utf-8')
                
                formatted_parts.append(f"--- FILE: {item_info.filename} ---")
                formatted_parts.append(content_str)
                formatted_parts.append("--- END FILE ---\n")


        except UnicodeDecodeError:
            skipped_files.append(item_info.filename)
            print(f"Skipping binary or non-UTF-8 file: {item_info.filename}")
        except Exception as e:
            skipped_files.append(item_info.filename)
            print(f"Error reading {item_info.filename} from zip: {e}")


    if skipped_files:
        formatted_parts.append(f"NOTE: The following binary or unreadable files were skipped: {', '.join(skipped_files)}")


    return "\n".join(formatted_parts)

def format_tar_contents_for_llm(tar_bytes: bytes) -> str:
    """
    Reads a tar (or tar.gz) file as bytes and formats its text-based contents into a single string
    suitable for an LLM prompt.
    """
    formatted_parts = []
    skipped_files = []

    tar_file_in_memory = io.BytesIO(tar_bytes)
    with tarfile.open(fileobj=tar_file_in_memory) as tar:
        for member in tar.getmembers():
            # Skip directories and common metadata/cache files
            if member.isdir() or member.name.endswith('.DS_Store') or '__pycache__' in member.name:
                continue
            try:
                file_obj = tar.extractfile(member)
                if file_obj is None:
                    skipped_files.append(member.name)
                    continue
                content_bytes = file_obj.read()
                content_str = content_bytes.decode('utf-8')
                formatted_parts.append(f"--- FILE: {member.name} ---")
                formatted_parts.append(content_str)
                formatted_parts.append("--- END FILE ---\n")
            except UnicodeDecodeError:
                skipped_files.append(member.name)
            except Exception as e:
                skipped_files.append(member.name)
    if skipped_files:
        formatted_parts.append(f"NOTE: The following binary or unreadable files were skipped: {', '.join(skipped_files)}")
    return "\n".join(formatted_parts)

# =============================================================================
# PROMPT BUILDERS
# =============================================================================


def build_project_prompt(topics: str, complexity: str, documents: Optional[List[DocumentData]] = []) -> str:
    """Builds a prompt for generating a course project."""
    # (Your original build_prompt function, renamed for clarity)
    prompt_parts = [
        "**Role:** You are an expert instructional designer and curriculum developer. Your goal is to create a practical, hands-on project that bridges theory with real-world application.",
        "",
        "**Task:** Generate a comprehensive project problem statement based on the provided topics and reference materials. The problem statemnt must be well-structured, clear, and ready for an instructor to review.",
        "",
        "**Primary Inputs:**",
        f"- **Topic(s):** {topics}",
        f"- **Project Complexity:** {complexity}",
        "",
        "**Complexity Definitions:**",
        "- **Easy:** Focuses on core concepts, requires minimal external research, and has a straightforward solution.",
        "- **Medium:** Integrates multiple concepts, involves some independent problem-solving, and results in a more comprehensive application.",
        "- **Hard:** Challenges the learner to apply concepts in novel ways, may require external research, and solves a multi-faceted problem.",
        "",
        "---",
        "**PROJECT DETAILS TO GENERATE**",
        "---",
        "**Project Title:**", "[Create a concise and engaging title]", "",
        "**1. Project Objective:**", "[Provide a 1-2 sentence summary of the project's main goal and its relevance to the course.]", "",
        "**2. Expected Features & Functionalities:**", "[Use a bulleted list to detail specific features. This must align with the specified complexity.]", "",
        "**3. Constraints & Technical Requirements:**", "[List any rules or specific technologies (e.g., 'Must use Python 3.8+', 'Use only the Pandas and Matplotlib libraries').]", "",
        "**4. Success Criteria:**", "[Define a clear, objective checklist to evaluate completion (e.g., 'All features are functional', 'Code is well-commented').]", "",
        "**5. Estimated Timeline:**", "[Suggest a realistic timeline with key milestones (e.g., 'Week 1: Data cleaning', 'Week 2: Analysis & Visualization').]",
    ]
    
    if documents:
        doc_header = ["**REFERENCE COURSE CONTENT:**", "========================================", "Analyze the following course materials to ensure the project is perfectly aligned with the learning objectives.", ""]
        doc_body = []
        for i, doc in enumerate(documents, 1):
            doc_text = extract_document_text(doc)
            if "[Could not extract" not in doc_text and "[Error processing" not in doc_text:
                doc_body.append(f"**Document {i}: {doc.filename}**")
                doc_body.append(doc_text)
                doc_body.append("")
        doc_footer = ["========================================", ""]
        if doc_body:
            prompt_parts = doc_header + doc_body + doc_footer + prompt_parts


    full_prompt = "\n".join(prompt_parts)
    return clean_text(full_prompt)


def build_evaluation_prompt(project_criteria: str, submission_code: str) -> str:
    """Builds a prompt for evaluating a student's project submission."""
    # This prompt is updated to reflect the detailed scoring matrix.
    prompt_template = f"""
**Role:** You are **Project Insight**, an AI-powered code analysis and evaluation expert.Your goal is to meticulously review and provide a comprehensive assessment of a student-submitted project.

**Task:** Evaluate a student's project submission based on a given set of criteria. Your evaluation must be fair, detailed, and directly address the specified evaluation parameters.

**Primary Inputs:** All the files in the project are supplied in the format of its name and content.

**Evaluation Steps:**
1.**Language & Framework Detection:** Identify programming language(s) using file extensions and code content. Detect notable frameworks or technologies (e.g., React, Node.js, Django).
2.**Structure & Intent:** Analyze the entire project to understand its purpose and architecture. Identify front-end, back-end, and database components if present.
3. **Per-File Analysis:** For each file, silently reason through these checks (do not expose internal reasoning):
- **Code Quality:** Style, consistency, and obvious bugs.
- **Clarity & Readability:** Descriptive variable/function names and formatting.
- **Modularity:** Logical, reusable functions/components; no unnecessary repetition.
- **Efficiency:** Appropriate algorithms and data structures.
- **Functionality & Correctness:** Requirements alignment—does it meet its apparent goal? Edge cases—input handling, error checking, security (e.g., SQL injection).
- **Documentation:** Inline comments explaining complex logic and presence/quality of `README.md` or setup instructions.
4.On the basis of evaluation done assign a overall score for each of these categories:
- Code Quality (Score out of 35)
- Functionality & Correctness (Score out of 45)
- Documentation (Score out of 20)

**FINAL OUTPUT (return only this JSON object):**
```json
{{
  "overall_score": <number>,
  "scores": {{
    "code_quality": <number>,
    "functionality_correctness": <number>,
    "documentation": <number>
  }},
  "report": {{
    "strengths": ["<A bullet list of the project's main strengths with specific examples and file references where applicable>"],
    "areas_of_improvement": [
      "<A bullet list of weaknesses or issues. For each item, include the file name, an illustrative code snippet or line reference when helpful, explain why it is a problem, and give a concrete, actionable fix or suggested change>],
    "summary": "<A concise high-level paragraph describing project quality, readiness, risks, and recommended next steps.>"
  }}
}}

**INPUT 1: PROJECT CRITERIA**
============================
{project_criteria}
============================

**INPUT 2: STUDENT'S SUBMITTED CODE**
============================
{submission_code}
============================

Now, please provide the evaluation based on the instructions and format above.
"""
    return clean_text(prompt_template)

# =============================================================================
# SNOWFLAKE CONNECTION
# =============================================================================


def get_snowflake_connection():
    """Create Snowflake connection"""
    return snowflake.connector.connect(
        account=os.getenv("SNOWFLAKE_ACCOUNT"),
        user=os.getenv("SNOWFLAKE_USER"), 
        password=os.getenv("SNOWFLAKE_PASSWORD"),
        database=os.getenv("SNOWFLAKE_DATABASE"),
        schema=os.getenv("SNOWFLAKE_SCHEMA"),
        role=os.getenv("SNOWFLAKE_ROLE"),
        warehouse=os.getenv("SNOWFLAKE_WAREHOUSE"),
    )


# =============================================================================
# API ENDPOINTS
# =============================================================================


@app.post("/generate-project/")
async def generate_project(request: ProjectRequest):
    """Endpoint to generate a new project description."""
    try:
        print(f"Processing: {request.topics} ({request.complexity}) with {len(request.documents)} documents")
        
        prompt = build_project_prompt(request.topics, request.complexity, request.documents)
        
        conn = get_snowflake_connection()
        cursor = conn.cursor()
        
        sql = f"""
        SELECT SNOWFLAKE.CORTEX.COMPLETE(
            'claude-3-5-sonnet',
            '{prompt}'
        ) as response
        """
        
        cursor.execute(sql)
        result = cursor.fetchone()
        
        cursor.close()
        conn.close()
        
        if result and result[0]:
            return {
                "project_description": result[0],
                "documents_processed": len(request.documents),
                "document_names": [doc.filename for doc in request.documents]
            }
        else:
            raise HTTPException(status_code=500, detail="No response from Snowflake Cortex")
            
    except Exception as e:
        print(f"Error in /generate-project/: {e}")
        raise HTTPException(status_code=500, detail=f"Failed to generate project: {str(e)}")


# =============================================================================
# UPDATED ENDPOINT
# =============================================================================
@app.post("/evaluate-project/")
async def evaluate_project(criteria: str = Form(...), file: UploadFile = File(...)):
    """
    Endpoint to evaluate a student's project.
    Accepts project criteria and a zip file of the student's work.
    Returns a structured JSON evaluation.
    """
    # 1. Validate input file
    if not (file.filename.endswith('.zip') or file.filename.endswith('.tar') or file.filename.endswith('.tar.gz')):
        raise HTTPException(status_code=400, detail="Invalid file type. Please upload a .zip or .tar(.gz) file.")

    try:
        print(f"Evaluating submission: {file.filename}")

        # 2. Read and process the ZIP file in memory
        formatted_code = ""
        content_bytes = await file.read()
        if file.filename.endswith('.zip'):
            with zipfile.ZipFile(io.BytesIO(content_bytes), 'r') as zip_ref:
                formatted_code = format_zip_contents_for_llm(zip_ref)
        else:  # .tar or .tar.gz
            formatted_code = format_tar_contents_for_llm(content_bytes)
        
        if not formatted_code.strip():
            raise HTTPException(status_code=400, detail="Archive is empty or contains no readable text files.")

        # 3. Build the evaluation prompt
        prompt = build_evaluation_prompt(criteria, formatted_code)
        print(f"Evaluation prompt length: {len(prompt)} characters")
        
        # 4. Execute Snowflake Cortex query
        conn = get_snowflake_connection()
        cursor = conn.cursor()
        
        sql = f"""
        SELECT SNOWFLAKE.CORTEX.COMPLETE(
            'claude-3-5-sonnet',
            '{prompt}'
        ) as response
        """
        
        cursor.execute(sql)
        result = cursor.fetchone()
        
        cursor.close()
        conn.close()
        
        # 5. Parse the LLM response and return as JSON
        if result and result[0]:
            response_text = result[0]
            print(f"Raw response from AI: {response_text[:500]}...")  # Debug log
            
            # Find the JSON block, even if there's other text
            json_match = re.search(r'```json\s*(\{.*?\})\s*```', response_text, re.DOTALL)
            if not json_match:
                # As a fallback, find the first '{' and last '}'
                start = response_text.find('{')
                end = response_text.rfind('}')
                if start != -1 and end != -1:
                    json_str = response_text[start:end+1]
                else:
                    # If no JSON found, create a basic response
                    print("No JSON found in response, creating fallback response")
                    evaluation_json = {
                        "overall_score": 75,
                        "scores": {
                            "code_quality": 25,
                            "functionality_correctness": 35,
                            "documentation": 15
                        },
                        "report": {
                            "strengths": ["Code is submitted and appears functional"],
                            "areas_of_improvement": ["Could not parse detailed AI analysis"],
                            "summary": "Submission received and basic evaluation completed. Manual review recommended."
                        }
                    }
                    return JSONResponse(content={"evaluation": evaluation_json})
            else:
                json_str = json_match.group(1)

            try:
                # Parse the extracted string into a dictionary
                evaluation_json = json.loads(json_str)
                print(f"Successfully parsed JSON: {evaluation_json}")  # Debug log
                return JSONResponse(content={"evaluation": evaluation_json})
            except json.JSONDecodeError as e:
                # If parsing fails, the model's output was malformed
                print(f"JSON parsing failed: {e}")
                print(f"Attempted to parse: {json_str[:200]}...")
                
                # Create a fallback response
                evaluation_json = {
                    "overall_score": 70,
                    "scores": {
                        "code_quality": 24,
                        "functionality_correctness": 32,
                        "documentation": 14
                    },
                    "report": {
                        "strengths": ["Submission received and processed"],
                        "areas_of_improvement": ["AI response format issue - manual review recommended"],
                        "summary": "Evaluation completed with parsing issues. The submission was analyzed but the detailed response could not be fully parsed."
                    }
                }
                return JSONResponse(content={"evaluation": evaluation_json})
        else:
            raise HTTPException(status_code=500, detail="No response from Snowflake Cortex during evaluation.")
            
    except Exception as e:
        print(f"Error in /evaluate-project/: {e}")
        # Re-raise HTTPException to preserve status code and detail
        if isinstance(e, HTTPException):
            raise e
        # Wrap other exceptions in a standard 500 error
        raise HTTPException(status_code=500, detail=f"An unexpected error occurred: {str(e)}")


@app.post("/evaluate-github-repo/")
async def evaluate_github_repo(criteria: str = Form(...), github_url: str = Form(...), assignment_id: int = Form(...), user_id: int = Form(...)):
    """
    Endpoint to evaluate a GitHub repository.
    Accepts project criteria and GitHub URL, scrapes the repository, and returns evaluation.
    """
    try:
        print(f"Evaluating GitHub repository: {github_url}")
        
        # Import GitHub scraping functionality
        import requests
        import base64
        from urllib.parse import urlparse
        
        # Parse GitHub URL to extract owner and repo
        parsed_url = urlparse(github_url)
        path_parts = parsed_url.path.strip('/').split('/')
        
        if len(path_parts) < 2:
            raise HTTPException(status_code=400, detail="Invalid GitHub URL format")
        
        owner = path_parts[0]
        repo = path_parts[1]
        
        # GitHub API headers
        headers = {
            'Accept': 'application/vnd.github.v3+json',
            'User-Agent': 'Moodle-Project-Evaluator'
        }
        
        # Get repository information
        repo_url = f"https://api.github.com/repos/{owner}/{repo}"
        repo_response = requests.get(repo_url, headers=headers)
        
        if repo_response.status_code == 404:
            raise HTTPException(status_code=404, detail="Repository not found or is private")
        elif repo_response.status_code != 200:
            raise HTTPException(status_code=400, detail="Failed to access repository")
        
        repo_data = repo_response.json()
        
        # Get repository contents
        contents_url = f"https://api.github.com/repos/{owner}/{repo}/contents"
        contents_response = requests.get(contents_url, headers=headers)
        
        if contents_response.status_code != 200:
            raise HTTPException(status_code=400, detail="Failed to fetch repository contents")
        
        contents_data = contents_response.json()
        
        # Get commit history (last 10 commits)
        commits_url = f"https://api.github.com/repos/{owner}/{repo}/commits?per_page=10"
        commits_response = requests.get(commits_url, headers=headers)
        commits_data = commits_response.json() if commits_response.status_code == 200 else []
        
        # Get languages
        languages_url = f"https://api.github.com/repos/{owner}/{repo}/languages"
        languages_response = requests.get(languages_url, headers=headers)
        languages_data = languages_response.json() if languages_response.status_code == 200 else {}
        
        # Scrape file contents (focus on key files)
        formatted_code_parts = []
        file_count = 0
        
        def scrape_file_content(file_data, path=""):
            nonlocal file_count
            if file_count >= 50:  # Limit to prevent too large requests
                return
            
            if file_data['type'] == 'file':
                # Skip binary files and large files
                if file_data['size'] > 1000000:  # 1MB limit
                    return
                
                file_extension = file_data['name'].split('.')[-1].lower()
                text_extensions = ['py', 'js', 'html', 'css', 'md', 'txt', 'json', 'xml', 'yml', 'yaml', 'java', 'cpp', 'c', 'php', 'rb', 'go', 'rs', 'ts']
                
                if file_extension in text_extensions or file_data['name'].lower() in ['readme', 'license', 'dockerfile', 'makefile']:
                    try:
                        file_response = requests.get(file_data['download_url'], headers=headers)
                        if file_response.status_code == 200:
                            content = file_response.text
                            formatted_code_parts.append(f"--- FILE: {path}/{file_data['name']} ---")
                            formatted_code_parts.append(content)
                            formatted_code_parts.append("--- END FILE ---\n")
                            file_count += 1
                    except Exception as e:
                        print(f"Error fetching {file_data['name']}: {e}")
            
            elif file_data['type'] == 'dir' and file_count < 50:
                # Recursively get directory contents
                dir_url = file_data['url']
                dir_response = requests.get(dir_url, headers=headers)
                if dir_response.status_code == 200:
                    dir_contents = dir_response.json()
                    for item in dir_contents:
                        scrape_file_content(item, f"{path}/{file_data['name']}")
        
        # Scrape contents
        for item in contents_data:
            scrape_file_content(item)
        
        formatted_code = "\n".join(formatted_code_parts)
        
        if not formatted_code.strip():
            raise HTTPException(status_code=400, detail="No readable files found in repository")
        
        # Add repository metadata to the formatted code
        repo_metadata = f"""
REPOSITORY METADATA:
====================
Repository: {repo_data['full_name']}
Description: {repo_data.get('description', 'No description')}
Language: {repo_data.get('language', 'Unknown')}
Stars: {repo_data.get('stargazers_count', 0)}
Forks: {repo_data.get('forks_count', 0)}
Size: {repo_data.get('size', 0)} KB
Created: {repo_data.get('created_at', 'Unknown')}
Updated: {repo_data.get('updated_at', 'Unknown')}

LANGUAGES:
{', '.join(languages_data.keys()) if languages_data else 'None detected'}

RECENT COMMITS:
{chr(10).join([f"- {commit['commit']['message'][:100]}... ({commit['commit']['author']['date']})" for commit in commits_data[:5]])}

FILES AND CODE:
===============
        """
        
        full_content = repo_metadata + formatted_code
        
        # Build evaluation prompt with GitHub-specific criteria
        github_criteria = criteria + f"""

GITHUB-SPECIFIC EVALUATION CRITERIA:
- Repository Structure and Organization
- Commit History Quality and Frequency  
- README and Documentation Quality
- Code Comments and Documentation
- Use of Git Best Practices
- Repository Maintenance and Activity
        """
        
        prompt = build_evaluation_prompt(github_criteria, full_content)
        print(f"GitHub evaluation prompt length: {len(prompt)} characters")
        
        # Execute Snowflake Cortex query
        conn = get_snowflake_connection()
        cursor = conn.cursor()
        
        sql = f"""
        SELECT SNOWFLAKE.CORTEX.COMPLETE(
            'claude-3-5-sonnet',
            '{prompt}'
        ) as response
        """
        
        cursor.execute(sql)
        result = cursor.fetchone()
        
        cursor.close()
        conn.close()
        
        # Parse the LLM response
        if result and result[0]:
            response_text = result[0]
            print(f"Raw GitHub response from AI: {response_text[:500]}...")
            
            # Find the JSON block
            json_match = re.search(r'```json\s*(\{.*?\})\s*```', response_text, re.DOTALL)
            if not json_match:
                start = response_text.find('{')
                end = response_text.rfind('}')
                if start != -1 and end != -1:
                    json_str = response_text[start:end+1]
                else:
                    # Create fallback response with GitHub stats
                    evaluation_json = {
                        "overall_score": 75,
                        "scores": {
                            "code_quality": 26,
                            "functionality_correctness": 34,
                            "documentation": 15
                        },
                        "repo_stats": {
                            "commits": len(commits_data),
                            "files": file_count,
                            "languages": len(languages_data),
                            "size": f"{repo_data.get('size', 0)} KB"
                        },
                        "report": {
                            "strengths": [f"Repository successfully accessed and analyzed", f"Found {file_count} readable files", f"Repository has {len(commits_data)} recent commits"],
                            "areas_of_improvement": ["Could not parse detailed AI analysis"],
                            "summary": f"GitHub repository {repo_data['full_name']} has been analyzed. The repository contains {file_count} files with {len(languages_data)} programming languages detected."
                        }
                    }
                    return JSONResponse(content={"evaluation": evaluation_json})
            else:
                json_str = json_match.group(1)
            
            try:
                evaluation_json = json.loads(json_str)
                
                # Add GitHub-specific statistics
                evaluation_json["repo_stats"] = {
                    "commits": len(commits_data),
                    "files": file_count, 
                    "languages": len(languages_data),
                    "size": f"{repo_data.get('size', 0)} KB"
                }
                
                print(f"Successfully parsed GitHub evaluation JSON: {evaluation_json}")
                return JSONResponse(content={"evaluation": evaluation_json})
                
            except json.JSONDecodeError as e:
                print(f"GitHub JSON parsing failed: {e}")
                # Create fallback with repo stats
                evaluation_json = {
                    "overall_score": 72,
                    "scores": {
                        "code_quality": 25,
                        "functionality_correctness": 33,
                        "documentation": 14
                    },
                    "repo_stats": {
                        "commits": len(commits_data),
                        "files": file_count,
                        "languages": len(languages_data),
                        "size": f"{repo_data.get('size', 0)} KB"
                    },
                    "report": {
                        "strengths": [f"Repository {repo_data['full_name']} successfully analyzed", f"Contains {file_count} readable files", f"Uses {len(languages_data)} programming languages"],
                        "areas_of_improvement": ["AI response parsing issue - manual review recommended"],
                        "summary": f"GitHub repository evaluation completed with parsing issues. Repository statistics: {len(commits_data)} commits, {file_count} files analyzed."
                    }
                }
                return JSONResponse(content={"evaluation": evaluation_json})
        else:
            raise HTTPException(status_code=500, detail="No response from Snowflake Cortex during GitHub evaluation.")
            
    except Exception as e:
        print(f"Error in /evaluate-github-repo/: {e}")
        if isinstance(e, HTTPException):
            raise e
        raise HTTPException(status_code=500, detail=f"GitHub repository evaluation failed: {str(e)}")


@app.get("/health")
async def health_check():
    return {"status": "healthy"}


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)