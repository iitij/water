<?php

/**
 * Experimental mPDF compatibility adapter for CI preview only.
 * Does not change production templates, routes, or accounting calculations.
 */
function waterMpdfPreviewHtml(string $html): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET)) {
            throw new RuntimeException('Cannot parse rendered print template');
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    $xpath = new DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//style')) as $style) {
        $style->parentNode->removeChild($style);
    }
    foreach (iterator_to_array($xpath->query('//*[@style]')) as $element) {
        // Retain only text direction, color and emphasis; no browser-specific geometry.
        $safe = [];
        foreach (explode(';', $element->getAttribute('style')) as $declaration) {
            if (preg_match('/^\s*(direction|color|font-weight)\s*:\s*([#a-zA-Z0-9 -]+)\s*$/', $declaration, $matches)) {
                $safe[] = $matches[1] . ':' . $matches[2];
            }
        }
        $element->removeAttribute('style');
        if ($safe) {
            $element->setAttribute('style', implode(';', $safe));
        }
    }

    // Convert flex rows into real tables, preserving all child content and order.
    foreach (['due-box', 'header', 'bottom-row', 'amount-strip', 'footer-box'] as $class) {
        $query = '//div[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]';
        foreach (iterator_to_array($xpath->query($query)) as $row) {
            $children = array_values(array_filter(iterator_to_array($row->childNodes), fn ($node) => $node instanceof DOMElement));
            if (!$children) {
                continue;
            }
            $table = $document->createElement('table');
            $table->setAttribute('class', 'layout-table '.$class);
            $table->setAttribute('width', '100%');
            $tr = $document->createElement('tr');
            $table->appendChild($tr);
            foreach ($children as $child) {
                $td = $document->createElement('td');
                $td->setAttribute('class', $child->getAttribute('class'));
                $td->setAttribute('width', (100 / count($children)).'%');
                if ($child->hasAttribute('dir')) {
                    $td->setAttribute('dir', $child->getAttribute('dir'));
                }
                if ($child->hasAttribute('style')) {
                    $td->setAttribute('style', $child->getAttribute('style'));
                }
                if ($class === 'header' && str_contains($child->getAttribute('class'), 'header-left')) {
                    $td->setAttribute('dir', 'ltr');
                }
                if ($class === 'amount-strip') {
                    foreach (iterator_to_array($child->childNodes) as $index => $part) {
                        if ($part instanceof DOMElement) {
                            $part->setAttribute('class', $index === 0 ? 'amount-value' : 'amount-detail');
                        }
                    }
                    $first = null;
                    foreach ($child->childNodes as $part) {
                        if ($part instanceof DOMElement) {
                            $first = $part;
                            break;
                        }
                    }
                    if ($first) {
                        $first->setAttribute('class', 'amount-heading');
                    }
                }
                while ($child->firstChild) {
                    $td->appendChild($child->firstChild);
                }
                $tr->appendChild($td);
            }
            $row->parentNode->replaceChild($table, $row);
        }
    }

    $css = <<<'CSS'
body { font-family:tajawal; font-size:9pt; color:#0b2b40; direction:rtl; }
.page-wrapper, .content { margin:0; padding:0; }
.layout-table { border-collapse:collapse; width:100%; margin:1mm 0; }
.layout-table td { vertical-align:middle; padding:1mm; }
.header-right { text-align:right; }
.header-center { text-align:center; }
.header-left { text-align:left; direction:ltr; }
.project-name-ar { font-size:16pt; font-weight:bold; color:#124c6b; }
.project-name-en { font-size:13pt; font-weight:bold; color:#124c6b; }
.header-detail { font-size:8pt; }
.logo-img { width:22mm; }
.title-container { background-color:#026b7e; color:#ffffff; text-align:center; padding:1mm; margin:1mm 0; }
.title-text { font-size:12pt; font-weight:bold; }
.rounded-table { width:100%; border-collapse:collapse; margin:1mm 0; }
.rounded-table td { border:0.2mm solid #8ca9b7; padding:1mm; font-size:9pt; text-align:center; vertical-align:middle; }
.label-cell, .label-cell-two { background-color:#b5dcea; font-weight:bold; }
.value-cell { background-color:#f5f9fb; }
.section-title { background-color:#86c1dd; text-align:right; font-weight:bold; }
.due-label, .amount-heading { background-color:#19528e; color:#ffffff; font-weight:bold; text-align:center; font-size:9pt; }
.due-value, .amount-detail { background-color:#e4ecef; color:#0b2b40; font-weight:bold; text-align:center; font-size:11pt; }
.amount-section { text-align:center; border:0.2mm solid #8ca9b7; }
.footer-box { border:0.2mm solid #8ca9b7; }
.thank-you { text-align:center; font-size:8pt; color:#2e5970; }
CSS;
    $head = $document->getElementsByTagName('head')->item(0);
    $style = $document->createElement('style');
    $style->appendChild($document->createTextNode($css));
    $head->appendChild($style);
    // A native watermark requires separate renderer support and a dedicated logo fixture.
    // The current preview uses no watermark. Fail rather than silently remove one.
    if ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," watermark ")]')->length > 0) {
        throw new RuntimeException('Watermark fixture requires a separate native mPDF test');
    }
    return $document->saveHTML();
}
