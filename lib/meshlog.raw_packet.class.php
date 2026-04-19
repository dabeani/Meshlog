<?php

class MeshLogRawPacket extends MeshLogEntity {
    protected static $table = "raw_packets";

    public $reporter_id = null;
    public $contact_id   = null;

    public $header = null;
    public $path = null;
    public $payload = null;
    public $snr = null;
    public $decded = null;
    public $hash_size = null;
    public $scope = null;
    public $route_type = null;

    public $received_at = null;
    public $created_at = null;

    public static function fromJson($data, $meshlog) {
        $m = new MeshLogRawPacket($meshlog);
        
        if (!isset($data['time'])) return $m;
        if (!isset($data['packet'])) return $m;

        $m->hash_size = $data['packet']['hash_size'] ?? 1;
        $m->scope = static::normalizeScope($data['packet']['scope'] ?? null);
        $m->route_type = static::normalizeRouteType($data['packet']['route_type'] ?? ($data['route_type'] ?? null));
        $m->header = $data['packet']['header'] ?? 0;
        $m->path = $data['packet']['path'] ?? '';
        $m->payload = hex2bin($data['packet']['payload'] ?? '');
        $m->snr = $data['packet']['snr'];
        $m->decoded = $data['packet']['decoded'] ?? false;
        $m->received_at = Utils::time2str($data['time']['local']);

        return $m;
    }

    private static function normalizeScope($scope) {
        if ($scope === null || $scope === '') return null;

        $value = intval($scope);
        if ($value < 0 || $value > 255) return null;

        return $value;
    }

    private static function normalizeRouteType($routeType) {
        if ($routeType === null || $routeType === '') return null;

        $value = intval($routeType);
        if ($value < 0 || $value > 3) return null;

        return $value;
    }

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogRawPacket($meshlog);
    
        $m->_id = $data['id'];
        $m->header = $data['header'];
        $m->path = $data['path'];
        $m->payload = $data['payload'];
        $m->snr = $data['snr'];
        $m->decoded = $data['decoded'];
        $m->hash_size = $data['hash_size'];
        $m->scope = static::normalizeScope($data['scope'] ?? null);
        $m->route_type = static::normalizeRouteType($data['route_type'] ?? null);

        $m->received_at = $data['received_at'];
        $m->created_at = $data['created_at'];

        $m->reporter_id = $data['reporter_id'];
        $m->contact_id  = isset($data['contact_id']) ? intval($data['contact_id']) : null;

        return $m;
    }

    function isValid() {
        if ($this->reporter_id == null) return false;

        if ($this->payload == null) { error_log('RawPacket isValid: Missing payload'); return false; }

        return true;
    }

    public function asArray($secret = false) {
        return array(
            'id' => $this->getId(),
            'reporter_id' => $this->reporter_id,
            'contact_id' => $this->contact_id,
            'header' => $this->header,
            'path' => $this->path,
            'payload' => bin2hex($this->payload),
            'snr' => $this->snr,
            'decoded' => $this->decoded,
            "hash_size" => $this->hash_size,
            "scope" => $this->scope,
            "route_type" => $this->route_type,
            'received_at' => $this->received_at,
            'created_at' => $this->created_at
        );
    }

    protected function getParams() {
        return array(
            "reporter_id" => array($this->reporter_id, PDO::PARAM_INT),
            "contact_id"  => array($this->contact_id, PDO::PARAM_INT),
            "header" => array($this->header, PDO::PARAM_INT),
            "path" => array($this->path, PDO::PARAM_STR),
            "payload" => array($this->payload, PDO::PARAM_STR),
            "path" => array($this->path, PDO::PARAM_STR),
            "snr" => array($this->snr, PDO::PARAM_INT),
            "decoded" => array($this->decoded, PDO::PARAM_INT),
            "hash_size" => array($this->hash_size, PDO::PARAM_INT),
            "scope" => array($this->scope, PDO::PARAM_INT),
            "route_type" => array($this->route_type, PDO::PARAM_INT),
            "received_at" => array($this->received_at, PDO::PARAM_STR),
            "created_at" => array($this->created_at, PDO::PARAM_STR)
        );
    }
}

?>