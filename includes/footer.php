<?php
// Fragmento de footer (sin cerrar <html>)
if (!function_exists('url')) {
  $BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
  function url($p){ global $BASE; return $BASE.'/'.ltrim($p,'/'); }
}
?>
<footer class="footer">
  <div class="container">
    <div class="foot-left">
      <img src="<?=url('assets/img/logo.png')?>" alt="Luna" style="height:24px;width:auto;vertical-align:middle;margin-right:8px">
      <small>© <?=date('Y')?> Luna — Todos los derechos reservados.</small>
    </div>
    <div class="foot-right">
      <a href="#" aria-label="Instagram">Instagram</a>
      <a href="#" aria-label="Facebook">Facebook</a>
      <a href="#" aria-label="WhatsApp">WhatsApp</a>
    </div>
  </div>
</footer>
