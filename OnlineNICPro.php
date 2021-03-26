<?php
require_once 'lib/ApiClient.php';

class OnlineNICPro_Punycode
{
    private static $DECODE_TABLE = array(
        'a' => 0, 'b' => 1, 'c' => 2, 'd' => 3, 'e' => 4, 'f' => 5,
        'g' => 6, 'h' => 7, 'i' => 8, 'j' => 9, 'k' => 10, 'l' => 11,
        'm' => 12, 'n' => 13, 'o' => 14, 'p' => 15, 'q' => 16, 'r' => 17,
        's' => 18, 't' => 19, 'u' => 20, 'v' => 21, 'w' => 22, 'x' => 23,
        'y' => 24, 'z' => 25, '0' => 26, '1' => 27, '2' => 28, '3' => 29,
        '4' => 30, '5' => 31, '6' => 32, '7' => 33, '8' => 34, '9' => 35
    );
    private static $ENCODE_TABLE = array(
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l',
        'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x',
        'y', 'z', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'
    );
    const BASE = 36;
    const TMIN = 1;
    const TMAX = 26;
    const SKEW = 38;
    const DAMP = 700;
    const INITIAL_BIAS = 72;
    const INITIAL_N = 0x80;
    const DELIMITER = '-';
    const PREFIX = 'xn--';
    const SUFFIX = '';

    private static $OPTIONS = array(
        'charset' => 'UTF-8'
    );

    public static function set_options($options)
    {
        self::$OPTIONS = array_merge(self::$OPTIONS, (array)$options);
    }

    private static function adapt($delta, $numpoints, $firsttime)
    {
        $delta = (int)($firsttime ? $delta / self::DAMP : $delta / 2);
        $delta += (int)($delta / $numpoints);
        $k = 0;
        while ($delta > (((self::BASE - self::TMIN) * self::TMAX) / 2)) {
            $delta = (int)($delta / (self::BASE - self::TMIN));
            $k += self::BASE;
        }
        return $k + (int)((self::BASE - self::TMIN + 1) * $delta / ($delta + self::SKEW));
    }

    private static function string_from_charcode($charcode)
    {
        return mb_convert_encoding('&#' . $charcode . ';', self::$OPTIONS['charset'], 'HTML-ENTITIES');
    }

    private static function charcode_from_string($string)
    {
        return (int)preg_replace('/^.*?([0-9]+).*$/', '$1', mb_convert_encoding($string, 'HTML-ENTITIES', self::$OPTIONS['charset']));
    }

    public static function urldecode($url)
    {
        return self::urlendecode($url, 'decode');
    }

    public static function urlencode($url)
    {
        return self::urlendecode($url, 'encode');
    }

    private static function urlendecode($url, $func)
    {
        $uri = parse_url($url);
        if (!empty($uri['host'])) {
            foreach (array_unique((array)explode('.', $uri['host'])) as $var) {
                if (($encoded = self::$func($var)) != $var) {
                    $url = str_replace($var, $encoded, $url);
                }
            }
        }
        return $url;
    }

    public static function decode($input)
    {
        if (preg_match('/^' . self::PREFIX . '/', $input)) {
            return self::_decode(substr($input, strlen(self::PREFIX)));
        }
        return $input;
    }

    public static function encode($input)
    {
        if (preg_match('/[^\x00-\x7f]/', $input)) {
            return self::PREFIX . self::_encode($input);
        }
        return $input;
    }

    public static function _decode($input)
    {
        $n = self::INITIAL_N;
        $bias = self::INITIAL_BIAS;
        $i = 0;
        $output = null;

        if ($pos = (int)strrpos($input, self::DELIMITER)) {
            $output = substr($input, 0, $pos++);
        }
        $ilen = strlen($input);
        $olen = strlen($output);

        while ($pos < $ilen) {
            $oldi = $i;
            $w = 1;
            $k = 0;
            while ($k += self::BASE) {
                $i += ($digit = self::$DECODE_TABLE[$input[$pos++]]) * $w;
                $t = $k <= $bias ? self::TMIN : ($k >= $bias + self::TMAX ? self::TMAX : $k - $bias);
                if ($digit < $t) {
                    break;
                }
                $w *= self::BASE - $t;
            }
            $bias = self::adapt($i - $oldi, ++$olen, $oldi == 0);
            $n += (int)($i / $olen);
            $i %= $olen;
            $output = mb_substr($output, 0, $i, self::$OPTIONS['charset']) . self::string_from_charcode($n) . mb_substr($output, $i, $olen - $i, self::$OPTIONS['charset']);
            ++$i;
        }
        return $output;
    }

    public static function _encode($input)
    {
        $n = self::INITIAL_N;
        $bias = self::INITIAL_BIAS;
        $delta = 0;
        $output = null;

        $ilen = mb_strlen($input, self::$OPTIONS['charset']);
        $non_basic_codepoints = array();
        $codepoints = array();
        for ($b = 0; $b < $ilen; ++$b) {
            if (($code = ord($char = mb_substr($input, $b, 1, self::$OPTIONS['charset']))) < $n) {
                $output .= $char;
            } else if (!in_array($code = self::charcode_from_string($char), $non_basic_codepoints)) {
                $non_basic_codepoints[] = $code;
            }
            $codepoints[] = $code;
        }

        if (($b = strlen($output)) == $ilen) {
            return $output;
        }
        if ($h = $b) {
            $output .= self::DELIMITER;
        }

        $j = 0;
        sort($non_basic_codepoints);
        while ($h < $ilen) {
            $m = $non_basic_codepoints[$j++];
            $delta += ($m - $n) * ($h + 1);
            $n = $m;

            foreach ($codepoints as $c) {
                if ($c < $n) {
                    ++$delta;
                } else if ($c == $n) {
                    $q = $delta;
                    $k = 0;
                    while ($k += self::BASE) {
                        $t = $k <= $bias ? self::TMIN : ($k >= $bias + self::TMAX ? self::TMAX : $k - $bias);
                        if ($q < $t) {
                            break;
                        }
                        $output .= self::$ENCODE_TABLE[$t + (($q - $t) % (self::BASE - $t))];
                        $q = (int)(($q - $t) / (self::BASE - $t));
                    }
                    $output .= self::$ENCODE_TABLE[$q];
                    $bias = self::adapt($delta, $h + 1, $h == $b);
                    $delta = 0;
                    ++$h;
                }
            }
            ++$delta;
            ++$n;
        }
        return $output;
    }

    public static function toASCII($input)
    {
        $parts = explode('.', $input);
        foreach ($parts as $k => $v) {
            $parts[$k] = self::encode($v);
        }

        return implode('.', $parts);
    }

    public static function toUnicode($input)
    {
        $parts = explode('.', $input);
        foreach ($parts as $k => $v) {
            $parts[$k] = self::decode($v);
        }

        return implode('.', $parts);
    }
}

class OnlineNICPro_Idn
{

    public static function encode($domainName)
    {
        $domainParts = explode('.', $domainName);
        foreach ($domainParts as $k => $v) {
            $domainParts[$k] = OnlineNICPro_Punycode::encode(strtolower($v));
        }

        return implode('.', $domainParts);
    }

    public static function decode($domainName)
    {
        $domainParts = explode('.', $domainName);
        foreach ($domainParts as $k => $v) {
            $domainParts[$k] = OnlineNICPro_Punycode::decode($v);
        }

        return implode('.', $domainParts);
    }
}

class OnlineNICPro_Domain extends ApiClient
{

    public function getconfig()
    {
        return $this->config;
    }

    /************************** start part of domain *********************************/

    public function checkDomain($domain, $op = '')
    {
        $params = [
            'domain' => $domain,
            'op' => $op // 1-register domain 2-transfer domain 3-renew domain
        ];
        return $this->request('checkDomain', $params);
    }

    public function infoDomain($domain, $extension = '')
    {
        $params = [
            'domain' => $domain,
        ];
        if ($extension) {
            $params['extension'] = $extension;
        }
        return $this->request('infoDomain', $params);
    }

    public function registerDomain($domain, $period, $dns, $registrant, $admin = '', $tech = '', $billing = '', $lang = '')
    {
        $params = [
            'domain' => $domain,
            'period' => $period,
            'registrant' => $registrant,
            'admin' => $admin,
            'tech' => $tech,
            'billing' => $billing,
        ];
        if ($lang != 0) {
            $params['lang'] = $lang;
        }
        foreach ($dns as $k => $v) {
            $params['dns' . ($k + 1)] = $v;
        }

        return $this->request('registerDomain', $params);
    }

    public function renewDomain($domain, $period)
    {
        $params = [
            'domain' => $domain,
            'period' => $period,
        ];
        return $this->request('renewDomain', $params);
    }

    public function deleteDomain($domain)
    {
        $params = [
            'domain' => $domain,
        ];
        return $this->request('deleteDomain', $params);
    }

    public function updateDomainStatus($domain, $ctp)
    {
        $params = [
            'domain' => $domain,
            'ctp' => $ctp
        ];
        return $this->request('updateDomainStatus', $params);
    }

