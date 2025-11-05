<?php

class ErrorNotifier
{
    private mysqli $db;
    private string $webhook;
    private string $env;
    private string $app;
    private int $throttleSeconds;

    public function __construct(array $config)
    {
        $this->db = $config['mysqli'];
        $this->webhook = $config['slack_webhook'] ?? '';
        $this->env = $config['env'] ?? 'production';
        $this->app = $config['app'] ?? 'php-app';
        $this->throttleSeconds = $config['throttle'] ?? 600; // 10 minutes
    }

    public function register(): void
    {
        if ($this->env == 'production') {
            set_error_handler([$this, 'handlePhpError']);
            set_exception_handler([$this, 'handleException']);
            register_shutdown_function([$this, 'handleShutdown']);
        }
    }

    public function handlePhpError($errno, $errstr, $errfile, $errline)
    {
        if (!(error_reporting() & $errno)) return false;

        $payload = $this->buildPayload($errstr, $errfile, $errline, $errno);
        $this->logError($payload);
        $this->process($payload);

        return false; // still let PHP log to file
    }

    public function handleException(Throwable $e)
    {
        $payload = $this->buildPayload(
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            'UNCAUGHT_EXCEPTION'
        );
        $payload['trace'] = $e->getTraceAsString();

        $this->logError($payload);
        $this->process($payload);

        throw $e;
    }

    public function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            $payload = $this->buildPayload($err['message'], $err['file'], $err['line'], $err['type']);
            $this->logError($payload);
            $this->process($payload);
        }
    }

    private function buildPayload($message, $file, $line, $level): array
    {
        return [
            'level' => $this->errorLevelName($level),
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'env' => $this->env,
            'app' => $this->app,
            'host' => gethostname() ?: php_uname('n'),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user' => $_SESSION['user_id'] ?? ($_COOKIE['uid'] ?? null),
            'trace' => (new Exception())->getTraceAsString(),
        ];
    }

    private function process(array $payload): void
    {
        $hash = md5($payload['file'] . $payload['line'] . $payload['message']);

        // check for recent duplicate
        $stmt = $this->db->prepare("SELECT hash FROM error_alerts 
            WHERE hash = ? AND last_seen > (NOW() - INTERVAL ? SECOND)");
        $stmt->bind_param("si", $hash, $this->throttleSeconds);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            // duplicate: update hit count, skip Slack
            $stmt->close();
            $upd = $this->db->prepare("UPDATE error_alerts 
                SET hit_count = hit_count + 1, last_seen = NOW() 
                WHERE hash = ?");
            $upd->bind_param("s", $hash);
            $upd->execute();
            $upd->close();
            return;
        }
        $stmt->close();

        // new or expired -> insert or update + send Slack
        $ins = $this->db->prepare("INSERT INTO error_alerts 
            (hash, level, message, file, line) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = NOW()");
        $ins->bind_param("ssssi", $hash, $payload['level'], $payload['message'], $payload['file'], $payload['line']);
        $ins->execute();
        $ins->close();

        $this->sendSlack($payload);
    }

    private function sendSlack(array $payload): void
    {
        if (empty($this->webhook)) return;

        // Pick color based on severity
        $level = strtoupper($payload['level']);
        $color = match ($level) {
            'ERROR', 'E_ERROR', 'USER_ERROR', 'UNCAUGHT_EXCEPTION' => '#e01e5a', // red
            'WARNING', 'E_WARNING', 'USER_WARNING' => '#ECB22E', // yellow
            'NOTICE', 'E_NOTICE', 'USER_NOTICE', 'DEPRECATED' => '#36C5F0', // blue
            default => '#2EB67D', // green / neutral
        };

        // Shortened trace
        $trace = $payload['trace'] ?? '';
        if ($trace) {
            $lines = explode("\n", $trace);
            $trace = implode("\n", array_slice($lines, 0, 5)); // top 5 lines only
            if (count($lines) > 5) $trace .= "\n... (truncated)";
        }

        $attachment = [
            'color' => $color,
            'title' => "{$level} in {$payload['file']}:{$payload['line']}",
            'text' => $payload['message'],
            'fields' => [
                [
                    'title' => 'Environment',
                    'value' => $payload['env'] ?? 'unknown',
                    'short' => true,
                ],
                [
                    'title' => 'App',
                    'value' => $payload['app'] ?? 'unknown',
                    'short' => true,
                ],
                [
                    'title' => 'Host',
                    'value' => $payload['host'] ?? 'unknown',
                    'short' => true,
                ],
            ],
            'footer' => ($payload['uri'] ?? '') ?: '(no URI)',
            'ts' => time(),
        ];

        if ($trace) {
            $attachment['fields'][] = [
                'title' => 'Trace (partial)',
                'value' => "```{$trace}```",
                'short' => false,
            ];
        }

        $payloadJson = json_encode([
            'attachments' => [$attachment],
        ]);

        $ch = curl_init($this->webhook);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payloadJson,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log("Slack send failed: " . curl_error($ch));
        }
        curl_close($ch);
    }


    private function logError(array $payload): void
    {
        $msg = "[{$payload['level']}] {$payload['message']} in {$payload['file']}:{$payload['line']}";
        error_log($msg);
    }

    private function errorLevelName($errno): string
    {
        $map = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            mysqli_sql_exception::class => 'MYSQLI_EXCEPTION',
            'UNCAUGHT_EXCEPTION' => 'UNCAUGHT_EXCEPTION'
        ];
        return $map[$errno] ?? "UNKNOWN({$errno})";
    }
}
