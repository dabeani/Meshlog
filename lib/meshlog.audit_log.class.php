<?php

class MeshLogAuditLog extends MeshLogEntity {
    protected static $table = "audit_log";

    // Event type constants
    const EVENT_LOGIN_OK     = 'login.ok';
    const EVENT_LOGIN_FAIL   = 'login.fail';
    const EVENT_LOGOUT       = 'logout';
    const EVENT_PURGE_AUTO   = 'purge.auto';
    const EVENT_PURGE_MANUAL = 'purge.manual';
    const EVENT_ERROR        = 'error';

    public $event      = null;
    public $actor      = '';
    public $detail     = '';
    public $ip         = '';
    public $created_at = null;

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;
        $m = new MeshLogAuditLog($meshlog);
        $m->_id       = $data['id'];
        $m->event     = $data['event'];
        $m->actor     = $data['actor'];
        $m->detail    = $data['detail'];
        $m->ip        = $data['ip'];
        $m->created_at = $data['created_at'];
        return $m;
    }

    public static function fromJson($data, $meshlog) {
        if (!$data) return null;
        $m = new MeshLogAuditLog($meshlog);
        $m->event  = $data['event']  ?? '';
        $m->actor  = $data['actor']  ?? '';
        $m->detail = $data['detail'] ?? '';
        $m->ip     = $data['ip']     ?? '';
        return $m;
    }

    public function getParams() {
        return array(
            'event'  => [$this->event,  PDO::PARAM_STR],
            'actor'  => [$this->actor,  PDO::PARAM_STR],
            'detail' => [$this->detail, PDO::PARAM_STR],
            'ip'     => [$this->ip,     PDO::PARAM_STR],
        );
    }

    public function isValid() {
        return !empty(static::$table) && !empty($this->event);
    }

    /**
     * Write a single audit entry.  Safe to call even if the table does not
     * exist yet (swallows PDO exceptions so ingest is never interrupted).
     */
    public static function write($meshlog, $event, $actor = '', $detail = '', $ip = '') {
        try {
            $entry = new MeshLogAuditLog($meshlog);
            $entry->event  = substr($event,  0, 64);
            $entry->actor  = substr($actor,  0, 200);
            $entry->detail = $detail;
            $entry->ip     = substr($ip,     0, 45);
            $entry->save($meshlog);
        } catch (Exception $e) {
            error_log('MeshLogAuditLog::write failed: ' . $e->getMessage());
        }
    }

    /**
     * Return the N most recent audit entries as plain arrays.
     */
    public static function recent($meshlog, $limit = 200, $offset = 0) {
        $limit  = max(1, min(1000, intval($limit)));
        $offset = max(0, intval($offset));
        $stmt = $meshlog->pdo->prepare(
            "SELECT * FROM audit_log ORDER BY id DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lim',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off',  $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count total rows (for pagination).
     */
    public static function countTotal($meshlog) {
        $stmt = $meshlog->pdo->query("SELECT COUNT(*) FROM audit_log");
        return (int)$stmt->fetchColumn();
    }
}

?>
