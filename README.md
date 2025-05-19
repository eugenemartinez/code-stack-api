# CodeStack API

CodeStack is an API-first backend for a code snippet sharing application, built with Slim PHP. It provides a robust set of endpoints for managing code snippets, designed with modern API practices.

## Overview

This project focuses on delivering a comprehensive and well-documented backend API. It allows users to create, retrieve, update, delete, and manage code snippets securely and efficiently. The API supports features like searching, filtering, pagination, and sorting.

**API-First Approach:** The development prioritizes the API, ensuring it is a standalone, testable, and reliable service that can be consumed by various clients (e.g., a web frontend, mobile app, or other services).

## Key Backend Features

*   **Full CRUD Operations:** Create, Read, Update, and Delete code snippets.
*   **Secure Modifications:** Snippet updates and deletions are protected by a unique `modification_code`.
*   **Snippet Management:**
    *   List snippets with pagination.
    *   Filter snippets by language or tag.
    *   Sort snippets by various fields (title, language, creation/update date).
    *   Search snippets by keywords in title, description, or username.
*   **Helper Endpoints:**
    *   Retrieve a single random snippet.
    *   Fetch multiple snippets by their IDs (batch get).
    *   Verify a snippet's modification code.
    *   Get lists of unique programming languages and tags used across all snippets.
*   **Automatic Username Generation:** If a username isn't provided during snippet creation, a unique, memorable username (adjective + noun) is automatically generated.
*   **Input Validation & Sanitization:** Robust validation for all incoming data and sanitization (e.g., HTMLPurifier for descriptions) to prevent XSS and ensure data integrity.
*   **Rate Limiting:** Middleware to protect Create, Update, and Delete operations against abuse (e.g., 50 requests/day/IP).
*   **Consistent JSON Error Handling:** Standardized JSON error responses for clear client-side error management.
*   **Logging:** Key application events, errors, and requests are logged for monitoring and debugging.

## Tech Stack

*   **Framework:** Slim PHP (v4)
*   **Language:** PHP 8.4.7
*   **Database:** PostgreSQL
*   **Database Interaction:** PDO
*   **Migrations:** Phinx
*   **UUIDs:** Ramsey\Uuid for unique identifiers.
*   **Validation:** Respect\Validation
*   **Sanitization:** HTMLPurifier
*   **HTTP Standards:** Adherence to PSR-7 (HTTP Message Interfaces), PSR-15 (HTTP Handlers), PSR-11 (Container Interface).
*   **Logging Standard:** PSR-3 (Logger Interface) with Monolog.
*   **Dependency Management:** Composer

## API Documentation & Access

*   **Interactive API Documentation (Swagger UI):**
    *   View and interact with the API documentation locally by opening `api/api-docs.html` in your browser after starting the local server.
    *   (Once deployed) Live Documentation: `[Link to your deployed Swagger UI]`
*   **Postman Collection:**
    *   A comprehensive Postman collection and environment are available in the `postman/` directory:
        *   Collection: [`postman/CodeStack_API.postman_collection.json`](./postman/CodeStack_API.postman_collection.json)
        *   Environment: [`postman/CodeStack_Local_Dev.postman_environment.json`](./postman/CodeStack_Local_Dev.postman_environment.json)
    *   Import both into Postman to easily test all API endpoints. The collection is configured to use environment variables for `baseUrl`, `snippetId`, and `modificationCode` for streamlined testing workflows.
*   **Live API Base URL:**
    *   (Once deployed) `[Your Live API Base URL]/api`

## API Endpoints

Below is a summary of the available API endpoints. All snippet-related endpoints are prefixed with `/api`.