    public function updateDomainDns($domain, $dns1, $dns2, $dns3 = '', $dns4 = '', $dns5 = '', $dns6 = '')
    {
        $params = [
            'domain' => $domain,
            'dns1' => $dns1,
            'dns2' => $dns2,
            'dns3' => $dns3,
            'dns4' => $dns4,
            'dns5' => $dns5,
            'dns6' => $dns6,
        ];

        return $this->request('updateDomainDns', $params);
    }

    public
    function getAuthCode($domain)
    {
        $params = [
            'domain' => $domain,
        ];
        return $this->request('getAuthCode', $params);
    }

    public
    function updateAuthCode($domain, $authcode)
    {
        $params = [
            'domain' => $domain,
            'authcode' => $authcode
        ];
        return $this->request('updateAuthCode', $params);
    }

    public
    function setDomainPassword($domain, $password)
    {
        $params = [
            'domain' => $domain,
            'password' => $password
        ];
        return $this->request('setDomainPassword', $params);
    }

    public
    function transferDomain($domain, $password, $contactid)
    {
        $params = [
            'domain' => $domain,
            'password' => $password,
            'contactid' => $contactid
        ];
        return $this->request('transferDomain', $params);
    }

    public
    function queryTransferStatus($domain, $password)
    {
        $params = [
            'domain' => $domain,
            'password' => $password
        ];
        return $this->request('queryTransferStatus', $params);
    }

    public
    function cancelDomainTransfer($domain, $password)
    {
        $params = [
            'domain' => $domain,
            'password' => $password
        ];
        return $this->request('cancelDomainTransfer', $params);
    }

    public
    function domainChangeContact($domain, $registrant, $admin, $tech, $billing)
    {
        $params = [
            'domain' => $domain,
            'registrant' => $registrant,
            'admin' => $admin,
            'tech' => $tech,
            'billing' => $billing
        ];
        return $this->request('domainChangeContact', $params);
    }

    /************************** end part of domain *********************************/

    /************************** start part of contact *********************************/

    public function infoContact($ext, $contactid)
    {
        $params = [
            'ext' => $ext,
            'contactid' => $contactid
        ];
        return $this->request('infoContact', $params);
    }

    public
    function createContact($ext, $name, $org, $country, $province, $city, $street, $postalcode, $voice, $fax, $email, $extInfo)
    {
        $params = [
            'ext' => $ext,
            'name' => $name,
            'org' => $org,
            'country' => $country,
            'province' => $province,
            'city' => $city,
            'street' => $street,
            'postalcode' => $postalcode,
            'voice' => $voice,
            'fax' => $fax,
            'email' => $email
        ];
        if (is_array($extInfo)) {
            foreach ($extInfo as $extName => $extVal) {
                if ($extVal) $params[$extName] = $extVal;
            }
        }
        return $this->request('createContact', $params);
    }

    public
    function updateContact($ext, $contactid, $name, $org, $country, $province, $city, $street, $postalcode, $voice, $fax, $email, $extInfo)
    {
        $params = [
            'ext' => $ext,
            'contactid' => $contactid,
            'name' => $name,
            'org' => $org,
            'country' => $country,
            'province' => $province,
            'city' => $city,
            'street' => $street,
            'postalcode' => $postalcode,
            'voice' => $voice,
            'fax' => $fax,
            'email' => $email
        ];
        if (is_array($extInfo)) {
            foreach ($extInfo as $extName => $extVal) {
                if ($extVal) $params[$extName] = $extVal;
            }
        }
        return $this->request('updateContact', $params);
    }

    /************************** end part of contact *********************************/

    /************************** start part of host *********************************/

    public function infoHost($ext, $hostName)
    {
        $params = [
            'ext' => $ext,
            'hostname' => $hostName,
        ];
        return $this->request('infoHost', $params);
    }

    public
    function createHost($ext, $hostName, $addr = '')
    {
        $params = [
            'ext' => $ext,
            'hostname' => $hostName,
        ];
        if ($addr) {
            $params['addr'] = $addr;
        }
        return $this->request('createHost', $params);
    }

    public
    function updateHost($domainType, $hostName, $addAddr, $remAddr)
    {
        $params = [
            'domaintype' => $domainType,
            'hostname' => $hostName,
            'addaddr' => $addAddr,
            'remaddr' => $remAddr,
        ];
        return $this->request('updateHost', $params);
    }

    public
    function deleteHost($ext, $hostName)
    {
        $params = [
            'ext' => $ext,
            'hostname' => $hostName,
        ];
        return $this->request('deleteHost', $params);
    }

    /************************** end part of contact *********************************/

    /************************** start part of idShield *********************************/

    public
    function infoIDShield($domain)
    {
        $params = [
            'domain' => $domain,
        ];
        return $this->request('infoIDShield', $params);
    }

    public
    function appIDShield($domain, $period, $autorenew, $fee)
    {
        $params = [
            'domain' => $domain,
            'period' => $period,
            'autorenew' => $autorenew, // Y or N
            'fee' => $fee,
        ];
        return $this->request('applyIDShield', $params);
    }

    public
    function resumeIDShield($domain)
    {
        $params = [
            'domain' => $domain,
        ];
        return $this->request('resumeIDShield', $params);
    }

    public
    function suspendIDShield($domain)
    {
        $params = [
            'domain' => $domain,
        ];
        return $this->request('suspendIDShield', $params);
    }

    /************************** end part of idShield *********************************/


    /************************** start part of Whois *********************************/

    public function resendWhoisVF($domain)
    {
        $params = [
            'domain' => $domain,
        ];
        return $this->request('resendWhoisVF', $params);
    }

    /************************** end part of Whois *********************************/


    public
    function queryRegTransfer($domainType, $domain)
    {
        $params = array(
            'domaintype' => $domainType,
            'domain' => $domain,
        );
        $cltrid = 'whmcs' . $this->config['user'] . date('ymdhis') . rand(1000, 9999);
        $chksum = md5($this->config['user'] . md5($this->config['pass']) . $cltrid . 'queryregtransfer' . $domainType . $domain);
        $cmd = $this->buildCommand('domain', 'QueryRegTransfer', $params, $cltrid, $chksum);
        return $this->request($cmd);
    }

    public
    function getDomainTypeByTld($tld)
    {
        $tld = strtolower($tld);
        switch ($tld) {
            case 'com':
                $domainType = 0;
                break;
            case 'net':
                $domainType = 0;
                break;
            case 'tw':
                $domainType = 302;
                break;
            case 'tv':
                $domainType = 400;
                break;
            case 'cc':
                $domainType = 600;
                break;
            case 'cc':
                $domainType = 610;
                break;
            case 'biz':
                $domainType = 800;
                break;
            case 'name':
                $domainType = 804;
                break;
            case 'info':
                $domainType = 805;
                break;
            case 'us':
                $domainType = 806;
                break;
            case 'org':
                $domainType = 807;
                break;
            case 'in':
                $domainType = 808;
                break;
            case 'co.uk':
                $domainType = 901;
                break;
            case 'org.uk':
                $domainType = 901;
                break;
            case 'plc.uk':
                $domainType = 901;
                break;
            case 'me.uk':
                $domainType = 901;
                break;
            case 'eu':
                $domainType = 902;
                break;
            case 'mobi':
                $domainType = 903;
                break;
            case 'asia':
                $domainType = 905;
                break;
            case 'me':
                $domainType = 906;
                break;
            case 'tel':
                $domainType = 907;
                break;
            case 'ws':
                $domainType = 301;
                break;
            case 'cn':
                $domainType = 220;
                break;
            default:
                $domainType = 9999;
                break;
        }
        return $domainType;
    }

    public
    function generatePwd($len = 10)
    {
        $isint = $isstr = $isspe = 0;
        $ascii = 0;
        $buff = '';
        for ($i = 0; $i < $len; $i++) {
            do {
                $ascii = mt_rand(33, 126);
            } while (34 == $ascii || 38 == $ascii || 39 == $ascii || 59 == $ascii || 60 == $ascii || 62 == $ascii || 92 == $ascii);
            $buff .= chr($ascii);

            if ($ascii >= 48 && $ascii <= 57) {
                $isint++;
            } else {
                if (($ascii >= 65 && $ascii <= 90) || ($ascii >= 97 && $ascii <= 122)) {
                    $isstr++;
                } else {
                    $isspe++;
                }
            }
        }
        if (0 == $isint || 0 == $isstr || 0 == $isspe) {
            $buff = substr($buff, 0, -3);
            $buff .= '-';
            $buff .= chr(mt_rand() % 26 + 97);
            $buff .= chr(mt_rand() % 10 + 48);
        }
        return $buff;
    }

    public
    function getUsDomainApplicationPurpose($param)
    {
        switch ($param) {
            case 'Business use for profit':
                $appPurpose = 'P1';
                break;
            case 'Non-profit business':
            case 'Club':
            case 'Association':
            case 'Religious Organization':
                $appPurpose = 'P2';
                break;
            case 'Personal Use':
                $appPurpose = 'P3';
                break;
            case 'Educational purposes':
                $appPurpose = 'P4';
                break;
            case 'Government purposes':
                $appPurpose = 'P5';
                break;
            default:
                $appPurpose = 'P2';
                break;
                break;
        }
        return $appPurpose;
    }

