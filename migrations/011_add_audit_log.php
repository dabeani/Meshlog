<?php

class Migration_011 extends Migration {
    public function __construct() {
        parent::__construct(10, 11);
    }

    function migrate($pdo) {
        $pdo->exec("CREATE TABLE `audit_log` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event`      VARCHAR(64)  NOT NULL,
            `actor`      VARCHAR(200) NOT NULL DEFAULT '',
            `detail`     TEXT         NOT NULL,
            `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_audit_event` (`event`),
            KEY `idx_audit_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        return array(
            'success' => true,
            'message' => ''
        );
    }
}

?>
