<?php

namespace App\Domain\Intelligence\Contracts;

interface AiDecisionProvider
{
    public function name(): string;

    public function model(array $config = []): string;

    public function generateProposal(array $context, array $config): array|string;
}