    public
    function getUkDomainOrgType($param)
    {
        switch ($param) {
            case 'Individual':
                $orgType = 'IND';
                break;
            case 'UK Limited Company':
                $orgType = 'LTD';
                break;
            case 'UK Public Limited Company':
                $orgType = 'PLC';
                break;
            case 'UK Partnership':
                $orgType = 'PTNR';
                break;
            case 'UK Limited Liability Partnership':
                $orgType = 'LLP';
                break;
            case 'Sole Trader':
                $orgType = 'STRA';
                break;
            case 'UK Registered Charity':
                $orgType = 'RCHAR';
                break;
            case 'UK Entity (other)':
                $orgType = 'OTHER';
                break;
            case 'Foreign Organization':
                $orgType = 'FCORP';
                break;
            case 'Other foreign organizations':
                $orgType = 'FOTHER';
                break;
            case 'UK Industrial/Provident Registered Company':
                $orgType = 'IP';
                break;
            case 'UK School':
                $orgType = 'SCH';
                break;
            case 'UK Government Body':
                $orgType = 'GOV';
                break;
            case 'UK Corporation by Royal Charter':
                $orgType = 'CRC';
                break;
            case 'UK Statutory Body':
                $orgType = 'STAT';
                break;
            case 'Non-UK Individual':
                $orgType = 'FIND';
                break;
            default:
                $orgType = 'OTHER';
                break;
        }
        return $orgType;
    }

}


/************************** WHMCS Module Config Start *********************************/

function OnlineNICPro_getConfigArray()
{
    return [
        "Username" => ["Type" => "text", "Size" => "20", "Description" => "Enter your OnlinenNIC username here",],
        "Password" => ["Type" => "password", "Size" => "20", "Description" => "Enter your OnlineNIC password here",],
        "TestMode" => ["Type" => "yesno", "Description" => "Tick on to connect to testing account and server. You don't need to change your account ID and password on above.Our OTE login is ID 135610 PW: 654123.For Support:http://support.OnlineNIC.com"],
        "APIKEY" => ["Type" => "password", "Size" => "20", "Description" => "Enter account APIKEY",],
    ];
}

/************************** WHMCS Module Config end *********************************/

function OnlineNICPro_getApiConfig($params)
{
    $username = $params['Username'];
    $password = $params['Password'];
    $testmode = $params['TestMode'];
    $apikey = $params['APIKEY'];
    $config = [
        'server' => $testmode ? 'ote.onlinenic.com' : 'www.onlinenic.com',
        'user' => $testmode ? '135610' : $username,
        'pass' => $testmode ? '654123' : $password,
        'apikey' => $apikey,
        'timeout' => 60, //The connection timeout, in seconds.
        'log_record' => true,//Record the log file
        'log_path' => 'apilogs/',
    ];
    return $config;
}

function OnlineNICPro_getApiClient($params)
{
    $api = new OnlineNICPro_Domain(OnlineNICPro_getApiConfig($params));
    return $api;
}

/************************** WHMCS Module Start *********************************/


function OnlineNICPro_RegisterDomain($params)
{
    $privacy = $params ['idprotection']; // True/false for if ID Protection add-on is active
    $tld = $params['tld'];
    $sld = $params['sld']; // domain
    $regperiod = $params['regperiod']; // The registration term for the domain (1-10 years)
    $lang = $params['additionalfields']['IDN Language'];

    /** nameserver **/
    $nameservers = [];
    $nameservers[] = $params['ns1'];
    $nameservers[] = $params['ns2'];
    if (isset($params['ns3']) && !empty($params['ns3'])) {
        $nameservers[] = $params['ns3'];
    }
    if (isset($params['ns4']) && !empty($params['ns4'])) {
        $nameservers[] = $params['ns4'];
    }
    if (isset($params['ns5']) && !empty($params['ns5'])) {
        $nameservers[] = $params['ns5'];
    }

    /** Contact Information **/
    $cellRegionCodeRule = "/^\+[0-9]{1,4}\./";
    /**
     * Registrant Details
     */
    $RegistrantName = OnlineNICPro_Char_Convertor($params['firstname'] . ' ' . $params['lastname']);
    $RegistrantOrg = OnlineNICPro_Char_Convertor(empty($params['companyname']) ? $RegistrantName : $params['companyname']);
    $RegistrantAddress = OnlineNICPro_Char_Convertor($params['address1']) . OnlineNICPro_Char_Convertor($params['address2']);
    $RegistrantCity = OnlineNICPro_Char_Convertor($params['city']);
    $RegistrantStateProvince = OnlineNICPro_Char_Convertor($params['state']);
    $RegistrantPostalCode = $params['postcode'];
    $RegistrantCountry = $params['countrycode'];
    $RegistrantEmailAddress = $params['email'];
    $RegistrantPhonePrefix = OnlineNICPro_getcountrycallingcodes($params['countrycode']);
    $RegistrantRawPhone = OnlineNICPro_trimall(str_replace("-", "", $params['phonenumber']));
    $RegistrantPhone = preg_match($cellRegionCodeRule, $params['phonenumber']) ? $params['phonenumber'] : '+' . $RegistrantPhonePrefix . '.' . $RegistrantRawPhone;
    $RegistrantFax = $RegistrantPhone;

    /**
     * Admin Details
     */
    $AdminName = OnlineNICPro_Char_Convertor($params['adminfirstname'] . ' ' . $params['adminlastname']);
    $AdminOrg = OnlineNICPro_Char_Convertor(empty($params['admincompanyname']) ? $AdminName : $params['admincompanyname']);
    $AdminAddress = OnlineNICPro_Char_Convertor($params['adminaddress1']) . OnlineNICPro_Char_Convertor($params['adminaddress2']);
    $AdminCity = OnlineNICPro_Char_Convertor($params['admincity']);
    $AdminStateProvince = OnlineNICPro_Char_Convertor($params['adminstate']);
    $AdminPostalCode = OnlineNICPro_Char_Convertor($params['adminpostcode']);
    $AdminCountry = $params['admincountry'];
    $AdminEmailAddress = $params['adminemail'];
    $AdminPhonePrefix = OnlineNICPro_getcountrycallingcodes($params['country']);#$countrycallingcodes[$params['admincountry']];
    $AdminRawPhone = OnlineNICPro_trimall(str_replace("-", "", $params['adminphonenumber']));
    $AdminPhone = preg_match($cellRegionCodeRule, $params['adminphonenumber']) ? $params['adminphonenumber'] : '+' . $AdminPhonePrefix . '.' . $AdminRawPhone;
    $AdminFax = $AdminPhone;
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);
    $isIDN = preg_match("/^xn--/i", $domain) ? true : false;

    try {
        $checkData = $api->checkDomain($domain, 1);
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
    if ($checkData['data']['avail'] == 0) {
        return ['error' => 'Domain is already taken'];
    } else if ($checkData['data']['premium']) {
        return array('error' => 'Domain is premium');
    }


    $registrantExtInfo = array();
    switch ($tld) {
        case 'us':
            $registrantExtInfo['apppurpose'] = $api->getUsDomainApplicationPurpose($params['additionalfields']['Application Purpose']);
            $registrantExtInfo['nexuscategory'] = $params['additionalfields']['Nexus Category'];
            if (in_array($registrantExtInfo['nexuscategory'], array('C31', 'C32'))) {
                $NexusCountry = $params['additionalfields']['Nexus Country'];
                $registrantExtInfo['nexuscategory'] .= '/' . $RegistrantCountry;
            }
            break;
        case 'uk':
        case 'co.uk':
        case 'org.uk':
        case 'plc.uk':
        case 'me.uk':
            //case 'net.uk':
            //case 'ltd.uk':
            $registrantExtInfo['orgtype'] = $api->getUkDomainOrgType($params['additionalfields']['Legal Type']);
            if (in_array($registrantExtInfo['orgtype'], array('LTD', 'PLC', 'LLP', 'IP', 'SCH', 'RCHAR', 'FCORP'))) {
                $registrantExtInfo['licence'] = $params['additionalfields']['Company ID Number'];
            }
            break;
    }
    /** create 4 contacter Create Registrant ID */

    //Create Registrant ID
    try {
        $createRegistrantData = $api->createContact(
            $tld,
            $RegistrantName,
            $RegistrantOrg,
            $RegistrantCountry,
            $RegistrantStateProvince,
            $RegistrantCity,
            $RegistrantAddress,
            $RegistrantPostalCode,
            $RegistrantPhone,
            $RegistrantFax,
            $RegistrantEmailAddress,
            $registrantExtInfo);
    } catch (\Exception $e) {
        return ['error' => 'Failed to create Registrants:' . $e->getMessage()];
    }

    $registrantId = $createRegistrantData['data']['contactid'];


    if ($tld == 'eu') {
        $billingId = $techId = $adminId = '';
    } else {
        try {
            $createAdminData = $api->createContact(
                $tld,
                $AdminName,
                $AdminOrg,
                $AdminCountry,
                $AdminStateProvince,
                $AdminCity,
                $AdminAddress,
                $AdminPostalCode,
                $AdminPhone,
                $AdminFax,
                $AdminEmailAddress,
                $registrantExtInfo);
        } catch (\Exception $e) {
            return ['error' => 'Failed to create Admin Contacter:' . $e->getMessage()];
        }

        $adminId = $createAdminData['data']['contactid'];
        //$billingId = $techId = $adminId;

        //Create techId
        try {
            $createTechData = $api->createContact(
                $tld,
                $RegistrantName,
                $RegistrantOrg,
                $RegistrantCountry,
                $RegistrantStateProvince,
                $RegistrantCity,
                $RegistrantAddress,
                $RegistrantPostalCode,
                $RegistrantPhone,
                $RegistrantFax,
                $RegistrantEmailAddress,
                $registrantExtInfo);
        } catch (\Exception $e) {
            return ['error' => 'Failed to create Registrant:' . $e->getMessage()];
        }

        $techId = $createTechData['data']['contactid'];
        //Create techId

        //Create billingId
        try {
            $createBillingData = $api->createContact(
                $tld,
                $RegistrantName,
                $RegistrantOrg,
                $RegistrantCountry,
                $RegistrantStateProvince,
                $RegistrantCity,
                $RegistrantAddress,
                $RegistrantPostalCode,
                $RegistrantPhone,
                $RegistrantFax,
                $RegistrantEmailAddress,
                $registrantExtInfo);
        } catch (\Exception $e) {
            return ['error' => 'Failed to create Registrant:' . $e->getMessage()];
        }

        $billingId = $createBillingData['data']['contactid'];
        //Create billingId
    }


    if ($tld == 'asia') {
        $ced = $registrantId;
    }

    /** create domain **/
    switch ($tld) {
        case 'tel':
            $telWhoisTypeConfig = array('Natural Person' => 'natural', 'Legal Person' => 'legal');
            $whoistype = $telWhoisTypeConfig[$params['additionalfields']['Legal Type']];
            $publish = $params['additionalfields']['WHOIS Opt-out'] == 'on' ? 'n' : 'y';
            break;
    }

    //Create Domain

    try {
        $createDomainRs = $api->registerDomain(
            $domain,
            $regperiod,
            $nameservers,
            $registrantId,
            $adminId,
            $techId,
            $billingId,
            $lang);
    } catch (\Exception $e) {
        return ['error' => 'Failed to register Domain:' . $e->getMessage()];
    }

    if ($privacy) {
        #check Domain status
        try {
            $api->appIDShield(
                $sld . '.' . $tld,
                $regperiod,
                'N',
                $regperiod);
        } catch (\Exception $e) {
            return ['error' => 'Success to register Domain but Failed to register IdShield:' . $e->getMessage()];
        }
    }

    return ['success' => true];
}

