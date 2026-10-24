<?php
header('Content-Type: text/plain');
echo $_SERVER['REQUEST_METHOD'] . ' ' . file_get_contents('php://input');
