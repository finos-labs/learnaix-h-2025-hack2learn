from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import List, Optional
import uvicorn
import os
import base64
import io
from dotenv import load_dotenv
from langchain_community.chat_models import ChatSnowflakeCortex
from langchain_core.messages import HumanMessage, SystemMessage

# For document processing
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

# Load environment variables from .env file
load_dotenv()

app = FastAPI()

# Configure CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=[""],  # Allows all origins
    allow_credentials=True,
    allow_methods=[""],  # Allows all methods
    allow_headers=["*"],  # Allows all headers
)

class DocumentData(BaseModel):
    filename: str
    content: str
    type: str

class ProjectRequest(BaseModel):
    topics: str
    complexity: str
    documents: Optional[List[DocumentData]] = []


def clean_and_limit_content(text: str, max_chars: int = 5000) -> str:
    """Clean and limit document content to prevent SQL parsing issues"""
    if not text or not text.strip():
        return ""
    
    # Remove problematic characters that might cause SQL issues
    text = text.replace("'", "'").replace('"', '"').replace('"', '"')
    text = text.replace('\r\n', '\n').replace('\r', '\n')
    
    # Remove excessive whitespace
    import re
    text = re.sub(r'\n\s*\n', '\n\n', text)  # Multiple newlines to double
    text = re.sub(r'[ \t]+', ' ', text)       # Multiple spaces/tabs to single space
    
    # Truncate if too long and add indication
    if len(text) > max_chars:
        text = text[:max_chars] + "\n\n[Content truncated for processing...]"
    
    return text.strip()

def extract_document_content(doc: DocumentData) -> str:
    """Extract text content from uploaded documents"""
    try:
        raw_content = ""
        
        if doc.type == 'txt':
            raw_content = doc.content
        
        elif doc.type == 'pdf' and PDF_AVAILABLE:
            # Decode base64 content
            pdf_content = base64.b64decode(doc.content)
            pdf_reader = PyPDF2.PdfReader(io.BytesIO(pdf_content))
            text = ""
            for page in pdf_reader.pages:
                text += page.extract_text() + "\n"
            raw_content = text
        
        elif doc.type in ['doc', 'docx'] and DOCX_AVAILABLE:
            # Decode base64 content
            doc_content = base64.b64decode(doc.content)
            document = docx.Document(io.BytesIO(doc_content))
            text = ""
            for paragraph in document.paragraphs:
                text += paragraph.text + "\n"
            raw_content = text
        
        else:
            # Fallback: try to decode as text
            try:
                raw_content = base64.b64decode(doc.content).decode('utf-8', errors='ignore')
            except:
                return f"[Could not extract content from {doc.filename}]"
        
        # Clean and limit the content
        return clean_and_limit_content(raw_content)
    
    except Exception as e:
        print(f"Error extracting content from {doc.filename}: {e}")
        return f"[Error processing {doc.filename}]"
    


@app.post("/generate-project/")
async def generate_project(request: ProjectRequest):
    try:
        chat = ChatSnowflakeCortex(
            model="claude-3-5-sonnet",  # Using deepseek-r1 as it was working in the earlier example
            cortex_function="complete",
            temperature=0,
            top_p=0.95,
            snowflake_account=os.getenv("SNOWFLAKE_ACCOUNT"),
            snowflake_username=os.getenv("SNOWFLAKE_USER"),
            snowflake_password=os.getenv("SNOWFLAKE_PASSWORD"),
            snowflake_database=os.getenv("SNOWFLAKE_DATABASE"),
            snowflake_schema=os.getenv("SNOWFLAKE_SCHEMA"),
            snowflake_role=os.getenv("SNOWFLAKE_ROLE"),
            snowflake_warehouse=os.getenv("SNOWFLAKE_WAREHOUSE"),
        )

        print(request.documents)

        # Create a simple, escaped prompt
        prompt = f"Create a {request.complexity.lower()} level project about {request.topics}. Include the following sections: 1) Project Objective 2) Expected Features 3) Success Criteria 4) Technical Requirements 5) Timeline"

        messages = [
            SystemMessage(content="You are an experienced educator creating project assignments."),
            HumanMessage(content=prompt)
        ]

        response = chat.invoke(messages)

        return {"project_description": response.content}
    except Exception as e:
        error_msg = str(e)
        print(f"Error details: {error_msg}")  # For debugging
        return {"error": f"Failed to generate project: {error_msg}"}

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)