function OnlineNICPro_TransferDomain($params)
{
# Create contact ID
# Registrant Details
    $cellRegionCodeRule = "/^\+[0-9]{1,4}\./";
    $RegistrantName = OnlineNICPro_Char_Convertor($params['firstname'] . ' ' . $params['lastname']);
    $RegistrantOrg = OnlineNICPro_Char_Convertor(empty($params['companyname']) ? $RegistrantName : $params['companyname']);
    $RegistrantAddress = OnlineNICPro_Char_Convertor($params['address1']) . OnlineNICPro_Char_Convertor($params['address2']);
    $RegistrantCity = OnlineNICPro_Char_Convertor($params['city']);
    $RegistrantStateProvince = OnlineNICPro_Char_Convertor($params['state']);
    $RegistrantPostalCode = $params['postcode'];
    $RegistrantCountry = $params['country'];
    $RegistrantEmailAddress = $params['email'];
    $RegistrantPhonePrefix = OnlineNICPro_getcountrycallingcodes($params['country']);
    $RegistrantRawPhone = OnlineNICPro_trimall(str_replace("-", "", $params['phonenumber']));
    $RegistrantPhone = preg_match($cellRegionCodeRule, $params['phonenumber']) ? $params['phonenumber'] : '+' . $RegistrantPhonePrefix . '.' . $RegistrantRawPhone;
    $RegistrantFax = $RegistrantPhone;
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    $registrantExtInfo = array();
    switch ($tld) {
        case 'us':
            $registrantExtInfo['apppurpose'] = $api->getUsDomainApplicationPurpose($params['additionalfields']['Application Purpose']);
            $registrantExtInfo['nexuscategory'] = $params['additionalfields']['Nexus Category'];
            if (in_array($registrantExtInfo['nexuscategory'], array('C31', 'C32'))) {
                $NexusCountry = $params['additionalfields']['Nexus Country'];
                $registrantExtInfo['nexuscategory'] .= '/' . $RegistrantCountry;
            }
            break;
        case 'uk':
        case 'co.uk':
        case 'org.uk':
        case 'plc.uk':
        case 'me.uk':
            //case 'net.uk':
            //case 'ltd.uk':
            $registrantExtInfo['orgtype'] = $api->getUkDomainOrgType($params['additionalfields']['Legal Type']);
            if (in_array($registrantExtInfo['orgtype'], array('LTD', 'PLC', 'LLP', 'IP', 'SCH', 'RCHAR', 'FCORP'))) {
                $registrantExtInfo['licence'] = $params['additionalfields']['Company ID Number'];
            }
            break;
    }
    try {
        $rscontactInfo = $api->createContact(
            $tld,
            $RegistrantName,
            $RegistrantOrg,
            $RegistrantCountry,
            $RegistrantStateProvince,
            $RegistrantCity,
            $RegistrantAddress,
            $RegistrantPostalCode,
            $RegistrantPhone,
            $RegistrantFax,
            $RegistrantEmailAddress,
            $registrantExtInfo);
    } catch (\Exception $e) {
        return ['error' => 'Failed to create Registrants:' . $e->getMessage()];
    }

    $contactID = $rscontactInfo['data']['contactid'];

    try {
        $transferRs = $api->transferDomain(
            $domain,
            $params['eppcode'],
            $contactID);
    } catch (\Exception $e) {
        return ['error' => 'Failed to transfer Domain:' . $e->getMessage()];
    }

    return ['success' => true];

}

function OnlineNICPro_RenewDomain($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $regperiod = $params['regperiod'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $renewRs = $api->renewDomain(
            $domain,
            $regperiod);
    } catch (\Exception $e) {
        return ['error' => 'Failed to Renew Domain:' . $e->getMessage()];
    }


    return ['success' => true];
}

function OnlineNICPro_GetNameservers($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $infoRs = $api->infoDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Domain DNS:' . $e->getMessage()];
    }


    return [
        'success' => true,
        'ns1' => array_key_exists('dns1', $infoRs['data']) ? $infoRs['data']['dns1'] : '',
        'ns2' => array_key_exists('dns2', $infoRs['data']) ? $infoRs['data']['dns2'] : '',
        'ns3' => array_key_exists('dns3', $infoRs['data']) ? $infoRs['data']['dns3'] : '',
        'ns4' => array_key_exists('dns4', $infoRs['data']) ? $infoRs['data']['dns4'] : '',
        'ns5' => array_key_exists('dns5', $infoRs['data']) ? $infoRs['data']['dns5'] : '',
        'ns6' => array_key_exists('dns6', $infoRs['data']) ? $infoRs['data']['dns6'] : ''

    ];

}

function OnlineNICPro_SaveNameservers($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $nameserver1 = $params['ns1'];
    $nameserver2 = $params['ns2'];
    $nameserver3 = $params['ns3'];
    $nameserver4 = $params['ns4'];
    $nameserver5 = $params['ns5'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $updateRs = $api->updateDomainDns(
            $domain,
            $nameserver1,
            $nameserver2,
            $nameserver3,
            $nameserver4,
            $nameserver5,
            '');
    } catch (\Exception $e) {
        return ['error' => 'Failed to update Domain DNS:' . $e->getMessage()];
    }

    return ['success' => true];
}

function OnlineNICPro_GetRegistrarLock($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $infoRs = $api->infoDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to update Domain DNS:' . $e->getMessage()];
    }
    if (in_array($infoRs['data']['status'], ['clientTransferProhibited', 'clientUpdateProhibited', 'clientDeleteProhibited', 'clientLock'])) {
        $lockstatus = 'locked';
    } else {
        $lockstatus = 'unlocked';
    }
    return $lockstatus;
}

function OnlineNICPro_SaveRegistrarLock($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);
    if ($params['lockenabled'] == "locked") {
        $ctp = "Y";
    } else {
        $ctp = "N";
    }

    try {
        $infoRs = $api->updateDomainStatus(
            $domain,
            $ctp);
    } catch (\Exception $e) {
        return ['error' => 'Failed to update Domain Status:' . $e->getMessage()];
    }


    return ['success' => true];
}

