<?php

namespace App\Livewire\Results;

use App\Models\QueryRequest;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Streams a stored NDJSON result file page-by-page. The file is read with a
 * stream and never loaded fully into memory, so 10k-row results stay cheap.
 */
class Viewer extends Component
{
    public QueryRequest $queryRequest;

    #[Locked]
    public int $page = 1;

    #[Locked]
    public int $perPage = 50;

    public function mount(): void
    {
        $this->authorize('viewResult', $this->queryRequest);
    }

    public function nextPage(): void
    {
        if ($this->page < $this->lastPage()) {
            $this->page++;
        }
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil(($this->queryRequest->result_row_count ?? 0) / $this->perPage));
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function rows(): array
    {
        if (! $this->queryRequest->hasResult()) {
            return [];
        }

        $stream = Storage::disk($this->queryRequest->result_disk)
            ->readStream($this->queryRequest->result_path);

        if ($stream === null) {
            return [];
        }

        $skip = ($this->page - 1) * $this->perPage;
        $rows = [];

        try {
            $index = 0;

            while (($line = fgets($stream)) !== false) {
                if ($index++ < $skip) {
                    continue;
                }

                if (count($rows) >= $this->perPage) {
                    break;
                }

                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    public function render()
    {
        return view('livewire.results.viewer', [
            'columns' => $this->queryRequest->result_columns ?? [],
            'rows' => $this->rows(),
            'lastPage' => $this->lastPage(),
        ]);
    }
}
