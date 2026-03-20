<?php

class MeshLogSetting extends MeshLogEntity {
    const KEY_DB_VERSION = "DB_VERSION";
    const KEY_MAX_CONTACT_AGE = "MAX_CONTACT_AGE";
    const KEY_MAX_GROUPING_AGE = "MAX_GROUPING_AGE";
    const KEY_INFLUXDB_URL = "INFLUXDB_URL";
    const KEY_INFLUXDB_DB = "INFLUXDB_DB";
    const KEY_ANONYMIZE_USERNAMES = "ANONYMIZE_USERNAMES";
    const KEY_DATA_RETENTION_ADV = "DATA_RETENTION_ADV";
    const KEY_DATA_RETENTION_MSG = "DATA_RETENTION_MSG";
    const KEY_DATA_RETENTION_RAW = "DATA_RETENTION_RAW";
    const KEY_LAST_PURGE_AT = "LAST_PURGE_AT";
    const KEY_TIME_SYNC_WARNING_THRESHOLD = "TIME_SYNC_WARNING_THRESHOLD";

    public static function getAdminDefinitions() {
        return array(
            static::KEY_MAX_CONTACT_AGE => array(
                'type' => 'number',
                'default' => 1814400,
            ),
            static::KEY_MAX_GROUPING_AGE => array(
                'type' => 'number',
                'default' => 21600,
            ),
            static::KEY_ANONYMIZE_USERNAMES => array(
                'type' => 'boolean',
                'default' => 0,
            ),
            static::KEY_DATA_RETENTION_ADV => array(
                'type' => 'number',
                'default' => 604800,
            ),
            static::KEY_DATA_RETENTION_MSG => array(
                'type' => 'number',
                'default' => 604800,
            ),
            static::KEY_DATA_RETENTION_RAW => array(
                'type' => 'number',
                'default' => 604800,
            ),
            static::KEY_TIME_SYNC_WARNING_THRESHOLD => array(
                'type' => 'number',
                'default' => 300,
            ),
        );
    }

    protected static $table = "settings";

    public $name = null;
    public $value = null;

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogSetting($meshlog);
        $m->name = $data['name'];
        $m->value = $data['value'];

        return $m;
    }

    public static function saveSettings($meshlog, $settings) {
        $tableStr = static::$table;
        $stmt = $meshlog->pdo->prepare("
            INSERT INTO $tableStr (name, value)
            VALUES (:name, :value)
            ON DUPLICATE KEY UPDATE value = :value
        ");

        foreach ($settings as $key => $val) {
            $stmt->execute([
                ':name'  => $key,
                ':value' => $val
            ]);
        }
    }

    public function asArray($secret = false) {
        return array(
            'id' => $this->getId(),
            'name' => $this->name,
            'value' => $this->value
        );
    }

    protected function getParams() {
        return array(
            "name" => array($this->name, PDO::PARAM_STR),
            "value" => array($this->value, PDO::PARAM_STR)
        );
    }
}

?>