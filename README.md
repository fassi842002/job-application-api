# Job Application API client

A small Symfony application that **applies for a job through an API instead of a form**.

`POST /job-application/test` on this app forwards your application to a company's vacancies
API (`POST https://jobs.example.com/api/apply/{vacancy-slug}`) and mirrors the answer back
to you, including the upstream HTTP status code.

- The API treats a call as a **test** unless the payload says `"test": false`. One line in
  `src/DTO/JobApplicationDTO.php` decides which flag this app sends — see
  [Test mode vs. a real application](#test-mode-vs-a-real-application).
- Rejections stay visible: a `400`, `404`, `413`, `429` or `502` from the API is passed
  through as that status with a JSON body — never swallowed into a generic `500`.

---

## Requirements

| Requirement | Notes |
| --- | --- |
| PHP `>= 8.4` | see `composer.json` |
| `ext-ctype`, `ext-iconv` | required by the Symfony runtime |
| Composer 2 | dependency installation |
| Network access to the API host | the API call itself |
| Symfony CLI *(optional)* | nicer local server via `symfony serve` |

## Installation

```bash
git clone <your-repository-url> job-application
cd job-application
composer install
```

Create your local configuration from the committed template `/.env`:

```bash
cp .env .env.local        # Windows (cmd/PowerShell): copy .env .env.local
```

Then edit `.env.local` and replace the placeholders with your own values
(see [Environment variables](#environment-variables)). `.env.local` is git-ignored —
keep personal data there and never commit it.

Generate an application secret for local use:

```bash
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

Put the result in `.env.local` as `APP_SECRET=...`.

## Environment variables

All variables are read from the committed `.env` (placeholders/defaults) and overridden by
the git-ignored `.env.local`.

| Variable | Required | Placeholder / example | Purpose |
| --- | --- | --- | --- |
| `JOB_API_URL` | **yes** | `https://jobs.example.com/api/apply/senior-php-developer-42` | Full endpoint of the vacancy you apply to. The slug is the **last segment** of the vacancy URL on `jobs.example.com/vacancies`. |
| `JOB_APPLICANT_FIRST_NAME` | **yes** | `Jane` | First name (`first_name`). |
| `JOB_APPLICANT_LAST_NAME` | **yes** | `Doe` | Last name (`last_name`). |
| `JOB_APPLICANT_EMAIL` | **yes** | `jane.doe@example.com` | Valid e-mail address (`email`). |
| `JOB_APPLICANT_TELEPHONE` | no | `0612345678` | Phone number; omitted from the payload when empty. |
| `JOB_APPLICANT_MOTIVATION` | no | `I build things...` | Motivation, max 5000 characters; omitted when empty. |
| `JOB_APPLICANT_CV` | no | base64 string | CV as base64 **without** the `data:...;base64,` prefix; omitted when empty. |
| `JOB_APPLICANT_CV_EXTENSION` | no | `pdf` | Extension of the CV (default `pdf`); only sent together with a CV. |
| `JOB_APPLICANT_LINKEDIN` | no | `https://www.linkedin.com/in/jane-doe` | LinkedIn profile URL; omitted when empty. |
| `JOB_APPLICANT_GITHUB` | no | `https://github.com/jane-doe` | GitHub profile URL; omitted when empty. |
| `APP_SECRET` | yes (dev/prod) | random hex string | Symfony application secret. |

Optional values that are empty or `null` are **left out** of the JSON payload entirely —
the API accepts them as optional, so they are not sent as `null` or `""`.

The host `jobs.example.com` is a documentation placeholder (RFC 2606) that resolves to no
real service: replace it, and the slug, with the real endpoint of the vacancy you target.

## Running the app

```bash
symfony serve -d                 # http://localhost:8000
```

Without the Symfony CLI:

```bash
php -S 127.0.0.1:8000 -t public
```

Useful commands:

```bash
php bin/console debug:router                       # list routes
php bin/console debug:container App\\Service\\JobApplicationService   # how the service is wired
php bin/console lint:container                     # validate the service container
php bin/console lint:yaml config/                  # validate YAML config
php bin/console cache:clear                        # after changing .env or config
```

## Calling the endpoint

```bash
curl -X POST http://localhost:8000/job-application/test
```

The route takes no request body: everything sent to the API comes from your `.env.local`.
The response mirrors the API, so with `"test": true` you get `200`:

```json
{
  "ok": true,
  "test": true,
  "message": "This was a test call. Nothing was submitted.",
  "vacancy": {
    "slug": "senior-php-developer-42",
    "title": "Senior PHP Developer",
    "department": "Engineering"
  }
}
```

## Test mode vs. a real application

The `test` flag in the payload decides what the API does with your application:

| `test` | The API | Status |
| --- | --- | --- |
| `true` | Validates the payload and tells you what it understood. **Stores nothing.** | `200` |
| `false` | Accepts a **real application**, handled like any other. | `201` |

It is a single constructor default in `src/DTO/JobApplicationDTO.php`:

```php
public bool $test = true;    // false = really apply, true = dry run
```

> ⚠️ **Check that line before you run anything.** If it reads `false`, the `curl` command
> above submits a real application for the vacancy in `JOB_API_URL`. Set it to `true` while
> developing or testing the setup, and check the [limits](#limits-and-etiquette) before you
> switch it back: a real application is stored, and you get **one real application per hour
> per IP address**.

`php tests/job-application-service-check.php` prints the configured mode before it runs any
check, so you can always see which of the two you are in.

## How it works

```
POST /job-application/test
        │
        ▼
JobApplicationController::test()        thin: calls the service, mirrors status + body
        │
        ▼
JobApplicationService::submitApplication()
        │   builds the payload, performs the POST, never throws on 4xx/5xx
        ▼
JobApplicationDTO::toArray()            camelCase properties -> snake_case API fields
        │
        ▼
POST https://jobs.example.com/api/apply/{vacancy-slug}
```

| File | Responsibility |
| --- | --- |
| `src/Controller/JobApplicationController.php` | Route `POST /job-application/test`; returns the service result as JSON with the upstream status code and headers. |
| `src/Service/JobApplicationService.php` | Builds the DTO, calls the API with `Accept: application/json`, converts transport/non-JSON failures into a `502`, forwards `Retry-After` on `429`. |
| `src/DTO/JobApplicationDTO.php` | The payload: maps to the API's `snake_case` field names and omits empty optional fields. |
| `src/DTO/JobApplicationResult.php` | Read-only result object: `statusCode`, `payload`, `headers`. |
| `config/services.yaml` | Wires `%env(JOB_API_URL)%` and the applicant array into the service; excludes `src/DTO/` from service registration. |
| `tests/job-application-service-check.php` | Offline regression check (no HTTP requests, see [Tests](#tests)). |

## Upstream API contract

`POST https://jobs.example.com/api/apply/{vacancy-slug}`

| Field | Type | Notes |
| --- | --- | --- |
| `first_name` | string, required | First name. |
| `last_name` | string, required | Last name. |
| `email` | string, required | Valid e-mail address. |
| `telephone` | string, optional | Phone number. |
| `motivation` | string, optional | Max 5000 characters. |
| `cv` | string, optional | CV as base64, no data URL prefix. |
| `cv_extension` | string, optional | Default `pdf`. |
| `linkedin_url` | string, optional | LinkedIn profile. |
| `github_url` | string, optional | GitHub profile. |
| `test` | boolean, default `true` | `false` submits for real. |

Statuses returned by the API — and passed through by this app:

| Status | Meaning |
| --- | --- |
| `200` | Test call succeeded, nothing was sent. |
| `201` | Application accepted. |
| `400` | Invalid JSON or an invalid field. |
| `404` | No vacancy with that slug. |
| `413` | Request larger than 4 MB. |
| `429` | Too many calls — see the forwarded `Retry-After` header. |
| `502` | The application was not accepted by the API. |

This app adds its own `502` when the API cannot be reached at all
(`The job application API is unreachable.`) or answers with something that is not JSON
(`The job application API returned a response that is not JSON.`).

## Adding your CV

Base64-encode the file and put the result on one line in `JOB_APPLICANT_CV` — the raw
base64 only, without the `data:application/pdf;base64,` prefix:

```bash
# Linux / macOS
base64 -w0 cv.pdf
```

```powershell
# Windows PowerShell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("cv.pdf"))
```

```bash
# PHP
php -r "echo base64_encode(file_get_contents('cv.pdf'));"
```

Keep `JOB_APPLICANT_CV_EXTENSION` in sync with the file (`pdf`, `docx`, ...). The whole
request, CV included, must stay under **4 MB**.

## Limits and etiquette

- **5 calls per hour per IP address** (test calls included).
- **1 real application per hour per IP address**.
- **Max 4 MB per request**, CV included.
- Do not retry automatically, and never loop a `test: false` call. On a `429`, wait for the
  `Retry-After` value that this app forwards to you.
- Keep test mode while developing.

## Tests

```bash
php tests/job-application-service-check.php
```

This check is **fully offline**: every HTTP client in it is a Symfony `MockHttpClient`
(its only URL is the placeholder `https://example.test/...`), so it never contacts the real
API. It covers the payload shape (`snake_case` keys, omitted empties, `cv` + `cv_extension`
together), status pass-through (`200`, `400`, `429` + `Retry-After`, `405` HTML body) and the
transport-failure `502` path. It prints the configured test mode first, and its last line
must read `0 failure(s)`.

`symfony/test-pack` (PHPUnit) is not installed in this project, so this script stands in for
a `KernelTestCase`/`WebTestCase` suite. Once the test pack is installed, it should be
converted into a real test.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `500` with `Cannot autowire service "App\Service\JobApplicationService": argument "$jobApiUrl" ... is type-hinted "string"` | The service id in `config/services.yaml` does not match the class name — service ids are **case-sensitive**, so a lowercase id creates a second, unused definition and the real one stays unconfigured. | Define it as exactly `App\Service\JobApplicationService:` with `$jobApiUrl` / `$jobApplicant` arguments. |
| `500` with `HTTP/1.1 405 Method Not Allowed returned for "https://jobs.example.com/vacancies/..."` | `JOB_API_URL` points to the vacancy **web page**, which only serves `GET`. | Use the API endpoint: `.../api/apply/{slug}`. |
| `404` "No vacancy with that slug" | Wrong slug. | Copy the last segment of the vacancy URL from `jobs.example.com/vacancies`. |
| `502` `The job application API returned a response that is not JSON.` | Upstream returned HTML (error page, proxy, WAF). | Check the slug and the endpoint; re-read the status code in the response. |
| `502` `The job application API is unreachable.` | DNS, TLS or timeout while contacting the API. | Check connectivity/proxy settings; the `error` field contains the transport message. |
| `429` | Rate limit hit. | Wait for the forwarded `Retry-After` seconds. |
| `Environment variable not found: JOB_API_URL` | `.env.local` missing or incomplete. | Copy `.env` to `.env.local`, fill it in, then `php bin/console cache:clear`. |
| Changed `.env` but nothing happens | Container/parameter cache. | `php bin/console cache:clear`. |
| `Xdebug: [Step Debug] Time-out connecting to debugging client` | Local PHP/Xdebug configuration, unrelated to this app. | Ignore, or set `xdebug.mode=off` / `xdebug.start_with_request=no`. |

## Project layout

```
├── bin/console
├── config/
│   ├── packages/            framework, routing and cache configuration
│   ├── routes.yaml          imports the #[Route] attributes from src/Controller
│   └── services.yaml        service wiring, %job_api_url% / %job_applicant%
├── public/index.php         front controller
├── src/
│   ├── Controller/JobApplicationController.php
│   ├── DTO/JobApplicationDTO.php
│   ├── DTO/JobApplicationResult.php
│   ├── Kernel.php
│   └── Service/JobApplicationService.php
├── tests/job-application-service-check.php
├── .env                     committed defaults and placeholders
└── composer.json
```

Not committed (see `.gitignore`): `.env.local`, `.env.*.local`, `var/`, `vendor/`,
`public/bundles/`.
