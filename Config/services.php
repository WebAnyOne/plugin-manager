<?php
// Explicit definitions live in config.php and the bundle extension. This file prevents
// Mautic 5/6 from treating templates and domain objects as autowired services.
return static function ($configurator): void {};
