# Ledgerly — Accountant AI Assistant

Laravel 12, Inertia 3, and React workspace based on the original Ledgerly prototype.

## Run with Docker

Install Git and Docker Desktop, start Docker Desktop, and use Linux containers. PHP, Composer, Node.js, and MySQL are installed inside the images. From a fresh GitHub clone, open a terminal in the directory containing `compose.yaml`.

```powershell
Copy-Item .env.docker.example .env.docker
docker compose --env-file .env.docker up -d --build
```

On macOS or Linux, replace the first command with `cp .env.docker.example .env.docker`. Edit the database passwords in `.env.docker` before starting if needed. Keep using `--env-file .env.docker` for the commands below, so Compose uses Docker settings instead of an existing native Laravel `.env`.

Open [http://localhost:8080](http://localhost:8080) and create an account. The first build downloads dependencies and can take several minutes. No demo data or existing local database is copied. The application is bound to this machine's loopback address. This Compose setup is for local use.

Compose starts MySQL, runs the `init` service to create a persistent encryption key and apply migrations, then starts the Apache/PHP app, queue worker, and scheduler. You do not need separate `artisan serve`, queue, scheduler, or Vite terminals. Frontend assets are built into the image.

The database, Laravel storage, and generated encryption key live in named Docker volumes and survive container rebuilds and `down`. Keep the key volume with database backups because Gmail tokens are encrypted with it. Leave `APP_KEY` blank for automatic generation, or supply one stable existing key when deliberately restoring a matching database. Never generate a new key for a database containing encrypted tokens.

```powershell
# Check containers and initial migrations
docker compose --env-file .env.docker ps -a
docker compose --env-file .env.docker logs --tail=100 init app queue scheduler

# Stop the project while keeping its data
docker compose --env-file .env.docker down

# After pulling code changes or changing Docker environment settings
docker compose --env-file .env.docker down
docker compose --env-file .env.docker up -d --build

# Run Laravel commands in the application container
docker compose --env-file .env.docker exec --user www-data app php artisan migrate:status
```

`init` should show **Exited (0)** after successful startup. If it fails, inspect its logs, fix the reported problem, then run `up -d --build` again. Avoid `down --volumes` unless you intend to permanently delete the Docker database, stored files, and encryption key. Database credentials initialize a new database volume; changing them later also requires updating the existing MySQL user.

For a different HTTP port, change `APP_PORT`, `APP_URL`, and `GOOGLE_REDIRECT_URI` together in `.env.docker`. The default Docker Gmail callback is `http://localhost:8080/gmail/callback`; register that exact URI in Google Cloud and use the same hostname in your browser. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env.docker`, then recreate the containers with the commands above. Each tester needs access to the OAuth application and must connect their own Gmail account. Without Google credentials, account creation, CSV uploads, and manual request workflows still work.

`OPENAI_API_KEY` is blank by default, so AI proposals remain disabled. Configure it in `.env.docker` only when you want API-backed proposals. The Docker build excludes local environment files, database files, uploads, dependencies, and cached configuration. Commit `.env.docker.example`, Docker files, and dependency lockfiles to GitHub; keep `.env.docker` and `.env` private.

## Run locally

Requires PHP 8.2 or newer, Composer, Node.js, and either MySQL or PDO SQLite.

```powershell
composer install
npm ci
php artisan migrate
npm run build
php artisan serve
```

For a new installation, create `.env` from `.env.example` and generate an application key once with `php artisan key:generate`. Configure the database before migrating. SQLite installations also need an empty `database/database.sqlite` file. The current main checkout uses its configured MySQL connection.

Open [http://127.0.0.1:8000](http://127.0.0.1:8000). Use `npm run dev` in a second terminal while editing React. The UI source lives in `resources/js/pages` and `resources/js/components`, with shared styles in `resources/css/workspace.css`.

## Workflow

- Create an account to keep a persistent workspace, or enter the local demo to explore four fictional requests.
- Open **Clients** to register companies and their approved contact addresses. Each client can have several contacts. Use **Manage contacts** to edit addresses or pause approval.
- Import and approve a CSV ledger in **Data sources**. Download the template for the required columns.
- Use **Update** beside a source to replace it with a verified CSV, or **Remove** to stop using that source for new drafts. Existing requests retain their saved record snapshots and attachments. Client contacts remain registered. New drafts prefer the most recently updated matching source; removing it allows another matching uploaded source to be used.
- New accounts proceed to **Connect Gmail** after registration. Add approved client contacts and authorize Gmail to queue their first import, or add a request manually.
- Search and filter the request queue. Open a request to inspect its original email, cited source rows, downloadable ledger excerpt, and draft.
- Save wording changes, record missing client or period details, start review, approve, and send. Manual requests use simulated delivery. Imported Gmail requests can send a real reply after approval.
- Use **Documents** for attachment downloads and **Activity log** for source references, changes, approvals, and delivery events.

The same workspace owner reviews and approves replies. Each account has its own records and Gmail connection. Requests and source imports are stored in the database. Attachment excerpts are immutable snapshots whose hashes and client/period identities are checked during review and delivery. Source imports apply to future matches.

CSV is the supported import format. Export an Excel worksheet as CSV using the template columns. Original invoice PDFs and arbitrary workbook layouts need additional import handling.

## Connect Gmail / Google Workspace

1. Enable the Gmail API in your Google Cloud project.
2. Configure the OAuth consent screen. In testing mode, add every Gmail account that will connect as an authorized test user.
3. Create an OAuth client of type **Web application**.
4. Register the exact redirect URI `http://127.0.0.1:8000/gmail/callback`.
5. Set these environment values.

```dotenv
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/gmail/callback
```

Run `php artisan config:clear`, visit the app using the matching host, open **Settings → Connect Gmail**, and complete Google's consent flow. The app requests Gmail read and send scopes. Tokens are encrypted with the application's key.

The developer configures one Google OAuth client for the application. Each workspace user signs into Google and authorizes their own mailbox. Public external access requires completing Google's applicable OAuth verification requirements for the requested Gmail scopes. [Google OAuth consent configuration](https://developers.google.com/workspace/guides/configure-oauth-consent), [Gmail scope requirements](https://developers.google.com/workspace/gmail/api/auth/scopes).

Approved ledger imports also register their client contacts from `client_email`. Existing approved sources populate the directory during the upgrade. Additional contacts can be registered before a ledger is available. Client names are fixed registration identities used to match source records.

Use **Clients → Delete** to remove a client and their registered contacts. Shared addresses remain eligible when another approved client uses them. Uploaded ledgers and request snapshots are retained. Importing a ledger containing the deleted client registers that client again.

An incoming Gmail message must match an active approved contact and a single client/period before financial records are selected. Shared contact addresses are allowed across clients, with clarification required for ambiguous requests. Period recognition supports YYYY-MM, YYYY-Q1 through YYYY-Q4, named months with a year, and quarters with a year.

Gmail searches are restricted to incoming messages sent by approved contacts before downloading message bodies. Candidate message headers and folder labels are verified before fetching full content. A workspace with no active contacts makes no Gmail sync requests.

The first import follows every page of incoming client emails, including archived messages. Sent mail, drafts, spam, trash, and messages merely addressed to clients are excluded. Message IDs are deduplicated per workspace and mailbox.

Later checks search since the previous round with a five-minute overlap. Adding, removing, or pausing contacts invalidates older queued jobs and resets the search for the updated contact list. Save changes to automatically queue matching past messages, or select **Clients → Import past client emails**. Use that button for backdated messages migrated into Gmail as well.

The workspace shows Gmail records only when their sender is currently approved and their stored labels qualify as incoming client mail. Previously imported records remain stored, while nonmatching records are hidden from lists, counts, and review views. Reconnecting or disconnecting also invalidates older jobs.

Run these commands in two additional terminals from the main project folder.

```powershell
php artisan queue:work database --timeout=300 --tries=3
```

```powershell
php artisan schedule:work
```

The scheduler queues syncs every minute. The browser refreshes mailbox data every 10 seconds while a connected workspace is open outside request editing. Keep the queue worker and scheduler running for automatic imports. The database queue retry interval must exceed the job timeout. The configured default is 360 seconds, also shown as DB_QUEUE_RETRY_AFTER in the environment example.

Mailbox browsing uses 50 messages per page and searches across stored messages on the server. Drafts, documents, and activity panels show the latest 100 relevant request records. Imported message text is limited to 10,000 characters. Open the original message in Gmail for its full content and original attachments.

Replies use the original sender and thread. Delivery is claimed before the API call to prevent duplicate sends. Failed or uncertain delivery requires checking Gmail Sent. A process interruption may leave a request at **Sending**, which also requires checking Gmail before any manual recovery.

Google authorization, inbox access, and real delivery require your credentials and mailbox consent. Automated tests use mocked Gmail responses.

## Optional AI drafting

Template drafts work without an API key. To enable the **Prepare AI proposal** button, configure these environment values.

```dotenv
OPENAI_API_KEY=your-api-key
OPENAI_MODEL=gpt-4.1-mini
```

Run `php artisan config:clear`. The reviewer explicitly requests an AI proposal from a matched request. This sends the email, current draft, and selected approved ledger rows to OpenAI. The response uses a structured schema, and the server checks its row citations and rejects new numerical values. The proposal still requires review against the source and the client's actual request. The model cannot choose workspace access, change attachments, approve, or send.

[OpenAI structured output documentation](https://developers.openai.com/api/docs/guides/structured-outputs) describes the response contract. [Google's Gmail sending guide](https://developers.google.com/workspace/gmail/api/guides/sending) and [OAuth web server guide](https://developers.google.com/identity/protocols/oauth2/web-server) describe the mailbox integration.

## Button styles

Use the shared classes in `resources/css/workspace.css` for buttons and action links: `btn-primary` (main action), `btn-secondary` (cancel/navigation), `btn-danger` (delete/remove/disconnect), `btn-info` (view/download/sync), `btn-success` (approve), `btn-warning` (review), and `btn-ghost` (subtle action). Existing `primary` and `outline` classes map to primary and secondary styles. Button text identifies the action alongside its color. All variants include hover, keyboard focus, and disabled states.

## Verify

```powershell
php artisan test
npm run build
```

Feature tests use an in-memory SQLite database, sanitized fixtures, and mocked external API calls. Keep credentials and client records out of source control.
