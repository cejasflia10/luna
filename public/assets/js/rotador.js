<script>
(function(){
  function setupRotator(el, words, opts){
    if (!el || !words || !words.length) return;
    const o = Object.assign({interval: 2200, fade: 260}, opts||{});
    let i = 0;
    el.textContent = words[0];
    setInterval(()=>{
      el.classList.add('rot-out');
      setTimeout(()=>{
        i = (i + 1) % words.length;
        el.textContent = words[i];
        el.classList.remove('rot-out');
      }, o.fade);
    }, o.interval);
  }

  // Auto-init: [data-rotator]
  window.initRotators = function(){
    document.querySelectorAll('[data-rotator]').forEach(el=>{
      let words = [];
      if (window.PROMO_WORDS && Array.isArray(window.PROMO_WORDS) && window.PROMO_WORDS.length){
        words = window.PROMO_WORDS;
      } else if (el.dataset.words){
        words = el.dataset.words.split('|').map(s=>s.trim()).filter(Boolean);
      }
      setupRotator(el, words, {interval: parseInt(el.dataset.interval||'2200',10)});
    });
  };

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', initRotators);
  } else {
    initRotators();
  }
})();
</script>
