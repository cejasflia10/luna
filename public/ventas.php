<?php


<h3>Pago</h3>
<div class="row">
<label>Método
<select class="input" name="method">
<option value="efectivo">Efectivo</option>
<option value="transferencia">Transferencia</option>
<option value="debito">Débito</option>
<option value="credito">Crédito</option>
<option value="mp">Mercado Pago</option>
<option value="qr">QR</option>
<option value="otro">Otro</option>
</select>
</label>
<label>Monto ($) <input class="input" type="number" step="0.01" name="amount" value="0"></label>
<label>Referencia <input class="input" name="reference" placeholder="N° de op, cupón, etc."></label>
<label>Fecha cobro <input class="input" type="datetime-local" name="received_at" value="<?=h(date('Y-m-d\TH:i'))?>"></label>
</div>


<button type="submit">Guardar venta</button>
</form>


<template id="tpl">
<div class="card" style="padding:10px;margin:10px 0">
<div class="row">
<label>Producto
<select class="input prod" onchange="fillVariants(this)" required>
<option value="">—</option>
<?php while($p=$products->fetch_assoc()): ?>
<option value="<?=$p['id']?>"><?=$p['name']?></option>
<?php endwhile; $products->data_seek(0); ?>
</select>
</label>
<label>Variante
<select class="input" name="items[idx][variant_id]" required></select>
</label>
<label>Cantidad <input class="input" type="number" min="1" name="items[idx][quantity]" value="1" required></label>
<label>Precio unitario ($) <input class="input" type="number" step="0.01" name="items[idx][unit_price]" value="0" required></label>
</div>
</div>
</template>


<script>
const variants = <?=json_encode($variantsByProduct)?>;
let i=0; function addItem(){
const html = document.getElementById('tpl').innerHTML.replaceAll('idx', i++);
document.getElementById('items').insertAdjacentHTML('beforeend', html);
}
function fillVariants(sel){
const prodId = sel.value; const wrap = sel.closest('.row');
const vsel = wrap.querySelector('select[name*="[variant_id]"]');
vsel.innerHTML = '<option value="">—</option>';
if(variants[prodId]){
for(const v of variants[prodId]){
const label = `#${v.sku||'-'} • ${v.size||''} ${v.color||''} ${v.measure_text||''} • $${v.price} • stk:${v.stock}`;
const opt = document.createElement('option');
opt.value = v.id; opt.textContent = label; vsel.appendChild(opt);
}
}
}
addItem();
</script>
</main>
</body></html>