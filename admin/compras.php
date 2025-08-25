<?php
<a href="/reportes.php">Reportes</a>
</div></div>
<main class="container">
<h2>📦 Nueva compra</h2>
<?php if($ok):?><div class="kpi"><div class="box"><b>OK</b><?=$ok?></div></div><?php endif; ?>
<?php if($err):?><div class="kpi"><div class="box"><b>Error</b><?=$err?></div></div><?php endif; ?>


<form method="post" class="card" style="padding:14px">
<input type="hidden" name="__action" value="save_purchase">
<div class="row">
<label>Proveedor <input class="input" name="supplier"></label>
<label>Fecha <input class="input" type="datetime-local" name="purchased_at" value="<?=h(date('Y-m-d\TH:i'))?>"></label>
<label style="grid-column:1/-1">Notas <input class="input" name="notes"></label>
</div>


<h3>Ítems</h3>
<div id="items"></div>
<button type="button" onclick="addRow()">+ Agregar ítem</button>
<button type="submit">Guardar compra</button>
</form>


<template id="tpl">
<div class="card" style="padding:10px;margin:10px 0">
<div class="row">
<label>Producto
<select class="input" name="items[idx][product_id]" required>
<option value="">—</option>
<?php while($p=$products->fetch_assoc()): ?>
<option value="<?=$p['id']?>"><?=$p['name']?></option>
<?php endwhile; $products->data_seek(0); ?>
</select>
</label>
<label>Talle <input class="input" name="items[idx][size]"></label>
<label>Color <input class="input" name="items[idx][color]"></label>
<label>Medidas <input class="input" name="items[idx][measure_text]"></label>
<label>Precio sugerido ($) <input class="input" type="number" step="0.01" name="items[idx][price]" value="0"></label>
<label>Cantidad <input class="input" type="number" min="1" name="items[idx][quantity]" value="1" required></label>
<label>Costo unitario ($) <input class="input" type="number" step="0.01" min="0" name="items[idx][unit_cost]" value="0" required></label>
</div>
</div>
</template>
</main>
<script>
let i=0; function addRow(){
const tpl=document.getElementById('tpl').innerHTML.replaceAll('idx', i++);
document.getElementById('items').insertAdjacentHTML('beforeend', tpl);
}
addRow();
</script>
</body></html>