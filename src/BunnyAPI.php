<?php

namespace Corbpie\BunnyCdn;

use Corbpie\BunnyCdn\BunnyAPIException;

/**
 * Bunny CDN pull & storage zone API class
 * @version  1.5
 * @author corbpie
 */
class BunnyAPI
{
    private const API_KEY = 'XXXX-XXXX-XXXX';//BunnyCDN API key
    private const API_URL = 'https://bunnycdn.com/api/';//URL for BunnyCDN API
    private const STORAGE_API_URL = 'https://storage.bunnycdn.com/';//URL for storage zone replication region (LA|NY|SG|SYD) Falkenstein is as default
    private const VIDEO_STREAM_URL = 'http://video.bunnycdn.com/';//URL for Bunny video stream API
    private const HOSTNAME = 'storage.bunnycdn.com';//FTP hostname
    private const STREAM_LIBRARY_ACCESS_KEY = 'XXXX-XXXX-XXXX';
    private string $api_key;
    private string $access_key;
    private string $storage_name;
    private $connection;
    private array $data;
    private int $stream_library_id;
    private string $stream_collection_guid = '';
    private string $stream_video_guid = '';

    public function __construct(int $execution_time = 240, bool $json_header = false)
    {
        try {
            if (!$this->constApiKeySet()) {
                throw new BunnyAPIException("You must provide an API key");
            } else {
                $this->api_key = self::API_KEY;
            }
        } catch (BunnyAPIException $e) {//display error message
            echo $e->errorMessage();
        }
        ini_set('max_execution_time', $execution_time);
        if ($json_header) {
            header('Content-Type: application/json');
        }
    }

    public function apiKey(string $api_key = '')
    {
        try {
            if (!isset($api_key) || trim($api_key) === '') {
                throw new BunnyAPIException('$api_key cannot be empty');
            } else {
                $this->api_key = $api_key;
            }
        } catch (BunnyAPIException $e) {//display error message
            echo $e->errorMessage();
        }
    }

    public function zoneConnect(string $storage_name, string $access_key = '')
    {
        $this->storage_name = $storage_name;
        (empty($access_key)) ? $this->findStorageZoneAccessKey($storage_name) : $this->access_key = $access_key;
        $conn_id = ftp_connect((self::HOSTNAME));
        $login = ftp_login($conn_id, $storage_name, $this->access_key);
        ftp_pasv($conn_id, true);
        try {
            if (!$conn_id) {
                throw new BunnyAPIException("Could not make FTP connection to " . (self::HOSTNAME) . "");
            } else {
                $this->connection = $conn_id;
            }
        } catch (BunnyAPIException $e) {//display error message
            echo $e->errorMessage();
        }
    }

    protected function findStorageZoneAccessKey(string $storage_name)
    {
        $data = $this->listStorageZones();
        foreach ($data as $zone) {
            if ($zone['Name'] === $storage_name) {
                $this->access_key = $zone['Password'];
                return true;//Found access key
            }
        }
        return false;//Never found access key for said storage zone
    }

    protected function constApiKeySet()
    {
        return !(!defined("self::API_KEY") || empty(self::API_KEY));
    }

