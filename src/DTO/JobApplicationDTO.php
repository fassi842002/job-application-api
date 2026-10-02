<?php

namespace App\DTO;

final class JobApplicationDTO
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $telephone = null,
        public ?string $motivation = null,
        public ?string $cv = null,
        public string $cvExtension = 'pdf',
        public ?string $linkedinUrl = null,
        public ?string $githubUrl = null,
        public bool $test = true,
    ) {
    }

    /**
     * *
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'test' => $this->test,
        ];

        $optional = [
            'telephone' => $this->telephone,
            'motivation' => $this->motivation,
            'cv' => $this->cv,
            'linkedin_url' => $this->linkedinUrl,
            'github_url' => $this->githubUrl,
        ];

        foreach ($optional as $field => $value) {
            if (null !== $value && '' !== trim($value)) {
                $payload[$field] = $value;
            }
        }

        if (isset($payload['cv'])) {
            $payload['cv_extension'] = $this->cvExtension;
        }

        return $payload;
    }
}
