<?php

namespace App\Service;

use App\DTO\JobApplicationDTO;
use App\DTO\JobApplicationResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class JobApplicationService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $jobApiUrl,
        private array $jobApplicant,
    ) {
    }

    public function submitApplication(): JobApplicationResult
    {
        $applicationData = new JobApplicationDTO(
            firstName: $this->jobApplicant['first_name'],
            lastName: $this->jobApplicant['last_name'],
            email: $this->jobApplicant['email'],
            telephone: $this->jobApplicant['telephone'],
            motivation: $this->jobApplicant['motivation'],
            cv: $this->jobApplicant['cv'],
            cvExtension: $this->jobApplicant['cv_extension'],
            linkedinUrl: $this->jobApplicant['linkedin_url'],
            githubUrl: $this->jobApplicant['github_url'],
        );

        try {
            $response = $this->httpClient->request('POST', $this->jobApiUrl, [
                'headers' => ['Accept' => 'application/json'],
                'json' => $applicationData->toArray(),
            ]);

            // No exception is thrown here: a 4xx/5xx is a valid outcome we report
            // back to the caller, it must not become a 500 on our side.
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            return $this->failure(
                Response::HTTP_BAD_GATEWAY,
                'The job application API is unreachable.',
                $exception->getMessage(),
            );
        } catch (DecodingExceptionInterface) {
            // 413 pages, WAF blocks, HTML error pages, ... are not JSON.
            return $this->failure(
                $statusCode ?? Response::HTTP_BAD_GATEWAY,
                'The job application API returned a response that is not JSON.',
            );
        }

        $headers = [];
        if (Response::HTTP_TOO_MANY_REQUESTS === $statusCode) {
            // Documented in the API contract: check Retry-After when rate limited.
            $retryAfter = $response->getHeaders(false)['retry-after'] ?? [];
            if ([] !== $retryAfter) {
                $headers['Retry-After'] = implode(', ', $retryAfter);
            }
        }

        return new JobApplicationResult($statusCode, $payload, $headers);
    }

    /**
     * @return JobApplicationResult the given HTTP status with a small JSON error body
     */
    private function failure(int $statusCode, string $message, ?string $detail = null): JobApplicationResult
    {
        $payload = [
            'ok' => false,
            'message' => $message,
        ];

        if (null !== $detail) {
            $payload['error'] = $detail;
        }

        return new JobApplicationResult($statusCode, $payload);
    }
}
