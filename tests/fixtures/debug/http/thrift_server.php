<?php
/**
 * Thrift over HTTP fixture: 返回 TBinaryProtocol REPLY,i32 字段 0 = 42。
 */
$body = file_get_contents('php://input');
header('Content-Type: application/x-thrift');
$method = 'ping';
if (is_string($body) && strlen($body) >= 8) {
    $first = unpack('N', substr($body, 0, 4))[1];
    if ($first > 0x7fffffff) {
        $nameLength = unpack('N', substr($body, 4, 4))[1];
        if ($nameLength > 0 && $nameLength <= 255 && strlen($body) >= 8 + $nameLength) {
            $method = substr($body, 8, $nameLength);
        }
    }
}
echo pack('N', 0x80010000 | 2) . pack('N', strlen($method)) . $method . pack('N', 0)
    . "\x08\x00\x00" . pack('N', 42) . "\x00";
