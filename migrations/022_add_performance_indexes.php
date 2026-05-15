<?php

class Migration_022 extends Migration {
    public function __construct() {
        parent::__construct(21, 22);
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

    private function indexExists($pdo, $tableName, $indexName) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name"
        );
        $stmt->execute(array(
            ':table_name' => $tableName,
            ':index_name' => $indexName,
        ));

        return intval($stmt->fetchColumn()) > 0;
    }

    private function addIndexIfMissing($pdo, $tableName, $indexName, $columns) {
        if (!$this->tableExists($pdo, $tableName)) {
            return false;
        }
        if ($this->indexExists($pdo, $tableName, $indexName)) {
            return false;
        }

        $columnSql = array();
        foreach ($columns as $columnName) {
            $columnSql[] = "`" . $columnName . "`";
        }

        $sql = "ALTER TABLE `" . $tableName . "` ADD INDEX `" . $indexName . "` (" . implode(', ', $columnSql) . ")";
        $pdo->exec($sql);
        return true;
    }

    public function migrate($pdo) {
        $indexPlan = array(
            array('reporters', 'idx_reporters_public_key_authorized', array('public_key', 'authorized')),
            array('reporters', 'idx_reporters_auth_lookup', array('public_key', 'auth', 'authorized')),

            array('contacts', 'idx_contacts_hidden_last_heard_id', array('hidden', 'last_heard_at', 'id')),

            array('advertisements', 'idx_adv_hash_created', array('hash', 'created_at')),
            array('advertisements', 'idx_adv_contact_created', array('contact_id', 'created_at')),
            array('advertisements', 'idx_adv_created', array('created_at')),

            array('direct_messages', 'idx_dm_hash_created', array('hash', 'created_at')),
            array('direct_messages', 'idx_dm_contact_created', array('contact_id', 'created_at')),
            array('direct_messages', 'idx_dm_created', array('created_at')),

            array('channel_messages', 'idx_cm_hash_created', array('hash', 'created_at')),
            array('channel_messages', 'idx_cm_contact_created', array('contact_id', 'created_at')),
            array('channel_messages', 'idx_cm_channel_created', array('channel_id', 'created_at')),
            array('channel_messages', 'idx_cm_created', array('created_at')),

            array('advertisement_reports', 'idx_ar_received_reporter', array('received_at', 'reporter_id')),
            array('advertisement_reports', 'idx_ar_advertisement_received', array('advertisement_id', 'received_at')),

            array('direct_message_reports', 'idx_dmr_received_reporter', array('received_at', 'reporter_id')),
            array('direct_message_reports', 'idx_dmr_direct_message_received', array('direct_message_id', 'received_at')),

            array('channel_message_reports', 'idx_cmr_received_reporter', array('received_at', 'reporter_id')),
            array('channel_message_reports', 'idx_cmr_channel_message_received', array('channel_message_id', 'received_at')),

            array('raw_packets', 'idx_raw_received_reporter', array('received_at', 'reporter_id')),
            array('raw_packets', 'idx_raw_created', array('created_at')),
            array('raw_packets', 'idx_raw_contact_created', array('contact_id', 'created_at')),

            array('telemetry', 'idx_tel_received_reporter', array('received_at', 'reporter_id')),
            array('telemetry', 'idx_tel_created', array('created_at')),
            array('telemetry', 'idx_tel_contact_created', array('contact_id', 'created_at')),

            array('system_reports', 'idx_sys_received_reporter', array('received_at', 'reporter_id')),
            array('system_reports', 'idx_sys_created', array('created_at')),
            array('system_reports', 'idx_sys_contact_created', array('contact_id', 'created_at')),
        );

        $createdIndexes = 0;
        foreach ($indexPlan as $entry) {
            $tableName = $entry[0];
            $indexName = $entry[1];
            $columns = $entry[2];

            if ($this->addIndexIfMissing($pdo, $tableName, $indexName, $columns)) {
                $createdIndexes++;
            }
        }

        return array(
            'success' => true,
            'message' => 'Added long-run performance indexes (' . $createdIndexes . ' new indexes)'
        );
    }
}

?>
