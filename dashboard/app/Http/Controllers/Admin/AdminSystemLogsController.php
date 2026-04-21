<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\FilterSecurityLogsRequest;
use App\Repositories\SecurityLogRepository;
use App\ViewData\LogsIndexViewData;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class AdminSystemLogsController extends Controller
{
    public function __construct(private readonly SecurityLogRepository $securityLogs) {}

    public function security(FilterSecurityLogsRequest $request): View
    {
        $payload = $this->securityLogs->fetchIndexPayload($request->validated(), null, true);
        $viewData = new LogsIndexViewData($payload, $request->query(), route('admin.logs.security'), true);

        return view('admin.logs.security', $viewData->toArray());
    }

    public function platform(): View
    {
        $files = collect(File::glob(storage_path('logs/*.log')) ?: [])
            ->sortByDesc(fn (string $path): int => File::lastModified($path))
            ->take(5)
            ->values();

        $entries = $files->flatMap(fn (string $path): array => $this->newestEntriesFrom($path, 80))->take(250)->all();

        return view('admin.logs.platform', [
            'logFiles' => $files->map(fn (string $path): array => [
                'name' => basename($path),
                'updated_at' => date('Y-m-d H:i:s', File::lastModified($path)),
                'size' => File::size($path),
            ])->all(),
            'entries' => $entries,
        ]);
    }

    private function newestEntriesFrom(string $path, int $limit): array
    {
        $contents = File::exists($path) ? (string) File::get($path) : '';
        if ($contents === '') {
            return [];
        }

        $blocks = preg_split('/(?=^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/m', trim($contents)) ?: [];

        return collect($blocks)
            ->filter(fn (string $block): bool => trim($block) !== '')
            ->reverse()
            ->take($limit)
            ->map(fn (string $block): array => [
                'file' => basename($path),
                'text' => trim($block),
            ])
            ->values()
            ->all();
    }
}
