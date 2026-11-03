<?php

namespace App\Services\Pdf;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\PdfOptions;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Keep concurrent print requests from exhausting the small production VM. */
class SerialBrowsershotDriver extends BrowsershotDriver
{
    public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
    {
        if (config('water-pdf.reuse_browser', false)) {
            return $this->renderExclusively(function () use ($html, $headerHtml, $footerHtml, $options) {
                try {
                    $response = Http::timeout(40)->post('http://127.0.0.1:9310/render', [
                        'html' => $html, 'headerHtml' => $headerHtml, 'footerHtml' => $footerHtml,
                        'options' => get_object_vars($options),
                    ]);
                    if ($response->successful() && str_starts_with($response->body(), '%PDF-')) {
                        return $response->body();
                    }
                } catch (ConnectionException $exception) {
                    throw new HttpException(503, 'تعذّر تجهيز ملف الطباعة. أعد المحاولة بعد قليل.', $exception);
                }
                throw new HttpException(503, 'تعذّر تجهيز ملف الطباعة. أعد المحاولة بعد قليل.');
            });
        }

        return $this->renderExclusively(fn () => parent::generatePdf($html, $headerHtml, $footerHtml, $options));
    }

    public function savePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options, string $path): void
    {
        if (config('water-pdf.reuse_browser', false)) {
            if (file_put_contents($path, $this->generatePdf($html, $headerHtml, $footerHtml, $options)) === false) {
                throw new \RuntimeException('Could not save generated PDF');
            }
            return;
        }
        $this->renderExclusively(fn () => parent::savePdf($html, $headerHtml, $footerHtml, $options, $path));
    }

    protected function buildBrowsershot(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): Browsershot
    {
        $browser = parent::buildBrowsershot($html, $headerHtml, $footerHtml, $options)
            ->timeout(35);
        if (PHP_OS_FAMILY === 'Linux') {
            // These trusted server templates need one renderer. Avoid loading
            // multiple Chromium copies into the 1 GB Linux production VM.
            $browser->addChromiumArguments(['renderer-process-limit' => 1, 'no-zygote', 'disable-gpu']);
        }

        return $browser;
    }

    protected function renderExclusively(callable $render): mixed
    {
        // The file store shares the lock across FPM/queue workers regardless of
        // the configured response cache. Financial document bytes are not cached.
        try {
            return Cache::store('file')->lock('water-pdf-render', 180)->block(5, $render);
        } catch (LockTimeoutException $exception) {
            throw new HttpException(503, 'الطباعة مشغولة حالياً. انتظر قليلاً ثم أعد المحاولة.', $exception, ['Retry-After' => '5']);
        }
    }
}
