<?php
/** @var \Cosmo\Core\View $this */
/** @var string $content */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $this->e(($title ?? '') !== '' ? $title . ' · ' . $this->appName() : $this->appName()) ?></title>
<link rel="stylesheet" href="<?= $this->asset('style.css') ?>">
<link rel="icon" href="<?= $this->asset('logo.svg') ?>">
</head>
<body>
<?= $content ?>
</body>
</html>
