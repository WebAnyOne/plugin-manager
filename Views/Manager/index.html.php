<?php
if (!isset($view,$body)) { http_response_code(404);exit; }
$view->extend('MauticCoreBundle:Default:content.html.php');
$view['slots']->set('headerTitle',$view['translator']->trans('wao.manager.title'));echo $body;