function OnlineNICPro_GetContactDetails($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $infoRs = $api->infoDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Domain Info:' . $e->getMessage()];
    }

    $registrant = $infoRs['data']['registrant'];
    try {
        $registrantDetail = $api->infoContact(
            $tld,
            $registrant);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Registrant Detail:' . $e->getMessage()];
    }

    $admin = $infoRs['data']['admin'];
    try {
        $adminDetail = $api->infoContact(
            $tld,
            $admin);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Admin Detail:' . $e->getMessage()];
    }

    $tech = $infoRs['data']['tech'];
    try {
        $techDetail = $api->infoContact(
            $tld,
            $tech);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Technical Detail:' . $e->getMessage()];
    }

    $billing = $infoRs['data']['billing'];
    try {
        $billingDetail = $api->infoContact(
            $tld,
            $billing);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Billing Detail:' . $e->getMessage()];
    }

    $registrantName = explode(' ', $registrantDetail['data']['name']);
    $techName = explode(' ', $techDetail['data']['name']);
    $adminName = explode(' ', $adminDetail['data']['name']);
    $billingNme = explode(' ', $billingDetail['data']['name']);
    return [
        'Registrant' => [
            'First Name' => $registrantName[0],
            'Last Name' => $registrantName[1],
            'Company Name' => $registrantDetail['data']['org'],
            'Email Address' => $registrantDetail['data']['email'],
            'Address 1' => $registrantDetail['data']['street'],
            'Address 2' => '',
            'City' => $registrantDetail['data']['city'],
            'State' => $registrantDetail['data']['province'],
            'Postcode' => $registrantDetail['data']['postalcode'],
            'Country' => $registrantDetail['data']['country'],
            'Phone Number' => $registrantDetail['data']['voice'],
            'Fax Number' => $registrantDetail['data']['fax'],
        ],
        'Technical' => [
            'First Name' => $techName[0],
            'Last Name' => $techName[1],
            'Company Name' => $techDetail['data']['org'],
            'Email Address' => $techDetail['data']['email'],
            'Address 1' => $techDetail['data']['street'],
            'Address 2' => '',
            'City' => $techDetail['data']['city'],
            'State' => $techDetail['data']['province'],
            'Postcode' => $techDetail['data']['postalcode'],
            'Country' => $techDetail['data']['country'],
            'Phone Number' => $techDetail['data']['voice'],
            'Fax Number' => $techDetail['data']['fax'],
        ],
        'Billing' => [
            'First Name' => $billingNme[0],
            'Last Name' => $billingNme[1],
            'Company Name' => $billingDetail['data']['org'],
            'Email Address' => $billingDetail['data']['email'],
            'Address 1' => $billingDetail['data']['street'],
            'Address 2' => '',
            'City' => $billingDetail['data']['city'],
            'State' => $billingDetail['data']['province'],
            'Postcode' => $billingDetail['data']['postalcode'],
            'Country' => $billingDetail['data']['country'],
            'Phone Number' => $billingDetail['data']['voice'],
            'Fax Number' => $billingDetail['data']['fax'],
        ],
        'Admin' => [
            'First Name' => $adminName[0],
            'Last Name' => $adminName[1],
            'Company Name' => $adminDetail['data']['org'],
            'Email Address' => $adminDetail['data']['email'],
            'Address 1' => $adminDetail['data']['street'],
            'Address 2' => '',
            'City' => $adminDetail['data']['city'],
            'State' => $adminDetail['data']['province'],
            'Postcode' => $adminDetail['data']['postalcode'],
            'Country' => $adminDetail['data']['country'],
            'Phone Number' => $adminDetail['data']['voice'],
            'Fax Number' => $adminDetail['data']['fax'],
        ],
    ];
}

function OnlineNICPro_SaveContactDetails($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $infoRs = $api->infoDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Domain Info:' . $e->getMessage()];
    }
    $originalContact = [
        'Registrant' => $infoRs['data']['registrant'],
        'Admin' => $infoRs['data']['admin'],
        'Technical' => $infoRs['data']['tech'],
        'Billing' => $infoRs['data']['billing'],
    ];
    $msg = true;
    foreach ($params['contactdetails'] as $k => $v) {
        try {
            $updateRs = $api->updateContact(
                $tld,
                $originalContact[$k],
                $v['First Name'] . ' ' . $v['Last Name'],
                $v['Company Name'],
                $v['Country'],
                $v['State'],
                $v['City'],
                $v['Address 1'] . ' ' . $v['Address 2'],
                $v['Postcode'],
                $v['Phone Number'],
                $v['Fax Number'],
                $v['Email Address'],
                '');
            if ($updateRs['code'] >= 2000) {
                $msg = $updateRs['msg'];
            }
        } catch (\Exception $e) {
            return ['error' => 'Failed to update ' . $k . ' Info:' . $e->getMessage()];
        }
    }

    return ['success' => $msg];
}

function OnlineNICPro_GetDNS($params)
{
    $sld = $params['sld'];
    $tld = $params['tld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $infoRs = $api->infoDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Domain Info:' . $e->getMessage()];
    }
    $dnsArr = ['dns1', 'dns2', 'dns3', 'dns4', 'dns5', 'dns6'];
    $return = [];
    foreach ($dnsArr as $k => $v) {
        if (isset($infoRs['data'][$v])) {
            try {
                $hostInfoRs = $api->infoHost(
                    $tld,
                    $infoRs['data'][$v]);
            } catch (\Exception $e) {
                return ['error' => 'Failed to get Host Info:' . $e->getMessage()];
            }
        }
        $return[] = [
            'hostname' => $hostInfoRs['data']['hostname'],
            'type' => '',
            'address' => $hostInfoRs['data']['addrs'],
            'priority' => ''
        ];
    }
    return $return;
}

function OnlineNICPro_IDProtectToggle($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $protectEnable = $params['protectenable'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $idShieldInfoRs = $api->infoIDShield(
            $domain);
    } catch (\Exception $e) {
        if ($protectEnable) {
            $api->appIDShield(
                $domain,
                1,
                'Y',
                1);
        }
    }

    try {
        if ($idShieldInfoRs['data']['state'] == 'off' && $protectEnable) {
            $api->resumeIDShield(
                $domain);
        } elseif ($idShieldInfoRs['data']['state'] == 'on' && !$protectEnable) {
            $api->suspendIDShield(
                $domain);
        }
    } catch (\Exception $e) {
        return ['error' => 'Failed to opera IdShield:' . $e->getMessage()];
    }

    return ['success' => true];
}

function OnlineNICPro_GetEPPCode($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $authCodeRs = $api->getAuthCode($domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get AuthCode:' . $e->getMessage()];
    }
    return ['success' => $authCodeRs['data']['authcode']];
}

function OnlineNICPro_RegisterNameserver($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $nameserver = $params['nameserver'];
    $ipaddress = $params['ipaddress'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $createHostRs = $api->createHost(
            $domain,
            $nameserver,
            $ipaddress);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get AuthCode:' . $e->getMessage()];
    }

    return ['success' => 'true'];
}

function OnlineNICPro_ModifyNameserver($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $nameserver = $params['nameserver'];
    $currentipaddress = $params['currentipaddress'];
    $newipaddress = $params['newipaddress'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $createHostRs = $api->updateHost(
            $domain,
            $nameserver,
            $newipaddress,
            $currentipaddress);
    } catch (\Exception $e) {
        return ['error' => 'Failed to modify DNS:' . $e->getMessage()];
    }

    return ['success' => 'true'];
}

function OnlineNICPro_DeleteNameserver($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $nameserver = $params['nameserver'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $createHostRs = $api->deleteHost(
            $tld,
            $nameserver);
    } catch (\Exception $e) {
        return ['error' => 'Failed to modify DNS:' . $e->getMessage()];
    }

    return ['success' => true];
}

function OnlineNICPro_RequestDelete($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $createHostRs = $api->deleteDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to delete Domain:' . $e->getMessage()];
    }

    return ['success' => true];
}

function OnlineNICPro_TransferSync($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $authCodeRs = $api->getAuthCode($domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get AuthCode:' . $e->getMessage()];
    }

    try {
        $statusRs = $api->queryTransferStatus(
            $domain,
            $authCodeRs['data']['authcode']);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Transfer Status:' . $e->getMessage()];
    }

    try {
        $infoRs = $api->infoDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Domain Info:' . $e->getMessage()];
    }

    if ($statusRs['data']['status'] == 'serverApproved') {
        return ['completed' => true, 'expirydate' => $infoRs['data']['expdate'], 'failed' => false];
    } elseif ($statusRs['data']['status'] == 'clientApproved') {
        return ['completed' => true, 'expirydate' => $infoRs['data']['expdate'], 'failed' => false];
    } elseif ($statusRs['data']['status'] == 'clientrejected') {
        return ['failed' => true, 'reason' => 'clientrejected', 'completed' => false];
    }

    return ['completed' => false,];
}

