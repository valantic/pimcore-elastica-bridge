<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Sentry\Breadcrumb;

class SentryBreadcrumbLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param LogLevel::*|mixed $level
     */
    public function log($level, $message, array $context = []): void
    {
        \Sentry\addBreadcrumb(
            new Breadcrumb(
                match ($level) {
                    LogLevel::EMERGENCY, LogLevel::CRITICAL => Breadcrumb::LEVEL_FATAL,
                    LogLevel::ALERT,  LogLevel::ERROR => Breadcrumb::LEVEL_ERROR,
                    LogLevel::WARNING => Breadcrumb::LEVEL_WARNING,
                    LogLevel::INFO, LogLevel::NOTICE => Breadcrumb::LEVEL_INFO,
                    LogLevel::DEBUG => Breadcrumb::LEVEL_DEBUG,
                    default => Breadcrumb::LEVEL_DEBUG,
                },
                match ($level) {
                    Breadcrumb::LEVEL_FATAL, Breadcrumb::LEVEL_ERROR => Breadcrumb::TYPE_ERROR,
                    default => Breadcrumb::TYPE_DEFAULT,
                },
                'elastica',
                $message,
                $context
            )
        );
    }
}
