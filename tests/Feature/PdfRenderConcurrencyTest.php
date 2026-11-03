<?php

namespace Tests\Feature;

use App\Services\Pdf\SerialBrowsershotDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfOptions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PdfRenderConcurrencyTest extends TestCase
{
    public function test_default_pdf_driver_uses_the_shared_render_gate(): void
    {
        $this->assertInstanceOf(SerialBrowsershotDriver::class, app(PdfDriver::class));
    }

    public function test_warm_worker_preserves_layout_and_rejects_invalid_responses(): void
    {
        config(['water-pdf.reuse_browser' => true, 'cache.stores.file.driver' => 'array']);
        Cache::forgetDriver('file');
        $options = new PdfOptions;
        $options->format = 'A5';
        $options->orientation = 'landscape';
        $options->margins = ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0, 'unit' => 'mm'];
        $driver = app(PdfDriver::class);
        Http::fake(['127.0.0.1:9310/*' => Http::sequence()
            ->push('%PDF-1.7 test', 200)->push('<html>error</html>', 200)]);
        $this->assertSame('%PDF-1.7 test', $driver->generatePdf('<p>فاتورة</p>', null, null, $options));
        Http::assertSent(fn ($request) => $request['options']['format'] === 'A5'
            && $request['options']['orientation'] === 'landscape' && $request['options']['margins']['unit'] === 'mm'
            && $request['options']['margins']['top'] === 0);
        try {
            $driver->generatePdf('<p>فاتورة</p>', null, null, $options);
            $this->fail('HTML must never become PDF bytes');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
    }

    public function test_parallel_render_is_rejected_and_lock_is_released_after_failure(): void
    {
        // Same store API with an isolated lock for this test process.
        config(['cache.stores.file.driver' => 'array']);
        Cache::forgetDriver('file');
        $driver = new class extends SerialBrowsershotDriver {
            public function run(callable $render): mixed { return $this->renderExclusively($render); }
        };
        $lock = Cache::store('file')->lock('water-pdf-render', 180);
        $this->assertTrue($lock->get());
        try {
            $driver->run(fn () => $this->fail('A concurrent Chromium process must not start'));
            $this->fail('Expected a busy response');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertSame('5', $error->getHeaders()['Retry-After']);
        } finally {
            $lock->release();
        }
        try {
            $driver->run(fn () => throw new \RuntimeException('render failed'));
        } catch (\RuntimeException $error) {
            $this->assertSame('render failed', $error->getMessage());
        }
        $this->assertSame('PDF', $driver->run(fn () => 'PDF'));
    }
}
