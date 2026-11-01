<?php

declare(strict_types=1);

namespace Cms\Media;

use DOMDocument;
use DOMElement;
use RuntimeException;

/** Allowlist SVG sanitizer: shapes, text, gradients and styling only — no scripts, links or foreign content. */
final class SvgSanitizer
{
    private const array ELEMENTS = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'symbol', 'use', 'title', 'desc', 'style', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'femerge', 'femergenode', 'feflood', 'fecomposite'];

    private const array ATTRIBUTES = ['id', 'class', 'd', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'viewbox', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-opacity', 'opacity', 'transform', 'points', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'clip-path', 'clip-rule', 'mask', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'preserveaspectratio', 'xmlns', 'version', 'style', 'href', 'xlink:href', 'stddeviation', 'in', 'in2', 'result', 'mode', 'values', 'type', 'dx', 'dy', 'flood-color', 'flood-opacity', 'operator', 'patternunits', 'maskunits', 'role', 'aria-label', 'aria-hidden', 'focusable'];

    public function clean(string $svg): string
    {
        if (strlen($svg) > 2_000_000 || preg_match('/<!ENTITY|<!DOCTYPE/i', $svg)) {
            throw new RuntimeException(__('This SVG is not allowed.'));
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') {
            throw new RuntimeException(__('Not a valid SVG file.'));
        }

        $this->walk($doc->documentElement);

        return (string) $doc->saveXML($doc->documentElement);
    }

    private function walk(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (! in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $element->removeChild($child);
                    continue;
                }

                $this->walk($child);
            } elseif ($child->nodeType === XML_PI_NODE || $child->nodeType === XML_COMMENT_NODE) {
                $element->removeChild($child);
            }
        }

        foreach (iterator_to_array($element->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim($attr->nodeValue ?? '');

            $unsafe = ! in_array($name, self::ATTRIBUTES, true)
                || str_starts_with($name, 'on')
                || (in_array($name, ['href', 'xlink:href'], true) && ! str_starts_with($value, '#'))
                || preg_match('/javascript:|expression\(|url\s*\(\s*["\']?\s*(?!#)/i', $value);

            if ($unsafe) {
                $element->removeAttributeNode($attr);
            }
        }

        if (strtolower($element->localName) === 'style') {
            $element->textContent = preg_replace('/@import|url\s*\(\s*["\']?\s*(?!#)[^)]*\)|expression\s*\(|javascript:/i', '', $element->textContent) ?? '';
        }
    }
}
