from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import List, Optional
import uvicorn
import os
import base64
import io
import re
import snowflake.connector
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

class DocumentData(BaseModel):
    filename: str
    content: str
    type: str

class ProjectRequest(BaseModel):
    topics: str
    complexity: str
    documents: Optional[List[DocumentData]] = []

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
    """Extract and clean text from document"""
    try:
        raw_text = ""
        
        if doc.type == 'txt':
            if isinstance(doc.content, str):
                try:
                    raw_text = base64.b64decode(doc.content).decode('utf-8', errors='ignore')
                except:
                    raw_text = doc.content
            else:
                raw_text = str(doc.content)
        
        elif doc.type == 'pdf' and PDF_AVAILABLE:
            pdf_content = base64.b64decode(doc.content)
            pdf_reader = PyPDF2.PdfReader(io.BytesIO(pdf_content))
            raw_text = "\n".join(page.extract_text() for page in pdf_reader.pages if page.extract_text())
        
        elif doc.type in ['doc', 'docx'] and DOCX_AVAILABLE:
            doc_content = base64.b64decode(doc.content)
            document = docx.Document(io.BytesIO(doc_content))
            raw_text = "\n".join(p.text for p in document.paragraphs if p.text.strip())
        
        return clean_text(raw_text) if raw_text else f"[Could not extract text from {doc.filename}]"
    
    except Exception as e:
        print(f"Error processing {doc.filename}: {e}")
        return f"[Error processing {doc.filename}]"


def build_prompt(topics: str, complexity: str, documents: Optional[List[DocumentData]] = []) -> str:
    """
    Builds a complete and robust prompt for generating a course project.
    
    This version uses a detailed instructional design template to give the AI
    clear context, roles, and output requirements for better results.
    """
    
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
        "**Project Title:**",
        "[Create a concise and engaging title]",
        "",
        "**1. Project Objective:**",
        "[Provide a 1-2 sentence summary of the project's main goal and its relevance to the course.]",
        "",
        "**2. Expected Features & Functionalities:**",
        "[Use a bulleted list to detail specific features. This must align with the specified complexity.]",
        "",
        "**3. Constraints & Technical Requirements:**",
        "[List any rules or specific technologies (e.g., 'Must use Python 3.8+', 'Use only the Pandas and Matplotlib libraries').]",
        "",
        "**4. Success Criteria:**",
        "[Define a clear, objective checklist to evaluate completion (e.g., 'All features are functional', 'Code is well-commented').]",
        "",
        "**5. Estimated Timeline:**",
        "[Suggest a realistic timeline with key milestones (e.g., 'Week 1: Data cleaning', 'Week 2: Analysis & Visualization').]",
    ]
    
    if documents:
        doc_header = [
            "**REFERENCE COURSE CONTENT:**",
            "========================================",
            "Analyze the following course materials to ensure the project is perfectly aligned with the learning objectives.",
            ""
        ]
        
        doc_body = []
        for i, doc in enumerate(documents, 1):
            doc_text = extract_document_text(doc)
            if "[Could not extract" not in doc_text and "[Error processing" not in doc_text:
                doc_body.append(f"**Document {i}: {doc.filename}**")
                doc_body.append(doc_text)
                doc_body.append("")

        doc_footer = [
            "========================================",
            ""
        ]
        
        if doc_body:
            prompt_parts = doc_header + doc_body + doc_footer + prompt_parts

    full_prompt = "\n".join(prompt_parts)
    
    # THIS IS THE FIX: Clean the entire final prompt for SQL compatibility.
    # This ensures any special characters in the template text are also handled.
    return clean_text(full_prompt)

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

@app.post("/generate-project/")
async def generate_project(request: ProjectRequest):
    try:
        print(f"Processing: {request.topics} ({request.complexity}) with {len(request.documents)} documents")
        
        # Build prompt
        prompt = build_prompt(request.topics, request.complexity, request.documents)
        print(f"Prompt length: {len(prompt)} characters")
        
        # Execute Snowflake Cortex query
        conn = get_snowflake_connection()
        cursor = conn.cursor()
        
        sql = f"""
        SELECT SNOWFLAKE.CORTEX.COMPLETE(
            'deeps',
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
            return {"error": "No response from Snowflake Cortex"}
            
    except Exception as e:
        print(f"Error: {e}")
        return {
            "error": f"Failed to generate project: {str(e)}",
            "documents_processed": len(request.documents)
        }

@app.get("/health")
async def health_check():
    return {"status": "healthy"}

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)