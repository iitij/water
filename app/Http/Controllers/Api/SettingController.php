<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;

class SettingController extends Controller
{
    public function index()
    {
        $defaultTemplate = "فاتورة استهلاك مياه\nالأخ:  {subscriber_name}،\nتم إصدار فاتورتك رقم (#{bill_number})\nللفترة :\nمن {period_start} إلى {period_end}.\n\nتفاصيل القراءة والاستهلاك:\n• رقم العداد: {meter_code}\n• القراءة السابقة: {previous_reading}\n• القراءة الحالية: {current_reading}\n• كمية الاستهلاك: {consumption_units} وحدة\n(سعر الوحدة: {unit_price}) ريال\n\nتفاصيل الرسوم والحساب:\n• قيمة الاستهلاك: {consumption_fee} ريال\n{if:subscription_fee}• رسوم الاشتراك: {subscription_fee} ريال{/if:subscription_fee}\n{if:tanker_fee}• رسوم الوايت: {tanker_fee} ريال{/if:tanker_fee}\n{if:previous_balance}• متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n{if:credit_applied}• رصيد مخصوم: {credit_applied} ريال{/if:credit_applied}\n------------------------\nالإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nلمعاينة وتحميل الفاتورة الرسمية (PDF):\n{pdf_url}\n\nشكراً لدفعكم في الموعد المحدد";
        
        $defaultPaymentTemplate = "سند قبض\nالأخ: {subscriber_name}،\nتم استلام مبلغ {amount} ريال بنجاح.\nرقم السند: {receipt_number}\nتاريخ الدفع: {date}\nطريقة الدفع: {payment_method}\n\nالمتبقي من الفاتورة: {invoice_remaining} ريال\n{if:credit_balance}الرصيد الدائن: {credit_balance} ريال{/if:credit_balance}\n\nالمحصل: {collector}\n\nللاطلاع على السند وتحميله (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا";
        $defaultSubscriptionTemplate = "فاتورة رسوم اشتراك\nالأخ: {subscriber_name}،\nتم إصدار فاتورة رسوم اشتراك جديدة رقم (#{bill_number}).\n\nقيمة الرسوم: {subscription_fee} ريال\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\nالإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nللاطلاع على الفاتورة وتحميلها (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا";
        $defaultTankerTemplate = "فاتورة رسوم وايت\nالأخ :{subscriber_name}،\nتم إصدار فاتورة رسوم وايت جديدة رقم (#{bill_number}).\n\nقيمة الرسوم: {tanker_fee} ريال\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\nالإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nللاطلاع على الفاتورة وتحميلها (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا";

        return response()->json([
            'auto_issue_invoice_on_reading' => Setting::get('auto_issue_invoice_on_reading', '0') == '1',
            'reading_period' => Setting::get('reading_period', 'monthly'),
            'reading_day' => Setting::get('reading_day', 'last_day'),
            'grace_period' => Setting::get('grace_period', '14'),
            'invoice_project_name_ar' => Setting::get('invoice_project_name_ar', 'مشروع مياه الوشاح'),
            'invoice_phone_1' => Setting::get('invoice_phone_1', '1234567890'),
            'reading_invoice_message_template' => Setting::get('reading_invoice_message_template', $defaultTemplate),
            'payment_receipt_message_template' => Setting::get('payment_receipt_message_template', $defaultPaymentTemplate),
            'subscription_invoice_template' => Setting::get('subscription_invoice_template', $defaultSubscriptionTemplate),
            'tanker_invoice_template' => Setting::get('tanker_invoice_template', $defaultTankerTemplate),
        ]);
    }

