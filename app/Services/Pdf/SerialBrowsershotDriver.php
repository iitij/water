<?php

namespace App\Services\Pdf;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\PdfOptions;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Keep concurrent print requests from exhausting the small production VM. */
class SerialBrowsershotDriver extends BrowsershotDriver
{
    public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
    {
        return $this->renderExclusively(fn () => parent::generatePdf($html, $headerHtml, $footerHtml, $options));
    }

    public function savePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options, string $path): void
    {
        $this->renderExclusively(fn () => parent::savePdf($html, $headerHtml, $footerHtml, $options, $path));
    }

    protected function buildBrowsershot(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): Browsershot
    {
        return parent::buildBrowsershot($html, $headerHtml, $footerHtml, $options)
            ->timeout(25)
            ->addChromiumArguments(['renderer-process-limit' => 1, 'disable-gpu']);
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
