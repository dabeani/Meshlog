<?php

require_once 'utils.php';
require_once 'meshlog.entity.class.php';
require_once 'meshlog.advertisement.class.php';
require_once 'meshlog.contact.class.php';
require_once 'meshlog.direct_message.class.php';
require_once 'meshlog.channel_message.class.php';
require_once 'meshlog.channel.class.php';
require_once 'meshlog.reporter.class.php';
require_once 'meshlog.setting.class.php';
require_once 'meshlog.telemetry.class.php';
require_once 'meshlog.user.class.php';
require_once 'meshlog.report.class.php';
require_once 'meshlog.raw_packet.class.php';
require_once 'meshlog.system_report.class.php';
require_once 'meshlog.mqtt_decoder.class.php';
require_once 'meshlog.audit_log.class.php';

define("MAX_COUNT", 2500);
define("DEFAULT_COUNT", 500);

class MeshLog {
    private $error = '';
    private $version = 17;
    private $ntpConfig = array(
        'enabled' => true,
        'host' => 'pool.ntp.org',
        'port' => 123,
        'timeout_ms' => 1500,
        'cache_ttl' => 300,
        'warning_threshold_seconds' => 300,
    );
    const PURGE_INTERVAL_SECONDS = 3600;
    const STATS_ROLLUP_BUCKET_SECONDS = 300;
    private $settings = array(
        MeshlogSetting::KEY_DB_VERSION => 0,
        MeshlogSetting::KEY_MAX_CONTACT_AGE => 1814400,
        MeshlogSetting::KEY_MAX_GROUPING_AGE => 21600,
        MeshlogSetting::KEY_INFLUXDB_URL => "",
        MeshlogSetting::KEY_INFLUXDB_DB => "Meshlog",
        MeshlogSetting::KEY_ANONYMIZE_USERNAMES => 0,
        MeshlogSetting::KEY_DATA_RETENTION_ADV => 604800,
        MeshlogSetting::KEY_DATA_RETENTION_MSG => 604800,
        MeshlogSetting::KEY_DATA_RETENTION_RAW => 604800,
        MeshlogSetting::KEY_LAST_PURGE_AT => 0,
        MeshlogSetting::KEY_TIME_SYNC_WARNING_THRESHOLD => 300,
    );

    function __construct($config) {
        $host = $config['host'] ?? die("Invalid db config");
        $name = $config['database'] ?? die("Invalid db config");
        $user = $config['user'] ?? die("Invalid db config");
        $pass = $config['password'] ?? die("Invalid db config");
        if (isset($config['ntp']) && is_array($config['ntp'])) {
            $this->ntpConfig = array_merge($this->ntpConfig, $config['ntp']);
        }
        $this->settings[MeshlogSetting::KEY_TIME_SYNC_WARNING_THRESHOLD] = max(
            0,
            intval($this->ntpConfig['warning_threshold_seconds'] ?? 300)
        );
        $this->pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->syncDatabaseSessionTimezone();
        $this->loadSettings();

        $this->error = $this->checkUpdates();
    }

    function __destruct() {
        $this->pdo = null;
    }

    function getError() {
        return $this->error;
    }

    function loadSettings() {
        $table = MeshLogSetting::getTable();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = :table");
            $stmt->execute(['table' => $table]);

        if ($stmt->fetchColumn() > 0) {
            $settings = MeshLogSetting::getAll($this, array());
            foreach ($settings['objects'] as $s) {
                $k = $s['name'];
                $v = $s['value'];
                if (in_array($k, array(
                    MeshlogSetting::KEY_MAX_CONTACT_AGE,
                    MeshlogSetting::KEY_MAX_GROUPING_AGE,
                    MeshlogSetting::KEY_ANONYMIZE_USERNAMES,
                    MeshlogSetting::KEY_DATA_RETENTION_ADV,
                    MeshlogSetting::KEY_DATA_RETENTION_MSG,
                    MeshlogSetting::KEY_DATA_RETENTION_RAW,
                    MeshlogSetting::KEY_LAST_PURGE_AT,
                    MeshlogSetting::KEY_TIME_SYNC_WARNING_THRESHOLD,
                ), true)) {
                    $v = (int)$v;
                }
                if ($k) {
                    $this->settings[$k] = $v;
                }
            }

            $users = MeshLogUser::countAll($this);
            if ($users > 0) return;
        }
        $this->error = 'Setup not complete. Go to <a href="setup.php">setup</a>';
    }

    function getDbVersion() {
        return $this->getConfig(MeshlogSetting::KEY_DB_VERSION, 0);
    }

    function updateAvailable() {
        return $this->version != $this->getDbVersion();
    }

    function checkUpdates() {
        if ($this->version != $this->getConfig(MeshlogSetting::KEY_DB_VERSION, 0)) {
            return "Database upgrade required! <a href=\"setup.php\">Login</a>";
        };
        return 0;
    }

    function saveSettings() {
        MeshLogSetting::saveSettings($this, $this->settings);
    }

    function getConfig($key, $default=null) {
        if (!isset($this->settings[$key])) return $default;
        return $this->settings[$key];
    }

    function setConfig($key, $value) {
        $this->settings[$key] = $value;
    }

    private function syncDatabaseSessionTimezone() {
        try {
            $offset = (new DateTimeImmutable('now'))->format('P');
            $stmt = $this->pdo->prepare('SET time_zone = :offset');
            $stmt->bindValue(':offset', $offset, PDO::PARAM_STR);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('MeshLog failed to set DB session timezone: ' . $e->getMessage());
        }
    }

    /**
     * Write an audit log entry.  Wraps MeshLogAuditLog::write() with the
     * client IP resolved from the server environment.
     */
    function auditLog($event, $actor = '', $detail = '') {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        // Only use the first address in X-Forwarded-For
        $ip = explode(',', $ip)[0];
        MeshLogAuditLog::write($this, $event, $actor, $detail, trim($ip));
    }

