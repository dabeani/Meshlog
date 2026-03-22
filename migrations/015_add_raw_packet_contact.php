<?php

class Migration_015 extends Migration {
    public function __construct() {
        parent::__construct(14, 15);
    }

    function migrate($pdo) {
        // Add optional contact_id to raw_packets so that packets whose sender
        // is identifiable (ANON_REQ has full sender pubkey; REQ/RESP have
        // src_hash usable as a short-key lookup) can be linked to a contact
        // and included in per-device packet-count statistics.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'raw_packets'
               AND COLUMN_NAME  = 'contact_id'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec(
                "ALTER TABLE `raw_packets`
                 ADD COLUMN `contact_id` INT(11) NULL DEFAULT NULL AFTER `reporter_id`,
                 ADD KEY `raw_packets_contact_id_idx` (`contact_id`),
                 ADD CONSTRAINT `raw_packet_contact_fk`
                     FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`)
                     ON DELETE SET NULL"
            );
        }

        return array(
            'success' => true,
            'message' => ''
        );
    }
}

?>
