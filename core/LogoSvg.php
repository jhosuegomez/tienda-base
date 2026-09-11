<?php
declare(strict_types=1);

final class LogoSvg
{
    // Accept static vector geometry only. Never store executable SVG uploads.
    public static function validate(string $source): bool
    {
        if ($source === '' || strlen($source) > 500 * 1024
            || preg_match('/<!DOCTYPE|<!ENTITY|<\?(?!xml\s)/i', $source)) {
            return false;
        }
        if (!class_exists(DOMDocument::class)) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            if (!$doc->loadXML($source, LIBXML_NONET) || $doc->doctype !== null
                || $doc->documentElement?->localName !== 'svg') {
                return false;
            }
            $elements = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'clipPath', 'mask', 'title', 'desc'];
            $attributes = ['id', 'version', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'transform', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'clip-path', 'clip-rule', 'mask', 'maskUnits', 'maskContentUnits', 'clipPathUnits', 'preserveAspectRatio', 'vector-effect'];
            foreach ($doc->getElementsByTagName('*') as $node) {
                if ($node->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($node->localName, $elements, true)) {
                    return false;
                }
                foreach ($node->attributes as $attr) {
                    if ($attr->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                        continue;
                    }
                    if ($attr->namespaceURI !== null || !in_array($attr->name, $attributes, true)) {
                        return false;
                    }
                    if (preg_match('/url\s*\(/i', $attr->value)
                        && !preg_match('/^url\(#[a-zA-Z_][a-zA-Z0-9_.-]*\)$/', $attr->value)) {
                        return false;
                    }
                }
            }
            return true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
