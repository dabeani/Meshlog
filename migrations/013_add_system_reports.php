<?php

class Migration_013 extends Migration {
    public function __construct() {
        parent::__construct(12, 13);
    }

    function migrate($pdo) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `system_reports` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `contact_id` int(11) DEFAULT NULL,
                `reporter_id` int(11) NOT NULL,
                `version` varchar(64) DEFAULT NULL,
                `heap_total` int(11) DEFAULT NULL,
                `heap_free` int(11) DEFAULT NULL,
                `rssi` int(11) DEFAULT NULL,
                `uptime` int(11) DEFAULT NULL,
                `sent_at` datetime(3) DEFAULT NULL,
                `received_at` datetime(3) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `system_reports_contact_id_idx` (`contact_id`),
                KEY `system_reports_reporter_id_idx` (`reporter_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return array(
            'success' => true,
            'message' => ''
        );
    }
}

?>