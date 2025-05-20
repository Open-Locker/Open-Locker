# Project Setup Guide

This guide describes the necessary steps to set up and run the project, particularly the backend, locally.

## 1. Prerequisites

Make sure the following tools are installed and running on your system:

*   **Docker** 

It is recommended to use Docker to handle dependencies like a specific PHP version.

## 2. Getting Started

1.  **Clone the repository** from GitHub.
2.  **Navigate into the project directory**.

The project is structured as a mono-repo and contains directories for the **`backend`**, the **`app`**, and **`packages`** (currently only `locker-api`). The `locker-api` was generated from the OpenAPI specs provided by the backend.

## 3. Backend Setup (Laravel)

The backend is based on **Laravel** . It is managed via a **`docker-compose.yml` file** that provides all necessary services for local development.

1.  **Start the Docker services:**
    *   Change into the backend directory (`locker-backend`).
    *   Execute the command:
        ```bash
        docker compose up
        ```
    *   Make sure Docker is running. This command starts the services defined in `docker-compose.yml`. By default, these include at least **two workers** for the application (one for HTTP requests, one as a background worker) and **Mailpit**. A Selenium container for tests is also defined but might not be necessary depending on the repo version and needs.

2.  **Install Composer Dependencies:**
    *   PHP dependencies are managed via Composer. Since you likely don't have a local PHP 8.4 instance, you should run Composer through Docker.
    *   Open a **second terminal window**.
    *   Run the Composer install command inside the Docker container. The service name for the Laravel backend is typically `laravel-test` (see `docker-compose.yml`).
        ```bash
        docker compose exec laravel-test composer install
        ```
    *   This might take a moment as dependencies are downloaded. You might need to pull the latest changes from the repository if you encounter issues or have an older version.

3.  **Create the `.env` Environment File:**
    *   The project requires a `.env` file for configuration.
    *   Copy the provided example environment file:
        ```bash
        cp example.env .env
        ```
    *   Make sure the file is now named `.env` (remove the `.example` part).
    *   This file contains important settings such as the application URL (defaulting to `localhost`), the app key, mail system configurations, etc. A correct `.env` is crucial to avoid errors (such as operating in production mode).

4.  **Set up the Database:**
    *   The system defaults to using an **SQLite database** for simplicity.
    *   Create the SQLite file. It should be located in the `database` directory. The file name should be `database.sqlite`.
        ```bash
        touch database/database.sqlite
        ```
    *   Run the database migrations and populate the database with test data (Seeding). Laravel provides a script called **`sail`** as a wrapper for typical Docker commands, located in `vendor/bin`. You can use `sail` to execute commands within the Docker container. `sail` is an interface to communicate with your Laravel Docker containers.
        *   Ensure your Docker containers are running with `docker compose up` or `sail up`.
        *   Execute the migration and seeding command:
            ```bash
            sail artisan migrate:fresh --seed
            ```
        *   `migrate:fresh` resets the database and runs all migrations again. `--seed` runs the seeders to create demo data, which is especially helpful when working with the app.

5.  **Link Storage for Images:**
    *   Images won't work initially because a system link is missing.
    *   Create this link using the following command:
        ```bash
        sail artisan storage:link
        ```
    *   After this, images should be displayed correctly in the app and the admin panel.

## 4. Accessing Running Services

Once all steps are completed, you should be able to access the different parts of the application:

*   **Laravel Default Page:** The application should be running on port **80**. If you open `localhost` in your browser, you should see the default Laravel welcome page (unless it has been removed).
*   **Admin Panel:** The admin panel is available under the **`/admin` URL**.
    *   You can log in with the default data created by the seeder.
    *   The default username is **`user@example.com`**.
    *   The default password is **`string`**. These credentials can be found in the seeder file (`database/seeders/DatabaseSeeder.php`).
*   **API Documentation:** The API documentation is accessible under the **`/docs/api` URL**. Here you can explore and test the REST API endpoints. You can log in with the default user to obtain a token and test authenticated requests (e.g., fetching items).
*   **Mailpit:** Mailpit intercepts all emails sent by the system (e.g., for user authentication or password resets). You can access the Mailpit interface to review these emails. Mailpit is a system to mock an email sending and receiving server.

## 5. Notes and Troubleshooting

*   If you encounter **500 errors**, check if the **`.env` file was created correctly**. The missing `.env` file can cause this issue.
*   If there are issues starting the containers, **pull the latest changes from the repository**, as errors have been fixed and the configuration has been streamlined. You might have an old version and need to pull some changes.

## 6. Optional Configuration

*   **Git Hooks:** The project supports Git hooks, particularly in the backend folder. There is a structure to instantiate hooks, e.g., to check code formatting before committing. While currently not used in the `locker-app`, they are used in the backend folder.