    /**
     * Delete records older than the configured retention periods.
     * Returns array with row counts deleted per category.
     */
    function purgeOldData() {
        $stats = array('advertisements' => 0, 'messages' => 0, 'raw_packets' => 0);

        $retAdv = intval($this->getConfig(MeshlogSetting::KEY_DATA_RETENTION_ADV, 0));
        $retMsg = intval($this->getConfig(MeshlogSetting::KEY_DATA_RETENTION_MSG, 0));
        $retRaw = intval($this->getConfig(MeshlogSetting::KEY_DATA_RETENTION_RAW, 0));

        if ($retAdv > 0) {
            $this->pdo->prepare("
                DELETE ar FROM advertisement_reports ar
                INNER JOIN advertisements a ON a.id = ar.advertisement_id
                WHERE a.created_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
            ")->execute([':age' => $retAdv]);

            $stmt = $this->pdo->prepare("
                DELETE FROM advertisements WHERE created_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
            ");
            $stmt->execute([':age' => $retAdv]);
            $stats['advertisements'] = $stmt->rowCount();
        }

        if ($retMsg > 0) {
            $this->pdo->prepare("
                DELETE dr FROM direct_message_reports dr
                INNER JOIN direct_messages d ON d.id = dr.direct_message_id
                WHERE d.created_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
            ")->execute([':age' => $retMsg]);

            $stmt = $this->pdo->prepare("
                DELETE FROM direct_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
            ");
            $stmt->execute([':age' => $retMsg]);
            $stats['messages'] += $stmt->rowCount();

            $this->pdo->prepare("
                DELETE cr FROM channel_message_reports cr
                INNER JOIN channel_messages c ON c.id = cr.channel_message_id
                WHERE c.created_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
            ")->execute([':age' => $retMsg]);

            $stmt = $this->pdo->prepare("
                DELETE FROM channel_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
            ");
            $stmt->execute([':age' => $retMsg]);
            $stats['messages'] += $stmt->rowCount();
        }

        if ($retRaw > 0) {
            $stmt = $this->pdo->prepare("
                DELETE FROM raw_packets WHERE received_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
            ");
            $stmt->execute([':age' => $retRaw]);
            $stats['raw_packets'] = $stmt->rowCount();
        }

        return $stats;
    }

    /**
     * Run purgeOldData() at most once per PURGE_INTERVAL_SECONDS.
     * Called automatically from HTTP/MQTT ingest.
     */
    function maybeAutoPurge() {
        $retAdv = intval($this->getConfig(MeshlogSetting::KEY_DATA_RETENTION_ADV, 0));
        $retMsg = intval($this->getConfig(MeshlogSetting::KEY_DATA_RETENTION_MSG, 0));
        $retRaw = intval($this->getConfig(MeshlogSetting::KEY_DATA_RETENTION_RAW, 0));
        if ($retAdv === 0 && $retMsg === 0 && $retRaw === 0) return;

        $lastPurge = intval($this->getConfig(MeshlogSetting::KEY_LAST_PURGE_AT, 0));
        if ((time() - $lastPurge) < static::PURGE_INTERVAL_SECONDS) return;

        $stats = $this->purgeOldData();
        $this->setConfig(MeshlogSetting::KEY_LAST_PURGE_AT, time());
        $this->saveSettings();
        $total = array_sum($stats);
        $detail = "auto-purge: {$stats['advertisements']} adv, {$stats['messages']} msg, {$stats['raw_packets']} raw ({$total} total)";
        $this->auditLog(MeshLogAuditLog::EVENT_PURGE_AUTO, 'system', $detail);
    }

    function authorize($data) {
        if (!isset($_SERVER['HTTP_AUTHORIZATION'])) return false;
        // Accept multiple payload keys used by different firmware versions
        if (!is_array($data)) return false;

        $pubkey = '';
        foreach (array('reporter', 'origin_id', 'public_key', 'pubkey') as $k) {
            if (isset($data[$k]) && is_scalar($data[$k]) && trim($data[$k]) !== '') {
                $candidate = preg_replace('/[^0-9A-Fa-f]/', '', strtoupper(trim(strval($data[$k]))));
                if ($candidate !== '') { $pubkey = $candidate; break; }
            }
        }
        if ($pubkey === '') return false;

        $count = 1;
        $token = $_SERVER['HTTP_AUTHORIZATION'];
        $token = str_replace("Bearer ", "", $token, $count);

        $query = $this->pdo->prepare('SELECT * FROM reporters WHERE public_key = :pubkey AND auth = :auth AND authorized = 1');
        $query->bindParam(':pubkey',$pubkey, PDO::PARAM_STR);
        $query->bindParam(':auth',  $token,  PDO::PARAM_STR);
        $query->execute();

        $result = $query->fetch(PDO::FETCH_ASSOC);

        if (!$result) return false;

        return MeshLogReporter::fromDb($result, $this);
    }

    function insert($data) {
        $reporter = $this->authorize($data);
        if (!$reporter) return false;

        return $this->insertForReporter($data, $reporter);
    }

    function insertMqtt($topic, $payload) {
        $channels = MeshLogChannel::getAllWithPsk($this);
        $data = MeshLogMqttDecoder::decode($topic, $payload, $channels);
        if (!$data || !isset($data['reporter'])) {
            return $this->repError(
                "invalid MQTT payload",
                array("_mqtt" => MeshLogMqttDecoder::extractMetadata($topic, json_decode($payload, true) ?? array()))
            );
        }
        $mqttMeta = $data['_mqtt'] ?? array();

        $reporter = MeshLogReporter::findBy(
            "public_key",
            $data['reporter'],
            $this,
            array("authorized" => array('operator' => '=', 'value' => 1)),
            false,
            true
        );
        if (!$reporter) {
            $error = $this->repError("invalid or unauthorized reporter");
            $mqttMeta['attempted_reporter'] = $data['reporter'];
            $error['_mqtt'] = $mqttMeta;
            return $error;
        }

        $result = $this->insertForReporter($data, $reporter);
        if (is_array($result)) {
            $result['insert_type'] = $data['type'] ?? null;
            $result['_mqtt'] = $mqttMeta;
            return $result;
        }
        // insertForReporter returned a boolean; wrap it to preserve _mqtt metadata
        $wrapped = array(
            '_mqtt' => $mqttMeta,
            'insert_type' => $data['type'] ?? null,
        );
        if ($result === false) {
            $wrapped['error'] = 'failed to insert packet';
        }
        return $wrapped;
    }

    private function insertForReporter($data, $reporter) {
        if (!$reporter) return false;

        if (!isset($data['type'])) return $this->repError('invalid type');

        $type = strtoupper(trim($data['type']));

        try {
            $this->pdo->beginTransaction();
            $rep = array();
            switch ($type) {
                case 'ADV':
                    $rep = $this->insertAdvertisement($data, $reporter);
                    break;
                case 'MSG':
                    $rep = $this->insertDirectMessage($data, $reporter);
                    break;
                case 'PUB':
                    $rep = $this->insertGroupMessage($data, $reporter);
                    break;
                case 'SYS':
                    $rep = $this->insertSelfReport($data, $reporter);
                    break;
                case 'TEL':
                    $rep = $this->insertTelemetry($data, $reporter);
                    break;
                case 'RAW':
                    $rep = $this->insertRawPacket($data, $reporter);
                    break;
                default:
                    $rep = $this->repError("Unknown type: $type");
                    break;
            }

            if (is_array($rep) && array_key_exists("error", $rep)) {
                $rep["error"];
                $this->pdo->rollBack();
            } else {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log($e);
            throw $e;
        }

        return $rep;
    }

    private function insertAdvertisement($data, $reporter) {
        if (!$reporter) return $this->repError('no reporter');

        $pubkey = $data['contact']['pubkey'] ?? null;
        if (!$pubkey) return $this->repError('no key');

        $encname = $data['contact']['name'];
        $data['contact']['name'] = $encname;

        $contact = MeshLogContact::findBy("public_key", $pubkey, $this, array(), false, true);

        if ($contact) {
            $contact->name = $data['contact']['name'];
            $this->syncContactHashSize($contact, $data);
        } else {
            $contact = MeshLogContact::fromJson($data, $this);
            $contact->name = $data['contact']['name'];
        }
        if (!$contact->save($this)) return $this->repError('failed to save contact');

        // Find adv by id, not older than X
        $adv = MeshLogAdvertisement::fromJson($data, $this);
        $adv->contact_ref = $contact;

        // Time grouping
        // Can't use sent_at. Device after reboot might send advert
        // with bad date, making hash duplicate with older messages
        $minage = date("Y-m-d H:i:s", time() -  $this->getConfig(MeshlogSetting::KEY_MAX_GROUPING_AGE));
        $existing = MeshLogAdvertisement::findBy(
            "hash",
            $adv->hash,
            $this,
            array('created_at' => array('operator' => '>', 'value' => $minage)),
            false,
            true
        );

        if ($existing) {
            $adv = $existing;
            $saved = true;
        } else {
            $saved = $adv->save($this);
            $contact->updateHeardAt($this);
        }

        if ($saved) {
            // add report
            $rep = MeshLogAdvertisementReport::fromJson($data, $this);
            $rep->object_id = $adv->getId();
            $rep->reporter_id = $reporter->getId();
            $repSaved = $rep->save($this);
            if ($repSaved) {
                $this->rollupAdvertisementStats($adv->getId(), $contact->getId(), $reporter->getId(), $rep->route_type, $rep->path);
                $this->rollupCollectorPacketStats($reporter->getId(), 'ADV');
            }
            return $repSaved;
        }
        return $saved;
    }

    private function insertDirectMessage($data, $reporter) {
        if (!$reporter) return $this->repError('no reporter');

        $pubkey = $data['contact']['pubkey'] ?? null;
        if (!$pubkey) return $this->repError('no key');

        $contact = MeshLogContact::findBy("public_key", $pubkey, $this, array(), false, true);
        if (!$contact) {
            $contact = MeshLogContact::fromJson($data, $this);
            if (!$contact->save($this)) return $this->repError('failed to save contact');
        } else {
            $this->syncContactHashSize($contact, $data);
            if (!$contact->save($this)) return $this->repError('failed to update contact');
        }

        $dm = MeshLogDirectMessage::fromJson($data, $this);
        $dm->contact_ref = $contact;

        // Time grouping
        // Can't use sent_at. Device after reboot might send advert
        // with bad date, making hash duplicate with older messages
        $minage = date("Y-m-d H:i:s", time() -  $this->getConfig(MeshlogSetting::KEY_MAX_GROUPING_AGE));
        $existing = MeshLogDirectMessage::findBy(
            "hash",
            $dm->hash,
            $this,
            array('created_at' => array('operator' => '>', 'value' => $minage)),
            false,
            true
        );

        if ($existing) {
            $dm = $existing;
            $saved = true;
        } else {
            $saved = $dm->save($this);
            $contact->updateHeardAt($this);
        }

        if ($saved) {
            // add report
            $rep = MeshLogDirectMessageReport::fromJson($data, $this);
            $rep->object_id = $dm->getId();
            $rep->reporter_id = $reporter->getId();
            $repSaved = $rep->save($this);
            if ($repSaved) {
                $this->rollupCollectorPacketStats($reporter->getId(), 'DIR');
            }
            return $repSaved;
        }
        return $saved;
    }

    private function insertGroupMessage($data, $reporter) {
        if (!$reporter) return $this->repError('no reporter');

        $hash = $data['channel']['hash'] ?? '11';
        $text = $data['message']['text'] ?? null;

        if (!$text) return $this->repError('no message');
        $name = explode(':', $text, 2)[0];

        $channel = MeshLogChannel::findBy("hash", $hash, $this, array(), false, true);

        if (!$channel) {
            $channel = MeshLogChannel::fromJson($data, $this);
            if (!$channel->save($this)) {
                error_log('[PUB insert] channel save failed: ' . $channel->getError() . ' hash=' . $hash);
                return $this->repError('failed to save channel');
            }
        } else {
            // If the channel was previously created without a real name (e.g. from
            // an HTTP firmware payload that omits channel.name), update the stored
            // name when a better one arrives — typically from the MQTT bridge which
            // always includes the channel name in its pre-decoded JSON.
            $newName = $data['channel']['name'] ?? '';
            $isPlaceholder = ($channel->name === '' || $channel->name === 'unknown' ||
                              $channel->name === ('#' . $hash));
            if ($newName !== '' && $isPlaceholder && $newName !== $channel->name) {
                $channel->name = $newName;
                $channel->save($this);
            }
        }

        $advertisement = MeshLogAdvertisement::findBy("name", $name, $this, array(), true, true);
        $contact = null;
        if ($advertisement) $contact = MeshLogContact::findById($advertisement->contact_ref->getId(), $this);
        if ($contact) {
            $this->syncContactHashSize($contact, $data);
            if (!$contact->save($this)) return $this->repError('failed to update contact');
        }

        $grpmsg = MeshLogChannelMessage::fromJson($data, $this);
        $grpmsg->contact_ref = $contact;
        $grpmsg->channel_ref = $channel;

        // Time grouping
        // Can't use sent_at. Device after reboot might send advert
        // with bad date, making hash duplicate with older messagesq
        $minage = date("Y-m-d H:i:s", time() -  $this->getConfig(MeshlogSetting::KEY_MAX_GROUPING_AGE));
        $existing = MeshLogChannelMessage::findBy("hash", $grpmsg->hash, $this, array('created_at' => array('operator' => '>', 'value' => $minage)), false, true);

        if ($existing) {
            $grpmsg = $existing;
            $saved = true;
        } else {
            $saved = $grpmsg->save($this);
            if (!$saved) {
                error_log('[PUB insert] channel_message save failed: ' . $grpmsg->getError() .
                    ' hash=' . $grpmsg->hash . ' name=' . $grpmsg->name .
                    ' msg=' . substr($grpmsg->message ?? '', 0, 30) .
                    ' sent_at=' . $grpmsg->sent_at);
            }
            if ($contact) $contact->updateHeardAt($this);
        }

        if ($saved) {
            // add report
            $rep = MeshLogChannelMessageReport::fromJson($data, $this);
            $rep->object_id = $grpmsg->getId();
            $rep->reporter_id = $reporter->getId();
            $repSaved = $rep->save($this);
            if (!$repSaved) {
                error_log('[PUB insert] report save failed: ' . $rep->getError() .
                    ' msg_id=' . $grpmsg->getId() . ' reporter_id=' . $reporter->getId());
            } else {
                $this->rollupCollectorPacketStats($reporter->getId(), 'PUB');
            }
            return $repSaved;
        }
        return $saved;
    }

    private function writeInfluxDb($line) {
        $influxHost = $this->getConfig(MeshlogSetting::KEY_INFLUXDB_URL, "");
        $database   = $this->getConfig(MeshlogSetting::KEY_INFLUXDB_DB, ""); 

        if (empty($influxHost) || empty($database)) return;

        $url = "$influxHost/write?db=" . urlencode($database);

        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $line);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode >= 400) {
            return "Error $httpcode: $response for request $line";
        }

        return "";
    }

    private function insertTelemetry($data, $reporter) {
        if (!$reporter) return $this->repError('no reporter');

        $pubkey = $data['contact']['pubkey'] ?? null;
        if (!$pubkey) return $this->repError('no key');

        $contact = MeshLogContact::findBy("public_key", $pubkey, $this);

        if (!$contact) {
            return $this->repError('contact doesnt exist');
        }

        $tel = MeshLogTelemetry::fromJson($data, $this);
        $tel->reporter_ref = $reporter;
        $tel->contact_ref = $contact;

        $cname = str_replace(
            " ",
            "\\ ",
            $contact->name
        );

        $cname = str_replace("\"", "", $cname);

        $res = $tel->save($this);
        if ($res) {
            $errors = "";

            $data = json_decode($tel->data, true);
            foreach ($data as $chan) {
                if ($chan['type'] != "0") {
                    $ch = $chan['channel'];
                    $ty = $chan['type'];
                    $na = $chan['name'];
                    $va = $chan['value'];

                    $line = "mc_$na,contact=$pubkey,type=$ty,ch=$ch,name=$cname value=$va";
                    $error = $this->writeInfluxDb($line);
                    if (!empty($error)) {
                        $errors .= $error . "\n";
                    }
                }
            }

            if (!empty($errors)) {
                return $this->repError($errors);
            }
        } else {
            return $this->repError('failed to write db');
        }

        if ($res) {
            $this->rollupCollectorPacketStats($reporter->getId(), 'TEL');
        }

        return $res;
    }

    private function insertRawPacket($data, $reporter) {
        if (!$reporter) return $this->repError('no reporter');

        $pkt = MeshLogRawPacket::fromJson($data, $this);
        $pkt->reporter_id = $reporter->getId();

        // Try to link a contact when the decoded payload contains sender info.
        // ANON_REQ packets carry the full 32-byte sender pubkey (64 hex chars).
        // REQ/RESPONSE packets carry a single-byte src_hash derived from the
        // sender's public_key — usable as a short-key lookup against contacts.
        $payloadHex = $data['packet']['payload'] ?? '';
        if ($pkt->decoded && $payloadHex) {
            $meta = @json_decode(hex2bin($payloadHex), true);
            if (is_array($meta)) {
                $senderPubkey = $meta['sender_pubkey'] ?? null; // ANON_REQ: full 64-char hex key
                $srcHash      = $meta['src_hash']      ?? null; // REQ/RESP: 1-byte hash

                if ($senderPubkey && strlen($senderPubkey) === 64) {
                    $contact = MeshLogContact::findBy('public_key', strtoupper($senderPubkey), $this);
                    if ($contact) {
                        $pkt->contact_id = $contact->getId();
                    }
                } elseif ($srcHash && strlen($srcHash) === 2) {
                    // src_hash is only 1 byte — match against the first byte of each contact key
                    $contact = MeshLogContact::findByHashPrefix(strtoupper($srcHash), $this);
                    if ($contact) {
                        $pkt->contact_id = $contact->getId();
                    }
                }
            }
        }

        $saved = $pkt->save($this);
        if ($saved) {
            $this->rollupCollectorPacketStats($reporter->getId(), 'RAW');
        }

        return $saved;
    }

    private function insertSelfReport($data, $reporter) {
        if (!$reporter) return $this->repError('no reporter');
        if (empty($data['contact']) || empty($data['sys'])) return $this->repError('missing sys payload');

        $lat = $data['contact']['lat'] ?? null;
        $lon = $data['contact']['lon'] ?? null;

        $vdata = array(
            "version" => $data['sys']['version'] ?? null
        );

        $reporter->updateLocation($this, $lat, $lon, $vdata);

        $pubkey = $data['contact']['pubkey'] ?? null;
        $contact = null;
        if ($pubkey) {
            $contact = MeshLogContact::findBy("public_key", $pubkey, $this, array(), false, true);
            if ($contact) {
                $contact->name = $data['contact']['name'] ?? $contact->name;
            } else {
                $contact = MeshLogContact::fromJson($data, $this);
                $contact->name = $data['contact']['name'] ?? null;
            }
            if ($contact && !$contact->save($this)) return $this->repError('failed to save contact');
        }

        $sys = MeshLogSystemReport::fromJson($data, $this);
        $sys->reporter_ref = $reporter;
        $sys->contact_ref = $contact;
        if (!$sys->save($this)) {
            return $this->repError('failed to save system report');
        }

        $heap_total = $data['sys']['heap_total'];
        $heap_free = $data['sys']['heap_free'];
        $rssi = $data['sys']['rssi'];
        $uptime = $data['sys']['uptime'];

        $cname = str_replace(
            " ",
            "\\ ",
            $data['contact']['name']
        );

        $cname = str_replace("\"", "", $cname);

        $line = "mc_reporter,contact=$pubkey,name=$cname heap_total=$heap_total,heap_free=$heap_free,rssi=$rssi,uptime=$uptime";
        $error = $this->writeInfluxDb($line);

        if (!empty($error)) {
            return $this->repError($error);
        }

        $this->rollupCollectorPacketStats($reporter->getId(), 'SYS');

        return true;
    }

    private function repError($msg, $extra = array()) {
        return array_merge(array('error' => $msg), $extra);
    }

    private function syncContactHashSize($contact, $data) {
        if (!$contact || !is_array($data) || !array_key_exists('hash_size', $data)) return;

        $hashSize = intval($data['hash_size']);
        if (!in_array($hashSize, array(1, 2, 3), true)) return;

        $contact->hash_size = $hashSize;
    }

    private function getStatsRollupBucketExpression($columnName) {
        return "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP($columnName) / " . static::STATS_ROLLUP_BUCKET_SECONDS . ") * " . static::STATS_ROLLUP_BUCKET_SECONDS . ")";
    }

    private function rollupAdvertisementStats($advertisementId, $contactId, $reporterId, $routeType, $path) {
        $advertisementId = intval($advertisementId);
        $contactId = intval($contactId);
        $reporterId = intval($reporterId);
        if ($advertisementId <= 0 || $contactId <= 0 || $reporterId <= 0) {
            return;
        }

        $routeType = is_null($routeType) ? null : intval($routeType);
        $hasRelay = !empty($path) ? 1 : 0;
        $isDirect = in_array($routeType, array(2, 3), true) ? 1 : 0;
        $isFlood = in_array($routeType, array(0, 1), true) ? 1 : 0;
        $isUnknown = is_null($routeType) ? 1 : 0;

        $summarySql = "
            INSERT INTO stats_adv_reports_rollup (
                bucket_start,
                report_count,
                direct_count,
                flood_count,
                unknown_route_count,
                relayed_count,
                no_hop_count
            ) VALUES (
                " . $this->getStatsRollupBucketExpression('NOW()') . ",
                1,
                :direct_count,
                :flood_count,
                :unknown_route_count,
                :relayed_count,
                :no_hop_count
            )
            ON DUPLICATE KEY UPDATE
                report_count = report_count + 1,
                direct_count = direct_count + VALUES(direct_count),
                flood_count = flood_count + VALUES(flood_count),
                unknown_route_count = unknown_route_count + VALUES(unknown_route_count),
                relayed_count = relayed_count + VALUES(relayed_count),
                no_hop_count = no_hop_count + VALUES(no_hop_count)
        ";

        $summaryStmt = $this->pdo->prepare($summarySql);
        $summaryStmt->bindValue(':direct_count', $isDirect, PDO::PARAM_INT);
        $summaryStmt->bindValue(':flood_count', $isFlood, PDO::PARAM_INT);
        $summaryStmt->bindValue(':unknown_route_count', $isUnknown, PDO::PARAM_INT);
        $summaryStmt->bindValue(':relayed_count', $hasRelay, PDO::PARAM_INT);
        $summaryStmt->bindValue(':no_hop_count', $hasRelay ? 0 : 1, PDO::PARAM_INT);
        $summaryStmt->execute();

        $entitySql = "
            INSERT IGNORE INTO stats_adv_entities_rollup (
                bucket_start,
                advertisement_id,
                contact_id
            ) VALUES (
                " . $this->getStatsRollupBucketExpression('NOW()') . ",
                :advertisement_id,
                :contact_id
            )
        ";

        $entityStmt = $this->pdo->prepare($entitySql);
        $entityStmt->bindValue(':advertisement_id', $advertisementId, PDO::PARAM_INT);
        $entityStmt->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $entityStmt->execute();
    }

    private function rollupCollectorPacketStats($reporterId, $packetType) {
        $reporterId = intval($reporterId);
        $packetType = strtoupper(trim(strval($packetType)));
        if ($reporterId <= 0 || $packetType === '') {
            return;
        }

        $sql = "
            INSERT INTO stats_collector_packets_rollup (
                bucket_start,
                reporter_id,
                packet_type,
                packet_count
            ) VALUES (
                " . $this->getStatsRollupBucketExpression('NOW()') . ",
                :reporter_id,
                :packet_type,
                1
            )
            ON DUPLICATE KEY UPDATE
                packet_count = packet_count + 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':reporter_id', $reporterId, PDO::PARAM_INT);
        $stmt->bindValue(':packet_type', $packetType, PDO::PARAM_STR);
        $stmt->execute();
    }

    private function getNtpCacheFile() {
        $host = strval($this->ntpConfig['host'] ?? 'pool.ntp.org');
        $port = intval($this->ntpConfig['port'] ?? 123);
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'meshlog-ntp-'
            . md5($host . ':' . $port)
            . '.json';
    }

    private function readNtpCache() {
        $cacheFile = $this->getNtpCacheFile();
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }

        $raw = @file_get_contents($cacheFile);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        if (!isset($data['reference_time_ms'], $data['platform_time_ms'], $data['fetched_at_ms'])) {
            return null;
        }

        return $data;
    }

    private function writeNtpCache($referenceTimeMs, $platformTimeMs) {
        $payload = json_encode(array(
            'reference_time_ms' => intval($referenceTimeMs),
            'platform_time_ms' => intval($platformTimeMs),
            'fetched_at_ms' => intval(round(microtime(true) * 1000)),
        ));
        if ($payload === false) {
            return;
        }

        @file_put_contents($this->getNtpCacheFile(), $payload, LOCK_EX);
    }

    private function queryNtpTimeMs() {
        $host = strval($this->ntpConfig['host'] ?? 'pool.ntp.org');
        $port = intval($this->ntpConfig['port'] ?? 123);
        $timeoutMs = max(100, intval($this->ntpConfig['timeout_ms'] ?? 1500));

        $socket = @stream_socket_client(
            "udp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutMs / 1000
        );

        if (!$socket) {
            throw new RuntimeException("NTP connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout(
            $socket,
            intdiv($timeoutMs, 1000),
            ($timeoutMs % 1000) * 1000
        );

        $request = str_repeat("\0", 48);
        $request[0] = chr(0x1B);

        $written = @fwrite($socket, $request);
        if ($written === false || $written < 48) {
            fclose($socket);
            throw new RuntimeException('Failed to write complete NTP request');
        }

        $response = @fread($socket, 48);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if (!is_string($response) || strlen($response) < 48) {
            throw new RuntimeException('Incomplete NTP response');
        }
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('Timed out waiting for NTP response');
        }

        $parts = unpack('Nseconds/Nfraction', substr($response, 40, 8));
        if (!$parts) {
            throw new RuntimeException('Failed to decode NTP response');
        }

        $seconds = intval($parts['seconds']) - 2208988800;
        $fractionMs = (int) round((intval($parts['fraction']) / 4294967296) * 1000);

        return ($seconds * 1000) + $fractionMs;
    }

    private function getReferenceClock() {
        $platformNowMs = intval(round(microtime(true) * 1000));
        $warningThresholdMs = max(
            1000,
            intval($this->getConfig(
                MeshlogSetting::KEY_TIME_SYNC_WARNING_THRESHOLD,
                $this->ntpConfig['warning_threshold_seconds'] ?? 300
            )) * 1000
        );
        $cacheTtlMs = max(1000, intval($this->ntpConfig['cache_ttl'] ?? 300) * 1000);

        $fallback = array(
            'source' => 'platform',
            'reference_time_ms' => $platformNowMs,
            'platform_time_ms' => $platformNowMs,
            'offset_ms' => 0,
            'warning_threshold_ms' => $warningThresholdMs,
        );

        if (empty($this->ntpConfig['enabled'])) {
            return $fallback;
        }

        $cache = $this->readNtpCache();
        if ($cache) {
            $cacheAgeMs = max(0, $platformNowMs - intval($cache['fetched_at_ms']));
            if ($cacheAgeMs <= $cacheTtlMs) {
                $offsetMs = intval($cache['reference_time_ms']) - intval($cache['platform_time_ms']);
                return array(
                    'source' => 'ntp-cache',
                    'reference_time_ms' => $platformNowMs + $offsetMs,
                    'platform_time_ms' => $platformNowMs,
                    'offset_ms' => $offsetMs,
                    'warning_threshold_ms' => $warningThresholdMs,
                );
            }
        }

        try {
            $referenceTimeMs = $this->queryNtpTimeMs();
            $this->writeNtpCache($referenceTimeMs, $platformNowMs);
            return array(
                'source' => 'ntp',
                'reference_time_ms' => $referenceTimeMs,
                'platform_time_ms' => $platformNowMs,
                'offset_ms' => $referenceTimeMs - $platformNowMs,
                'warning_threshold_ms' => $warningThresholdMs,
            );
        } catch (Throwable $e) {
            error_log('MeshLog NTP lookup failed: ' . $e->getMessage());
            if ($cache) {
                $offsetMs = intval($cache['reference_time_ms']) - intval($cache['platform_time_ms']);
                return array(
                    'source' => 'ntp-stale-cache',
                    'reference_time_ms' => $platformNowMs + $offsetMs,
                    'platform_time_ms' => $platformNowMs,
                    'offset_ms' => $offsetMs,
                    'warning_threshold_ms' => $warningThresholdMs,
                );
            }
        }

        return $fallback;
    }

    private function parseTimestampMs($value) {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/', $value, $matches)) {
            $fraction = str_pad($matches[2] ?? '0', 6, '0', STR_PAD_RIGHT);
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $matches[1] . '.' . $fraction);
            if ($date instanceof DateTimeImmutable) {
                $secondsMs = intval($date->format('U')) * 1000;
                $microsecondsMs = intdiv(intval($date->format('u')), 1000);
                return $secondsMs + $microsecondsMs;
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return intval($timestamp) * 1000;
    }

    private function getReporterLatestAdvertisements($publicKeys) {
        $publicKeys = array_values(array_unique(array_filter(array_map(function ($value) {
            return strtoupper(trim(strval($value)));
        }, $publicKeys))));

        if (count($publicKeys) < 1) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($publicKeys), '?'));
        $sql = "
            SELECT
                r.public_key,
                a.type,
                ar.sender_at,
                a.created_at,
                ar.created_at AS report_created_at
            FROM reporters r
            INNER JOIN advertisement_reports ar ON ar.id = (
                SELECT ar2.id
                FROM advertisement_reports ar2
                INNER JOIN advertisements a2 ON a2.id = ar2.advertisement_id
                INNER JOIN contacts c2 ON c2.id = a2.contact_id
                WHERE ar2.reporter_id = r.id
                  AND c2.public_key = r.public_key
                ORDER BY ar2.id DESC
                LIMIT 1
            )
            INNER JOIN advertisements a ON a.id = ar.advertisement_id
            WHERE r.public_key IN ($placeholders)
        ";

        $query = $this->pdo->prepare($sql);
        foreach ($publicKeys as $index => $publicKey) {
            $query->bindValue($index + 1, $publicKey, PDO::PARAM_STR);
        }
        $query->execute();

        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $lookup = array();
        foreach ($rows as $row) {
            $lookup[strtoupper($row['public_key'])] = $row;
        }

        return $lookup;
    }

    private function buildReporterTimeSync($advertisement, $referenceClock) {
        if (!is_array($advertisement)) {
            return null;
        }

        // Only compute time-sync data for repeater nodes (type = 2).
        // Chat (1), room (3), and sensor (4) devices do not display the time-sync badge.
        if (intval($advertisement['type'] ?? 0) !== 2) {
            return null;
        }

        $sentAtMs = $this->parseTimestampMs($advertisement['sender_at'] ?? null);
        $receivedAtMs = $this->parseTimestampMs(
            $advertisement['report_created_at'] ?? ($advertisement['created_at'] ?? null)
        );
        if ($sentAtMs === null || $receivedAtMs === null) {
            return null;
        }

        $referenceAtReceiveMs = $receivedAtMs + intval($referenceClock['offset_ms'] ?? 0);
        $driftMs = $sentAtMs - $referenceAtReceiveMs;
        $thresholdMs = intval($referenceClock['warning_threshold_ms'] ?? 300000);

        return array(
            'available' => true,
            'warning' => abs($driftMs) >= $thresholdMs,
            'in_sync' => abs($driftMs) < $thresholdMs,
            'drift_ms' => $driftMs,
            'threshold_ms' => $thresholdMs,
            'source' => $referenceClock['source'] ?? 'platform',
            'latest_sent_at' => $advertisement['sender_at'] ?? null,
            'latest_received_at' => $advertisement['report_created_at'] ?? ($advertisement['created_at'] ?? null),
            'latest_contact_type' => intval($advertisement['type'] ?? 0),
        );
    }

    public function getReporterTimeSyncMap($publicKeys) {
        $referenceClock = $this->getReferenceClock();
        $latestAdvertisements = $this->getReporterLatestAdvertisements($publicKeys);
        $timeSyncMap = array();

        foreach ($publicKeys as $publicKey) {
            $normalizedKey = strtoupper(trim(strval($publicKey)));
            if ($normalizedKey === '') {
                continue;
            }

            $latestAdvertisement = $latestAdvertisements[$normalizedKey] ?? null;
            $timeSync = $this->buildReporterTimeSync($latestAdvertisement, $referenceClock);
            if ($timeSync) {
                $timeSyncMap[$normalizedKey] = $timeSync;
            }
        }

        return $timeSyncMap;
    }

    // getters
    public function getReporters($params) {
        // Reporters are reference data for feed entries, not feed entries themselves.
        // Always return the full authorized reporter set so message/report rendering
        // can resolve reporter names even when the feed is time-filtered or paged.
        $params['offset'] = 0;
        $params['count'] = MAX_COUNT;
        $params['after_ms'] = 0;
        $params['before_ms'] = 0;
        $params['where'] = array(
            'authorized = 1'
        );
        $results = MeshLogReporter::getAll($this, $params);

        $publicKeys = array();
        foreach ($results['objects'] as $reporter) {
            if (!empty($reporter['public_key'])) {
                $publicKeys[] = $reporter['public_key'];
            }
        }
        $timeSyncMap = $this->getReporterTimeSyncMap($publicKeys);

        // find contact
        $out = [];
        foreach ($results['objects'] as $k => $r) {
            $pk = $r["public_key"];
            $c = MeshLogContact::findBy("public_key", $pk, $this, array());
            if ($c) {
                $r['contact_id'] = $c->getId();
                $r['contact'] = $c->asArray();
            }

            $timeSync = $timeSyncMap[strtoupper($pk)] ?? null;
            if ($timeSync) {
                $r['time_sync'] = $timeSync;
            }

            $out[] = $r;
        }

        return array("objects" => $out);
    }

    public function getContacts($params, $adv=FALSE) {
        $params['where'] = array(
            'enabled = 1'
        );

        $results = MeshLogContact::getAll($this, $params);
        $out = [];
        $maxage = isset($params['max_age']) ? $params['max_age'] : 0;

        if ($params['advertisements'] || $maxage) {
            foreach ($results['objects'] as $k => $c) {
                $id = $c['id'];

                if ($params['telemetry']) {
                    $tel = MeshLogTelemetry::findBy("contact_id", $id, $this, array('created_at' => array('operator' => '>', 'value' => $maxage)));
                    if ($tel) {
                        $c['telemetry'] = json_decode($tel->data);
                    }
                }

                $ad = MeshLogAdvertisement::findBy("contact_id", $id, $this, array('created_at' => array('operator' => '>', 'value' => $maxage)));
                if ($ad) {
                    $c['advertisement'] = $ad->asArray();
                    $out[] = $c;
                }
            }
        }

        return array("objects" => $out);
    }

    public function addReports($results, $klass) {
        foreach ($results['objects'] as $key => $val) {
            $id = $val['id'];

            $outrep = array();
            $reports = $klass::getAllReports($this, $id);
            foreach ($reports['objects'] as $rkey => $rval) {
                $outrep[] = $rval;
            }

            $results['objects'][$key]['reports'] = $outrep;
        }
        return $results;
    }

    public function getAdvertisements($params, $reports = false) {
        $params['where'] = array();
        $results = MeshLogAdvertisement::getAll($this, $params);

        if ($reports) {
            $results = $this->addReports($results, 'MeshLogAdvertisementReport');
        }

        return $results;
    }

    public function getChannels($params) {
        // Always return ALL channels (enabled and disabled) regardless of time filters.
        // The JS layer checks each channel's 'enabled' flag from the server response
        // (MeshLogChannel.isEnabled()) so that admin-disabled channels are hidden in the
        // live-feed without needing to filter in SQL.  Returning all channels is also
        // required so that isVisible() can correctly evaluate messages from channels that
        // an admin has disabled — without the channel object present, those messages would
        // erroneously appear to have no associated channel and would be permanently hidden.
        $params['after_ms']  = 0;
        $params['before_ms'] = 0;
        $params['where'] = array();
        return MeshLogChannel::getAll($this, $params);
    }

    private function getQuickSql($tklass, $rklass, $extra1='') {
        $tfields = $tklass::getPublicFields();
        $ttable = $tklass::getTable();
        $rtable = $rklass::getTable();
        $rrefname = $rklass::getRefName();

        $sql = "
            SELECT
                $tfields,
                JSON_ARRAYAGG(
                        JSON_OBJECT(
                            'id', r.id,
                            'reporter_id', r.reporter_id,
                            'snr', r.snr,
                            'scope', r.scope,
                            'route_type', r.route_type,
                            'path', r.path,
                            'sender_at', r.sender_at,
                            'received_at', r.received_at,
                            'created_at', r.created_at
                        )
                ) AS reports
            FROM (
                SELECT t.* FROM $ttable t
                $extra1
                ORDER BY t.id DESC
                LIMIT :offset,:limit
            ) t
            LEFT JOIN $rtable r ON r.$rrefname = t.id
            GROUP BY t.id
            ORDER BY t.id DESC
        ";

        return $sql;
    }

    private function getTimeFiltersSql($params) {
        $after_ms = $params['after_ms'] ?? 0;
        $before_ms = $params['before_ms'] ?? 0;

        $binds = [];
        $sqlWhere = "";
        if ($after_ms > 0) {
            $after_ms = floor($after_ms / 1000);
            $sqlWhere = "t.created_at > FROM_UNIXTIME(:after_ms) ";
            $binds[] = array(":after_ms", $after_ms, PDO::PARAM_INT);
        }
        if ($before_ms > 0) {
            $before_ms = floor($before_ms / 1000);
            if (strlen($sqlWhere)) {
                $sqlWhere .= " AND t.created_at < FROM_UNIXTIME(:before_ms)";
            } else {
                $sqlWhere = "t.created_at < FROM_UNIXTIME(:before_ms)";
            }
            $binds[] = array(":before_ms", $before_ms, PDO::PARAM_INT);
        }

        return array($sqlWhere, $binds);
    }

    public function getReportedQuick($params, $tklass, $rklass, $extra, $binds) {
        $offset = (int) ($params['offset'] ?? 0);
        $limit = (int) ($params['count'] ?? DEFAULT_COUNT);
        $where = $this->getTimeFiltersSql($params);
        if (!empty($where[0])) {
            $extra .= " WHERE " . $where[0];
            foreach ($where[1] as $w) {
                $binds[] = $w;
            }
        }

        if ($limit > MAX_COUNT) $limit = MAX_COUNT;

        $sql = $this->getQuickSql(
            $tklass,
            $rklass,
            $extra
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        foreach ($binds as $b) {
            $stmt->bindValue($b[0], $b[1], $b[2]);
        }

        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $reports = json_decode($row['reports'], true);
            // Filter out NULL rows produced by LEFT JOIN when no reports exist
            $reports = array_values(array_filter($reports, function($r) { return !empty($r['id']); }));

            // Convert DB row to entity and call asArray()
            if (class_exists($tklass)) {
                $entity = call_user_func([$tklass, 'fromDb'], $row, $this);
                $arr = $entity ? $entity->asArray() : $row;
            } else {
                $arr = $row;
            }
            $arr['reports'] = $reports;
            $results[] = $arr;
        }

        return array("objects" => $results);
    }

    public function getChannelMessagesQuick($params) {
        $channel_id = $params['channel_id'] ?? null;
        // Use LEFT JOIN so messages from disabled (or temporarily unavailable) channels
        // are still returned by the API.  The JS layer (MeshLogChannel.isEnabled +
        // MeshLogChannelMessage.isVisible) already handles display filtering based on
        // the channel's admin-enabled flag and the user's live-feed preferences.
        $extra = "LEFT JOIN channels c ON c.id = t.channel_id ";
        $binds = array();

        if ($channel_id !== null) {
            $extra .= "AND t.channel_id = :channel_id ";
            $binds[] = array(':channel_id', (int) $channel_id, PDO::PARAM_INT);
        }

        return $this->getReportedQuick(
            $params,
            'MeshLogChannelMessage',
            'MeshLogChannelMessageReport',
            $extra,
            $binds
        );
    }

    public function getDirectMessagesQuick($params) {
        return $this->getReportedQuick(
            $params,
            'MeshLogDirectMessage',
            'MeshLogDirectMessageReport',
            "",
            array()
        );
    }

    public function getAdvertisementsQuick($params) {
        return $this->getReportedQuick(
            $params,
            'MeshLogAdvertisement',
            'MeshLogAdvertisementReport',
            "",
            array()
        );
    }

    public function getContactsQuick($params) {
        $maxage = $this->getConfig(MeshlogSetting::KEY_MAX_CONTACT_AGE);
        $offset = (int) ($params['offset'] ?? 0);
        $limit = (int) ($params['count'] ?? DEFAULT_COUNT);
        $extra = "WHERE last_heard_at >= NOW() - INTERVAL $maxage SECOND ";
        $binds = array();
        $where = $this->getTimeFiltersSql($params);
        if (!empty($where[0])) {
            $extra .= " AND " . $where[0];
            foreach ($where[1] as $w) {
                $binds[] = $w;
            }
        }

        $sql = "
            SELECT
                t.id,
                t.public_key,
                t.name,
                GREATEST(
                    COALESCE(t.hash_size, 1),
                    COALESCE((SELECT MAX(a.hash_size) FROM advertisements a WHERE a.contact_id = t.id), 1),
                    COALESCE((SELECT MAX(dm.hash_size) FROM direct_messages dm WHERE dm.contact_id = t.id), 1),
                    COALESCE((SELECT MAX(cm.hash_size) FROM channel_messages cm WHERE cm.contact_id = t.id), 1)
                ) AS hash_size,
                t.last_heard_at,
                t.created_at,

                -- Latest advertisement
                (
                    SELECT JSON_OBJECT(
                        'id', a.id,
                        'hash', a.hash,
                        'name', a.name,
                        'lat', a.lat,
                        'lon', a.lon,
                        'type', a.type,
                        'flags', a.flags,
                        'sent_at', a.sent_at,
                        'created_at', a.created_at
                    )
                    FROM advertisements a
                    WHERE a.contact_id = t.id
                    ORDER BY a.created_at DESC
                    LIMIT 1
                ) AS advertisement,

                -- All unique reporter IDs that have ever heard this contact.
                -- Used client-side for the collector filter on the map.
                (
                    SELECT JSON_ARRAYAGG(DISTINCT ar.reporter_id)
                    FROM advertisement_reports ar
                    INNER JOIN advertisements ax ON ax.id = ar.advertisement_id
                    WHERE ax.contact_id = t.id
                ) AS reporter_ids,

                -- Latest telemetry
                (
                    SELECT l.data
                    FROM telemetry l
                    WHERE l.contact_id = t.id
                    ORDER BY l.created_at DESC
                    LIMIT 1
                ) AS telemetry

            FROM contacts t
            $extra
            ORDER BY t.id DESC
            LIMIT :offset,:limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        foreach ($binds as $b) {
            $stmt->bindValue($b[0], $b[1], $b[2]);
        }

        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['telemetry'] = json_decode($row['telemetry'], true);
            $row['advertisement'] = json_decode($row['advertisement'], true);
            $row['reporter_ids'] = json_decode($row['reporter_ids'], true) ?? [];
            $results[] = $row;
        }

        return array("objects" => $results);

    }

    public function getContactAdvertisementsWithCoordinates($contactId) {
        $contactId = intval($contactId);
        if ($contactId <= 0) {
            return array('error' => 'invalid contact_id');
        }

        $sql = "
            SELECT
                id,
                contact_id,
                lat,
                lon,
                sent_at,
                created_at,
                (
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT(
                            'id', ar.id,
                            'reporter_id', ar.reporter_id,
                            'path', ar.path,
                            'snr', ar.snr,
                            'scope', ar.scope,
                            'route_type', ar.route_type,
                            'sender_at', ar.sender_at,
                            'received_at', ar.received_at,
                            'created_at', ar.created_at
                        )
                    )
                    FROM advertisement_reports ar
                    WHERE ar.advertisement_id = advertisements.id
                ) AS reports
            FROM advertisements
            WHERE contact_id = :contact_id
              AND lat IS NOT NULL
              AND lon IS NOT NULL
              AND NOT (lat = 0 AND lon = 0)
            ORDER BY created_at ASC, id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $stmt->execute();

        $results = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = array(
                'id' => intval($row['id']),
                'contact_id' => intval($row['contact_id']),
                'lat' => is_null($row['lat']) ? null : floatval($row['lat']),
                'lon' => is_null($row['lon']) ? null : floatval($row['lon']),
                'sent_at' => $row['sent_at'],
                'created_at' => $row['created_at'],
                'reports' => json_decode($row['reports'], true) ?? array(),
            );
        }

