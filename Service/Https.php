<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Https
{
    public static function url(string $url,array $hosts): void
    {
        $p=parse_url($url);
        if (!$p || ($p['scheme'] ?? '')!=='https' || !in_array(strtolower($p['host'] ?? ''),$hosts,true) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port']!==443)) { throw new \InvalidArgumentException('unapproved_https_url'); }
    }
    public function request(string $url,array $hosts,?array $body=null,int $max=2097152): string
    {
        self::url($url,$hosts);
        $host=parse_url($url,PHP_URL_HOST); $ips=gethostbynamel($host);
        if (!$ips) { throw new \RuntimeException('dns_unavailable'); }
        foreach ($ips as $ip) { if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) { throw new \RuntimeException('private_network_refused'); } }
        $data='';$c=curl_init($url);
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_USERAGENT=>'WebAnyOne-PluginManager/0.1.0',CURLOPT_WRITEFUNCTION=>static function($c,$chunk) use (&$data,$max) { if (strlen($data)+strlen($chunk)>$max) { return 0; } $data.=$chunk;return strlen($chunk); }]);
        curl_setopt($c,CURLOPT_PROXY,'');
        if ($body!==null) { curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));curl_setopt($c,CURLOPT_HTTPHEADER,['Content-Type: application/json']); }
        try { if (curl_exec($c)===false || curl_getinfo($c,CURLINFO_HTTP_CODE)!==200) { throw new \RuntimeException('https_failed'); } return $data; } finally { curl_close($c); }
    }
}