    private function APIcall(string $method, string $url, array $params = [], bool $storage_call = false, bool $video_stream_call = false)
    {
        $curl = curl_init();
        if ($method === "POST") {
            curl_setopt($curl, CURLOPT_POST, 1);
            if (!empty($params)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
            }
        } elseif ($method === "PUT") {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_UPLOAD, 1);
            $params = json_decode(json_encode($params));
            curl_setopt($curl, CURLOPT_INFILE, fopen($params->file, "r"));
            curl_setopt($curl, CURLOPT_INFILESIZE, filesize($params->file));
        } elseif ($method === "DELETE") {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
            if (!empty($params)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
            }
        } else {//GET
            if (!empty($params)) {
                $url = sprintf("%s?%s", $url, http_build_query(json_encode($params)));
            }
        }
        if (!$storage_call && !$video_stream_call) {//General CDN pullzone
            curl_setopt($curl, CURLOPT_URL, self::API_URL . (string)$url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "AccessKey: $this->api_key"));
        } elseif ($video_stream_call) {//Video stream
            curl_setopt($curl, CURLOPT_URL, self::VIDEO_STREAM_URL . (string)$url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("AccessKey: " . self::STREAM_LIBRARY_ACCESS_KEY . ""));
        } else {//Storage zone
            curl_setopt($curl, CURLOPT_URL, self::STORAGE_API_URL . (string)$url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("AccessKey: $this->access_key"));
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
        $result = curl_exec($curl);
        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($responseCode === 200) {
            return $this->data = json_decode($result, true);
        }
        return array('http_code' => $responseCode);
    }

    public function listPullZones()
    {
        return $this->APIcall('GET', 'pullzone');
    }

    public function getPullZone(int $id)
    {
        return $this->APIcall('GET', "pullzone/$id");
    }

    public function createPullZone(string $name, string $origin, array $args = array())
    {
        $args = array_merge(
            array(
                'Name' => $name,
                'OriginUrl' => $origin,
            ),
            $args
        );
        return $this->APIcall('POST', 'pullzone', $args);
    }

    public function updatePullZone(int $id, array $args = array())
    {
        return $this->APIcall('POST', "pullzone/$id", $args);
    }

    public function pullZoneData(int $id)
    {
        return $this->APIcall('GET', "pullzone/$id");
    }

    public function purgePullZone(int $id)
    {
        return $this->APIcall('POST', "pullzone/$id/purgeCache");
    }

    public function deletePullZone(int $id)
    {
        return $this->APIcall('DELETE', "pullzone/$id");
    }

    public function pullZoneHostnames(int $id)
    {
        $data = $this->pullZoneData($id);
        if (isset($this->pullZoneData($id)['Hostnames'])) {
            $hn_count = count($data['Hostnames']);
            $hn_arr = array();
            foreach ($data['Hostnames'] as $a_hn) {
                $hn_arr[] = array(
                    'id' => $a_hn['Id'],
                    'hostname' => $a_hn['Value'],
                    'force_ssl' => $a_hn['ForceSSL']
                );
            }
            return array(
                'hostname_count' => $hn_count,
                'hostnames' => $hn_arr
            );
        }
        return array('hostname_count' => 0);
    }

    public function addHostnamePullZone(int $id, string $hostname)
    {
        return $this->APIcall('POST', "pullzone/$id/addHostname", array("Hostname" => $hostname));
    }

    public function removeHostnamePullZone(int $id, string $hostname)
    {
        return $this->APIcall('DELETE', "pullzone/$id/removeHostname", array("Hostname" => $hostname));
    }

    public function addFreeSSLCertificate(string $hostname)
    {
        return $this->APIcall('GET', 'pullzone/loadFreeCertificate?hostname=' . $hostname);
    }

    public function forceSSLPullZone(int $id, string $hostname, bool $force_ssl = true)
    {
        return $this->APIcall('POST', "pullzone/$id/setForceSSL", array("Hostname" => $hostname, 'ForceSSL' => $force_ssl));
    }

    public function listBlockedIpPullZone(int $id)
    {
        $data = $this->pullZoneData($id);
        if (isset($data['BlockedIps'])) {
            $ip_count = count($data['BlockedIps']);
            $ip_arr = array();
            foreach ($data['BlockedIps'] as $a_hn) {
                $ip_arr[] = $a_hn;
            }
            return array(
                'blocked_ip_count' => $ip_count,
                'ips' => $ip_arr
            );
        }
        return array('blocked_ip_count' => 0);
    }

    public function resetTokenKey(int $id)
    {
        return $this->APIcall('POST', "pullzone/$id/resetSecurityKey", array());
    }

    public function addBlockedIpPullZone(int $id, string $ip)
    {
        return $this->APIcall('POST', 'pullzone/addBlockedIp', array("PullZoneId" => $id, "BlockedIp" => $ip));
    }

    public function unBlockedIpPullZone(int $id, string $ip)
    {
        return $this->APIcall('POST', 'pullzone/removeBlockedIp', array("PullZoneId" => $id, "BlockedIp" => $ip));
    }

    public function addAllowedReferrer(int $id, string $hostname)
    {
        return $this->APIcall('POST', "pullzone/$id/addAllowedReferrer", array("Hostname" => $hostname));
    }

    public function removeAllowedReferrer(int $id, string $hostname)
    {
        return $this->APIcall('POST', "pullzone/$id/removeAllowedReferrer", array("Hostname" => $hostname));
    }

    public function addBlockedReferrer(int $id, string $hostname)
    {
        return $this->APIcall('POST', "pullzone/$id/addBlockedReferrer", array("Hostname" => $hostname));
    }

    public function removeBlockedReferrer(int $id, string $hostname)
    {
        return $this->APIcall('POST', "pullzone/$id/removeBlockedReferrer", array("Hostname" => $hostname));
    }

    public function pullZoneLogs(int $id, string $date)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://logging.bunnycdn.com/$date/$id.log");
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "AccessKey: {$this->api_key}"));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        $result = curl_exec($curl);
        curl_close($curl);
        $linetoline = explode("\n", $result);
        $line = array();
        foreach ($linetoline as $v1) {
            if (isset($v1) && strlen($v1) > 0) {
                $log_format = explode('|', $v1);
                $details = array(
                    'cache_result' => $log_format[0],
                    'status' => intval($log_format[1]),
                    'datetime' => date('Y-m-d H:i:s', round($log_format[2] / 1000, 0)),
                    'bytes' => intval($log_format[3]),
                    'ip' => $log_format[5],
                    'referer' => $log_format[6],
                    'file_url' => $log_format[7],
                    'user_agent' => $log_format[9],
                    'request_id' => $log_format[10],
                    'cdn_dc' => $log_format[8],
                    'zone_id' => intval($log_format[4]),
                    'country_code' => $log_format[11]
                );
                $line[] = $details;
            }
        }
        return $line;
    }

    public function listStorageZones()
    {
        return $this->APIcall('GET', 'storagezone');
    }

    public function addStorageZone(string $name, string $origin_url, string $main_region = 'DE', array $replicated_regions = [])
    {
        return $this->APIcall('POST', 'storagezone', array("Name" => $name, "OriginUrl" => $origin_url, "Region" => $main_region, "ReplicationRegions" => $replicated_regions));
    }

    public function deleteStorageZone(int $id)
    {
        return $this->APIcall('DELETE', "storagezone/$id");
    }

    public function purgeCache(string $url)
    {
        return $this->APIcall('POST', 'purge', array("url" => $url));
    }

    public function convertBytes(int $bytes, string $convert_to = 'GB', bool $format = true, int $decimals = 2)
    {
        if ($convert_to === 'GB') {
            $value = ($bytes / 1073741824);
        } elseif ($convert_to === 'MB') {
            $value = ($bytes / 1048576);
        } elseif ($convert_to === 'KB') {
            $value = ($bytes / 1024);
        } else {
            $value = $bytes;
        }
        if ($format) {
            return number_format($value, $decimals);
        }
        return $value;
    }

    public function getStatistics()
    {
        return $this->APIcall('GET', 'statistics');
    }

    public function getBilling()
    {
        return $this->APIcall('GET', 'billing');
    }

    public function balance()
    {
        return $this->getBilling()['Balance'];
    }

    public function monthCharges()
    {
        return $this->getBilling()['ThisMonthCharges'];
    }

    public function totalBillingAmount(bool $format = false, int $decimals = 2)
    {
        $data = $this->getBilling();
        $tally = 0;
        foreach ($data['BillingRecords'] as $charge) {
            $tally += $charge['Amount'];
        }
        if ($format) {
            return array('amount' => (float)number_format($tally, $decimals), 'since' => str_replace('T', ' ', $charge['Timestamp']));
        }
        return array('amount' => $tally, 'since' => str_replace('T', ' ', $charge['Timestamp']));
    }

    public function monthChargeBreakdown()
    {
        $ar = $this->getBilling();
        return array('storage' => $ar['MonthlyChargesStorage'], 'EU' => $ar['MonthlyChargesEUTraffic'],
            'US' => $ar['MonthlyChargesUSTraffic'], 'ASIA' => $ar['MonthlyChargesASIATraffic'],
            'SA' => $ar['MonthlyChargesSATraffic']);
    }

    public function applyCoupon(string $code)
    {
        return $this->APIcall('POST', 'applycode', array("couponCode" => $code));
    }

    public function uploadFileHTTP(string $file, string $save_as = 'folder/filename.jpg')
    {
        $this->APIcall('PUT', $this->storage_name . "/" . $save_as, array('file' => $file), true);
    }

    public function deleteFileHTTP(string $file)
    {
        $this->APIcall('DELETE', $this->storage_name . "/" . $file, array(), true);
    }

    public function downloadFileHTTP(string $file)
    {
        $this->APIcall('GET', $this->storage_name . "/" . $file, array(), true);
    }

    public function createFolder(string $name)
    {
        if (ftp_mkdir($this->connection, $name)) {
            return array('response' => 'success', 'action' => __FUNCTION__, 'value' => $name);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'value' => $name);
    }

    public function deleteFolder(string $name)
    {
        if (ftp_rmdir($this->connection, $name)) {
            return array('response' => 'success', 'action' => __FUNCTION__, 'value' => $name);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'value' => $name);
    }

    public function deleteFile(string $name)
    {
        if (ftp_delete($this->connection, $name)) {
            return array('response' => 'success', 'action' => __FUNCTION__, 'value' => $name);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'value' => $name);
    }

    public function deleteAllFiles(string $dir)
    {
        $array = json_decode(file_get_contents(self::STORAGE_API_URL . "/$this->storage_name/{$dir}/?AccessKey=" . $this->access_key), true);
        $files_deleted = 0;
        foreach ($array as $value) {
            if ($value['IsDirectory'] === false) {
                $file_name = $value['ObjectName'];
                if (ftp_delete($this->connection, "$dir/$file_name")) {
                    $files_deleted++;
                }
            }
        }
        return array('action' => __FUNCTION__, 'value' => $dir, 'files_deleted' => $files_deleted);
    }

    public function uploadAllFiles(string $dir, string $place, $mode = FTP_BINARY)
    {
        $obj = scandir($dir);
        $files_uploaded = 0;
        foreach ($obj as $file) {
            if (!is_dir($file) && ftp_put($this->connection, $place . $file, "$dir/$file", $mode)) {
                $files_uploaded++;
            }
        }
        return array('action' => __FUNCTION__, 'value' => $dir, 'files_uploaded' => $files_uploaded);
    }

    public function getFileSize(string $file)
    {
        return ftp_size($this->connection, $file);
    }

    public function dirSize(string $dir = '')
    {
        $array = json_decode(file_get_contents(self::STORAGE_API_URL . "/$this->storage_name" . $dir . "/?AccessKey=" . $this->access_key), true);
        $size = $files = 0;
        foreach ($array as $value) {
            if ($value['IsDirectory'] === false) {
                $size += $value['Length'];
                $files++;
            }
        }
        return array('dir' => $dir, 'files' => $files, 'size_b' => $size, 'size_kb' => number_format(($size / 1024), 3),
            'size_mb' => number_format(($size / 1048576), 3), 'size_gb' => number_format(($size / 1073741824), 3));
    }

    public function currentDir()
    {
        return ftp_pwd($this->connection);
    }

    public function changeDir(string $moveto)
    {
        if (ftp_chdir($this->connection, $moveto)) {
            return array('response' => 'success', 'action' => __FUNCTION__, 'value' => $moveto);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'value' => $moveto);
    }

    public function moveUpOne()
    {
        if (ftp_cdup($this->connection)) {
            return array('response' => 'success', 'action' => __FUNCTION__);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__);
    }

    public function renameFile(string $dir, string $file_name, string $new_file_name)
    {
        $path_data = pathinfo("{$dir}$file_name");
        $file_type = $path_data['extension'];
        if (ftp_get($this->connection, "TEMPFILE.$file_type", "{$dir}$file_name", FTP_BINARY)) {
            if (ftp_put($this->connection, "{$dir}$new_file_name", "TEMPFILE.$file_type", FTP_BINARY)) {
                $this->deleteFile("{$dir}$file_name");
                return array('response' => 'success', 'action' => __FUNCTION__, 'old' => "{$dir}$file_name", 'new' => "{$dir}$new_file_name");
            }
            return array('response' => 'fail', 'action' => __FUNCTION__, 'old' => "{$dir}$file_name", 'new' => "{$dir}$new_file_name");
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'old' => "{$dir}$file_name", 'new' => "{$dir}$new_file_name");
    }

    public function moveFile(string $dir, string $file_name, string $move_to)
    {
        $path_data = pathinfo("{$dir}$file_name");
        $file_type = $path_data['extension'];
        if (ftp_get($this->connection, "TEMPFILE.$file_type", "{$dir}$file_name", FTP_BINARY)) {
            if (ftp_put($this->connection, "$move_to{$file_name}", "TEMPFILE.$file_type", FTP_BINARY)) {
                $this->deleteFile("{$dir}$file_name");
                return array('response' => 'success', 'action' => __FUNCTION__, 'file' => "{$dir}$file_name", 'move_to' => $move_to);
            }
            return array('response' => 'fail', 'action' => __FUNCTION__, 'file' => "{$dir}$file_name", 'move_to' => $move_to);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'file' => "{$dir}$file_name", 'move_to' => $move_to);
    }

    public function downloadFile(string $save_as, string $get_file, int $mode = FTP_BINARY)
    {
        if (ftp_get($this->connection, $save_as, $get_file, $mode)) {
            return array('response' => 'success', 'action' => __FUNCTION__, 'file' => $get_file, 'save_as' => $save_as);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'file' => $get_file, 'save_as' => $save_as);
    }

    public function downloadFileWithProgress(string $save_as, string $get_file, string $progress_file = 'DOWNLOAD_PERCENT.txt')
    {
        $ftp_url = "ftp://$this->storage_name:$this->access_key@" . self::HOSTNAME . "/$this->storage_name/$get_file";
        $size = filesize($ftp_url);
        $in = fopen($ftp_url, "rb") or die("Cannot open source file");
        $out = fopen($save_as, "wb");
        while (!feof($in)) {
            $buf = fread($in, 10240);
            fwrite($out, $buf);
            $file_data = (int)(ftell($out) / $size * 100);
            file_put_contents($progress_file, $file_data);
        }
        fclose($out);
        fclose($in);
    }

    public function downloadAll(string $dir_dl_from = '', string $dl_into = '', int $mode = FTP_BINARY)
    {
        $array = json_decode(file_get_contents(self::STORAGE_API_URL . "/$this->storage_name" . $dir_dl_from . "/?AccessKey=" . $this->access_key), true);
        $files_downloaded = 0;
        foreach ($array as $value) {
            if ($value['IsDirectory'] === false) {
                $file_name = $value['ObjectName'];
                if (ftp_get($this->connection, $dl_into . "$file_name", $file_name, $mode)) {
                    $files_downloaded++;
                }
            }
        }
        return array('action' => __FUNCTION__, 'files_downloaded' => $files_downloaded);
    }

    public function uploadFile(string $upload, string $upload_as, int $mode = FTP_BINARY)
    {
        if (ftp_put($this->connection, $upload_as, $upload, $mode)) {
            return array('response' => 'success', 'action' => __FUNCTION__, 'file' => $upload, 'upload_as' => $upload_as);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__, 'file' => $upload, 'upload_as' => $upload_as);
    }

    public function uploadFileWithProgress(string $upload, string $upload_as, string $progress_file = 'UPLOAD_PERCENT.txt')
    {
        $ftp_url = "ftp://$this->storage_name:$this->access_key@" . self::HOSTNAME . "/$this->storage_name/$upload_as";
        $size = filesize($upload);
        $out = fopen($ftp_url, "wb");
        $in = fopen($upload, "rb");
        while (!feof($in)) {
            $buffer = fread($in, 10240);
            fwrite($out, $buffer);
            $file_data = (int)(ftell($in) / $size * 100);
            file_put_contents($progress_file, $file_data);
        }
        fclose($in);
        fclose($out);
    }

    public function boolToInt(bool $bool)
    {
        if ($bool) {
            return 1;
        }
        return 0;
    }

    public function jsonHeader()
    {
        header('Content-Type: application/json');
    }

    public function listAllOG()
    {
        return json_decode(file_get_contents(self::STORAGE_API_URL . "/$this->storage_name/?AccessKey=" . $this->access_key), true);
    }

    public function listFiles(string $location = '')
    {
        $array = json_decode(file_get_contents(self::STORAGE_API_URL . "/$this->storage_name" . $location . "/?AccessKey=" . $this->access_key), true);
        $items = array('storage_name' => $this->storage_name, 'current_dir' => $location, 'data' => array());
        foreach ($array as $value) {
            if ($value['IsDirectory'] === false) {
                $created = date('Y-m-d H:i:s', strtotime($value['DateCreated']));
                $last_changed = date('Y-m-d H:i:s', strtotime($value['LastChanged']));
                if (isset(pathinfo($value['ObjectName'])['extension'])) {
                    $file_type = pathinfo($value['ObjectName'])['extension'];
                } else {
                    $file_type = null;
                }
                $file_name = $value['ObjectName'];
                $size_kb = (float)($value['Length'] / 1024);
                $guid = $value['Guid'];
                $items['data'][] = array('name' => $file_name, 'file_type' => $file_type, 'size' => $size_kb, 'created' => $created,
                    'last_changed' => $last_changed, 'guid' => $guid);
            }
        }
        return $items;
    }

    public function listFolders(string $location = '')
    {
        $array = json_decode(file_get_contents(self::STORAGE_API_URL . "/$this->storage_name" . $location . "/?AccessKey=$this->access_key"), true);
        $items = array('storage_name' => $this->storage_name, 'current_dir' => $location, 'data' => array());
        foreach ($array as $value) {
            $created = date('Y-m-d H:i:s', strtotime($value['DateCreated']));
            $last_changed = date('Y-m-d H:i:s', strtotime($value['LastChanged']));
            $foldername = $value['ObjectName'];
            $guid = $value['Guid'];
            if ($value['IsDirectory'] === true) {
                $items['data'][] = array('name' => $foldername, 'created' => $created,
                    'last_changed' => $last_changed, 'guid' => $guid);
            }
        }
        return $items;
    }

    public function listAll(string $location = '')
    {
        $array = json_decode(file_get_contents(self::STORAGE_API_URL . "/$this->storage_name" . $location . "/?AccessKey=" . $this->access_key), true);
        $items = array('storage_name' => $this->storage_name, 'current_dir' => $location, 'data' => array());
        foreach ($array as $value) {
            $created = date('Y-m-d H:i:s', strtotime($value['DateCreated']));
            $last_changed = date('Y-m-d H:i:s', strtotime($value['LastChanged']));
            $file_name = $value['ObjectName'];
            $guid = $value['Guid'];
            if ($value['IsDirectory'] === true) {
                $file_type = null;
                $size_kb = null;
            } else {
                if (isset(pathinfo($value['ObjectName'])['extension'])) {
                    $file_type = pathinfo($value['ObjectName'])['extension'];
                } else {
                    $file_type = null;
                }
                $size_kb = (float)($value['Length'] / 1024);
            }
            $items['data'][] = array('name' => $file_name, 'file_type' => $file_type, 'size' => $size_kb, 'is_dir' => $value['IsDirectory'], 'created' => $created,
                'last_changed' => $last_changed, 'guid' => $guid);
        }
        return $items;
    }

    public function closeConnection()
    {
        if (ftp_close($this->connection)) {
            return array('response' => 'success', 'action' => __FUNCTION__);
        }
        return array('response' => 'fail', 'action' => __FUNCTION__);
    }

    public function costCalculator(int $bytes)
    {
        $zone1 = 0.01;
        $zone2 = 0.03;
        $zone3 = 0.045;
        $zone4 = 0.06;
        $s500t = 0.005;
        $s1pb = 0.004;
        $s2pb = 0.003;
        $s2pb_plus = 0.0025;
        $gigabytes = (float)($bytes / 1073741824);
        $terabytes = (float)($gigabytes / 1024);
        return array(
            'bytes' => $bytes,
            'gigabytes' => $gigabytes,
            'terabytes' => $terabytes,
            'EU_NA' => ($zone1 * $gigabytes),
            'ASIA_OC' => ($zone2 * $gigabytes),
            'SOUTH_AMERICA' => ($zone3 * $gigabytes),
            'MIDDLE_EAST_AFRICA' => ($zone4 * $gigabytes),
            'storage_500tb' => sprintf('%f', ($s500t * $terabytes)),
            'storage_500tb_1PB' => sprintf('%f', ($s1pb * $terabytes)),
            'storage_1PB_2PB' => sprintf('%f', ($s2pb * $terabytes)),
            'storage_2PB_PLUS' => sprintf('%f', ($s2pb_plus * $terabytes))
        );
    }

    /*
     * Bunny net video stream section
     *
    */
    //Library -> collection -> video
    public function setStreamLibraryId(int $library_id)
    {
        $this->stream_library_id = $library_id;
    }

    public function setStreamCollectionGuid(string $collection_guid)
    {
        $this->stream_collection_guid = $collection_guid;
    }

    public function setStreamVideoGuid(string $video_guid)
    {
        $this->stream_video_guid = $video_guid;
    }

    public function getVideoCollections()
    {
        return $this->APIcall('GET', "library/{$this->stream_library_id}/collections", [], false, true);
    }

    public function getStreamCollections(int $library_id = 0, int $page = 1, int $items_pp = 100, string $order_by = 'date')
    {
        if ($library_id === 0) {
            $library_id = $this->stream_library_id;
        }
        return $this->APIcall('GET', "library/$library_id/collections?page=$page&itemsPerPage=$items_pp&orderBy=$order_by", [], false, true);
    }

    public function getStreamForCollection(int $library_id = 0, string $collection_guid = '')
    {
        if ($library_id === 0) {
            $library_id = $this->stream_library_id;
        }
        if (empty($collection_guid)) {
            $collection_guid = $this->stream_collection_guid;
        }
        return $this->APIcall('GET', "library/$library_id/collections/$collection_guid", [], false, true);
    }

    public function updateCollection(int $library_id, string $collection_guid, string $video_library_id, int $video_count, int $total_size)
    {
        return $this->APIcall('POST', "library/$library_id/collections/$collection_guid", array("videoLibraryId" => $video_library_id, "videoCount" => $video_count, "totalSize" => $total_size), false, true);
    }

    public function deleteCollection(int $library_id, string $collection_id)
    {
        return $this->APIcall('DELETE', "library/$library_id/collections/$collection_id", [], false, true);
    }

    public function createCollection(int $library_id, string $video_library_id, int $video_count, int $total_size)
    {
        return $this->APIcall('POST', "library/$library_id/collections", array("videoLibraryId" => $video_library_id, "videoCount" => $video_count, "totalSize" => $total_size), false, true);
    }

    public function listVideos(int $page = 1, int $items_pp = 100, string $order_by = 'date')
    {
        if (!isset($this->stream_library_id)) {
            return array('response' => 'fail', 'action' => __FUNCTION__, 'message' => 'You must set library id with: setStreamLibraryId()');
        }
        return $this->APIcall('GET', "library/{$this->stream_library_id}/videos?page=$page&itemsPerPage=$items_pp&orderBy=$order_by", [], false, true);
    }

    public function getVideo(int $library_id, string $video_guid)
    {
        return $this->APIcall('GET', "library/$library_id/videos/$video_guid", [], false, true);
    }

    public function deleteVideo(int $library_id, string $video_guid)
    {
        return $this->APIcall('DELETE', "library/$library_id/videos/$video_guid", [], false, true);
    }

    public function createVideo(int $library_id, string $video_title, string $collection_guid = '')
    {
        if (!empty($collection_guid)) {
            return $this->APIcall('POST', "library/$library_id/videos?title=$video_title&collectionId=$collection_guid", [], false, true);
        }
        return $this->APIcall('POST', "library/$library_id/videos?title=$video_title", [], false, true);
    }

    public function uploadVideo(int $library_id, string $video_guid, string $video_to_upload)
    {
        //Need to use createVideo() first to get video guid
        return $this->APIcall('PUT', "library/$library_id/videos/$video_guid", array('file' => $video_to_upload), false, true);
    }

    public function setThumbnail(int $library_id, string $video_guid, string $thumbnail_url)
    {
        return $this->APIcall('POST', "library/$library_id/videos/$video_guid/thumbnail?$thumbnail_url", [], false, true);
    }

    public function addCaptions(int $library_id, string $video_guid, string $srclang, string $label, string $captions_file)
    {
        return $this->APIcall('POST', "library/$library_id/videos/$video_guid/captions/$srclang?label=$label&captionsFile=$captions_file", [], false, true);
    }

    public function deleteCaptions(int $library_id, string $video_guid, string $srclang)
    {
        return $this->APIcall('DELETE', "library/$library_id/videos/$video_guid/captions/$srclang", [], false, true);
    }

}
