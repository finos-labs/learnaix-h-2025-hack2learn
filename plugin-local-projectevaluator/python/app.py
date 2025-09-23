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

# =============================================================================
# PROMPT BUILDERS
# =============================================================================

def build_project_prompt(topics: str, complexity: str, documents: Optional[List[DocumentData]] = []) -> str:
    """Builds a prompt for generating a course project."""
    # (Your original build_prompt function, renamed for clarity)
    prompt_parts = [
        "**Role:** You are an expert instructional designer and curriculum developer. Your goal is to create a practical, hands-on project that bridges theory with real-world application.",
        "",
        "**Task:** Generate a comprehensive project problem statement based on the provided topics and reference materials. The project must be well-structured, clear, and ready for an instructor to review.",
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
**Role:** You are an expert code reviewer and a helpful teaching assistant.

**Task:** Evaluate a student's project submission based on a given set of criteria. Your evaluation must be fair, detailed, and directly address the specified evaluation parameters.

**Evaluation Parameters & Scoring:**
You must evaluate the project across these three categories and assign a score for each:
1.  **Code Quality (Score out of 35):** Assess clean coding standards (e.g., PEP 8 in Python), modularity, variable naming, and use of efficient algorithms.
2.  **Functionality & Correctness (Score out of 45):** Verify if the code meets all project requirements, runs without errors, and correctly handles potential edge cases.
3.  **Documentation (Score out of 20):** Check for meaningful comments, clear function docstrings, and a README or usage instructions if applicable.

**Output Format:**
Provide your evaluation in Markdown format with the following strict sections. Do not add any other sections.

---
## Score Breakdown
- **Code Quality:** [Your Score]/35
- **Functionality & Correctness:** [Your Score]/45
- **Documentation:** [Your Score]/20
- **Total Score:** [Sum of Scores]/100

## Detailed Feedback Report
### Strengths
- [Bulleted list of what the student did well, referencing specific evaluation parameters.]

### Areas for Improvement
- [Bulleted list of specific, actionable suggestions for improvement.]

### Code-Specific Analysis
- [Provide detailed analysis here. Reference specific file names and line numbers. Include short code snippets from the student's submission to explain issues or suggest improvements.]
---

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


@app.post("/evaluate-project/")
async def evaluate_project(criteria: str = Form(...), file: UploadFile = File(...)):
    """
    Endpoint to evaluate a student's project.
    Accepts project criteria and a zip file of the student's work.
    """
    # 1. Validate input file
    if not file.filename.endswith('.zip'):
        raise HTTPException(status_code=400, detail="Invalid file type. Please upload a .zip file.")

    try:
        print(f"Evaluating submission: {file.filename}")

        # 2. Read and process the ZIP file in memory
        zip_content_bytes = await file.read()
        zip_file_in_memory = io.BytesIO(zip_content_bytes)
        
        with zipfile.ZipFile(zip_file_in_memory, 'r') as zip_ref:
            formatted_code = format_zip_contents_for_llm(zip_ref)

        if not formatted_code.strip():
             raise HTTPException(status_code=400, detail="The zip file seems to be empty or contains no readable text files.")

        # 3. Build the evaluation prompt
        prompt = build_evaluation_prompt(criteria, formatted_code)
        print(f"Evaluation prompt length: {len(prompt)} characters")
        
        # 4. Execute Snowflake Cortex query
        conn = get_snowflake_connection()
        cursor = conn.cursor()
        
        sql = f"""
        SELECT SNOWFLAKE.CORTEX.COMPLETE(
            'dee',
            '{prompt}'
        ) as response
        """
        
        cursor.execute(sql)
        result = cursor.fetchone()
        
        cursor.close()
        conn.close()
        
        # 5. Return the result
        if result and result[0]:
            return JSONResponse(content={
                "evaluation": result[0]
            })
        else:
            raise HTTPException(status_code=500, detail="No response from Snowflake Cortex during evaluation.")
            
    except Exception as e:
        print(f"Error in /evaluate-project/: {e}")
        # Check if it's an HTTPException and re-raise, otherwise wrap it
        if isinstance(e, HTTPException):
            raise e
        raise HTTPException(status_code=500, detail=f"Failed to evaluate project: {str(e)}")

@app.get("/health")
async def health_check():
    return {"status": "healthy"}

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)