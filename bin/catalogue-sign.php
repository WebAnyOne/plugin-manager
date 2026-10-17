<?php
if (PHP_SAPI!=='cli' || count($argv)!==5) { fwrite(STDERR,"Usage: php catalogue-sign.php source.json private-key key-id output.json\n");exit(64); }
require __DIR__.'/../Service/Schema.php';
use MauticPlugin\WebAnyOnePluginManagerBundle\Service\Schema;
try {
    $keyPath=realpath($argv[2]);$package=realpath(__DIR__.'/..');
    if (!$keyPath || strpos($keyPath,$package.'/')===0 || (fileperms($keyPath)&0077) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$argv[3])) { throw new RuntimeException('private_key_must_be_private_and_outside_package'); }
    $manifest=json_decode(file_get_contents($argv[1]),false,64,JSON_THROW_ON_ERROR);
    Schema::check($manifest,json_decode(file_get_contents(__DIR__.'/../catalogue/schema.json'),true,64,JSON_THROW_ON_ERROR));
    if ($manifest->issued_at>time()+300 || $manifest->expires_at<=time() || $manifest->expires_at<=$manifest->issued_at || $manifest->expires_at-$manifest->issued_at>604800) { throw new RuntimeException('invalid_manifest_lifetime'); }
    $secret=base64_decode(trim(file_get_contents($keyPath)),true);
    if ($secret===false || strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { throw new RuntimeException('invalid_private_key'); }
    $raw=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $signature=sodium_crypto_sign_detached($raw,$secret);sodium_memzero($secret);
    $envelope=['payload'=>base64_encode($raw),'signatures'=>[['key_id'=>$argv[3],'signature'=>base64_encode($signature)]]];
    if (file_put_contents($argv[4],json_encode($envelope,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n")===false) { throw new RuntimeException('output_failed'); }
    echo 'signed_sequence_'.$manifest->sequence."\n";
} catch (Throwable $e) { fwrite(STDERR,"Signing failed: ".$e->getMessage()."\n");exit(1); }
