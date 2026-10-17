<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
final class Community
{
    private $manager;private $helper;
    public function __construct(Manager $manager,$helper) { $this->manager=$manager;$this->helper=$helper; }
    public function preview(array $data): array
    {
        $kind=$data['kind'] ?? '';
        if (!in_array($kind,['support','suggestion','feedback','erasure'],true)) { throw new \InvalidArgumentException('message_kind'); }
        $clean=[];
        foreach (['name'=>120,'email'=>254,'subject'=>200,'description'=>6000,'plugin'=>64,'category'=>64,'desired'=>3000,'workaround'=>3000,'answer'=>30] as $key=>$max) {
            $v=$data[$key] ?? '';if (!is_string($v) || strlen($v)>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v)) { throw new \InvalidArgumentException('invalid_message'); }$clean[$key]=trim($v);
        }
        if ($kind!=='suggestion' && !filter_var($clean['email'],FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('reply_email_required'); }
        if ($clean['email'] && (!filter_var($clean['email'],FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$clean['email']))) { throw new \InvalidArgumentException('invalid_email'); }
        if (!$clean['description']) { throw new \InvalidArgumentException('description_required'); }
        if ($kind==='feedback' && !in_array($clean['answer'],['yes','partly','no'],true)) { throw new \InvalidArgumentException('feedback_answer'); }
        if ($clean['category'] && !in_array($clean['category'],['usability','automation','reporting','api','contacts','other'],true)) { throw new \InvalidArgumentException('suggestion_category'); }
        $recipient=$kind==='suggestion' ? 'contact@webanyone.net' : 'support@webanyone.net';
        $diagnostic=($data['diagnostic'] ?? '')==='1' ? $this->manager->diagnosticExport() : [];
        if ($kind==='erasure') { $diagnostic=['installation_id'=>$this->manager->state->read()['installation_id']]; }
        return ['kind'=>$kind,'to'=>$recipient,'reply'=>$clean['email'],'subject'=>'[WebAnyOne Plugins] '.$kind.' — '.preg_replace('/[\r\n]+/',' ',$clean['subject']),'fields'=>$clean,'diagnostic'=>$diagnostic];
    }
    public function send(array $preview,int $user): bool
    {
        $this->manager->state->limit('community:'.$user,300);
        $mail=$this->helper->getMailer();
        try {
            $mail->setLead([],true);$mail->setTo([$preview['to']]);$mail->setCc([]);$mail->setBcc([]);$mail->setSubject($preview['subject']);
            if ($preview['reply']) { $mail->setReplyTo($preview['reply']); }
            $text=json_encode($preview['fields'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n\n".json_encode($preview['diagnostic'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $html='<pre>'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</pre>';$mail->setBody($html,'text/html','UTF-8',true);$mail->setPlainText($text);
            $ok=(bool)$mail->send(false);
            $this->manager->state->change(static function (&$s) use ($ok,$user,$preview) {
                State::audit($s,$ok ? 'community_sent' : 'community_failed',$user,['kind'=>$preview['kind']]);
                if ($ok && $preview['kind']==='feedback') { $s['feedback'][$preview['fields']['plugin']]=time(); }
            });return $ok;
        } catch (\Throwable $e) { return false; } finally { $mail->reset(); }
    }
}
