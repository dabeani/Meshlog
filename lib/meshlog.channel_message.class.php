<?php

class MeshLogChannelMessage extends MeshLogEntity {
    protected static $table = "channel_messages";

    public $contact_ref = null;  // MeshLogContact
    public $channel_ref = null;    // MeshLogChannel

    public $hash = null;
    public $name = null;
    public $message = null;
    public $hash_size = null;

    public $sent_at = null;
    public $created_at = null;

    public static function fromJson($data, $meshlog) {
        $m = new MeshLogChannelMessage($meshlog);
        
        if (!isset($data['message'])) return $m;
        if (!isset($data['time'])) return $m;

        $text = $data['message']['text'] ?? null;
        if ($text === null || $text === '') {
            // No text at all — let isValid() reject it.
            $m->sent_at = Utils::time2str($data['time']['sender']) ?? null;
            return $m;
        }

        $colonPos = strpos($text, ':');
        if ($colonPos !== false) {
            $name = substr($text, 0, $colonPos);
            // Skip the colon and one optional space that separates name from body.
            $bodyStart = $colonPos + 1;
            if ($bodyStart < strlen($text) && $text[$bodyStart] === ' ') {
                $bodyStart++;
            }
            $msg = substr($text, $bodyStart);
        } else {
            // No "Name: message" separator (e.g. binary MQTT binary packets where
            // the plaintext is the raw message, not prefixed with a node name).
            // Store empty string so the NOT-NULL constraint is satisfied.
            $name = '';
            $msg  = $text;
        }

        $m->hash = $data['hash'] ?? null;
        $m->hash_size = $data['hash_size'] ?? 1;
        $m->name = ($name !== null && $name !== '') ? $name : '';
        $m->message = $msg;

        $m->sent_at = Utils::time2str($data['time']['sender']) ?? null;

        return $m;
    }

    public static function fromDb($data, $meshlog) {
        if (!$data) return null;

        $m = new MeshLogChannelMessage($meshlog);

        $m->_id = $data['id'];
        $m->hash = $data['hash'];
        $m->name = $data['name'];
        $m->message = $data['message'];
        $m->hash_size = $data['hash_size'];

        $m->sent_at = $data['sent_at'];
        $m->created_at = $data['created_at'];

        $m->contact_ref = MeshLogContact::findById($data['contact_id'], $meshlog);
        $m->channel_ref = MeshLogChannel::findById($data['channel_id'], $meshlog);

        return $m;
    }

    function isValid() {
        // contact can be empty if it has not advertised yet.

        // fromJson() converts empty names to null, so the null check is sufficient here.
        if ($this->name === null) { $this->error = 'Missing name'; return false; }
        if ($this->hash === null || $this->hash === '') { $this->error = 'Missing hash'; return false; }
        // Allow empty string message body: a node can legitimately send "Name: " with no text.
        if ($this->message === null) { $this->error = 'Missing message'; return false; }
        if ($this->sent_at === null) { $this->error = 'Missing sent_at'; return false; }

        return true;
    }

    public function asArray($secret = false) {
        $cid = null;

        if ($this->contact_ref) $cid = $this->contact_ref->getId();

        return array(
            'id' => $this->getId(),
            'contact_id' => $cid,
            'channel_id' => $this->channel_ref->getId(),
            'hash' => $this->hash,
            'name' => $this->name ?? '',
            'message' => $this->message ?? '',
            "hash_size" => $this->hash_size,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at
        );
    }

    protected function getParams() {
        $cid = null;

        if ($this->contact_ref) $cid = $this->contact_ref->getId();

        return array(
            "contact_id" => array($cid, PDO::PARAM_INT),
            "channel_id" => array($this->channel_ref->getId(), PDO::PARAM_INT),
            "hash" => array($this->hash, PDO::PARAM_STR),
            "name" => array($this->name, PDO::PARAM_STR),
            "message" => array($this->message, PDO::PARAM_STR),
            "hash_size" => array($this->hash_size, PDO::PARAM_INT),
            "sent_at" => array($this->sent_at, PDO::PARAM_STR),
        );
    }

    public static function getPublicFields($prefix='t') {
        return "$prefix.id,
                $prefix.contact_id,
                $prefix.channel_id,
                $prefix.hash,
                $prefix.name,
                $prefix.message,
                $prefix.hash_size,
                $prefix.sent_at,
                $prefix.created_at";
    }
}

?>