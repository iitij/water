<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingController extends ApiController
{
    private const SECRET_KEYS = ['whatsapp_access_token'];

    public function index(Request $request)
    {
        // Authenticated staff need the public project/reading options on startup.
        // Credentials are only returned by the restricted WhatsApp settings endpoint.
        $settings = Setting::query()->whereNotIn('key', self::SECRET_KEYS)->get()->mapWithKeys(fn (Setting $item) => [$item->key => $item->value])->all();
        $settings += [
            'auto_issue_invoice_on_reading' => filter_var(Setting::get('auto_issue_invoice_on_reading', '0'), FILTER_VALIDATE_BOOLEAN),
            'reading_period' => Setting::get('reading_period', 'monthly'),
            'reading_day' => Setting::get('reading_day', 'last_day'),
            'grace_period' => Setting::get('grace_period', '14'),
            'strict_overdue_on_new_invoice' => filter_var(Setting::get('strict_overdue_on_new_invoice', '0'), FILTER_VALIDATE_BOOLEAN),
            'invoice_project_name_ar' => Setting::get('invoice_project_name_ar', 'مشروع مياه الوشاح'),
            'invoice_phone_1' => Setting::get('invoice_phone_1', '1234567890'),
        ];
        foreach (['auto_issue_invoice_on_reading', 'strict_overdue_on_new_invoice', 'whatsapp_enabled', 'auto_send_whatsapp_invoice', 'auto_send_whatsapp_payment'] as $booleanKey) {
            if (array_key_exists($booleanKey, $settings)) $settings[$booleanKey] = filter_var($settings[$booleanKey], FILTER_VALIDATE_BOOLEAN);
        }
        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $this->authorizeApi(Setting::class, 'update');
        $values = $this->extractValues($request);
        $saved = $this->saveValues($values, null, true);
        return response()->json(['message' => 'تم تحديث الإعدادات بنجاح.', 'settings' => $saved]);
    }

    public function reading()
    {
        $this->authorizeSystem('View:ReadingSettings');
        return response()->json($this->readingPayload());
    }

    public function updateReading(Request $request)
    {
        $this->authorizeSystemWrite('ReadingSettings');
        $data = $request->validate([
            'reading_period' => ['sometimes', 'in:weekly,ten_days,half_monthly,monthly'],
            'reading_day' => ['sometimes', 'string', 'max:20'],
            'auto_issue_invoice_on_reading' => ['sometimes', 'boolean'],
            'grace_period' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'strict_overdue_on_new_invoice' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['reading_day']) && $data['reading_day'] !== 'last_day' && ((int) $data['reading_day'] < 20 || (int) $data['reading_day'] > 28)) {
            return response()->json(['errors' => ['reading_day' => ['يجب اختيار آخر الشهر أو يوم بين 20 و28.']]], 422);
        }
        $this->saveValues($data, null, true, [
            'reading_period', 'reading_day', 'auto_issue_invoice_on_reading', 'grace_period', 'strict_overdue_on_new_invoice',
        ]);
        return response()->json(['message' => 'تم حفظ إعدادات القراءات.', 'settings' => $this->readingPayload()]);
    }

    public function invoiceSettings()
    {
        $this->authorizeSystem('View:InvoiceSettings');
        return response()->json($this->settingsByPrefixes(['invoice_', 'inv_', 'sub_inv_', 'tanker_']));
    }

    public function updateInvoiceSettings(Request $request)
    {
        $this->authorizeSystemWrite('InvoiceSettings');
        $values = $this->extractValues($request);
        $this->saveUploadedSettings($request, ['invoice_logo', 'site_favicon', 'invoice_watermark']);
        $saved = $this->saveValues($values, ['invoice_', 'inv_', 'sub_inv_', 'tanker_'], true);
        return response()->json(['message' => 'تم حفظ إعدادات الفاتورة.', 'settings' => $saved]);
    }

    public function receiptSettings()
    {
        $this->authorizeSystem('View:ReceiptSettings');
        return response()->json($this->settingsByPrefixes(['rect_', 'invoice_project_', 'invoice_address_', 'invoice_phone_', 'invoice_logo', 'invoice_watermark']));
    }

    public function updateReceiptSettings(Request $request)
    {
        $this->authorizeSystemWrite('ReceiptSettings');
        $this->saveUploadedSettings($request, ['receipt_logo', 'invoice_logo', 'invoice_watermark']);
        $saved = $this->saveValues($this->extractValues($request), ['rect_', 'invoice_project_', 'invoice_address_', 'invoice_phone_', 'invoice_logo', 'invoice_watermark'], true);
        return response()->json(['message' => 'تم حفظ إعدادات سند القبض.', 'settings' => $saved]);
    }

    public function whatsappSettings()
    {
        $this->authorizeSystem('View:WhatsAppSettings');
        $settings = $this->settingsByPrefixes(['whatsapp_', 'auto_send_whatsapp']);
        $settings['whatsapp_access_token_configured'] = filled(Setting::get('whatsapp_access_token'));
        return response()->json($settings);
    }

    public function updateWhatsAppSettings(Request $request)
    {
        $this->authorizeSystemWrite('WhatsAppSettings');
        $saved = $this->saveValues($this->extractValues($request), ['whatsapp_', 'auto_send_whatsapp'], true, null, true);
        unset($saved['whatsapp_access_token']);
        $saved['whatsapp_access_token_configured'] = filled(Setting::get('whatsapp_access_token'));
        return response()->json(['message' => 'تم حفظ إعدادات الواتساب.', 'settings' => $saved]);
    }

    public function uploadLogo(Request $request)
    {
        $this->authorizeApi(Setting::class, 'update');
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255', 'not_in:whatsapp_access_token'],
            'file' => ['required', 'image', 'max:5120'],
        ]);
        $oldPath = Setting::get($data['key']);
        if ($oldPath) Storage::disk('public')->delete($oldPath);
        $path = $request->file('file')->store('settings/invoices', 'public');
        Setting::set($data['key'], $path, $data['key']);
        return response()->json(['message' => 'تم رفع الصورة.', 'key' => $data['key'], 'path' => $path, 'url' => Storage::disk('public')->url($path)]);
    }

    public function items(Request $request)
    {
        $this->authorizeApi(Setting::class, 'viewAny');
        $query = Setting::query()->whereNotIn('key', self::SECRET_KEYS)->orderBy('key');
        if ($request->filled('q')) $query->where(fn ($q) => $q->where('key', 'like', '%' . $request->query('q') . '%')->orWhere('label', 'like', '%' . $request->query('q') . '%'));
        return $query->paginate($this->perPage($request))->appends($request->query());
    }

    public function storeItem(Request $request)
    {
        $this->authorizeApi(Setting::class, 'create');
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255', 'not_in:whatsapp_access_token', 'unique:settings,key'],
            'value' => ['nullable'],
            'label' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'in:text,image'],
        ]);
        $item = Setting::create($data + ['type' => 'text']);
        return response()->json(['message' => 'تمت إضافة الإعداد.', 'setting' => $item], 201);
    }

    public function updateItem(Request $request, int $id)
    {
        $this->authorizeApi(Setting::class, 'update');
        $item = Setting::findOrFail($id);
        abort_if(in_array($item->key, self::SECRET_KEYS, true), 403, 'لا يمكن إدارة بيانات الاعتماد من شاشة الإعدادات العامة.');
        $data = $request->validate([
            'key' => ['sometimes', 'required', 'string', 'max:255', 'not_in:whatsapp_access_token', Rule::unique('settings', 'key')->ignore($item->id)],
            'value' => ['sometimes', 'nullable'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'in:text,image'],
        ]);
        $item->update($data);
        return response()->json(['message' => 'تم تحديث الإعداد.', 'setting' => $item]);
    }

    public function destroyItem(int $id)
    {
        $this->authorizeApi(Setting::class, 'delete');
        $item = Setting::findOrFail($id);
        abort_if(in_array($item->key, self::SECRET_KEYS, true), 403, 'لا يمكن حذف بيانات الاعتماد من شاشة الإعدادات العامة.');
        $item->delete();
        return response()->json(['message' => 'تم حذف الإعداد.']);
    }

    public function bulkDestroyItems(Request $request)
    {
        $this->authorizeApi(Setting::class, 'deleteAny');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:settings,id'],
        ]);
        $deleted = Setting::query()
            ->whereIn('id', $data['ids'])
            ->whereNotIn('key', self::SECRET_KEYS)
            ->delete();
        return response()->json(['deleted_count' => $deleted]);
    }

    private function readingPayload(): array
    {
        return [
            'reading_period' => Setting::get('reading_period', 'monthly'),
            'reading_day' => Setting::get('reading_day', 'last_day'),
            'auto_issue_invoice_on_reading' => filter_var(Setting::get('auto_issue_invoice_on_reading', '0'), FILTER_VALIDATE_BOOLEAN),
            'grace_period' => (int) Setting::get('grace_period', '14'),
            'strict_overdue_on_new_invoice' => filter_var(Setting::get('strict_overdue_on_new_invoice', '0'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function settingsByPrefixes(array $prefixes, bool $includeSecrets = false): array
    {
        $settings = Setting::query()->get()->filter(function (Setting $setting) use ($prefixes, $includeSecrets) {
            if (! $includeSecrets && in_array($setting->key, self::SECRET_KEYS, true)) return false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($setting->key, $prefix) || $setting->key === $prefix) return true;
            }
            return false;
        })->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value])->all();
        foreach (['whatsapp_enabled', 'auto_send_whatsapp_invoice', 'auto_send_whatsapp_payment'] as $key) {
            if (array_key_exists($key, $settings)) $settings[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
        }
        return $settings;
    }

    private function saveUploadedSettings(Request $request, array $allowedKeys): void
    {
        foreach ($allowedKeys as $key) {
            if (! $request->hasFile($key)) continue;
            $request->validate([$key => ['image', 'max:5120']]);
            $oldPath = Setting::get($key);
            if ($oldPath) Storage::disk('public')->delete($oldPath);
            $path = $request->file($key)->store('settings/invoices', 'public');
            Setting::set($key, $path, $key);
        }
    }

    private function extractValues(Request $request): array
    {
        $values = $request->input('settings', $request->except(['_token', '_method']));
        return is_array($values) ? $values : [];
    }

    private function saveValues(array $values, array|string|null $prefixes, bool $convertBooleans, ?array $exactKeys = null, bool $includeSecrets = false): array
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || str_starts_with($key, '_')) continue;
            if ($exactKeys !== null && ! in_array($key, $exactKeys, true)) continue;
            if ($prefixes !== null) {
                $allowed = false;
                foreach ((array) $prefixes as $prefix) {
                    if (str_starts_with($key, $prefix) || $key === $prefix) { $allowed = true; break; }
                }
                if (! $allowed) continue;
            }
            if (in_array($key, self::SECRET_KEYS, true) && ! $includeSecrets) continue;
            if (is_array($value) || is_object($value)) continue;
            if ($convertBooleans && is_bool($value)) $value = $value ? '1' : '0';
            Setting::set($key, $value, $key);
        }
        return $prefixes === null ? $this->indexPayload() : $this->settingsByPrefixes((array) $prefixes, $includeSecrets);
    }

    private function indexPayload(): array
    {
        return Setting::query()->whereNotIn('key', self::SECRET_KEYS)->get()->mapWithKeys(fn (Setting $item) => [$item->key => $item->value])->all();
    }
}
