<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfOfflineAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_and_receipt_embed_arabic_fonts_without_network_assets(): void
    {
        $square = Square::create(['name' => 'مربع الطباعة']);
        $subscriber = Subscriber::create([
            'name' => 'مشترك الطباعة', 'status' => 'active', 'square_id' => $square->id,
        ]);
        $invoice = Invoice::create([
            'subscriber_id' => $subscriber->id,
            'bill_number' => 'INV-PRINT-1',
            'bill_date' => '2026-11-03',
            'total_amount' => 1200,
            'status' => 'issued',
        ]);
        $payment = new Payment([
            'receipt_number' => 'REC-PRINT-1',
            'amount' => 100,
            'payment_date' => '2026-11-03',
            'payment_method' => 'cash',
        ]);
        $payment->setRelation('subscriber', $subscriber);

        foreach (['invoice' => $invoice, 'receipt' => $payment] as $view => $record) {
            $html = view("pdf.$view", [$view === 'invoice' ? 'invoice' : 'payment' => $record])->render();
            $this->assertStringNotContainsString('@import', $html);
            $this->assertDoesNotMatchRegularExpression('~(?:src|url)\s*[=(]\s*[\x22\x27]?https?://~i', $html);
            $this->assertSame(4, substr_count($html, 'data:font/ttf;base64,'));
            preg_match_all('~data:font/ttf;base64,([^\x27]+)~', $html, $fonts);
            foreach ($fonts[1] as $font) {
                $decoded = base64_decode($font, true);
                $this->assertNotFalse($decoded);
                $this->assertSame("\x00\x01\x00\x00", substr($decoded, 0, 4));
            }
            $this->assertStringContainsString($subscriber->name, $html);
        }
    }
}
