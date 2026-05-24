<?php
declare(strict_types=1);

namespace Strux\Support;

/**
 * Simple paginator DTO
 */
final class Paginator
{
    public Collection $items;
    public int $total;
    public int $perPage;
    public int $currentPage;
    public int $lastPage;
    public ?int $from;
    public ?int $to;
    public string $path;
    public array $query;

    public function __construct(Collection $items, int $total, int $perPage, int $currentPage, string $path = '/', array $query = [])
    {
        $this->items = $items;
        $this->total = $total;
        $this->perPage = max(1, $perPage);
        $this->currentPage = max(1, $currentPage);
        $this->lastPage = (int) ceil($total / $this->perPage);
        $this->from = $total === 0 ? null : (($this->currentPage - 1) * $this->perPage) + 1;
        $this->to = $total === 0 ? null : min($this->currentPage * $this->perPage, $total);
        $this->path = $path;
        $this->query = $query;
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function previousPage(): ?int
    {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    public function nextPage(): ?int
    {
        return $this->currentPage < $this->lastPage ? $this->currentPage + 1 : null;
    }

    /**
     * Build URL preserving existing query params and replacing page/per_page.
     */
    public function urlForPage(int $page): string
    {
        $query = $this->query;
        $query['page'] = max(1, $page);
        $query['per_page'] = $this->perPage;
        $qs = http_build_query($query);
        return rtrim($this->path, '/') . (strlen($qs) ? '?' . $qs : '');
    }
}