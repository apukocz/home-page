<?php
/**
 * Przekierowanie do panelu sterowania bramami na serwerze PI1 (10.0.0.4)
 * Fizyczne przekaźniki bram (GPIO 530 i GPIO 527) podłączone są do Raspberry Pi 1.
 */
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: http://10.0.0.4/brama.php' . $qs, true, 302);
exit;
