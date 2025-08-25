<?php
// Función para renderizar la cabecera HERO unificada
if (!function_exists('page_head')) {
  /**
   * Muestra una sección HERO con título, subtítulo y botón opcional
   * @param string $title     Título principal
   * @param string $subtitle  Texto debajo del título
   * @param array  $cta       ['label'=>'Texto','href'=>'link'] o null
   */
  function page_head(string $title, string $subtitle='', array $cta=null): void {
    ?>
    <header class="hero">
      <div class="container">
        <h1><?=htmlspecialchars($title,ENT_QUOTES,'UTF-8')?></h1>
        <?php if($subtitle): ?>
          <p><?=htmlspecialchars($subtitle,ENT_QUOTES,'UTF-8')?></p>
        <?php endif; ?>
        <?php if($cta): ?>
          <a class="cta" href="<?=htmlspecialchars($cta['href'])?>">
            <?=htmlspecialchars($cta['label'])?>
          </a>
        <?php endif; ?>
      </div>
    </header>
    <?php
  }
}