function OnlineNICPro_Sync($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $infoRs = $api->infoDomain(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Domain Info:' . $e->getMessage()];
    }

    try {
        $authCodeRs = $api->getAuthCode($domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get AuthCode:' . $e->getMessage()];
    }
    $transferredAway = false;
    try {
        $statusRs = $api->queryTransferStatus(
            $domain,
            $authCodeRs['data']['authcode']);
    } catch (\Exception $e) {
        return ['error' => 'Failed to get transfer status:' . $e->getMessage()];
    }

    $expirydate = $infoRs['data']['expdate'];
    $expired = true;
    if (strtotime($expirydate) > time()) {
        $expired = false;
    }
    $active = false;
    if (in_array('ok', $infoRs['data']['status'])) {
        $active = true;
    }
    if ($statusRs['data']['status'] == 'serverApproved') {
        $transferredAway = true;
    } elseif ($statusRs['data']['status'] == 'clientApproved') {
        $transferredAway = true;
    }

    return [
        'expirydate' => $expirydate,
        'active' => $active,
        'expired' => $expired,
        'transferredAway' => $transferredAway
    ];
}

function OnlineNICPro_ResendIRTPVerificationEmail($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $sendRs = $api->resendWhoisVF(
            $domain);
    } catch (\Exception $e) {
        return ['error' => 'Failed to resend Whois Verification Email:' . $e->getMessage()];
    }

    return ['success' => true];
}

function OnlineNICPro_GetDomainInformation($params)
{
    $tld = $params['tld'];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . '.' . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    try {
        $domainInfo = $api->infoDomain(
            $domain,
            'IRTP');
    } catch (\Exception $e) {
        return ['error' => 'Failed to get Domain Info:' . $e->getMessage()];
    }
    $values = [];

    $values['setIrtpTransferLock'] = false;
    if (isset($domainInfo['isTransferLock'])) {
        $values['setIrtpTransferLock'] = $domainInfo['isTransferLock'];
    }

    $values['setDomainContactChangePending'] = false;
    if (isset($domainInfo['isChangeRegistrant'])) {
        $values['setDomainContactChangePending'] = $domainInfo['isChangeRegistrant'];
    }

    $values['setPendingSuspension'] = false;
    if (isset($domainInfo['isPendingSuspension'])) {
        $values['setPendingSuspension'] = $domainInfo['isPendingSuspension'];
    }

    if (isset($domainInfo['domainContactChangeExpiryDate'])) {
        $values['setDomainContactChangeExpiryDate'] = $domainInfo['domainContactChangeExpiryDate'];
    }

    return (new \WHMCS\Domain\Registrar\Domain)
        ->setIsIrtpEnabled(true)
        ->setIrtpTransferLock($values['setIrtpTransferLock'])
        ->setDomainContactChangePending($values['setDomainContactChangePending'])
        ->setPendingSuspension($values['setPendingSuspension'])
        ->setDomainContactChangeExpiryDate($values['setDomainContactChangeExpiryDate'] ? \WHMCS\Carbon::createFromFormat('!Y-m-d', $values['setDomainContactChangeExpiryDate']) : null)
        ->setIrtpVerificationTriggerFields(
            [
                'Registrant' => [
                    'First Name',
                    'Last Name',
                    'Organization Name',
                    'Email',
                ],
            ]
        );
}

function OnlineNICPro_CheckAvailability($params)
{
    $tld = $params['tlds'][0];
    $sld = $params['sld'];
    $api = OnlineNICPro_getApiClient($params);
    $domain = $sld . $tld;
    $domain = OnlineNICPro_Idn::encode($domain);

    $result = new \WHMCS\Domains\DomainLookup\ResultsList();
    $searchResult = new \WHMCS\Domains\DomainLookup\SearchResult($sld, $tld);

    try {
        $checkRs = $api->checkDomain(
            $domain, 1);
        if ($checkRs['data']['avail'] == 0) {
            $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_REGISTERED);
        } else {
            $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_NOT_REGISTERED);
        }

        if ($checkRs['data']['premium']) {
            $searchResult->setPremiumDomain(true);
            $searchResult->isAvailableForPurchase(true);
        }

    } catch (\Exception $e) {
        $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_REGISTERED);
    }
    $result->append($searchResult);


    return $result;
}


function OnlineNICPro_GetDomainSuggestions($params)
{
    $api = OnlineNICPro_getApiClient($params);
    // user defined configuration values
    $userIdentifier = $params['API Username'];
    $apiKey = $params['API Key'];
    $testMode = $params['Test Mode'];
    $accountMode = $params['Account Mode'];
    $emailPreference = $params['Email Preference'];
    $additionalInfo = $params['Additional Information'];

    // availability check parameters
    $searchTerm = $params['searchTerm'];
    $punyCodeSearchTerm = $params['punyCodeSearchTerm'];
    $tldsToInclude = $params['tldsToInclude'];
    $isIdnDomain = (bool) $params['isIdnDomain'];
    $premiumEnabled = (bool) $params['premiumEnabled'];
    $suggestionSettings = $params['suggestionSettings'];

    // Build post data
    $postfields = array(
        'username' => $userIdentifier,
        'password' => $apiKey,
        'testmode' => $testMode,
        'domain' => $sld . '.' . $tld,
        'searchTerm' => $searchTerm,
        'tldsToSearch' => $tldsToInclude,
        'includePremiumDomains' => $premiumEnabled,
        'includeCCTlds' => $suggestionSettings['includeCCTlds'],
    );

    try {
        $api = new ApiClient();
        $api->call('GetSuggestions', $postfields);

        $results = new ResultsList();
        foreach ($api->getFromResponse('domains') as $domain) {

            // Instantiate a new domain search result object
            $searchResult = new SearchResult($domain['sld'], $domain['tld']);

            // All domain suggestions should be available to register
            $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);

            // Used to weight results by relevance
            $searchResult->setScore($domain['score']);

            // Return premium information if applicable
            if ($domain['isPremiumName']) {
                $searchResult->setPremiumDomain(true);
                $searchResult->setPremiumCostPricing(
                    array(
                        'register' => $domain['premiumRegistrationPrice'],
                        'renew' => $domain['premiumRenewPrice'],
                        'CurrencyCode' => 'USD',
                    )
                );
            }

            // Append to the search results list
            $results->append($searchResult);
        }

        return $results;

    } catch (\Exception $e) {
        return array(
            'error' => $e->getMessage(),
        );
    }
}


/************************** Tools *********************************/

function OnlineNICPro_Char_Convertor($str)
{

    $str = preg_replace("/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/", "a", $str);
    $str = preg_replace("/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/", "e", $str);
    $str = preg_replace("/(ì|í|ị|ỉ|ĩ)/", "i", $str);
    $str = preg_replace("/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/", "o", $str);
    $str = preg_replace("/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/", "u", $str);
    $str = preg_replace("/(ỳ|ý|ỵ|ỷ|ỹ)/", "y", $str);
    $str = preg_replace("/(đ)/", "d", $str);
    $str = preg_replace("/(À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ)/", "A", $str);
    $str = preg_replace("/(È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ)/", "E", $str);
    $str = preg_replace("/(Ì|Í|Ị|Ỉ|Ĩ)/", "I", $str);
    $str = preg_replace("/(Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ)/", "O", $str);
    $str = preg_replace("/(Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ)/", "U", $str);
    $str = preg_replace("/(Ỳ|Ý|Ỵ|Ỷ|Ỹ)/", "Y", $str);
    #Turkish
    $str = preg_replace("/(Đ)/", "D", $str);
    $str = preg_replace("/(ı)/", "i", $str);
    $str = preg_replace("/(ğ)/", "g", $str);
    $str = preg_replace("/(ü)/", "u", $str);
    $str = preg_replace("/(ş)/", "s", $str);
    $str = preg_replace("/(ö)/", "o", $str);
    $str = preg_replace("/(ç)/", "c", $str);
    $str = preg_replace("/(Ğ)/", "G", $str);
    $str = preg_replace("/(Ü)/", "U", $str);
    $str = preg_replace("/(Ş)/", "S", $str);
    $str = preg_replace("/(İ)/", "I", $str);
    $str = preg_replace("/(Ö)/", "O", $str);
    $str = preg_replace("/(Ç)/", "C", $str);
    return $str;
}

