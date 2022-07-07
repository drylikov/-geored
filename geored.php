<?php
    /**
     * Class GeoRed
     */
    class GeoRed {
        const TABLE      = 'geored_ips';
        const DP         = 'geoplugin_';
        const PROXY_LIST = '';
        const URL        = 'http://178.237.33.50/json.gp?ip=[+ip+]';

        protected static $data  = null;
        protected static $table = array(
            'continent' => array('data' => 'continentCode'),
            'country'   => array('data' => 'countryCode'),
            'region'    => array('data' => 'regionCode'),
            'city'      => array('data' => 'city', 'length' => 64),
            'currency'  => array('data' => 'currencyCode')
        );

        /**
         * @param null $key
         * @return mixed
         */
        public static function get($key = null) {
            if (!is_array(self::$data)) self::$data = array();
            if (empty($key)) return self::$data;
            return isset(self::$data[$key]) ? self::$data[$key] : false;
        }

        /**
         * @param mixed $ip
         * @return mixed
         * @throws Exception
         */
        public static function ip($ip = null) {
            if (empty($ip)) $ip = empty($_SERVER['REMOTE_ADDR']) ? false : $_SERVER['REMOTE_ADDR'];
            if ($ip = filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            throw new Exception('Invalid IP');
        }

        /**
         * @return array
         */
        public static function table() {
            return array(
                'continent' => array('data' => 'continentCode'),
                'country'   => array('data' => 'countryCode'),
                'region'    => array('data' => 'regionCode'),
                'city'      => array('data' => 'city', 'length' => 64),
                'currency'  => array('data' => 'currencyCode')
            );
        }

        /**
         * @return array
         */
        public static function proxies() {
            if (!file_exists(self::PROXY_LIST)) return array();
            $proxies = array();
            foreach (array_filter(array_map('trim', file(self::PROXY_LIST))) as $item) {
                $_ = explode(':', $item);
                if (count($_) < 2) $_[1] = '80';
                $proxies[] = $_[0].':'.$_[1];
            }
            return $proxies;
        }

        /**
         * @param string $ip
         * @return mixed
         */
        public static function data($ip = null) {
            $proxy   = false;
            $proxies = self::proxies();
            $pc      = 0;
            $data    = '';
            $ctx     = array('timeout' => 5, 'ignore_errors' => true);
            while (true) {
                $context = stream_context_create(array('http' => $ctx));
                $URL     = str_replace('[+ip+]', urlencode($ip), self::URL);
                $data    = file_get_contents($URL, false, $context);
                if (empty($http_response_header)) break;
                $hcode = explode(' ', $http_response_header[0]);
                $hcode = empty($hcode[1]) ? 0 : intval($hcode[1]);
                if (empty($hcode) || ($hcode == 200)) break;
                if (empty($proxies)) break;
                $ctx['request_fulluri'] = true;
                while (true) {
                    if ($pc >= count($proxies)) break;
                    if (empty($proxies[$pc])) {
                        $pc++;
                        continue;
                    }
                    $proxy = $proxies[$pc];
                    $pc++;
                    break;
                }
                if (!$proxy) break;
                $ctx['proxy'] = 'tcp://'.$proxy;
            }
            return empty($data) ? false : json_decode($data, true);
        }

        /**
         * @return bool
         */
        public static function isBot() {
            $bots = array(
                'Google', 'Yahoo', 'Rambler', 'Yandex', 'Mail',
                'Bot', 'Spider', 'Snoopy', 'Crawler', 'Finder', 'curl'
            );
            if (empty($_SERVER['HTTP_USER_AGENT'])) return false;
            if (preg_match('~('.implode('|', $bots).')~i', $_SERVER['HTTP_USER_AGENT'])) return true;
            return false;
        }

        /**
         * @param string $ip
         * @param array  $config
         * @return mixed
         * @throws Exception
         */
        public static function getData($config = array(), $ip = null) {
            $ip = self::ip($ip);
            $ec = 0;
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = new mysqli(
                empty($config['host'])     ? 'localhost' : $config['host'],
                empty($config['user'])     ? 'root'      : $config['user'],
                empty($config['password']) ? ''          : $config['password'],
                empty($config['name'])     ? 'mysql'     : $config['name'],
                empty($config['port'])     ? 3306        : $config['port']
            );
            $P = empty($config['prefix']) ? '' : $config['prefix'];
            if ($db->connect_error) self::error($db->connect_error);
            try {
                $rows = $db->query("select * from `{$P}".self::TABLE."` where `ip` = '{$ip}'");
                if ($row = $rows->fetch_assoc()) return self::result($row, $db);
                geored_add:
                if ($ec > 2) self::error('Emergency counter');
                $data = self::data($ip);
                if (empty($data)) return false;
                $row  = array('ip' => $ip);
                $cols = array('`ip`');
                $vals = array("'{$ip}'");
                foreach (self::table() as $key => $col) {
                    $dk  = $col['data'];
                    $val = empty($data[self::DP.$dk]) ? '' : strtolower($data[self::DP.$dk]);
                    $row[$key] = trim($val);
                    $cols[] = "`{$key}`";
                    $vals[] = "'{$val}'";
                }
                // Запись данных
                $cols = implode(',', $cols);
                $vals = implode(',', $vals);
                try {
                    $db->query("insert into `{$P}".self::TABLE."` ({$cols}) values ({$vals})");
                    return self::result($row, $db);
                } catch (mysqli_sql_exception $e) {
                    self::error($e);
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() != 1146) self::error($e, $db);
                $ec++;
                $cols = array(
                    '`id` bigint not null auto_increment',
                    "`ip` varchar(16) not null default ''",
                );
                foreach (self::table() as $key => $col) {
                    $l = empty($col['length']) ? 16 : intval($col['length']);
                    $cols[] = "`{$key}` varchar({$l}) not null default ''";
                }
                $cols[] = 'primary key (`id`)';
                $cols   = implode(',', $cols);
                $q = "create table `{$P}".self::TABLE."` ({$cols}) engine = InnoDB";
                try {
                    $db->query($q);
                    goto geored_add;
                } catch (mysqli_sql_exception $e) {
                    self::error($e, $db);
                }
            }
            return self::result(false, $db);
        }

        /**
         * @param array $value
         * @return array
         */
        public static function setData($value) {
            self::$data = is_array($value) ? $value : array();
            return self::$data;
        }

        /**
         * @param mixed $e
         * @param null  $db
         * @throws Exception
         */
        public static function error($e, $db = null) {
            if ($db instanceof mysqli) $db->close();
            if ($e instanceof Exception) throw new Exception($e->getMessage(), $e->getCode());
            throw new Exception($e);
        }

        /**
         * @param mixed $val
         * @param null  $db
         * @return mixed
         */
        public static function result($val, $db = null) {
            if ($db instanceof mysqli) $db->close();
            return $val;
        }
    }
