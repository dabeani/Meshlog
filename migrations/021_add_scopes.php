<?php

class Migration_021 extends Migration {
    public function __construct() {
        parent::__construct(20, 21);
    }

    function migrate($pdo) {
        // Create scopes table for region scope definitions
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `scopes` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `number` tinyint(3) UNSIGNED NOT NULL UNIQUE,
                `name` varchar(255) NOT NULL,
                `description` varchar(512),
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `scope_number` (`number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        return array(
            'success' => true,
            'message' => 'Added scopes table for region scope definitions'
        );
    }
}

?>
