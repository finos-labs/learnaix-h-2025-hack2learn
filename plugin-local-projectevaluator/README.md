
# Project Evaluator for Moodle

Automatically generate rich project assignments for your Moodle courses using AI (Snowflake Cortex LLM) and a FastAPI backend.

## Features

- **Teacher-Only Access**: Only teachers/admins can access the project generation page.
- **Custom Project Generation**: Teachers specify topics and complexity; optional document upload for context.
- **AI-Powered Backend**: FastAPI service connects to Snowflake Cortex LLM to generate detailed project descriptions.
- **One-Click Activity Creation**: Instantly create Moodle assignments with the generated project details in any course section.

## Directory Structure

- `/projectevaluator/` — Moodle plugin PHP code (UI, assignment creation)
- `/python/` — FastAPI backend (AI project generation)

## Installation

### Moodle Plugin
1. Copy the `projectevaluator` folder to your Moodle `local/` directory.
2. Visit Site Administration → Notifications to install.
3. Ensure teachers have the `local/projectevaluator:view` capability.

### FastAPI Backend
1. Copy the `python` folder to your server.
2. Install dependencies:
	 ```bash
	 cd python
	 pip install -r requirements.txt
	 ```
3. Set up your `.env` file with Snowflake credentials:
	 ```env
	 SNOWFLAKE_ACCOUNT=your_account
	 SNOWFLAKE_USER=your_user
	 SNOWFLAKE_PASSWORD=your_password
	 SNOWFLAKE_DATABASE=your_db
	 SNOWFLAKE_SCHEMA=your_schema
	 SNOWFLAKE_ROLE=your_role
	 SNOWFLAKE_WAREHOUSE=your_warehouse
	 ```
4. Start the backend:
	 ```bash
	 uvicorn app:app --host 0.0.0.0 --port 8001
	 ```

## Usage

1. As a teacher, go to the Project Evaluator page in Moodle.
2. Enter project topics, select complexity, and (optionally) upload reference documents.
3. Click **Generate Project** to fetch an AI-generated project description.
4. Review and create the assignment in your chosen course section.

## API Documentation

The FastAPI backend exposes:

- `POST /generate-project/`
	- **Request Body:**
		```json
		{
			"topics": "FastAPI, REST",
			"complexity": "Easy",
			"documents": [
				{"filename": "spec.pdf", "content": "<base64>", "type": "pdf"}
			]
		}
		```
	- **Response:**
		```json
		{
			"project_description": "...markdown..."
		}
		```

Interactive docs: [http://localhost:8001/docs](http://localhost:8001/docs)

## Contributing

Pull requests and suggestions are welcome! Please open issues for bugs or feature requests.

## License

See `LICENSE` in the root directory.
