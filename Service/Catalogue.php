<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Catalogue
{
    private $state;private $https;private $config;
    public function __construct(State $state,Https $https,array $config) { $this->state=$state;$this->https=$https;$this->config=$config; }
    public function refresh(): void
    {
        $url=$this->config['catalogue_url'] ?? '';
        if (!$url) { throw new \RuntimeException('catalogue_not_published'); }
        $this->accept($this->https->request($url,['raw.githubusercontent.com']),true);
    }
    public function accept(string $envelope,bool $remote=false): void
    {
        if (strlen($envelope)>3000000) { throw new \InvalidArgumentException('catalogue_too_large'); }
        $e=json_decode($envelope,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($e) || array_diff(array_keys($e),['payload','signatures']) || !isset($e['payload'],$e['signatures']) || !is_string($e['payload']) || !is_array($e['signatures']) || count($e['signatures'])>8) { throw new \InvalidArgumentException('catalogue_envelope'); }
        $raw=base64_decode($e['payload'],true);
        if ($raw===false) { throw new \InvalidArgumentException('catalogue_payload'); }
        $obj=json_decode($raw,false,64,JSON_THROW_ON_ERROR);
        Schema::check($obj,json_decode(file_get_contents(__DIR__.'/../catalogue/schema.json'),true,64,JSON_THROW_ON_ERROR));
        $m=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        $this->state->change(function (&$s) use ($e,$raw,$m,$remote) {
            $keys=array_merge($this->config['trusted_keys'] ?? [],$s['keys']);$valid=false;
            foreach ($e['signatures'] as $sig) {
                if (!is_array($sig) || array_keys($sig)!==['key_id','signature']) { throw new \InvalidArgumentException('catalogue_signature_shape'); }
                $key=$keys[$sig['key_id']] ?? null;$signature=base64_decode($sig['signature'],true);
                if ($key && !in_array($sig['key_id'],$s['revoked'],true)) {
                    $decoded=base64_decode($key,true);
                    if ($decoded!==false && strlen($decoded)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && $signature!==false && strlen($signature)===SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($signature,$raw,$decoded)) { $valid=true; }
                }
            }
            if (!$valid) { throw new \RuntimeException('catalogue_signature'); }
            $hash=hash('sha256',$raw);
            if ($m['issued_at']>time()+300 || $m['expires_at']<=time() || $m['expires_at']-$m['issued_at']>604800 || $m['expires_at']<=$m['issued_at']) { throw new \RuntimeException('catalogue_expired'); }
            if ($m['sequence']<$s['catalogue_sequence'] || ($m['sequence']===$s['catalogue_sequence'] && $s['catalogue_digest']!==$hash)) { throw new \RuntimeException('catalogue_rollback'); }
            $ids=[];$packages=[];
            foreach ($m['plugins'] as $p) {
                if (isset($ids[$p['id']]) || isset($packages[$p['package']])) { throw new \RuntimeException('catalogue_duplicate'); } $ids[$p['id']]=true;$packages[$p['package']]=true;
                Https::url($p['github'],['github.com']);Https::url($p['packagist'],['packagist.org']);
                foreach ($p['documentation'] as $url) { Https::url($url,['github.com','webanyone.net','www.webanyone.net']); }
                foreach ($p['screenshots'] as $url) { Https::url($url,['github.com','raw.githubusercontent.com','webanyone.net','www.webanyone.net']); }
                foreach (array_merge($p['blocked_versions'],[$p['recommended']]) as $version) { if ($version!=='' && !preg_match('/^\d+\.\d+\.\d+$/D',$version)) { throw new \RuntimeException('version_invalid'); } }

                $versions=[];
                $parser=new \Composer\Semver\VersionParser();
                foreach ($p['releases'] as $release) {
                    $parser->parseConstraints($release['mautic']);$parser->parseConstraints($release['php']);
                    foreach ([$release['dependencies'],$release['conflicts']] as $constraints) {
                        foreach ($constraints as $package=>$constraint) {
                            if (!preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D',$package)) { throw new \RuntimeException('dependency_name_invalid'); }
                            $parser->parseConstraints($constraint);
                        }
                    }
                }
                foreach ($p['releases'] as $r) { if (isset($versions[$r['version']])) { throw new \RuntimeException('release_duplicate'); } $versions[$r['version']]=true; foreach ($r['certifications'] as $cert) { Https::url($cert['report_url'],['github.com','raw.githubusercontent.com']); } Https::url($r['artifact']['url'],['codeload.github.com','api.github.com','github.com']); }
            }
            foreach ($m['links'] as $name=>$url) { if ($url!=='') { Https::url($url,$name==='newsletter_fr'||$name==='newsletter_en' ? ['go.webanyone.net'] : ['webanyone.net','www.webanyone.net']); } }
            $newKeys=$m['next_keys'];
            foreach ($newKeys as $id=>$key) { if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$id) || strlen((string)base64_decode($key,true))!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) { throw new \RuntimeException('rotation_key'); } }
            $revoked=array_values(array_unique(array_merge($s['revoked'],$m['revoked_keys'])));
            if (!array_diff(array_keys(array_merge($keys,$newKeys)),$revoked)) { throw new \RuntimeException('rotation_no_trust'); }
            $s['keys']=array_merge($s['keys'],$newKeys);$s['revoked']=$revoked;
            $s['catalogue']=$m;$s['catalogue_digest']=$hash;$s['catalogue_sequence']=$m['sequence'];$s['validated_at']=time();
            if ($remote) { $s['catalogue_at']=time();unset($s['catalogue_error']); }
            State::audit($s,'catalogue_validated',0,['sequence'=>$m['sequence']]);
        });
    }
    public function view(): array
    {
        $s=$this->state->read();
        return ['manifest'=>$s['catalogue'] ?: json_decode(file_get_contents(__DIR__.'/../catalogue/bootstrap.json'),true,64,JSON_THROW_ON_ERROR),'verified'=>(bool)$s['catalogue'],'fresh'=>(bool)$s['catalogue'] && $s['catalogue']['expires_at']>time(),'synced_at'=>$s['catalogue_at'] ?? null];
    }
    public function release(string $id,string $version): array
    {
        $v=$this->view();if (!$v['fresh']) { throw new \RuntimeException('catalogue_not_fresh'); }
        foreach ($v['manifest']['plugins'] as $p) { if ($p['id']===$id) { foreach ($p['releases'] as $r) { if ($r['version']===$version) { return [$p,$r]; } } } }
        throw new \InvalidArgumentException('release_not_approved');
    }
}
