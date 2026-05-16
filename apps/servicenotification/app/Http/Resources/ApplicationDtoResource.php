<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Contracts\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

final readonly class ApplicationDtoResource implements Responsable
{
    public function __construct(
        private Arrayable $dto,
        private int $status = 200,
    ) {}

    public function toResponse($request): JsonResponse
    {
        return response()->json($this->dto->toArray(), $this->status);
    }
}
