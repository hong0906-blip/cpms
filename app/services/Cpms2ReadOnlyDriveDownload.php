<?php
// app/services/Cpms2ReadOnlyDriveDownload.php
// No token/cache writes; OAuth scope permits reading Drive files only.
require_once __DIR__.'/GoogleDriveHelper.php';
class Cpms2ReadOnlyDriveDownload
{
    private $token='';
    public function download($id,$destination)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$id) || !function_exists('curl_init')) return false;
        if ($this->token==='') {
            $read=cpms_drive_read_service_account(); if (!$read['ok']) return false;
            $now=time(); $url='https://oauth2.googleapis.com/token';
            $claim=array('iss'=>$read['account']['client_email'],'scope'=>'https://www.googleapis.com/auth/drive.readonly','aud'=>$url,'iat'=>$now,'exp'=>$now+3600);
            $input=cpms_drive_base64url_encode(json_encode(array('alg'=>'RS256','typ'=>'JWT'))).'.'.cpms_drive_base64url_encode(json_encode($claim));
            $signature=''; if (!@openssl_sign($input,$signature,$read['account']['private_key'],OPENSSL_ALGO_SHA256)) return false;
            $body=http_build_query(array('grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$input.'.'.cpms_drive_base64url_encode($signature)),'','&');
            $res=cpms_drive_curl_request('POST',$url,array('Content-Type: application/x-www-form-urlencoded'),$body,30);
            if (!$res['ok'] || empty($res['json']['access_token'])) return false;
            $this->token=$res['json']['access_token'];
        }
        $out=fopen($destination,'wb'); if (!$out) return false; $bytes=0; $max=104857600;
        $ch=curl_init('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media&supportsAllDrives=true');
        curl_setopt_array($ch,array(CURLOPT_HTTPHEADER=>array('Authorization: Bearer '.$this->token),CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>180,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_WRITEFUNCTION=>function($ch,$data) use($out,&$bytes,$max) { $bytes+=strlen($data); return $bytes>$max?0:fwrite($out,$data); }));
        $ok=curl_exec($ch)!==false && curl_getinfo($ch,CURLINFO_HTTP_CODE)===200; curl_close($ch); fclose($out); return $ok;
    }
}
