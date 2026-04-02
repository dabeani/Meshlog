<?php

class MeshLogReporter extends MeshLogEntity {
    protected static $table = "reporters";

    const FORMAT_MESHLOG = 'meshlog';
    const FORMAT_LETSMESH = 'letsmesh';

    public $name = null;
    public $authorized = null;
    public $public_key = null;
    public $lat = null;
    public $lon = null;
    public $style = null;
    public $data = null;
    public $auth = null;
    public $hash_size = 1;
    public $report_format = self::FORMAT_MESHLOG;
    public $iata_code = '';

    public static function normalizeFormat($value) {
        $format = strtolower(trim(strval($value ?? '')));
        if ($format === static::FORMAT_LETSMESH) return static::FORMAT_LETSMESH;
        return static::FORMAT_MESHLOG;
    }

    public static function normalizeIataCode($value) {
        if (!is_scalar($value)) return '';

        $iata = strtoupper(trim(strval($value)));
        if ($iata === '') return '';
        $iata = preg_replace('/[^A-Z0-9]/', '', $iata);

        return substr($iata, 0, 8);
    }

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogReporter($meshlog);

        $m->_id = $data['id'];
        $m->name = $data['name'];
        $m->authorized = $data['authorized'];
        $m->public_key = $data['public_key'];
        $m->lat = $data['lat'];
        $m->lon = $data['lon'];
        $m->style = $data['style'] ?? '{}';
        $m->data = $data['data'] ?? '{}';
        $m->auth = $data['auth'] ?? '';
        $m->hash_size = intval($data['hash_size'] ?? 1);
        $m->report_format = static::normalizeFormat($data['report_format'] ?? static::FORMAT_MESHLOG);
        $m->iata_code = static::normalizeIataCode($data['iata_code'] ?? '');

        return $m;
    }

    public function asArray($secret = false) {
        $data = array(
            'id' => $this->getId(),
            'name' => $this->name,
            'public_key' => $this->public_key,
            'lat' => $this->lat,
            'lon' => $this->lon,
            'style' => $this->style,
            'data' => $this->data,
            'hash_size' => intval($this->hash_size ?? 1),
            'report_format' => static::normalizeFormat($this->report_format ?? static::FORMAT_MESHLOG),
            'iata_code' => static::normalizeIataCode($this->iata_code ?? ''),
        );

        if ($secret) {
            $data['auth'] = $this->auth;
            $data['authorized'] = $this->authorized;
        }

        return $data;
    }

    public function updateLocation($meshlog, $lat, $lon, $data=array()) {
        if (!$lat || !$lon) return;
        if (number_format(floatval($lat), 6, '.', '') == number_format(floatval($this->lat), 6, '.', '') &&
            number_format(floatval($lon), 6, '.', '') == number_format(floatval($this->lon), 6, '.', '')) return;

        $tableStr = static::$table;
        $query = $meshlog->pdo->prepare("UPDATE $tableStr SET lat = :lat, lon = :lon WHERE id = :id");

        $query->bindParam(':lat', $lat,  PDO::PARAM_STR);
        $query->bindParam(':lon', $lon,  PDO::PARAM_STR);
        $query->bindParam(':id', $this->_id,  PDO::PARAM_INT);
        $query->execute();
    }

    public function isValid() {
        if ($this->public_key == null) { $this->error = "Missing Public Key"; return false; };
        if ($this->name == null) { $this->error = "Missing Name"; return false; };
        if (!in_array(intval($this->hash_size), array(1, 2, 3), true)) {
            $this->error = "Hash size must be 1, 2, or 3 bytes";
            return false;
        }
        if (!in_array(
            static::normalizeFormat($this->report_format ?? static::FORMAT_MESHLOG),
            array(static::FORMAT_MESHLOG, static::FORMAT_LETSMESH),
            true
        )) {
            $this->error = "Unknown reporter format";
            return false;
        }
        $iata = static::normalizeIataCode($this->iata_code ?? '');
        if ($iata !== '' && (strlen($iata) < 2 || strlen($iata) > 8)) {
            $this->error = "IATA code must be 2-8 alphanumeric chars";
            return false;
        }

        return parent::isValid();
    }

    protected function getParams() {
        return array(
            "name" => array($this->name, PDO::PARAM_STR),
            "public_key" => array($this->public_key, PDO::PARAM_STR),
            "authorized" => array($this->authorized, PDO::PARAM_STR),
            "lat" => array($this->lat, PDO::PARAM_STR),
            "lon" => array($this->lon, PDO::PARAM_STR),
            "style" => array($this->style, PDO::PARAM_STR),
            "color" => array("", PDO::PARAM_STR),
            "auth" => array($this->auth, PDO::PARAM_STR),
            "hash_size" => array(intval($this->hash_size ?? 1), PDO::PARAM_INT),
            "report_format" => array(static::normalizeFormat($this->report_format ?? static::FORMAT_MESHLOG), PDO::PARAM_STR),
            "iata_code" => array(static::normalizeIataCode($this->iata_code ?? ''), PDO::PARAM_STR),
        );
    }
    
}

?>
