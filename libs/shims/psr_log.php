<?php
// libs/shims/psr_log.php
namespace Psr\Log;

interface LoggerInterface {
    public function emergency($message, array $context = []);
    public function alert($message, array $context = []);
    public function critical($message, array $context = []);
    public function error($message, array $context = []);
    public function warning($message, array $context = []);
    public function notice($message, array $context = []);
    public function info($message, array $context = []);
    public function debug($message, array $context = []);
}

class NullLogger implements LoggerInterface {
    public function emergency($m, array $c = []){}
    public function alert($m, array $c = []){}
    public function critical($m, array $c = []){}
    public function error($m, array $c = []){}
    public function warning($m, array $c = []){}
    public function notice($m, array $c = []){}
    public function info($m, array $c = []){}
    public function debug($m, array $c = []){}
}