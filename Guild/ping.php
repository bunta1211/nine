<?php
/**
 * Guild 診断用: PHP が動作しているかだけを返す（require なし）
 * https://social9.jp/Guild/ping.php が 200 OK なら .htaccess と PHP は問題なし。
 */
header('Content-Type: text/plain; charset=UTF-8');
echo 'OK';
