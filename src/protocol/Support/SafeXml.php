<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use erikwang2013\apidoc\protocol\ProtocolParseException;

/**
 * 安全 XML 加载:禁 DOCTYPE/外部实体/网络,只做本地解析
 */
class SafeXml
{
    /**
     * @param string $xml
     * @return DOMDocument
     * @throws ProtocolParseException
     */
    public static function load(string $xml): DOMDocument
    {
        if (!class_exists('DOMDocument')) {
            throw new ProtocolParseException('ext-dom is required for XML parsing', 0, 0);
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new ProtocolParseException('DOCTYPE is not allowed', 0, 0);
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            $first = $errors[0] ?? null;
            $message = $first ? trim($first->message) : 'unknown XML error';
            throw new ProtocolParseException('XML parse error: ' . $message, $first ? (int)$first->line : 0, $first ? (int)$first->column : 0);
        }
        return $dom;
    }

    public static function first(DOMNode $node, string $localName): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }
        return null;
    }

    /**
     * @return array<int,DOMElement>
     */
    public static function all(DOMNode $node, string $localName): array
    {
        $result = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $result[] = $child;
            }
        }
        return $result;
    }

    public static function attr(?DOMElement $element, string $name): string
    {
        if (!$element instanceof DOMElement) {
            return '';
        }
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }
}
