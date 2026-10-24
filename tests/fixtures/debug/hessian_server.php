<?php
/**
 * Hessian 2.0 fixture: 读取请求体,返回固定的 Hessian 字符串值 "hello"。
 * 用作 php -S 的路由脚本,任意路径都返回同一响应。
 */
$body = file_get_contents('php://input');
header('Content-Type: x-application/hessian');
header('X-Request-Bytes: ' . strlen((string)$body));
// /envelope 路径返回 Hessian 2.0 应答信封 r x02 x00 <value> z
if (strpos((string)($_SERVER['REQUEST_URI'] ?? ''), 'envelope') !== false) {
    echo "r\x02\x00\x05helloz";
    exit;
}
// /fault 路径返回 Hessian 2.0 fault 应答
if (strpos((string)($_SERVER['REQUEST_URI'] ?? ''), 'fault') !== false) {
    echo "r\x02\x00f\x04code\x10ServiceException\x07message\x0eFile Not Foundz";
    exit;
}
echo "\x05hello";