function OnlineNICPro_getcountrycallingcodes($tt)
{

    switch ($tt) {

        case  'AF' :
            $countrycode = 93;
            break; //Afghanistan
        case  'AX' :
            $countrycode = 358;
            break; //Aland Islands (Finland)
        case  'AL' :
            $countrycode = 355;
            break; //Albania
        case  'DZ' :
            $countrycode = 213;
            break; //Algeria
        case  'AS' :
            $countrycode = 684;
            break; //American Samoa
        case  'AD' :
            $countrycode = 376;
            break; //Andorra
        case  'AO' :
            $countrycode = 244;
            break; //Angola
        case  'AI' :
            $countrycode = 1;
            break; //Anguilla (1 264)
        case  'AQ' :
            $countrycode = 672;
            break; //Antarctica
        case  'AG' :
            $countrycode = 1;
            break; //Antigua And Barbuda
        case  'AR' :
            $countrycode = 54;
            break; //Argentina
        case  'AM' :
            $countrycode = 374;
            break; //Armenia
        case  'AW' :
            $countrycode = 297;
            break; //Aruba
        case  'AU' :
            $countrycode = 61;
            break; //Australia
        case  'AT' :
            $countrycode = 43;
            break; //Austria
        case  'AZ' :
            $countrycode = 994;
            break; //Azerbaijan
        case  'BS' :
            $countrycode = 1;
            break; //Bahamas
        case  'BH' :
            $countrycode = 973;
            break; //Bahrain
        case  'BD' :
            $countrycode = 880;
            break; //Bangladesh
        case  'BB' :
            $countrycode = 1;
            break; //Barbados
        case  'BY' :
            $countrycode = 375;
            break; //Belarus
        case  'BE' :
            $countrycode = 32;
            break; //Belgium
        case  'BZ' :
            $countrycode = 501;
            break; //Belize
        case  'BJ' :
            $countrycode = 229;
            break; //Benin
        case  'BM' :
            $countrycode = 1;
            break; //Bermuda
        case  'BT' :
            $countrycode = 975;
            break; //Bhutan
        case  'BO' :
            $countrycode = 591;
            break; //Bolivia
        case  'BA' :
            $countrycode = 387;
            break; //Bosnia And Herzegovina
        case  'BW' :
            $countrycode = 267;
            break; //Botswana
        case  'BV' :
            $countrycode = 47;
            break; //Bouvet Island (Norway)
        case  'BR' :
            $countrycode = 55;
            break; //Brazil
        case  'IO' :
            $countrycode = 246;
            break; //British Indian Ocean Territory
        case  'BN' :
            $countrycode = 673;
            break; //Brunei Darussalam
        case  'BG' :
            $countrycode = 359;
            break; //Bulgaria
        case  'BF' :
            $countrycode = 226;
            break; //Burkina Faso
        case  'BI' :
            $countrycode = 257;
            break; //Burundi
        case  'KH' :
            $countrycode = 855;
            break; //Cambodia
        case  'CM' :
            $countrycode = 237;
            break; //Cameroon
        case  'CA' :
            $countrycode = 1;
            break; //Canada
        case  'CV' :
            $countrycode = 238;
            break; //Cape Verde
        case  'KY' :
            $countrycode = 1;
            break; //Cayman Islands
        case  'CF' :
            $countrycode = 236;
            break; //Central African Republic
        case  'TD' :
            $countrycode = 235;
            break; //Chad
        case  'CL' :
            $countrycode = 56;
            break; //Chile
        case  'CN' :
            $countrycode = 86;
            break; //China
        case  'CX' :
            $countrycode = 618;
            break; //Christmas Island
        case  'CC' :
            $countrycode = 61;
            break; //Cocos (Keeling) Islands
        case  'CO' :
            $countrycode = 57;
            break; //Colombia
        case  'KM' :
            $countrycode = 269;
            break; //Comoros
        case  'CG' :
            $countrycode = 242;
            break; //Congo
        case  'CD' :
            $countrycode = 243;
            break; //Congo; break; Democratic Republic
        case  'CK' :
            $countrycode = 682;
            break; //Cook Islands
        case  'CR' :
            $countrycode = 506;
            break; //Costa Rica
        case  'CI' :
            $countrycode = 225;
            break; //Ivory Coast
        case  'HR' :
            $countrycode = 385;
            break; //Croatia
        case  'CU' :
            $countrycode = 53;
            break; //Cuba
        case  'CY' :
            $countrycode = 357;
            break; //Cyprus
        case  'CZ' :
            $countrycode = 420;
            break; //Czech Republic
        case  'DK' :
            $countrycode = 45;
            break; //Denmark
        case  'DJ' :
            $countrycode = 253;
            break; //Djibouti
        case  'DM' :
            $countrycode = 1;
            break; //Dominica
        case  'DO' :
            $countrycode = 1;
            break; //Dominican Republic
        case  'EC' :
            $countrycode = 593;
            break; //Ecuador
        case  'EG' :
            $countrycode = 20;
            break; //Egypt
        case  'SV' :
            $countrycode = 503;
            break; //El Salvador
        case  'GQ' :
            $countrycode = 240;
            break; //Equatorial Guinea
        case  'ER' :
            $countrycode = 291;
            break; //Eritrea
        case  'EE' :
            $countrycode = 372;
            break; //Estonia
        case  'ET' :
            $countrycode = 251;
            break; //Ethiopia
        case  'FK' :
            $countrycode = 500;
            break; //Falkland Islands (Malvinas)
        case  'FO' :
            $countrycode = 298;
            break; //Faroe Islands
        case  'FJ' :
            $countrycode = 679;
            break; //Fiji
        case  'FI' :
            $countrycode = 358;
            break; //Finland
        case  'FR' :
            $countrycode = 33;
            break; //France
        case  'GF' :
            $countrycode = 594;
            break; //French Guiana
        case  'PF' :
            $countrycode = 689;
            break; //French Polynesia
        case  'TF' :
            $countrycode = false;
            break; //French Southern Territories
        case  'GA' :
            $countrycode = 241;
            break; //Gabon
        case  'GM' :
            $countrycode = 220;
            break; //Gambia
        case  'GE' :
            $countrycode = 995;
            break; //Georgia
        case  'DE' :
            $countrycode = 49;
            break; //Germany
        case  'GH' :
            $countrycode = 233;
            break; //Ghana
        case  'GI' :
            $countrycode = 350;
            break; //Gibraltar
        case  'GR' :
            $countrycode = 30;
            break; //Greece
        case  'GL' :
            $countrycode = 299;
            break; //Greenland
        case  'GD' :
            $countrycode = 1;
            break; //Grenada
        case  'GP' :
            $countrycode = 590;
            break; //Guadeloupe
        case  'GU' :
            $countrycode = 1;
            break; //Guam
        case  'GT' :
            $countrycode = 502;
            break; //Guatemala
        case  'GG' :
            $countrycode = 44;
            break; //Guernsey
        case  'GN' :
            $countrycode = 224;
            break; //Guinea
        case  'GW' :
            $countrycode = 245;
            break; //Guinea-Bissau
        case  'GY' :
            $countrycode = 592;
            break; //Guyana
        case  'HT' :
            $countrycode = 509;
            break; //Haiti
        case  'HM' :
            $countrycode = false;
            break; //Heard Island & Mcdonald Islands
        case  'VA' :
            $countrycode = 39;
            break; //Holy See (Vatican City State)
        case  'HN' :
            $countrycode = 504;
            break; //Honduras
        case  'HK' :
            $countrycode = 852;
            break; //Hong Kong
        case  'HU' :
            $countrycode = 36;
            break; //Hungary
        case  'IS' :
            $countrycode = 354;
            break; //Iceland
        case  'IN' :
            $countrycode = 91;
            break; //India
        case  'ID' :
            $countrycode = 62;
            break; //Indonesia
        case  'IR' :
            $countrycode = 98;
            break; //Iran; break; Islamic Republic Of
        case  'IQ' :
            $countrycode = 964;
            break; //Iraq
        case  'IE' :
            $countrycode = 353;
            break; //Ireland
        case  'IM' :
            $countrycode = 44;
            break; //Isle Of Man
        case  'IL' :
            $countrycode = 972;
            break; //Israel
        case  'IT' :
            $countrycode = 39;
            break; //Italy
        case  'JM' :
            $countrycode = 1;
            break; //Jamaica
        case  'JP' :
            $countrycode = 81;
            break; //Japan
        case  'JE' :
            $countrycode = 44;
            break; //Jersey
        case  'JO' :
            $countrycode = 962;
            break; //Jordan
        case  'KZ' :
            $countrycode = 7;
            break; //Kazakhstan
        case  'KE' :
            $countrycode = 254;
            break; //Kenya
        case  'KI' :
            $countrycode = 686;
            break; //Kiribati
        case  'KR' :
            $countrycode = 82;
            break; //Korea; break; Democratic Republic Of
        case  'KW' :
            $countrycode = 965;
            break; //Kuwait
        case  'KG' :
            $countrycode = false;
            break; // 996; break; //Kyrgyzstan
        case  'LA' :
            $countrycode = 856;
            break; //Lao People's Democratic Republic
        case  'LV' :
            $countrycode = 371;
            break; //Latvia
        case  'LB' :
            $countrycode = 961;
            break; //Lebanon
        case  'LS' :
            $countrycode = 266;
            break; //Lesotho
        case  'LR' :
            $countrycode = 231;
            break; //Liberia
        case  'LY' :
            $countrycode = 218;
            break; //Libyan Arab Jamahiriya
        case  'LI' :
            $countrycode = 423;
            break; //Liechtenstein
        case  'LT' :
            $countrycode = 370;
            break; //Lithuania
        case  'LU' :
            $countrycode = 352;
            break; //Luxembourg
        case  'MO' :
            $countrycode = 853;
            break; //Macao
        case  'MK' :
            $countrycode = 389;
            break; //Macedonia; break; Former Yugoslav Rep.
        case  'MG' :
            $countrycode = 261;
            break; //Madagascar
        case  'MW' :
            $countrycode = 265;
            break; //Malawi
        case  'MY' :
            $countrycode = 60;
            break; //Malaysia
        case  'MV' :
            $countrycode = 960;
            break; //Maldives
        case  'ML' :
            $countrycode = 223;
            break; //Mali
        case  'MT' :
            $countrycode = 356;
            break; //Malta
        case  'MH' :
            $countrycode = 692;
            break; //Marshall Islands
        case  'MQ' :
            $countrycode = 596;
            break; //Martinique
        case  'MR' :
            $countrycode = 222;
            break; //Mauritania
        case  'MU' :
            $countrycode = 230;
            break; //Mauritius
        case  'YT' :
            $countrycode = 269;
            break; //Mayotte
        case  'MX' :
            $countrycode = 52;
            break; //Mexico
        case  'FM' :
            $countrycode = false;
            break; //691; break; //Micronesia; break; Federated States Of
        case  'MD' :
            $countrycode = 373;
            break; //Moldova; break; Republic Of
        case  'MC' :
            $countrycode = 377;
            break; //Monaco
        case  'MN' :
            $countrycode = 976;
            break; //Mongolia
        case  'ME' :
            $countrycode = false;
            break; //382; break; //Montenegro
        case  'MS' :
            $countrycode = false;
            break; //1664; break; //Montserrat
        case  'MA' :
            $countrycode = 212;
            break; //Morocco
        case  'MZ' :
            $countrycode = 258;
            break; //Mozambique
        case  'MM' :
            $countrycode = 95;
            break; //Myanmar
        case  'NA' :
            $countrycode = 264;
            break; //Namibia
        case  'NR' :
            $countrycode = 674;
            break; //Nauru
        case  'NP' :
            $countrycode = 977;
            break; //Nepal
        case  'NL' :
            $countrycode = 31;
            break; //Netherlands
        case  'AN' :
            $countrycode = 599;
            break; //Netherlands Antilles
        case  'NC' :
            $countrycode = 687;
            break; //New Caledonia
        case  'NZ' :
            $countrycode = 64;
            break; //New Zealand
        case  'NI' :
            $countrycode = 505;
            break; //Nicaragua
        case  'NE' :
            $countrycode = 227;
            break; //Niger
        case  'NG' :
            $countrycode = 234;
            break; //Nigeria
        case  'NU' :
            $countrycode = 683;
            break; //Niue
        case  'NF' :
            $countrycode = false;
            break; //6723; break; //Norfolk Island
        case  'MP' :
            $countrycode = false;
            break; //1670; break; //Northern Mariana Islands
        case  'NO' :
            $countrycode = 47;
            break; //Norway
        case  'OM' :
            $countrycode = 968;
            break; //Oman
        case  'PK' :
            $countrycode = 92;
            break; //Pakistan
        case  'PW' :
            $countrycode = 680;
            break; //Palau
        case  'PS' :
            $countrycode = false;
            break; //970; break; //Palestinian Territory; break; Occupied
        case  'PA' :
            $countrycode = 507;
            break; //Panama
        case  'PG' :
            $countrycode = 675;
            break; //Papua New Guinea
        case  'PY' :
            $countrycode = 595;
            break; //Paraguay
        case  'PE' :
            $countrycode = 51;
            break; //Peru
        case  'PH' :
            $countrycode = 63;
            break; //Philippines
        case  'PN' :
            $countrycode = 64;
            break; //Pitcairn
        case  'PL' :
            $countrycode = 48;
            break; //Poland
        case  'PT' :
            $countrycode = 351;
            break; //Portugal
        case  'PR' :
            $countrycode = 1;
            break; //Puerto Rico
        case  'QA' :
            $countrycode = 974;
            break; //Qatar
        case  'RE' :
            $countrycode = 262;
            break; //Reunion
        case  'RO' :
            $countrycode = 40;
            break; //Romania
        case  'RU' :
            $countrycode = 7;
            break; //Russian Federation
        case  'RW' :
            $countrycode = 250;
            break; //Rwanda
        case  'BL' :
            $countrycode = false;
            break; //Saint Barthelemy
        case  'SH' :
            $countrycode = 290;
            break; //Saint Helena
        case  'KN' :
            $countrycode = false;
            break; //1869; break; //Saint Kitts And Nevis
        case  'LC' :
            $countrycode = 1;
            break; //Saint Lucia
        case  'MF' :
            $countrycode = false;
            break; //Saint Martin
        case  'PM' :
            $countrycode = 508;
            break; //Saint Pierre And Miquelon
        case  'VC' :
            $countrycode = 1;
            break; //Saint Vincent And Grenadines
        case  'WS' :
            $countrycode = 685;
            break; //Samoa
        case  'SM' :
            $countrycode = 378;
            break; //San Marino
        case  'ST' :
            $countrycode = 239;
            break; //Sao Tome And Principe
        case  'SA' :
            $countrycode = 966;
            break; //Saudi Arabia
        case  'SN' :
            $countrycode = 221;
            break; //Senegal
        case  'RS' :
            $countrycode = 381;
            break; //Serbia
        case  'SC' :
            $countrycode = 248;
            break; //Seychelles
        case  'SL' :
            $countrycode = 232;
            break; //Sierra Leone
        case  'SG' :
            $countrycode = 65;
            break; //Singapore
        case  'SK' :
            $countrycode = 421;
            break; //Slovakia
        case  'SI' :
            $countrycode = 386;
            break; //Slovenia
        case  'SB' :
            $countrycode = 677;
            break; //Solomon Islands
        case  'SO' :
            $countrycode = 252;
            break; //Somalia
        case  'ZA' :
            $countrycode = 27;
            break; //South Africa
        case  'GS' :
            $countrycode = false;
            break; //South Georgia And Sandwich Isl.
        case  'ES' :
            $countrycode = 34;
            break; //Spain
        case  'LK' :
            $countrycode = 94;
            break; //Sri Lanka
        case  'SD' :
            $countrycode = 249;
            break; //Sudan
        case  'SR' :
            $countrycode = 597;
            break; //Suriname
        case  'SJ' :
            $countrycode = 47;
            break; //Svalbard And Jan Mayen
        case  'SZ' :
            $countrycode = 268;
            break; //Swaziland
        case  'SE' :
            $countrycode = 46;
            break; //Sweden
        case  'CH' :
            $countrycode = 41;
            break; //Switzerland
        case  'SY' :
            $countrycode = 963;
            break; //Syrian Arab Republic
        case  'TW' :
            $countrycode = 886;
            break; //Taiwan; break; Province Of China
        case  'TJ' :
            $countrycode = 992;
            break; //Tajikistan
        case  'TZ' :
            $countrycode = 255;
            break; //Tanzania; break; United Republic Of
        case  'TH' :
            $countrycode = 66;
            break; //Thailand
        case  'TL' :
            $countrycode = 670;
            break; //Timor-Leste
        case  'TG' :
            $countrycode = 228;
            break; //Togo
        case  'TK' :
            $countrycode = 690;
            break; //Tokelau
        case  'TO' :
            $countrycode = 676;
            break; //Tonga
        case  'TT' :
            $countrycode = false;
            break; //Trinidad And Tobago
        case  'TN' :
            $countrycode = 216;
            break; //Tunisia
        case  'TR' :
            $countrycode = 90;
            break; //Turkey
        case  'TM' :
            $countrycode = 993;
            break; //Turkmenistan
        case  'TC' :
            $countrycode = false;
            break; //1649; break; //Turks And Caicos Islands
        case  'TV' :
            $countrycode = 688;
            break; //Tuvalu
        case  'UG' :
            $countrycode = 256;
            break; //Uganda
        case  'UA' :
            $countrycode = 380;
            break; //Ukraine
        case  'AE' :
            $countrycode = 971;
            break; //United Arab Emirates
        case  'GB' :
            $countrycode = 44;
            break; //United Kingdom
        case  'US' :
            $countrycode = 1;
            break; //United States
        case  'UM' :
            $countrycode = false;
            break; //United States Outlying Islands
        case  'UY' :
            $countrycode = 598;
            break; //Uruguay
        case  'UZ' :
            $countrycode = 998;
            break; //Uzbekistan
        case  'VU' :
            $countrycode = 678;
            break; //Vanuatu
        case  'VE' :
            $countrycode = 58;
            break; //Venezuela
        case  'VN' :
            $countrycode = 84;
            break; //Viet Nam
        case  'VG' :
            $countrycode = false;
            break; //Virgin Islands; break; British
        case  'VI' :
            $countrycode = false;
            break; //Virgin Islands; break; U.S.
        case  'WF' :
            $countrycode = 681;
            break; //Wallis And Futuna
        case  'EH' :
            $countrycode = 212;
            break; //Western Sahara
        case  'YE' :
            $countrycode = 967;
            break; //Yemen
        case  'ZM' :
            $countrycode = 260;
            break; //Zambia
        case  'ZW' :
            $countrycode = 263;
            break; //Zimbabwe
    }
    return $countrycode;
}

function OnlineNICPro_trimall($str)//delete blank
{
    $qian = array(" ", "  ", "\t", "\n", "\r");
    $hou = array("", "", "", "", "");
    return str_replace($qian, $hou, $str);
}

?>