| Method | Endpoint                                        | Description                                     |
| :----- | :---------------------------------------------- | :---------------------------------------------- |
| `GET`  | `/`                                             | Welcome message for the API.                    |
| `POST` | `/api/snippets`                                 | Create a new code snippet.                      |
| `GET`  | `/api/snippets`                                 | List all code snippets (supports pagination, filtering, sorting, search). |
| `GET`  | `/api/snippets/random`                          | Get a single random public code snippet.        |
| `GET`  | `/api/snippets/{id}`                            | Get a specific code snippet by its ID.          |
| `PUT`  | `/api/snippets/{id}`                            | Update an existing code snippet by its ID.      |
| `DELETE`| `/api/snippets/{id}`                            | Delete a code snippet by its ID.                |
| `POST` | `/api/snippets/batch-get`                       | Get multiple snippets by a list of their IDs.   |
| `POST` | `/api/snippets/{id}/verify-modification-code`   | Verify the modification code for a snippet.     |
| `GET`  | `/api/languages`                                | Get a list of unique programming languages used. |
| `GET`  | `/api/tags`                                     | Get a list of unique tags used.                 |

**Note:**
*   `{id}` in the endpoints should be replaced with the actual UUID of the snippet.
*   For detailed request/response schemas, query parameters, and examples, please refer to the [Interactive API Documentation](#interactive-api-documentation-swagger-ui) or the [Postman Collection](#postman-collection).

## Getting Started (Local Development)

### Prerequisites

*   PHP >= 8.4.7
*   Composer
*   PostgreSQL Server

### Setup

1.  **Clone the repository (this `codestack` directory will be your project root):**
    ```bash
    git clone https://github.com/eugenemartinez/code-stack-api
    cd code-stack-api
    ```

2.  **Install PHP dependencies:**
    ```bash
    composer install
    ```

3.  **Set up your environment variables:**
    *   Copy the example environment file:
        ```bash
        cp .env.example .env
        ```
    *   Edit the `.env` file. Below are the key variables you'll likely need to configure or be aware of for local development. This reflects the structure of `.env.example`:

        ```dotenv
        # .env (Copied from .env.example)

        APP_ENV="development" # Should be 'production' in production, 'testing' for test-specific overrides if not using TESTING flag
        DEBUG_MODE="true"     # Should be 'false' in production

        # --- Development Database Configuration ---
        DB_ADAPTER="pgsql"
        DB_HOST="localhost"
        DB_PORT="5432"
        DB_NAME="your_dev_db_name"      # Replace with your preferred local database name
        DB_USER="your_dev_db_user"      # Replace with your PostgreSQL username
        DB_PASS="your_dev_db_password"  # Replace with your PostgreSQL password
        DB_CHARSET="utf8"

        # --- Testing Database Configuration ---
        # TESTING flag is usually set by phpunit.xml.dist
        # These are used if TESTING=true (or APP_ENV=testing)
        # Ensure this database exists and migrations are run for the 'testing' environment.
        DB_ADAPTER_TEST="pgsql"
        DB_HOST_TEST="localhost"
        DB_PORT_TEST="5432"
        DB_NAME_TEST="your_test_db_name"    # e.g., codestack_local_test
        DB_USER_TEST="your_test_db_user"    # Often the same as dev user
        DB_PASS_TEST="your_test_db_password"
        DB_CHARSET_TEST="utf8"

        # --- Production Database Configuration (Reference for when you deploy) ---
        # These are used if APP_ENV=production (and TESTING is not true)
        # You will set these in your actual production server environment.
        # DB_ADAPTER_PROD="pgsql"
        # DB_HOST_PROD="your_production_db_host"
        # DB_NAME_PROD="your_production_db_name"
        # DB_USER_PROD="your_production_db_user"
        # DB_PASS_PROD="your_production_db_password"
        # DB_PORT_PROD="5432"
        # DB_CHARSET_PROD="utf8"

        # --- Redis Configuration ---
        # For local development (optional, for rate limiting)
        REDIS_URL="redis://localhost:6379/0"
        # For production (example, replace with your actual Upstash/Cloud Redis URL)
        # CLOUD_REDIS_DSN="rediss://:your_upstash_password@your_upstash_host:your_upstash_port/0"

        # --- Application Settings ---
        RATE_LIMIT_CUD_REQUESTS="50" # Example: 50 requests
        RATE_LIMIT_CUD_WINDOW_SECONDS="86400" # Example: per day (24 * 60 * 60 seconds)

        CORS_ALLOWED_ORIGINS="http://localhost:5173" # Adjust to your frontend's local development URL
                                                     # For production, use your actual frontend URL(s)
        ```
    *   **Important:**
        *   For local development, ensure the database specified in `DB_NAME` (e.g., `your_dev_db_name`) exists on your PostgreSQL server before running migrations.
        *   For running tests, ensure the database specified in `DB_NAME_TEST` (e.g., `your_test_db_name`) exists and migrations for the `testing` environment have been run.

4.  **Database Setup:**
    *   Ensure your PostgreSQL server is running.
    *   Create the database (e.g., `codestack_db`) if it doesn't exist.
    *   Run database migrations using Phinx (make sure Phinx is configured correctly in `phinx.php` if you've customized it):
        ```bash
        ./vendor/bin/phinx migrate -e development
        ```
        *(Adjust the command if your Phinx executable path or environment name differs)*
    *   For the testing environment:
        ```bash
        ./vendor/bin/phinx migrate -e testing
        ```

5.  **Run the local development server:**
    *   The API entry point is `api/index.php`. Serve it from the `codestack/` directory, setting the document root to `api/`:
        ```bash
        php -S localhost:8000 -t api
        ```
    *   The API will be accessible at `http://localhost:8000/api/...`
    *   The interactive documentation will be at `http://localhost:8000/api-docs.html` (assuming `api-docs.html` is in the `api/` directory and served correctly).

6.  **Run Automated Tests:**
    *   Ensure your test database (`DB_NAME_TEST` in `.env`) is created and migrated (see step 4 for migration command, using `-e testing`).
    *   Execute the test suite using PHPUnit:
        ```bash
        ./vendor/bin/phpunit
        ```

## Project Structure

A brief overview of the main files and directories within the `codestack` project root:

*   `api/`: Contains the core Slim application.
    *   `index.php`: The main entry point for the API (front controller).
    *   `openapi.yaml`: OpenAPI specification file for the API.
    *   `api-docs.html`: HTML page to render Swagger UI from `openapi.yaml`.
    *   `RateLimitMiddleware.php`: Custom middleware for rate limiting.
    *   `Actions/`: Houses all the action classes (request handlers/controllers) for different API endpoints.
    *   `config/`: Contains application configuration files.
        *   `dependencies.php`: Sets up service container dependencies (like database, HTMLPurifier).
        *   `middleware.php`: Configures application middleware.
        *   `routes.php`: Defines API routes and maps them to Actions.
*   `db/`: Database related files.
    *   `migrations/`: Phinx database migration files.
    *   `seeds/`: Phinx database seed files (for populating initial data).
*   `logs/`: Directory for application log files.
    *   `api-YYYY-MM-DD.log`: Daily API log files generated by Monolog.
    *   `htmlpurifier_cache/`: Cache directory for HTMLPurifier.
*   `postman/`: Exported Postman collection and environment files for API testing.
    *   `CodeStack API.postman_collection.json`: The Postman collection.
    *   `CodeStack Local Dev.postman_environment.json`: Postman environment for local development.
*   `tests/`: Contains all automated tests.
    *   `bootstrap.php`: PHPUnit bootstrap file (loads autoloader, environment variables).
    *   `Actions/`: Unit/Integration tests for the Action classes, mirroring the `api/Actions/` structure.
*   `vendor/`: Composer managed dependencies. This directory is not committed to version control.
*   `.env.example`: Template for the `.env` file (committed to version control).
*   `.gitignore`: Specifies intentionally untracked files that Git should ignore.
*   `composer.json`: Defines project dependencies and other metadata for Composer.
*   `composer.lock`: Records the exact versions of dependencies installed (typically committed).
*   `phinx.php`: Configuration file for the Phinx database migration tool.
*   `phpunit.xml.dist`: Default PHPUnit configuration file (used for running tests).
*   `README.md`: This file, providing information about the project.

---

This provides a solid foundation. You can adjust the paths, links, and details as needed based on your exact project structure and deployment plans. For example, the path to `api-docs.html` might need adjustment if it's not directly in the `api/` folder or if your local server setup serves it differently.