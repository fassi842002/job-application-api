<?php

// Offline verification of JobApplicationService.
//
// This script makes NO HTTP requests: the only HTTP client used is Symfony's
// MockHttpClient (in-memory responses), and the endpoint below is a placeholder.
// Run it with: php tests/job-application-service-check.php

require __DIR__.'/../vendor/autoload.php';

use App\DTO\JobApplicationDTO;
use App\Service\JobApplicationService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$applicant = [
    'first_name' => 'Jane',
    'last_name' => 'Doe',
    'email' => 'jane.doe@example.com',
    'telephone' => '',
    'motivation' => '',
    'cv' => '',
    'cv_extension' => 'pdf',
    'linkedin_url' => '',
    'github_url' => '',
];

$captured = [];

// The "test" flag decides whether the API stores the application. Report which
// mode the app is configured for, so nobody runs this blind.
$testMode = (new JobApplicationDTO(firstName: 'Jane', lastName: 'Doe', email: 'jane.doe@example.com'))->test;
printf(
    "Configured mode: %s\n\n",
    $testMode ? 'TEST - the API validates and stores nothing' : 'REAL - a real application is submitted',
);

/** Builds a client that never touches the network and records what was sent. */
$client = function (MockResponse $response) use (&$captured): MockHttpClient {
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $response) {
        $captured[] = [
            'method' => $method,
            'url' => $url,
            'body' => $options['body'] ?? null,
            'headers' => $options['headers'] ?? [],
        ];

        return $response;
    });
};

$service = fn (MockResponse $response, array $data = []): JobApplicationService => new JobApplicationService(
    $client($response),
    'https://example.test/api/apply/example-vacancy',
    [] !== $data ? $data : $applicant,
);

$checks = 0;
$failures = 0;
$assert = function (string $label, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    ++$checks;
    $ok || ++$failures;
    printf("[%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, '' !== $detail ? ' :: '.$detail : '');
};

// --- 1. successful test call -------------------------------------------------
$result = $service(new MockResponse(
    json_encode([
        'ok' => true,
        'test' => true,
        'message' => 'This was a test call. Nothing was submitted.',
        'vacancy' => ['slug' => 'example-vacancy', 'title' => 'Example Vacancy', 'department' => 'Development'],
    ], JSON_THROW_ON_ERROR),
    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
))->submitApplication();

$assert('200 test response is forwarded', 200 === $result->statusCode && true === ($result->payload['ok'] ?? null));
$assert('POST goes to the /api/apply/{slug} endpoint', 'POST' === ($captured[0]['method'] ?? null) && str_ends_with((string) ($captured[0]['url'] ?? ''), '/api/apply/example-vacancy'), (string) ($captured[0]['url'] ?? 'n/a'));

$sent = json_decode((string) $captured[0]['body'], true, 512, JSON_THROW_ON_ERROR);
$assert('payload uses the API snake_case field names', ['first_name', 'last_name', 'email', 'test'] === array_keys($sent), implode(',', array_keys($sent)));
$assert('required fields are correct', 'Jane' === $sent['first_name'] && 'Doe' === $sent['last_name'] && 'jane.doe@example.com' === $sent['email']);
$assert('payload test flag equals the configured default', $testMode === $sent['test'], 'test = '.var_export($sent['test'], true));
$assert('empty optional fields are omitted (no null/"" sent)', !array_intersect(['telephone', 'motivation', 'cv', 'cv_extension', 'linkedin_url', 'github_url'], array_keys($sent)), implode(',', array_keys($sent)));

// --- 2. cv + motivation present ---------------------------------------------
$captured = [];
$result = $service(
    new MockResponse('{"ok":true,"test":true}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
    [...$applicant, 'cv' => base64_encode('%PDF-1.4 fake'), 'cv_extension' => 'pdf', 'motivation' => 'Motivation'],
)->submitApplication();

$sent = json_decode((string) $captured[0]['body'], true, 512, JSON_THROW_ON_ERROR);
$assert('cv and cv_extension are sent together', isset($sent['cv'], $sent['cv_extension']) && 'pdf' === $sent['cv_extension']);
$assert('motivation is sent when filled in', 'Motivation' === ($sent['motivation'] ?? null));

// --- 3. the exact regression: 405 HTML page (vacancy URL / wrong method) -----
$captured = [];
$result = $service(new MockResponse('<html><body>405 Method Not Allowed</body></html>', [
    'http_code' => 405,
    'response_headers' => ['content-type' => 'text/html'],
]))->submitApplication();

$assert('405 HTML no longer throws, it is reported as 405', 405 === $result->statusCode, 'status '.$result->statusCode);
$assert('405 body is a JSON error payload', false === ($result->payload['ok'] ?? null) && isset($result->payload['message']));

// --- 4. rate limited: Retry-After must be forwarded -------------------------
$result = $service(new MockResponse('{"ok":false,"message":"Too many calls"}', [
    'http_code' => 429,
    'response_headers' => ['content-type' => 'application/json', 'retry-after' => '120'],
]))->submitApplication();

$assert('429 is forwarded', 429 === $result->statusCode);
$assert('Retry-After header is forwarded', '120' === ($result->headers['Retry-After'] ?? null), json_encode($result->headers));

// --- 5. invalid payload: 400 from the API -----------------------------------
$result = $service(new MockResponse('{"error":"invalid email"}', [
    'http_code' => 400,
    'response_headers' => ['content-type' => 'application/json'],
]))->submitApplication();

$assert('400 is forwarded with the API body', 400 === $result->statusCode && 'invalid email' === ($result->payload['error'] ?? null));

// --- 6. network down: TransportException must not escape ---------------------
$result = $service(new MockResponse('', ['error' => 'Connection refused']))->submitApplication();

$assert('transport error becomes 502, not a 500', 502 === $result->statusCode, 'status '.$result->statusCode);
$assert('transport error body is JSON', false === ($result->payload['ok'] ?? null) && isset($result->payload['error']));

printf("\n%d checks, %d failure(s)\n", $checks, $failures);
exit($failures > 0 ? 1 : 0);
