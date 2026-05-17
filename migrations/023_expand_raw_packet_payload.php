<?php

class Migration_023 extends Migration {
    public function __construct() {
        parent::__construct(22, 23);
    }

    private function tableExists($pdo, $tableName) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name"
        );
        $stmt->execute(array(':table_name' => $tableName));

        return intval($stmt->fetchColumn()) > 0;
    }

    public function migrate($pdo) {
        if (!$this->tableExists($pdo, 'raw_packets')) {
            return array(
                'success' => true,
                'message' => 'raw_packets table not present; nothing to expand'
            );
        }

        $pdo->exec("ALTER TABLE `raw_packets` MODIFY `payload` BLOB NOT NULL");

        return array(
            'success' => true,
            'message' => 'Expanded raw_packets.payload to BLOB for decoded packet metadata'
        );
    }
}

?>
