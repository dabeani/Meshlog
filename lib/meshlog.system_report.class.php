<?php

class MeshLogSystemReport extends MeshLogEntity {
    protected static $table = "system_reports";

    public $contact_ref = null;
    public $reporter_ref = null;

    public $version = null;
    public $heap_total = null;
    public $heap_free = null;
    public $rssi = null;
    public $uptime = null;

    public $sent_at = null;
    public $received_at = null;
    public $created_at = null;

    public static function fromJson($data, $meshlog) {
        $m = new MeshLogSystemReport($meshlog);

        if (!isset($data['sys']) || !isset($data['time'])) return $m;

        $m->version = $data['sys']['version'] ?? null;
        $m->heap_total = isset($data['sys']['heap_total']) ? intval($data['sys']['heap_total']) : null;
        $m->heap_free = isset($data['sys']['heap_free']) ? intval($data['sys']['heap_free']) : null;
        $m->rssi = isset($data['sys']['rssi']) ? intval($data['sys']['rssi']) : null;
        $m->uptime = isset($data['sys']['uptime']) ? intval($data['sys']['uptime']) : null;

        $m->sent_at = Utils::time2str($data['time']['sender']) ?? null;
        $m->received_at = Utils::time2str($data['time']['local'] ?? $data['time']['server'] ?? null) ?? null;

        return $m;
    }

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogSystemReport($meshlog);

        $m->_id = $data['id'];
        $m->version = $data['version'];
        $m->heap_total = $data['heap_total'];
        $m->heap_free = $data['heap_free'];
        $m->rssi = $data['rssi'];
        $m->uptime = $data['uptime'];
        $m->sent_at = $data['sent_at'];
        $m->received_at = $data['received_at'];
        $m->created_at = $data['created_at'];

        $m->contact_ref = !empty($data['contact_id']) ? MeshLogContact::findById($data['contact_id'], $meshlog) : null;
        $m->reporter_ref = !empty($data['reporter_id']) ? MeshLogReporter::findById($data['reporter_id'], $meshlog) : null;

        return $m;
    }

    function isValid() {
        if ($this->reporter_ref == null) return false;
        if ($this->sent_at == null) return false;
        if ($this->received_at == null) return false;
        return true;
    }

    public function asArray($secret = false) {
        return array(
            'id' => $this->getId(),
            'contact_id' => $this->contact_ref ? $this->contact_ref->getId() : null,
            'reporter_id' => $this->reporter_ref ? $this->reporter_ref->getId() : null,
            'version' => $this->version,
            'heap_total' => $this->heap_total,
            'heap_free' => $this->heap_free,
            'rssi' => $this->rssi,
            'uptime' => $this->uptime,
            'sent_at' => $this->sent_at,
            'received_at' => $this->received_at,
            'created_at' => $this->created_at,
        );
    }

    protected function getParams() {
        return array(
            'contact_id' => array($this->contact_ref ? $this->contact_ref->getId() : null, PDO::PARAM_INT),
            'reporter_id' => array($this->reporter_ref ? $this->reporter_ref->getId() : null, PDO::PARAM_INT),
            'version' => array($this->version, PDO::PARAM_STR),
            'heap_total' => array($this->heap_total, PDO::PARAM_INT),
            'heap_free' => array($this->heap_free, PDO::PARAM_INT),
            'rssi' => array($this->rssi, PDO::PARAM_INT),
            'uptime' => array($this->uptime, PDO::PARAM_INT),
            'sent_at' => array($this->sent_at, PDO::PARAM_STR),
            'received_at' => array($this->received_at, PDO::PARAM_STR),
        );
    }

    public static function getPublicFields($prefix='t') {
        return "$prefix.id,
                $prefix.contact_id,
                $prefix.reporter_id,
                $prefix.version,
                $prefix.heap_total,
                $prefix.heap_free,
                $prefix.rssi,
                $prefix.uptime,
                $prefix.sent_at,
                $prefix.received_at,
                $prefix.created_at";
    }
}

?>