        return array(
            'contact_id' => $contactId,
            'count' => count($results),
            'objects' => $results,
        );
    }

    public function getContactPacketStats($contactId, $windowHours = 24) {
        $contactId = intval($contactId);
        $windowHours = intval($windowHours);

        if ($contactId <= 0) {
            return array('error' => 'invalid contact_id');
        }

        if (!in_array($windowHours, array(1, 24, 36), true)) {
            $windowHours = 24;
        }

        $bucketCount = 12;
        if ($windowHours === 24) $bucketCount = 24;
        if ($windowHours === 36) $bucketCount = 18;

        $bucketSeconds = max(1, intval(($windowHours * 3600) / $bucketCount));
        $bucketMaxIndex = $bucketCount - 1;

        $unionSql = "
            SELECT 'ADV' AS packet_type, created_at
            FROM advertisements
            WHERE contact_id = :contact_id_adv

            UNION ALL

            SELECT 'DIR' AS packet_type, created_at
            FROM direct_messages
            WHERE contact_id = :contact_id_dir

            UNION ALL

            SELECT 'PUB' AS packet_type, created_at
            FROM channel_messages
            WHERE contact_id = :contact_id_pub

            UNION ALL

            SELECT 'TEL' AS packet_type, created_at
            FROM telemetry
            WHERE contact_id = :contact_id_tel

            UNION ALL

            SELECT 'SYS' AS packet_type, created_at
            FROM system_reports
            WHERE contact_id = :contact_id_sys

            UNION ALL

            -- CONTROL packets (payload_type = 11, derived from header byte)
            SELECT 'CTRL' AS packet_type, created_at
            FROM raw_packets
            WHERE contact_id = :contact_id_ctrl AND (header >> 2) & 15 = 11

            UNION ALL

            -- All other raw packets (ACK, PATH, ANON_REQ, undecoded, etc.)
            SELECT 'RAW' AS packet_type, created_at
            FROM raw_packets
            WHERE contact_id = :contact_id_raw AND (header >> 2) & 15 != 11
        ";

        $bindContact = function($stmt) use ($contactId) {
            $stmt->bindValue(':contact_id_adv',  $contactId, PDO::PARAM_INT);
            $stmt->bindValue(':contact_id_dir',  $contactId, PDO::PARAM_INT);
            $stmt->bindValue(':contact_id_pub',  $contactId, PDO::PARAM_INT);
            $stmt->bindValue(':contact_id_tel',  $contactId, PDO::PARAM_INT);
            $stmt->bindValue(':contact_id_sys',  $contactId, PDO::PARAM_INT);
            $stmt->bindValue(':contact_id_ctrl', $contactId, PDO::PARAM_INT);
            $stmt->bindValue(':contact_id_raw',  $contactId, PDO::PARAM_INT);
        };

        $summarySql = "
            SELECT
                COUNT(*) AS total_packets,
                MIN(created_at) AS oldest_created_at,
                MAX(created_at) AS newest_created_at,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS last_1h,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS last_24h,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 36 HOUR) THEN 1 ELSE 0 END) AS last_36h
            FROM (
                $unionSql
            ) packets
        ";

        $summaryStmt = $this->pdo->prepare($summarySql);
        $bindContact($summaryStmt);
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

        $mixSql = "
            SELECT packet_type, COUNT(*) AS packet_count
            FROM (
                $unionSql
            ) packets
            GROUP BY packet_type
            ORDER BY packet_count DESC, packet_type ASC
        ";

        $mixStmt = $this->pdo->prepare($mixSql);
        $bindContact($mixStmt);
        $mixStmt->execute();

        $packetMix = array();
        while ($row = $mixStmt->fetch(PDO::FETCH_ASSOC)) {
            $packetMix[$row['packet_type']] = intval($row['packet_count']);
        }

        $bucketSql = "
            SELECT bucket_index, COUNT(*) AS packet_count
            FROM (
                SELECT
                    GREATEST(
                        0,
                        LEAST(
                            :bucket_max_index,
                            :bucket_max_index - FLOOR(TIMESTAMPDIFF(SECOND, created_at, NOW()) / :bucket_seconds)
                        )
                    ) AS bucket_index
                FROM (
                    $unionSql
                ) packets
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
                  AND created_at <= NOW()
            ) bucketed
            GROUP BY bucket_index
            ORDER BY bucket_index ASC
        ";

        $bucketStmt = $this->pdo->prepare($bucketSql);
        $bucketStmt->bindValue(':bucket_max_index', $bucketMaxIndex, PDO::PARAM_INT);
        $bucketStmt->bindValue(':bucket_seconds', $bucketSeconds, PDO::PARAM_INT);
        $bucketStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        $bindContact($bucketStmt);
        $bucketStmt->execute();

        $buckets = array_fill(0, $bucketCount, 0);
        while ($row = $bucketStmt->fetch(PDO::FETCH_ASSOC)) {
            $index = intval($row['bucket_index']);
            if ($index >= 0 && $index < $bucketCount) {
                $buckets[$index] = intval($row['packet_count']);
            }
        }

        return array(
            'contact_id' => $contactId,
            'window_hours' => $windowHours,
            'bucket_count' => $bucketCount,
            'bucket_seconds' => $bucketSeconds,
            'total_packets' => intval($summary['total_packets'] ?? 0),
            'last_1h' => intval($summary['last_1h'] ?? 0),
            'last_24h' => intval($summary['last_24h'] ?? 0),
            'last_36h' => intval($summary['last_36h'] ?? 0),
            'oldest_created_at' => $summary['oldest_created_at'] ?? null,
            'newest_created_at' => $summary['newest_created_at'] ?? null,
            'packet_mix' => $packetMix,
            'chart_buckets' => $buckets,
            'note' => 'Includes all contact-linked packets stored in the database (ADV, DIR, PUB, TEL, SYS, CTRL, RAW).',
        );
    }

    public function getGeneralAdvertisementStats($windowHours = 24) {
        $windowHours = intval($windowHours);
        if (!in_array($windowHours, array(1, 24, 36), true)) {
            $windowHours = 24;
        }

        $bucketCount = 12;
        if ($windowHours === 24) $bucketCount = 24;
        if ($windowHours === 36) $bucketCount = 18;

        $bucketSeconds = max(1, intval(($windowHours * 3600) / $bucketCount));
        $bucketMaxIndex = $bucketCount - 1;
        $rollupBucketSeconds = static::STATS_ROLLUP_BUCKET_SECONDS;

        $summarySql = "
            SELECT
                COALESCE(SUM(report_count), 0) AS total_reports,
                COALESCE(SUM(direct_count), 0) AS direct_reports,
                COALESCE(SUM(flood_count), 0) AS flood_reports,
                COALESCE(SUM(unknown_route_count), 0) AS unknown_route_reports,
                COALESCE(SUM(no_hop_count), 0) AS no_hop_reports,
                COALESCE(SUM(relayed_count), 0) AS relayed_reports,
                MIN(bucket_start) AS oldest_created_at,
                MAX(bucket_start) AS newest_created_at
            FROM stats_adv_reports_rollup
            WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
              AND bucket_start <= NOW()
        ";

        $summaryStmt = $this->pdo->prepare($summarySql);
        $summaryStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

        $entitySummarySql = "
            SELECT
                COUNT(DISTINCT advertisement_id) AS total_advertisements,
                COUNT(DISTINCT contact_id) AS unique_devices
            FROM stats_adv_entities_rollup
            WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
              AND bucket_start <= NOW()
        ";

        $entitySummaryStmt = $this->pdo->prepare($entitySummarySql);
        $entitySummaryStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        $entitySummaryStmt->execute();
        $entitySummary = $entitySummaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

        $collectorSummarySql = "
            SELECT COUNT(DISTINCT reporter_id) AS unique_collectors
            FROM stats_collector_packets_rollup
            WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
              AND bucket_start <= NOW()
        ";

        $collectorSummaryStmt = $this->pdo->prepare($collectorSummarySql);
        $collectorSummaryStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        $collectorSummaryStmt->execute();
        $collectorSummary = $collectorSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

        $bucketSql = "
            SELECT bucket_index, SUM(report_count) AS report_count
            FROM (
                SELECT
                    GREATEST(
                        0,
                        LEAST(
                            :bucket_max_index,
                            :bucket_max_index - FLOOR(TIMESTAMPDIFF(SECOND, bucket_start, NOW()) / :bucket_seconds)
                        )
                    ) AS bucket_index,
                    report_count
                FROM stats_adv_reports_rollup
                WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
                  AND bucket_start <= NOW()
            ) bucketed
            GROUP BY bucket_index
            ORDER BY bucket_index ASC
        ";

        $bucketStmt = $this->pdo->prepare($bucketSql);
        $bucketStmt->bindValue(':bucket_max_index', $bucketMaxIndex, PDO::PARAM_INT);
        $bucketStmt->bindValue(':bucket_seconds', $bucketSeconds, PDO::PARAM_INT);
        $bucketStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        $bucketStmt->execute();

        $chartBuckets = array_fill(0, $bucketCount, 0);
        while ($row = $bucketStmt->fetch(PDO::FETCH_ASSOC)) {
            $bucketIndex = intval($row['bucket_index'] ?? -1);
            if ($bucketIndex < 0 || $bucketIndex >= $bucketCount) continue;
            $chartBuckets[$bucketIndex] = intval($row['report_count'] ?? 0);
        }

        $deviceBucketSql = "
            SELECT bucket_index, COUNT(DISTINCT contact_id) AS device_count
            FROM (
                SELECT
                    contact_id,
                    GREATEST(
                        0,
                        LEAST(
                            :bucket_max_index,
                            :bucket_max_index - FLOOR(TIMESTAMPDIFF(SECOND, bucket_start, NOW()) / :bucket_seconds)
                        )
                    ) AS bucket_index
                FROM stats_adv_entities_rollup
                WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
                  AND bucket_start <= NOW()
            ) bucketed
            GROUP BY bucket_index
            ORDER BY bucket_index ASC
        ";

        $deviceBucketStmt = $this->pdo->prepare($deviceBucketSql);
        $deviceBucketStmt->bindValue(':bucket_max_index', $bucketMaxIndex, PDO::PARAM_INT);
        $deviceBucketStmt->bindValue(':bucket_seconds', $bucketSeconds, PDO::PARAM_INT);
        $deviceBucketStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        $deviceBucketStmt->execute();

        $deviceChartBuckets = array_fill(0, $bucketCount, 0);
        while ($row = $deviceBucketStmt->fetch(PDO::FETCH_ASSOC)) {
            $bucketIndex = intval($row['bucket_index'] ?? -1);
            if ($bucketIndex < 0 || $bucketIndex >= $bucketCount) continue;
            $deviceChartBuckets[$bucketIndex] = intval($row['device_count'] ?? 0);
        }

        $collectorSql = "
            SELECT
                totals.reporter_id,
                COALESCE(r.name, CONCAT('Collector ', totals.reporter_id)) AS reporter_name,
                COALESCE(r.public_key, '') AS public_key,
                COALESCE(r.style, '{}') AS style,
                SUM(totals.packet_count) AS total_packets,
                SUM(CASE WHEN totals.packet_type = 'ADV' THEN totals.packet_count ELSE 0 END) AS adv_packets,
                SUM(CASE WHEN totals.packet_type = 'DIR' THEN totals.packet_count ELSE 0 END) AS dir_packets,
                SUM(CASE WHEN totals.packet_type = 'PUB' THEN totals.packet_count ELSE 0 END) AS pub_packets,
                SUM(CASE WHEN totals.packet_type = 'TEL' THEN totals.packet_count ELSE 0 END) AS tel_packets,
                SUM(CASE WHEN totals.packet_type = 'SYS' THEN totals.packet_count ELSE 0 END) AS sys_packets,
                SUM(CASE WHEN totals.packet_type = 'RAW' THEN totals.packet_count ELSE 0 END) AS raw_packets
            FROM (
                SELECT reporter_id, packet_type, SUM(packet_count) AS packet_count
                FROM stats_collector_packets_rollup
                WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
                  AND bucket_start <= NOW()
                GROUP BY reporter_id, packet_type
            ) totals
            LEFT JOIN reporters r ON r.id = totals.reporter_id
            GROUP BY totals.reporter_id, reporter_name, public_key, style
            ORDER BY total_packets DESC, reporter_name ASC, totals.reporter_id ASC
        ";

        $collectorStmt = $this->pdo->prepare($collectorSql);
        $collectorStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        $collectorStmt->execute();

        $collectorTotals = array();
        while ($row = $collectorStmt->fetch(PDO::FETCH_ASSOC)) {
            $collectorTotals[] = array(
                'reporter_id' => intval($row['reporter_id'] ?? 0),
                'reporter_name' => $row['reporter_name'] ?? '',
                'public_key' => $row['public_key'] ?? '',
                'style' => $row['style'] ?? '{}',
                'total_packets' => intval($row['total_packets'] ?? 0),
                'adv_packets' => intval($row['adv_packets'] ?? 0),
                'dir_packets' => intval($row['dir_packets'] ?? 0),
                'pub_packets' => intval($row['pub_packets'] ?? 0),
                'tel_packets' => intval($row['tel_packets'] ?? 0),
                'sys_packets' => intval($row['sys_packets'] ?? 0),
                'raw_packets' => intval($row['raw_packets'] ?? 0),
            );
        }

        return array(
            'window_hours' => $windowHours,
            'bucket_count' => $bucketCount,
            'bucket_seconds' => $bucketSeconds,
            'rollup_bucket_seconds' => $rollupBucketSeconds,
            'total_reports' => intval($summary['total_reports'] ?? 0),
            'total_advertisements' => intval($entitySummary['total_advertisements'] ?? 0),
            'unique_devices' => intval($entitySummary['unique_devices'] ?? 0),
            'unique_collectors' => intval($collectorSummary['unique_collectors'] ?? 0),
            'direct_reports' => intval($summary['direct_reports'] ?? 0),
            'flood_reports' => intval($summary['flood_reports'] ?? 0),
            'unknown_route_reports' => intval($summary['unknown_route_reports'] ?? 0),
            'no_hop_reports' => intval($summary['no_hop_reports'] ?? 0),
            'relayed_reports' => intval($summary['relayed_reports'] ?? 0),
            'oldest_created_at' => $summary['oldest_created_at'] ?? null,
            'newest_created_at' => $summary['newest_created_at'] ?? null,
            'chart_buckets' => $chartBuckets,
            'unique_device_buckets' => $deviceChartBuckets,
            'collector_totals' => $collectorTotals,
            'note' => 'Counts are served from persistent 5-minute rollups, so historical stats remain available after old packet rows are auto-purged. Direct and flood values still depend on stored route metadata; legacy rows may remain unclassified.',
        );
    }

    public function getChannelMessages($params, $reports = false) {
        $params['where'] = array();
        if (isset($params['id'])) {
            $ch = MeshLogChannel::findById(intval($id), $this);
            if (!$ch->enabled) return array();
            $params['where'] = array('channel_id = ' . intval($id));
        } else {
            $params['join'] = 'JOIN channels  ON t.channel_id = channels.id';
            $params['where'] = array('channels.enabled = 1');
        }

        $results = MeshLogChannelMessage::getAll($this, $params);

        if ($reports) {
            $results = $this->addReports($results, 'MeshLogChannelMessageReport');
        }

        return $results;
    }

    public function getDirectMessages($params, $reports = false) {
        $params['where'] = array();
        if (isset($params['id'])) {
            $params['where'] = array('contact_id = ' . intval($id));
        }

        $results = MeshLogDirectMessage::getAll($this, $params);

        if ($reports) {
            $results = $this->addReports($results, 'MeshLogDirectMessageReport');
        }

        return $results;
    }

    public function getRawPackets($params) {
        $params['where'] = array();
        $results = MeshLogRawPacket::getAll($this, $params);
        return $results;
    }

    public function getTelemetry($params) {
        $params['where'] = array();
        return MeshLogTelemetry::getAll($this, $params);
    }

    public function getSystemReports($params) {
        $params['where'] = array();
        return MeshLogSystemReport::getAll($this, $params);
    }
};

?>