    public function update(Request $request)
    {
        if ($request->has('auto_issue_invoice_on_reading')) {
            Setting::set('auto_issue_invoice_on_reading', $request->boolean('auto_issue_invoice_on_reading') ? '1' : '0', 'إصدار الفاتورة تلقائياً عند إدخال القراءة');
        }
        if ($request->has('reading_period')) {
            Setting::set('reading_period', $request->input('reading_period'), 'دورة القراءات');
        }
        if ($request->has('reading_day')) {
            Setting::set('reading_day', $request->input('reading_day'), 'يوم القراءة');
        }
        if ($request->has('grace_period')) {
            Setting::set('grace_period', $request->input('grace_period'), 'مدة السماح (أيام)');
        }
        if ($request->has('invoice_project_name_ar')) {
            Setting::set('invoice_project_name_ar', $request->input('invoice_project_name_ar'), 'اسم المشروع');
        }
        if ($request->has('invoice_phone_1')) {
            Setting::set('invoice_phone_1', $request->input('invoice_phone_1'), 'رقم الهاتف');
        }
        if ($request->has('reading_invoice_message_template')) {
            Setting::set('reading_invoice_message_template', $request->input('reading_invoice_message_template'), 'قالب رسالة الفاتورة عند القراءة');
        }
        if ($request->has('payment_receipt_message_template')) {
            Setting::set('payment_receipt_message_template', $request->input('payment_receipt_message_template'), 'قالب رسالة سند القبض');
        }
        if ($request->has('subscription_invoice_template')) {
            Setting::set('subscription_invoice_template', $request->input('subscription_invoice_template'), 'قالب فاتورة رسوم الاشتراك');
        }
        if ($request->has('tanker_invoice_template')) {
            Setting::set('tanker_invoice_template', $request->input('tanker_invoice_template'), 'قالب فاتورة رسوم الوايت');
        }

        $defaultTemplate = "فاتورة استهلاك مياه\nالأخ:  {subscriber_name}،\nتم إصدار فاتورتك رقم (#{bill_number})\nللفترة :\nمن {period_start} إلى {period_end}.\n\nتفاصيل القراءة والاستهلاك:\n• رقم العداد: {meter_code}\n• القراءة السابقة: {previous_reading}\n• القراءة الحالية: {current_reading}\n• كمية الاستهلاك: {consumption_units} وحدة\n(سعر الوحدة: {unit_price}) ريال\n\nتفاصيل الرسوم والحساب:\n• قيمة الاستهلاك: {consumption_fee} ريال\n{if:subscription_fee}• رسوم الاشتراك: {subscription_fee} ريال{/if:subscription_fee}\n{if:tanker_fee}• رسوم الوايت: {tanker_fee} ريال{/if:tanker_fee}\n{if:previous_balance}• متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n{if:credit_applied}• رصيد مخصوم: {credit_applied} ريال{/if:credit_applied}\n------------------------\nالإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nلمعاينة وتحميل الفاتورة الرسمية (PDF):\n{pdf_url}\n\nشكراً لدفعكم في الموعد المحدد";
        
        $defaultPaymentTemplate = "سند قبض\nالأخ: {subscriber_name}،\nتم استلام مبلغ {amount} ريال بنجاح.\nرقم السند: {receipt_number}\nتاريخ الدفع: {date}\nطريقة الدفع: {payment_method}\n\nالمتبقي من الفاتورة: {invoice_remaining} ريال\n{if:credit_balance}الرصيد الدائن: {credit_balance} ريال{/if:credit_balance}\n\nالمحصل: {collector}\n\nللاطلاع على السند وتحميله (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا";
        $defaultSubscriptionTemplate = "فاتورة رسوم اشتراك\nالأخ: {subscriber_name}،\nتم إصدار فاتورة رسوم اشتراك جديدة رقم (#{bill_number}).\n\nقيمة الرسوم: {subscription_fee} ريال\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\nالإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nللاطلاع على الفاتورة وتحميلها (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا";
        $defaultTankerTemplate = "فاتورة رسوم وايت\nالأخ :{subscriber_name}،\nتم إصدار فاتورة رسوم وايت جديدة رقم (#{bill_number}).\n\nقيمة الرسوم: {tanker_fee} ريال\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\nالإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nللاطلاع على الفاتورة وتحميلها (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا";

        return response()->json([
            'message' => 'تم تحديث الإعدادات بنجاح',
            'settings' => [
                'auto_issue_invoice_on_reading' => Setting::get('auto_issue_invoice_on_reading', '0') == '1',
                'reading_period' => Setting::get('reading_period', 'monthly'),
                'reading_day' => Setting::get('reading_day', 'last_day'),
                'grace_period' => Setting::get('grace_period', '14'),
                'invoice_project_name_ar' => Setting::get('invoice_project_name_ar', 'مشروع مياه الوشاح'),
                'invoice_phone_1' => Setting::get('invoice_phone_1', '1234567890'),
                'reading_invoice_message_template' => Setting::get('reading_invoice_message_template', $defaultTemplate),
                'payment_receipt_message_template' => Setting::get('payment_receipt_message_template', $defaultPaymentTemplate),
                'subscription_invoice_template' => Setting::get('subscription_invoice_template', $defaultSubscriptionTemplate),
                'tanker_invoice_template' => Setting::get('tanker_invoice_template', $defaultTankerTemplate),
            ]
        ]);
    }
}
