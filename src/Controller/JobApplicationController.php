<?php

namespace App\Controller;

use App\Service\JobApplicationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class JobApplicationController
{
    #[Route('/job-application/test', name: 'job_application_test', methods: ['POST'])]
    public function test(JobApplicationService $jobApplicationService): JsonResponse
    {
        $result = $jobApplicationService->submitApplication();

        // Mirror the API contract: 200 test call, 201 accepted, 400/404/413/429/502 otherwise.
        return new JsonResponse($result->payload, $result->statusCode, $result->headers);
    }
}
