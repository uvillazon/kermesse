<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

final readonly class PageRequest
{
    public function __construct(
        public int $page,
        public int $pageSize,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    /** @return array{page: int, page_size: int, total: int, total_pages: int} */
    public function metadata(int $total): array
    {
        return [
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'total' => $total,
            'total_pages' => 0 === $total ? 0 : (int) ceil($total / $this->pageSize),
        ];
    }
}
