<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use League\Csv\Writer;
use Carbon\Carbon;
use Illuminate\Auth\Access\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class ExportService {
    private array $config;
    private $logger;

    public function __construct(?LoggerInterface $logger = null) {
        $this->logger = $logger;
        // TODO: Initialize configuration parameters
        $this->config = [
            'max_export_size' => env('MAX_EXPORT_SIZE', 10000),
            'export_timeout' => env('EXPORT_TIMEOUT', 300),
            'date_formats' => ['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y'],
            'download_expiry_minutes' => env('DOWNLOAD_EXPIRY_MINUTES', 60),
            'enable_analytics' => env('ENABLE_EXPORT_ANALYTICS', true),
        ];
    }

    public function exportEntries(): JsonResponse {
        AppLogger::debug('Export entries...');
        try {
            $events = Event::all()->sortBy([
                ['start_datetime', 'asc']
            ]);
            $tasks = Task::all()->sortBy([
                ['due_date', 'asc'],
                ['priority', 'asc']
            ]);

            return response()->json([$events, $tasks], 200);
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('Database query failed in exportEntries', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            return response()->json([
                'error' => 'Failed to retrieve data for export',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // NEW: Export events to iCalendar (.ics) format
    public function exportEventsToIcs($startDate = null, $endDate = null): Response {
        $this->trackExportEvent('events_ics_export', [
            'user_id' => Auth::id(),
            'timestamp' => now()->toISOString(),
            'export_type' => 'events_ics',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $this->validateExportPermissions('export_events');

        try {
            $events = $this->getFilteredEvents($startDate, $endDate);
            $icsContent = $this->generateIcsContent($events);

            $filename = $this->generateIcsFilename($startDate, $endDate);

            return $this->downloadIcsResponse($icsContent, $filename);
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('ICS export failed', [
                    'error' => $e->getMessage(),
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]);
            }

            throw new \RuntimeException('Failed to generate ICS export: ' . $e->getMessage());
        }
    }

    // NEW: Generate iCalendar content from events
    protected function generateIcsContent($events): string {
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//App//Event Calendar//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";

        foreach ($events as $event) {
            $ics .= $this->generateEventIcs($event);
        }

        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    // NEW: Generate individual event in iCalendar format
    protected function generateEventIcs(Event $event): string {
        $uid = $event->id . '@' . request()->getHost();
        $created = $event->created_at ? $event->created_at->format('Ymd\THis\Z') : now()->format('Ymd\THis\Z');
        $start = Carbon::parse($event->start_datetime)->format('Ymd\THis');
        $end = $event->end_datetime
            ? Carbon::parse($event->end_datetime)->format('Ymd\THis')
            : Carbon::parse($event->start_datetime)->addHour()->format('Ymd\THis');

        $ics = "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$created}\r\n";
        $ics .= "DTSTART:{$start}\r\n";
        $ics .= "DTEND:{$end}\r\n";
        $ics .= "SUMMARY:" . $this->escapeIcsText($event->title) . "\r\n";

        if ($event->description) {
            $ics .= "DESCRIPTION:" . $this->escapeIcsText($event->description) . "\r\n";
        }

        if ($event->location) {
            $ics .= "LOCATION:" . $this->escapeIcsText($event->location) . "\r\n";
        }

        // Add status if available
        if ($event->status) {
            $ics .= "STATUS:" . strtoupper($event->status) . "\r\n";
        }

        // Add last modified timestamp
        if ($event->updated_at) {
            $modified = $event->updated_at->format('Ymd\THis\Z');
            $ics .= "LAST-MODIFIED:{$modified}\r\n";
        }

        $ics .= "END:VEVENT\r\n";

        return $ics;
    }

    // NEW: Escape text for iCalendar format
    protected function escapeIcsText(string $text): string {
        // Remove or replace special characters
        $text = str_replace(["\r\n", "\n"], "\\n", $text);
        $text = str_replace(["\r"], "\\n", $text);
        $text = str_replace([','], '\,', $text);
        $text = str_replace([';'], '\;', $text);

        // Limit line length to 75 characters as per RFC5545
        $text = wordwrap($text, 75, "\r\n ", true);

        return $text;
    }

    // NEW: Generate appropriate filename for ICS export
    protected function generateIcsFilename($startDate = null, $endDate = null): string {
        $baseName = 'events';

        if ($startDate && $endDate) {
            $start = Carbon::parse($startDate)->format('Y-m-d');
            $end = Carbon::parse($endDate)->format('Y-m-d');
            return "{$baseName}_{$start}_to_{$end}.ics";
        } elseif ($startDate) {
            $start = Carbon::parse($startDate)->format('Y-m-d');
            return "{$baseName}_from_{$start}.ics";
        } else {
            return "{$baseName}_" . now()->format('Y-m-d') . ".ics";
        }
    }

    // NEW: Download response for ICS file
    protected function downloadIcsResponse(string $content, string $filename): Response {
        try {
            if (empty($content)) {
                throw new \RuntimeException('Generated ICS content is empty');
            }

            $expiresAt = now()->addMinutes($this->config['download_expiry_minutes']);

            $this->trackDownloadStatistics($filename, strlen($content));

            return response($content)
                ->header('Content-Type', 'text/calendar; charset=utf-8')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                ->header('Expires', $expiresAt->toRfc7231String())
                ->header('Cache-Control', 'private, must-revalidate');
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('ICS file download failed', [
                    'filename' => $filename,
                    'error' => $e->getMessage()
                ]);
            }
            throw new \RuntimeException('Failed to initiate ICS file download: ' . $e->getMessage());
        }
    }

    // NEW: Export to JSON format
    public function exportEventsToJson($startDate = null, $endDate = null): Response {
        $this->trackExportEvent('events_json_export', [
            'user_id' => Auth::id(),
            'timestamp' => now()->toISOString(),
            'export_type' => 'events_json'
        ]);

        $this->validateExportPermissions('export_events');

        try {
            $events = $this->getFilteredEvents($startDate, $endDate);
            $jsonContent = json_encode($events, JSON_PRETTY_PRINT);

            $filename = $this->generateJsonFilename($startDate, $endDate);

            return $this->downloadJsonResponse($jsonContent, $filename);
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('JSON export failed', [
                    'error' => $e->getMessage()
                ]);
            }
            throw new \RuntimeException('Failed to generate JSON export: ' . $e->getMessage());
        }
    }

    // NEW: Generate JSON filename
    protected function generateJsonFilename($startDate = null, $endDate = null): string {
        $baseName = 'events';

        if ($startDate && $endDate) {
            $start = Carbon::parse($startDate)->format('Y-m-d');
            $end = Carbon::parse($endDate)->format('Y-m-d');
            return "{$baseName}_{$start}_to_{$end}.json";
        } else {
            return "{$baseName}_" . now()->format('Y-m-d') . ".json";
        }
    }

    // NEW: Download response for JSON file
    protected function downloadJsonResponse(string $content, string $filename): Response {
        $expiresAt = now()->addMinutes($this->config['download_expiry_minutes']);

        $this->trackDownloadStatistics($filename, strlen($content));

        return response($content)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Expires', $expiresAt->toRfc7231String())
            ->header('Cache-Control', 'private, must-revalidate');
    }

    public function exportEventsToCsv(): StreamedResponse {
        $this->trackExportEvent('events_csv_export', [
            'user_id' => Auth::id(),
            'timestamp' => now()->toISOString(),
            'export_type' => 'events'
        ]);

        $this->validateExportPermissions('export_events');

        return $this->exportToCsv(Event::class, 'events.csv');
    }

    public function exportTasksToCsv(): StreamedResponse {
        $totalTasks = Task::count();
        if ($totalTasks > $this->config['max_export_size']) {
            throw new \RuntimeException(
                "Export size exceeds maximum limit of {$this->config['max_export_size']} records. " .
                    "Please use batch export or apply filters."
            );
        }

        if ($totalTasks > 1000) {
            return $this->exportToCompressedCsv(Task::class, 'tasks.csv');
        }

        return $this->exportToCsv(Task::class, 'tasks.csv');
    }

    protected function exportToCsv(string $modelClass, string $filename): StreamedResponse {
        try {
            $model = new $modelClass;
            $columns = array_keys($model->getAttributes());
            $records = $modelClass::all();
            $csv = Writer::createFromString();
            $csv->insertOne($columns);

            foreach ($records as $record) {
                $row = [];
                foreach ($columns as $column) {
                    $value = $record->$column;
                    if (in_array($column, ['start_datetime', 'due_date'])) {
                        $value = $this->formatDateTime($value);
                    }
                    $row[] = $value;
                }
                $csv->insertOne($row);
            }

            return $this->downloadCsvResponse($csv, $filename);
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('CSV writing failed', [
                    'model' => $modelClass,
                    'error' => $e->getMessage()
                ]);
            }

            throw new \RuntimeException('Failed to generate CSV export: ' . $e->getMessage());
        }
    }

    public function getFilteredEvents($startDate = null, $endDate = null) {
        // Validate date formats
        if ($startDate && !$this->isValidDate($startDate)) {
            throw new \InvalidArgumentException('Invalid start date format');
        }

        if ($endDate && !$this->isValidDate($endDate)) {
            throw new \InvalidArgumentException('Invalid end date format');
        }

        // Validate date range logic
        if ($startDate && $endDate && $startDate > $endDate) {
            throw new \InvalidArgumentException('Start date cannot be after end date');
        }

        $query = Event::query();

        if ($startDate) {
            $query->where('start_datetime', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('start_datetime', '<=', $endDate);
        }

        return $query->get();
    }

    protected function isValidDate($date): bool {
        return (bool) strtotime($date);
    }

    public function getFilteredTasks($priority = null) {
        $query = Task::query();

        if ($priority !== null) {
            if (is_array($priority)) {
                $query->whereIn('priority', $priority);
            } else {
                $query->where('priority', $priority);
            }
        }

        return $query->get();
    }

    public function getTotalEvents(): int {
        $cacheKey = 'total_events_count';
        $cacheDuration = 300; // 5 minutes

        return cache()->remember($cacheKey, $cacheDuration, function () {
            return Event::count();
        });
    }

    public function getTotalTasks(): int {
        try {
            return Task::count();
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('Failed to count tasks', [
                    'error' => $e->getMessage()
                ]);
            }
            throw new \RuntimeException('Failed to retrieve task count: ' . $e->getMessage());
        }
        // TODO: Implement real-time count updates
        $this->updateRealTimeTaskCount();
    }

    public function formatDateTime($dateTime, $format = 'Y-m-d H:i:s'): string {
        if ($dateTime === null) {
            return '';
        }

        try {
            return Carbon::parse($dateTime)->format($format);
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->warning('Failed to format date time', [
                    'dateTime' => $dateTime,
                    'error' => $e->getMessage()
                ]);
            }
            return (string) $dateTime;
        }
        // TODO: Add support for multiple date formats
        if (in_array($format, $this->config['date_formats'])) {
            return Carbon::parse($dateTime)->format($format);
        }

        throw new \InvalidArgumentException("Unsupported date format: {$format}");
    }

    public function formatDate($date, $format = 'Y-m-d'): string {
        // TODO: Add localization support for dates
        $locale = app()->getLocale();
        $carbonDate = Carbon::parse($date)->locale($locale);

        // TODO: Validate input date format
        if (!$this->isValidDate($date)) {
            throw new \InvalidArgumentException("Invalid date format: {$date}");
        }

        return $carbonDate->format($format);
    }

    protected function downloadCsvResponse(Writer $csv, string $filename): StreamedResponse {
        // TODO: Add error handling for file download.
        try {
            $csvContent = $csv->toString();
            if (empty($csvContent)) {
                throw new \RuntimeException('Generated CSV content is empty');
            }

            // TODO: Implement download expiration
            $expiresAt = now()->addMinutes($this->config['download_expiry_minutes']);

            // TODO: Add download statistics tracking
            $this->trackDownloadStatistics($filename, strlen($csvContent));

            return response()->streamDownload(
                function () use ($csv) {
                    echo $csv->toString();
                },
                $filename,
                [
                    'Content-Type' => 'text/csv',
                    'Expires' => $expiresAt->toRfc7231String(),
                    'Cache-Control' => 'private, must-revalidate',
                ]
            );
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('File download failed', [
                    'filename' => $filename,
                    'error' => $e->getMessage()
                ]);
            }
            throw new \RuntimeException('Failed to initiate file download: ' . $e->getMessage());
        }
    }

    private function trackExportEvent(string $eventName, array $properties = []): void {
        if ($this->config['enable_analytics']) {
            // Log the export event
            AppLogger::info("Export event: {$eventName}", $properties);
        }
    }

    private function validateExportPermissions(string $permission): void {
        if (!\Illuminate\Support\Facades\Gate::allows($permission)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                "You do not have permission to perform this export."
            );
        }
    }

    private function exportToCompressedCsv(string $modelClass, string $filename): StreamedResponse {
        // Implementation for compressed CSV export
        // This would handle large datasets by compressing the output
        $compressedFilename = str_replace('.csv', '.zip', $filename);

        // Implementation would go here for creating a compressed archive
        // For now, we'll fall back to regular CSV with a warning
        if (isset($this->logger)) {
            $this->logger->warning('Large dataset export without compression', [
                'model' => $modelClass,
                'filename' => $filename
            ]);
        }

        return $this->exportToCsv($modelClass, $filename);
    }

    private function updateRealTimeTaskCount(): void {
        // Implementation for real-time count updates
        // This could broadcast to websockets or update a cache
        $count = Task::count();
        cache()->put('realtime_task_count', $count, now()->addSeconds(30));

        // Example: broadcast(new TaskCountUpdated($count));
    }

    private function trackDownloadStatistics(string $filename, int $fileSize): void {
        $stats = [
            'filename' => $filename,
            'file_size' => $fileSize,
            'download_time' => now()->toISOString(),
            'user_id' => Auth::id(),
            'ip_address' => request()->ip()
        ];

        AppLogger::info('File download statistics', $stats);

        // Store in database or analytics service
        // Example: DownloadStatistic::create($stats);
    }

    // TODO: Implement batch export functionality
    // TODO: Add cleanup method for temporary export files
}
