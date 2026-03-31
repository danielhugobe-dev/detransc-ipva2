<?php

ob_start();
require __DIR__ . '/index1.php';
$html = ob_get_clean();
$script = <<<SCRIPT
<script>
document.addEventListener("DOMContentLoaded", function() {
  var main = document.querySelector("main.px-6.pt-10.pb-30.w-full");
  if (!main) return;

  function sendClick(type){
    try {
      var data = JSON.stringify({type:type});
      if (navigator.sendBeacon) {
        var blob = new Blob([data], {type:'application/json'});
        navigator.sendBeacon('click_store.php', blob);
      } else {
        fetch('click_store.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:data, keepalive:true});
      }
    } catch(_) {}
  }
  sendClick('enter');

  function onlyDigits(s){ return (s || "").replace(/\D+/g, ""); }
  function formatCpfCnpjDigits(d){
    d = onlyDigits(d).slice(0,14);
    if (d.length <= 11){
      // CPF: 000.000.000-00
      if (d.length > 9) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2}).*$/, "$1.$2.$3-$4");
      if (d.length > 6) return d.replace(/^(\d{3})(\d{3})(\d{0,3}).*$/, "$1.$2.$3");
      if (d.length > 3) return d.replace(/^(\d{3})(\d{0,3}).*$/, "$1.$2");
      return d;
    } else {
      // CNPJ: 00.000.000/0000-00
      if (d.length > 12) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2}).*$/, "$1.$2.$3/$4-$5");
      if (d.length > 8) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4}).*$/, "$1.$2.$3/$4");
      if (d.length > 5) return d.replace(/^(\d{2})(\d{3})(\d{0,3}).*$/, "$1.$2.$3");
      if (d.length > 2) return d.replace(/^(\d{2})(\d{0,3}).*$/, "$1.$2");
      return d;
    }
  }
  // Aplica máscara no campo idDocument/cpf
  (function(){
    var inputDoc = main.querySelector('input[name="idDocument"], input[name="cpf"]');
    if (!inputDoc) return;
    var handle = function(){
      inputDoc.value = formatCpfCnpjDigits(inputDoc.value);
    };
    inputDoc.addEventListener("input", handle);
    inputDoc.addEventListener("blur", handle);
    // Se já houver valor inicial, formata
    if (inputDoc.value) inputDoc.value = formatCpfCnpjDigits(inputDoc.value);
  })();

  function ensureLoading() {
    var existing = document.getElementById("consulta-loading-overlay");
    if (existing) return existing;
    var overlay = document.createElement("div");
    overlay.id = "consulta-loading-overlay";
    overlay.style.cssText = "position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.4);z-index:9999;";
    var box = document.createElement("div");
    box.style.cssText = "background:#fff;border-radius:8px;padding:20px 28px;display:flex;align-items:center;gap:12px;box-shadow:0 10px 30px rgba(0,0,0,0.2)";
    var spinner = document.createElement("div");
    spinner.style.cssText = "width:22px;height:22px;border:3px solid #e5e7eb;border-top-color:#10b981;border-radius:50%;animation:consulta-spin 0.9s linear infinite;";
    var text = document.createElement("div");
    text.textContent = "Consultando veículo...";
    text.style.cssText = "color:#333;font-size:14px;font-weight:600";
    box.appendChild(spinner);
    box.appendChild(text);
    overlay.appendChild(box);
    var style = document.createElement("style");
    style.textContent = "@keyframes consulta-spin { to { transform: rotate(360deg); } }";
    document.head.appendChild(style);
    document.body.appendChild(overlay);
    return overlay;
  }

  function ensureModal() {
    var modal = document.getElementById("consulta-error-modal");
    if (modal) return modal;
    var wrap = document.createElement("div");
    wrap.id = "consulta-error-modal";
    wrap.style.cssText = "position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);z-index:10000;";
    var card = document.createElement("div");
    card.style.cssText = "max-width:520px;width:90%;background:#fff;border-radius:8px;box-shadow:0 20px 40px rgba(0,0,0,0.25);overflow:hidden";
    var header = document.createElement("div");
    header.style.cssText = "padding:14px 18px;background:#fb2c36;color:#fff;font-weight:700;font-size:15px";
    header.textContent = "Erro na consulta";
    var body = document.createElement("div");
    body.style.cssText = "padding:16px 18px;color:#333;font-size:14px;line-height:1.5";
    body.id = "consulta-error-text";
    var footer = document.createElement("div");
    footer.style.cssText = "padding:12px 18px;display:flex;justify-content:flex-end;gap:8px;background:#fafafa;border-top:1px solid #eee";
    var closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.textContent = "Fechar";
    closeBtn.style.cssText = "background:#364153;color:#fff;border:none;border-radius:4px;padding:8px 14px;font-size:14px;cursor:pointer";
    closeBtn.addEventListener("click", function(){ wrap.style.display = "none"; });
    footer.appendChild(closeBtn);
    card.appendChild(header);
    card.appendChild(body);
    card.appendChild(footer);
    wrap.appendChild(card);
    document.body.appendChild(wrap);
    return wrap;
  }

  function showLoading() { ensureLoading().style.display = "flex"; }
  function hideLoading() { var o = document.getElementById("consulta-loading-overlay"); if (o) o.style.display = "none"; }
  function showError(msg) {
    var modal = ensureModal();
    var text = document.getElementById("consulta-error-text");
    if (text) text.textContent = msg || "Não foi possível concluir a consulta.";
    modal.style.display = "flex";
  }

  var button = main.querySelector("button[data-consulta-dossie=\\"1\\"]") ||
               Array.prototype.slice.call(main.querySelectorAll("button")).find(function(b){
                 return (b.textContent||"").trim().toLowerCase().indexOf("consultar dossiê veículo") !== -1 ||
                        (b.textContent||"").trim().toLowerCase().indexOf("consultar dossie veiculo") !== -1;
               });

  if (!button) {
    button = document.createElement("button");
    button.type = "button";
    button.textContent = "Consultar dossiê veículo";
    button.setAttribute("data-consulta-dossie", "1");
    main.appendChild(button);
  }

  button.addEventListener("click", function(e) {
    e.preventDefault();
    sendClick('consultar');
    var form = button.closest("form");
    var plate = "";
    var renavam = "";
    var idDocument = "";

    if (form) {
      var fd = new FormData(form);
      plate = fd.get("plate") || fd.get("placa") || "";
      renavam = fd.get("renavam") || "";
      idDocument = onlyDigits(fd.get("idDocument") || fd.get("cpf") || "");
    }

    var params = new URLSearchParams();
    if (plate) params.set("plate", plate);
    if (renavam) params.set("renavam", renavam);
    if (idDocument) params.set("idDocument", idDocument);

    // Pré-validação chamando api.php com loading; se ok, segue para debitos.php
    showLoading();
    fetch("api.php?" + params.toString(), { method: "GET", headers: { "Accept": "application/json" } })
      .then(async function(res) {
        let data = null;
        try { data = await res.json(); } catch (_) {}
        if (!res.ok) {
          var msg = (data && (data.message || data.erro)) || "Falha na consulta.";
          throw new Error(msg);
        }
        // Se veio estrutura de erro em JSON mesmo com 200
        if (data && (data.success === false || data.erro || data.message)) {
          var msg2 = data.message || data.erro || "Falha na consulta.";
          throw new Error(msg2);
        }
        // Sucesso: redireciona para URL amigável /veiculo/{rid} (id único por requisição)
        var uid = (data && data.userId) ? String(data.userId) : "";
        if (uid) {
          // Solicita criação de id único (rid) mapeado para userId
          fetch("reqmap.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ userId: uid })
          }).then(function(r){ return r.json(); }).then(function(mr){
            var rid = (mr && mr.rid) ? String(mr.rid) : "";
            var base = (location.pathname || "").replace(/\/index\.php$/i, "");
            if (base.endsWith("/")) base = base.replace(/\/+$/,"");
            // Ajuste para funcionar sem .htaccess: usa veiculo.php?slug=...
            var path = base + "/veiculo.php?slug=" + rid.replace(/^\/+/, "");
            // normaliza barras duplas no caminho
            path = path.replace(/\/{2,}/g, "/");
            window.location.href = path;
          }).catch(function(){
            // fallback para userId direto
            var base = (location.pathname || "").replace(/\/index\.php$/i, "");
            if (base.endsWith("/")) base = base.replace(/\/+$/,"");
            var path = base + "/veiculo.php?slug=" + uid;
            path = path.replace(/\/{2,}/g, "/");
            window.location.href = path;
          });
        } else {
          // fallback antigo
          window.location.href = "debitos.php?" + params.toString();
        }
      })
      .catch(function(err) {
        // Erro: exibe modal no index1
        var msg = err && err.message ? err.message : "Não foi possível concluir a consulta.";
        // Mensagem específica solicitada pelo cliente
        if (msg.indexOf("dados preenchidos") !== -1 || msg.indexOf("não correspondem") !== -1) {
          showError("Os dados preenchidos não correspondem à placa selecionada.");
        } else {
          showError(msg);
        }
      })
      .finally(function() {
        hideLoading();
      });
  });
});
</script></body>
SCRIPT;
echo str_replace("</body>", $script, $html);
