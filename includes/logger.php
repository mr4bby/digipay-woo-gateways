<?php
// ------------------------
// Digipay global logger
// ------------------------

if (! defined('DIGIPAY_LOG')) {
    define('DIGIPAY_LOG', false);
}

function digipay_init_logger() {
    global $digipay_logger, $digipay_log_context;

    $digipay_log_context = array('source' => 'digipay');

    if (defined('DIGIPAY_LOG') && DIGIPAY_LOG === true && function_exists('wc_get_logger')) {
        $digipay_logger = wc_get_logger();
    } else {
        $digipay_logger = new class {
            public function __call($name, $args) {
                return null;
            }
        };
    }
}

add_action('init', 'digipay_init_logger', 0);

/**
 * @return object logger
 */
function digipay_logger() {
    global $digipay_logger;
    if (! isset($digipay_logger)) {
        digipay_init_logger();
    }
    return $digipay_logger;
}

/**
 * @param string $level (info, error, warning, debug, etc.)
 * @param string $message
 * @param array  $context
 */
function digipay_log($level, $message, $context = array()) {
    global $digipay_log_context;
    if (! is_array($context)) {
        $context = array();
    }
    $context = array_merge($digipay_log_context ?? array(), $context);

    $logger = digipay_logger();

    if (is_object($logger) && method_exists($logger, $level)) {
        return $logger->{$level}($message, $context);
    }

    if (is_object($logger) && method_exists($logger, 'log')) {
        return $logger->log($level, $message, $context);
    }

    return null;
}
