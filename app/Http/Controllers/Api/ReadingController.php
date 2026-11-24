<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Services\InvoiceService;
use App\Services\ReadingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReadingController extends ApiController
{
    public function __construct(private readonly ReadingService $readingService) {}

    public function index(Request $request)
    {
        $this->authorizeApi(Reading::class, 'viewAny');
        $query = Reading::with([
            'meter:id,unique_code,path_number,subscriber_id,status',
            'meter.subscriber:id,name,phone,square_id',
            'meter.subscriber.square:id,name,code',
            'invoice:id,bill_number,status,total_amount',
            'creator:id,name',
        ])->latest('reading_date')->latest('id');

        if ($request->filled('is_invoiced')) {
            $value = filter_var($request->query('is_invoiced'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $query->where('is_invoiced', $value);
            }
        }
        if ($request->filled('meter_id')) {
            $query->where('meter_id', $request->integer('meter_id'));
        }
        if ($request->filled('square_id')) {
            $query->whereHas('meter.subscriber', fn ($q) => $q->where('square_id', $request->integer('square_id')));
        }
        if ($request->filled('subscriber_id')) {
            $subscriberId = $request->integer('subscriber_id');
            $query->whereHas('meter', fn ($q) => $q->where('subscriber_id', $subscriberId));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('reading_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('reading_date', '<=', $request->query('to_date'));
        }
        if ($search = trim((string) $request->query('q', $request->query('search', '')))) {
            $normalized = $this->normalizeDigits($search);
            if ($path = $this->parseCompoundSearch($normalized)) {
                [$squareId, $pathNumber] = $path;
                $query->whereHas('meter', fn ($meterQuery) => $meterQuery->where('path_number', $pathNumber)
                    ->whereHas('subscriber', fn ($subscriberQuery) => $subscriberQuery->where('square_id', $squareId)));
            } else {
                $query->where(function ($builder) use ($search, $normalized) {
                    $builder->where('period_start', 'like', "%{$search}%")
                        ->orWhereHas('meter.subscriber.square', fn ($square) => $square->where('code', 'like', "%{$search}%"))
                        ->orWhere('period_end', 'like', "%{$search}%")
                        ->orWhereHas('meter', function ($meterQuery) use ($search, $normalized) {
                            $meterQuery->where('unique_code', 'like', "%{$search}%")
                                ->orWhereHas('subscriber', fn ($subscriberQuery) => $subscriberQuery->where('name', 'like', "%{$search}%"));
                            if (ctype_digit($normalized)) {
                                $meterQuery->orWhere('path_number', (int) $normalized);
                            }
                        });
                });
            }
        }

        $this->applySorting($query, $request, self::LIST_SORTS['readings']);

        return $query->paginate($this->perPage($request))->through(function (Reading $reading) {
            return $this->decorateReading($reading);
        })->appends($request->query());
    }

    /** Legacy fast meter search path; kept as an alias for existing clients. */
    public function searchMeter(Request $request)
    {
        return app(MeterController::class)->search($request);
    }

    public function searchMeters(Request $request)
    {
        return $this->searchMeter($request);
    }

    public function getMeterPeriod(int $meterId)
    {
        $this->authorizeApi(Meter::class, 'view');
        $meter = Meter::with(['subscriber', 'subscriber.square'])->findOrFail($meterId);
        // Preserve idempotent retries above, but never accept new readings for removed meters.
        if ($meter->removed_at !== null) {
            throw ValidationException::withMessages([
                'meter_id' => ['هذا العداد من السجل التاريخي وقد تم استبداله أو نزعه. اختر العداد الحالي.'],
            ]);
        }

        $lastReading = $this->readingService->latestReading($meter->id);
        $period = $this->readingService->calculateReadingPeriod(
            $meterId,
            request()->query('reading_date') ?: today()->toDateString()
        );

        return response()->json([
            'meter' => $meter,
            'last_reading' => $lastReading ? (float) $lastReading->current_reading : (float) $meter->initial_reading,
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'auto_issue_invoice' => filter_var(Setting::get('auto_issue_invoice_on_reading', '0'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function calculateDates(Request $request)
    {
        $data = $request->validate(['meter_id' => ['required', 'integer', 'exists:meters,id']]);

        return $this->getMeterPeriod((int) $data['meter_id']);
    }

    public function meterHistory(int $meterId)
    {
        $this->authorizeApi(Reading::class, 'viewAny');
        $meter = Meter::with('subscriber.square')->findOrFail($meterId);
        $readings = $meter->readings()->with(['invoice:id,bill_number,status', 'creator:id,name'])
            ->latest('reading_date')->paginate(50)
            ->through(fn (Reading $reading) => $this->decorateReading($reading));

        return response()->json(['meter' => $meter, 'readings' => $readings]);
    }

    public function store(Request $request)
    {
        $this->authorizeApi(Reading::class, 'create');
        $data = $request->validate($this->readingRules());
        try {
            $result = $this->createReading($data, $request);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Mobile reading creation failed', ['exception' => $exception]);

            return response()->json(['message' => 'تعذر حفظ القراءة حالياً. حاول مرة أخرى.'], 500);
        }

        return response()->json([
            'message' => $result['duplicate'] ? 'تمت مزامنة هذه القراءة سابقاً.' : 'تم حفظ القراءة بنجاح.',
            'reading' => $result['reading'],
            'duplicate' => $result['duplicate'],
            'invoice_issued' => $result['invoice_issued'],
            'invoice_data' => $result['invoice_data'],
            'invoice_error' => $result['invoice_error'],
        ], $result['duplicate'] ? 200 : 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(Reading::class, 'update');
        $reading = Reading::with('meter')->findOrFail($id);
        if ($reading->is_invoiced) {
            return response()->json(['message' => 'لا يمكن تعديل قراءة مرتبطة بفاتورة.'], 409);
        }
        $data = $request->validate([
            'reading_date' => ['sometimes', 'required', 'date'],
            'actual_reading_date' => ['sometimes', 'nullable', 'date'],
            'current_reading' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'previous_reading' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'period_start' => ['sometimes', 'required', 'date'],
            'period_end' => ['sometimes', 'required', 'date', 'after_or_equal:period_start'],
        ]);
        // There is one writable interval contract. Calendar-era records are
        // not a reason to retain a second, incorrect editing algorithm.
        if ($reading->period_basis !== 'observation') {
            throw ValidationException::withMessages([
                'period_start' => ['هذه القراءة لا تتبع منطق الفترات الفعلية؛ لا يمكن تعديلها عبر مسار القراءات.'],
            ]);
        }
        if (isset($data['period_start'])
            && Carbon::parse($data['period_start'])->toDateString() !== Carbon::parse($reading->period_start)->toDateString()) {
            throw ValidationException::withMessages(['period_start' => ['بداية الفترة محسوبة من القراءة السابقة.']]);
        }
        if (isset($data['previous_reading']) && (float) $data['previous_reading'] !== (float) $reading->previous_reading) {
            throw ValidationException::withMessages(['previous_reading' => ['القراءة السابقة قيمة محسوبة لا تُعدل يدوياً.']]);
        }

        // The model owns validation and synchronization of both date fields.
        // Client period fields are not authoritative.
        unset($data['period_start'], $data['period_end'], $data['previous_reading']);
        if (array_key_exists('actual_reading_date', $data) && $data['actual_reading_date'] === null) {
            throw ValidationException::withMessages(['actual_reading_date' => ['يلزم تحديد تاريخ أخذ القراءة الفعلي.']]);
        }

        $reading->update($data);

        return response()->json(['message' => 'تم تحديث القراءة بنجاح.', 'reading' => $this->decorateReading($reading->fresh(['meter.subscriber', 'invoice']))]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Reading::class, 'delete');
        $reading = Reading::findOrFail($id);
        if ($reading->is_invoiced) {
            return response()->json(['message' => 'لا يمكن حذف قراءة مفوترة. احذف الفاتورة المرتبطة بها أولاً.'], 409);
        }
        $reading->delete();

        return response()->json(['message' => 'تم حذف القراءة بنجاح.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(Reading::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:readings,id']]);
        $deleted = 0;
        $skipped = 0;
        DB::transaction(function () use ($data, &$deleted, &$skipped) {
            $meterIds = Reading::whereIn('id', $data['ids'])->whereNotNull('meter_id')->pluck('meter_id')->unique()->sort()->values();
            Meter::query()->whereIn('id', $meterIds)->orderBy('id')->lockForUpdate()->get();
            foreach (Reading::whereIn('id', $data['ids'])->orderBy('id')->lockForUpdate()->get() as $reading) {
                if ($reading->is_invoiced) {
                    $skipped++;

                    continue;
                }
                $reading->delete();
                $deleted++;
            }
        });

        return response()->json(['deleted_count' => $deleted, 'skipped_count' => $skipped]);
    }

    public function bulkInvoice(Request $request, InvoiceService $invoiceService)
    {
        $this->authorizeApi(Invoice::class, 'create');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:readings,id'],
            'notes' => ['nullable', 'string'],
        ]);
        $readings = Reading::with('meter.subscriber')->whereIn('id', $data['ids'])->where('is_invoiced', false)->get();
        if ($readings->isEmpty()) {
            return response()->json(['message' => 'لا توجد قراءات غير مفوترة ضمن الاختيار.'], 422);
        }

        $invoices = [];
        $errors = [];
        foreach ($readings->groupBy('meter_id') as $meterId => $meterReadings) {
            $meter = $meterReadings->first()->meter;
            if (! $meter?->subscriber) {
                $errors[] = ['meter_id' => $meterId, 'message' => 'تعذر العثور على المشترك المرتبط بالعداد.'];

                continue;
            }
            try {
                $invoice = $invoiceService->generateInvoice(
                    subscriber: $meter->subscriber,
                    meter: $meter,
                    notes: $data['notes'] ?? null,
                );
                $invoices[] = $invoice->load(['subscriber', 'meter', 'items']);
            } catch (\Throwable $exception) {
                $errors[] = ['meter_id' => $meterId, 'message' => $exception->getMessage()];
            }
        }

        return response()->json([
            'invoices_issued' => count($invoices),
            'invoices' => $invoices,
            'errors' => $errors,
        ], $invoices ? 201 : 422);
    }

    public function syncBatch(Request $request)
    {
        $this->authorizeApi(Reading::class, 'create');
        $payload = $request->validate([
            'readings' => ['required', 'array', 'min:1', 'max:100'],
            'readings.*.meter_id' => ['required', 'integer'],
            'readings.*.reading_date' => ['required', 'date'],
            'readings.*.current_reading' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'readings.*.actual_reading_date' => ['nullable', 'date'],
            'readings.*.period_start' => ['nullable', 'date'],
            'readings.*.period_end' => ['nullable', 'date'],
            'readings.*.sync_uuid' => ['nullable', 'uuid'],
            'readings.*.client_action_id' => ['nullable', 'string', 'max:100'],
            'readings.*.local_id' => ['nullable', 'string', 'max:100'],
            'readings.*.image' => ['nullable', 'image', 'max:5120'],
            'readings.*.image_base64' => ['nullable', 'string'],
            'readings.*.image_filename' => ['nullable', 'string', 'max:255'],
        ]);

        $results = [];
        foreach ($payload['readings'] as $index => $item) {
            $clientActionId = $item['client_action_id'] ?? $item['local_id'] ?? $item['sync_uuid'] ?? null;
            if (empty($item['sync_uuid']) && is_string($clientActionId) && Str::isUuid($clientActionId)) {
                $item['sync_uuid'] = strtolower($clientActionId);
            }
            try {
                $result = $this->createReading($item, $request, $index);
                $results[] = [
                    'index' => $index,
                    'sync_uuid' => $item['sync_uuid'] ?? null,
                    'client_action_id' => $clientActionId,
                    'success' => true,
                    'duplicate' => $result['duplicate'],
                    'reading' => $result['reading'],
                    'invoice_issued' => $result['invoice_issued'],
                    'invoice_data' => $result['invoice_data'],
                    'invoice_error' => $result['invoice_error'],
                ];
            } catch (\Throwable $exception) {
                $permanent = $exception instanceof ValidationException
                    || $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    || ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                        && $exception->getStatusCode() < 500);
                $results[] = [
                    'index' => $index,
                    'sync_uuid' => $item['sync_uuid'] ?? null,
                    'client_action_id' => $clientActionId,
                    'success' => false,
                    'retryable' => ! $permanent,
                    'message' => $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : ($permanent ? $exception->getMessage() : 'خطأ مؤقت في الخادم؛ أعد المحاولة لاحقاً.'),
                ];
            }
        }

        return response()->json([
            'processed_count' => count($results),
            'success_count' => collect($results)->where('success', true)->count(),
            'failed_count' => collect($results)->where('success', false)->count(),
            'results' => $results,
        ]);
    }

    private function readingRules(): array
    {
        return [
            'meter_id' => ['required', 'integer', 'exists:meters,id'],
            'reading_date' => ['required', 'date'],
            'actual_reading_date' => ['nullable', 'date'],
            'current_reading' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'sync_uuid' => ['nullable', 'uuid'],
            'client_action_id' => ['nullable', 'string', 'max:100'],
            'local_id' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'max:5120'],
            'image_base64' => ['nullable', 'string'],
            'image_filename' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private function existingReadingReplay(Meter $meter, array $data, Request $request): ?array
    {
        if (empty($data['sync_uuid'])) {
            return null;
        }
        $existing = Reading::withTrashed()->where('sync_uuid', $data['sync_uuid'])->first();
        if (! $existing) {
            return null;
        }
        abort_if($existing->trashed(), 409, 'عملية القراءة المرتبطة بمعرّف المزامنة محذوفة مسبقاً.');
        $sameOwner = (int) ($existing->created_by ?? 0) === (int) ($request->user()?->id ?? 0);
        $sameReading = (int) $existing->meter_id === $meter->id
            && round((float) $existing->current_reading, 2) === round((float) $data['current_reading'], 2)
            && Carbon::parse($existing->reading_date)->toDateString() === Carbon::parse($data['reading_date'])->toDateString()
            && Carbon::parse($existing->actual_reading_date ?? $existing->reading_date)->toDateString()
                === Carbon::parse($data['actual_reading_date'] ?? $data['reading_date'])->toDateString();
        $samePeriod = true;
        foreach (['period_start', 'period_end'] as $field) {
            $originalField = 'submitted_'.$field;
            $expected = $existing->{$originalField} ?? $existing->{$field};
            if (isset($data[$field])
                && Carbon::parse($data[$field])->toDateString()
                    !== Carbon::parse($expected)->toDateString()) {
                $samePeriod = false;
                break;
            }
        }
        abort_unless($sameOwner && $sameReading && $samePeriod, 409, 'معرّف المزامنة مستخدم مسبقاً لقراءة مختلفة أو لحساب آخر.');

        return ['reading' => $existing->load(['meter.subscriber', 'invoice']), 'duplicate' => true, 'invoice_issued' => (bool) $existing->invoice_id, 'invoice_data' => $existing->invoice, 'invoice_error' => null];
    }

    private function createReading(array $data, Request $request, ?int $batchIndex = null): array
    {
        if (empty($data['sync_uuid'])) {
            $fallbackId = $data['client_action_id'] ?? $data['local_id'] ?? $request->header('Idempotency-Key');
            if (is_string($fallbackId) && Str::isUuid($fallbackId)) {
                $data['sync_uuid'] = strtolower($fallbackId);
            }
        }
        if (! empty($data['sync_uuid'])) {
            $data['sync_uuid'] = strtolower($data['sync_uuid']);
        }
        $meter = Meter::with(['subscriber.square'])->findOrFail((int) $data['meter_id']);
        if ($replay = $this->existingReadingReplay($meter, $data, $request)) {
            return $replay;
        }

        // Replacement also locks the physical meter row. Keep validation,
        // period calculation and insertion inside that SAME row lock so a
        // reading cannot slip between the preflight guard and meter deletion.
        try {
            $reading = DB::transaction(function () use ($meter, $data, $request, $batchIndex) {
                \App\Models\Subscriber::query()->lockForUpdate()->findOrFail($meter->subscriber_id);
                $locked = Meter::query()->lockForUpdate()->findOrFail($meter->id);
                abort_unless((int) $locked->subscriber_id === (int) $meter->subscriber_id, 409);
                // A simultaneous retry may have committed while this request waited for the meter lock.
                // Check identity again before rejecting a repeated observation date or writing an image.
                if ($replay = $this->existingReadingReplay($locked, $data, $request)) {
                    return $replay;
                }
                $lastReading = $this->readingService->latestReading($locked->id);
                $previous = $lastReading
                    ? (float) $lastReading->current_reading : (float) $locked->initial_reading;
                $current = (float) $data['current_reading'];

                // Report retired meters and invalid physical readings before
                // trying to derive the interval from an installation anchor.
                if (! $locked->isCurrent()) {
                    throw ValidationException::withMessages([
                        'meter_id' => ['لا يمكن تسجيل قراءة على عداد منزوع أو مستبدل.'],
                    ]);
                }
                if ($current < $previous) {
                    throw ValidationException::withMessages([
                        'current_reading' => ['القراءة الحالية أقل من القراءة السابقة.'],
                    ]);
                }
                $calculatedPeriod = $this->readingService->calculateReadingPeriod($locked->id, $data['actual_reading_date'] ?? $data['reading_date']);
                // The server's observed dates are authoritative. Legacy mobile
                // clients may send a calendar forecast; never bill that forecast.
                $periodStart = $calculatedPeriod['period_start'];
                $periodEnd = $calculatedPeriod['period_end'];
                app(\App\Services\CurrentMeterReadingGuard::class)->assertCanCreate(
                    $locked, $current, $periodStart, $periodEnd,
                    $data['actual_reading_date'] ?? $data['reading_date'],
                );

                $created = Reading::create([
                    'meter_id' => $locked->id,
                    'created_by' => $request->user()?->id,
                    'reading_date' => $data['reading_date'],
                    'actual_reading_date' => $data['actual_reading_date'] ?? $data['reading_date'],
                    'previous_reading' => $previous,
                    'current_reading' => $current,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'period_basis' => 'observation',
                    'submitted_period_start' => $data['period_start'] ?? null,
                    'submitted_period_end' => $data['period_end'] ?? null,
                    'sync_uuid' => $data['sync_uuid'] ?? null,
                ]);

                // Reserve the unique reading identity before creating a file on disk.
                $imagePath = null;
                $imageFile = $batchIndex === null
                    ? $request->file('image') : $request->file("readings.$batchIndex.image");
                if ($imageFile) {
                    $imagePath = $imageFile->store('readings', 'public');
                } elseif (! empty($data['image_base64']) && is_string($data['image_base64'])) {
                    $decodedImage = base64_decode($data['image_base64'], true);
                    if ($decodedImage !== false && strlen($decodedImage) > 0 && strlen($decodedImage) <= 5 * 1024 * 1024) {
                        $ext = strtolower(pathinfo((string) ($data['image_filename'] ?? 'jpg'), PATHINFO_EXTENSION));
                        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                            $ext = 'jpg';
                        }
                        $storedName = 'readings/'.Str::uuid().'.'.$ext;
                        Storage::disk('public')->put($storedName, $decodedImage);
                        $imagePath = $storedName;
                    }
                }
                if ($imagePath !== null) {
                    $created->update(['image_path' => $imagePath]);
                }

                return $created;
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            // Other subscribers use different row locks. The global unique index
            // arbitrates a UUID collision; resolve the winner only after rollback.
            if ($replay = $this->existingReadingReplay($meter, $data, $request)) {
                return $replay;
            }
            throw $exception;
        }

        if (is_array($reading)) {
            return $reading;
        }

        $invoice = null;
        $invoiceError = null;
        $invoiceIssued = false;
        if (filter_var(Setting::get('auto_issue_invoice_on_reading', '0'), FILTER_VALIDATE_BOOLEAN)) {
            try {
                $invoice = app(InvoiceService::class)->generateInvoice(
                    subscriber: $meter->subscriber,
                    meter: $meter,
                    notes: $data['notes'] ?? null,
                );
                $invoiceIssued = true;
                $invoice->load(['subscriber.square', 'meter', 'items', 'readings']);
            } catch (\Throwable $exception) {
                $invoiceError = $exception->getMessage();
                Log::warning('Auto invoice generation failed after reading save', ['reading_id' => $reading->id, 'error' => $invoiceError]);
            }
        }

        $freshReading = $reading->fresh(['meter.subscriber.square', 'invoice']);
        if ($freshReading === null) {
            // The meter was replaced immediately after the reading committed.
            // Return a permanent stale-meter error instead of a 500 response.
            throw \Illuminate\Validation\ValidationException::withMessages([
                'meter_id' => ['تم استبدال العداد أثناء إرسال القراءة. حدّث العدادات قبل المحاولة مجدداً.'],
            ]);
        }

        return [
            'reading' => $this->decorateReading($freshReading),
            'duplicate' => false,
            'invoice_issued' => $invoiceIssued,
            'invoice_data' => $invoice ? $this->invoicePayload($invoice, $request) : null,
            'invoice_error' => $invoiceError,
        ];
    }

    private function decorateReading(Reading $reading): Reading
    {
        $reading->setAttribute('image_url', $reading->image_path ? url(Storage::disk('public')->url($reading->image_path)) : null);

        return $reading;
    }

    private function invoicePayload(Invoice $invoice, Request $request): array
    {
        $readings = $invoice->readings->sortBy(fn (Reading $r) => sprintf('%s|%s|%010d', $r->period_start, $r->period_end, $r->id));
        $first = $readings->first();
        $last = $readings->last();
        $units = (float) $readings->sum('consumption_units');
        $tariffLines = \Illuminate\Support\Facades\DB::table('invoice_tariff_lines')
            ->where('invoice_id', $invoice->id)->orderBy('period_start')->orderBy('id')->get();
        $prices = $tariffLines->pluck('unit_price')->map(fn ($price) => number_format((float) $price, 2, '.', ''))->unique();
        $unitPrice = $prices->count() === 1 ? $prices->first() : null;

        return [
            'id' => $invoice->id,
            'bill_number' => $invoice->bill_number,
            'bill_date' => optional($invoice->bill_date)->format('Y-m-d'),
            'total_amount' => round((float) $invoice->total_amount + (float) $invoice->previous_balance - (float) $invoice->credit_applied, 2),
            'subscriber_name' => $invoice->subscriber?->name,
            'subscriber_phone' => $invoice->subscriber?->phone,
            'meter_code' => $invoice->meter?->unique_code,
            'square' => $invoice->subscriber?->square?->name,
            'pdf_url' => route('api.invoices.pdf', ['id' => $invoice->id]),
            'period_start' => optional($invoice->billing_period_start)->format('Y-m-d'),
            'period_end' => optional($invoice->billing_period_end)->format('Y-m-d'),
            'previous_reading' => $first?->previous_reading,
            'current_reading' => $last?->current_reading,
            'consumption_units' => $units,
            'unit_price' => $unitPrice,
            'tariff_lines' => $tariffLines,
            'consumption_fee' => number_format((float) $invoice->consumption_fee, 2, '.', ''),
            'previous_balance' => number_format((float) $invoice->previous_balance, 2, '.', ''),
            'credit_applied' => number_format((float) $invoice->credit_applied, 2, '.', ''),
            'collector' => $request->user()?->name ?? 'إدارة المشروع',
            'message_template' => Setting::get('reading_invoice_message_template'),
        ];
    }
}
